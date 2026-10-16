<?php
namespace app\controller;

use support\Request;
use support\Db;

class TipController
{
    // 提交打赏（pay_type: 1=微信 2=支付宝 3=积分；游客仅可扫码，积分需登录）
    public function doTip(Request $request)
    {
        $toUid = (int)$request->input('to_user_id', 0);
        $payType = (int)$request->input('pay_type', 0);
        $uid = $request->session()->get('user_id');

        $to = Db::table('users')->where('id', $toUid)->where('status', 1)->where('deleted_at', null)->first();
        if (!$to) {
            return json(['code' => 1, 'msg' => '收款用户不存在']);
        }
        if ($uid && $uid == $toUid) {
            return json(['code' => 1, 'msg' => '不能给自己打赏']);
        }

        // 频率限制：同一账号 / IP 对同一收款方 60 秒内只能一次
        $ip = $request->getRealIp();
        $since = date('Y-m-d H:i:s', time() - 60);
        $recent = Db::table('tips')
            ->where('to_user_id', $toUid)
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($uid, $ip) {
                if ($uid) {
                    $q->where('from_user_id', $uid)->orWhere('from_ip', $ip);
                } else {
                    $q->where('from_ip', $ip);
                }
            })
            ->exists();
        if ($recent) {
            return json(['code' => 1, 'msg' => '操作过于频繁，请 60 秒后再试']);
        }

        // ===== 积分打赏 =====
        if ($payType == 3) {
            if (!$uid) {
                return json(['code' => 1, 'msg' => '请先登录后再打赏']);
            }
            $points = (int)$request->input('points', 0);
            if ($points < 1) {
                return json(['code' => 1, 'msg' => '打赏积分需大于 0']);
            }
            if ($points > 100000) {
                return json(['code' => 1, 'msg' => '单次打赏积分不能超过 100000']);
            }
            $me = Db::table('users')->where('id', $uid)->where('deleted_at', null)->first();
            if (!$me || $me->points < $points) {
                return json(['code' => 1, 'msg' => '当前积分不足']);
            }
            // 先扣打赏人（余额不足会返回 false）
            $ok = add_points($uid, -$points, 'tip_send', '积分打赏给 ' . $to->username);
            if (!$ok) {
                return json(['code' => 1, 'msg' => '当前积分不足']);
            }
            // 再加作者（无余额限制，不会失败）
            add_points($toUid, $points, 'tip_recv', '收到 ' . $me->username . ' 的积分打赏');
            // 双方经验 +5（与扫码打赏激励一致）
            add_experience($uid, 5);
            add_experience($toUid, 5);
            // 通知作者（系统通知，type=14）
            Db::table('notifications')->insert([
                'user_id' => $toUid, 'type' => 14, 'title' => '收到积分打赏',
                'content' => $me->username . ' 打赏了你 ' . $points . ' 积分',
                'from_user_id' => $uid, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $remain = (int)Db::table('users')->where('id', $uid)->value('points');
            return json(['code' => 0, 'msg' => '积分打赏成功，已扣除你 ' . $points . ' 积分', 'points' => $remain]);
        }

        // ===== 扫码打赏（微信 / 支付宝） =====
        $level = (int)$request->input('level', 0);
        $remark = mb_substr(trim($request->input('remark', '')), 0, 100);
        if (!in_array($level, [1, 2, 3], true) || !in_array($payType, [1, 2], true)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $amount = [1 => '0.01', 2 => '0.10', 3 => '1.00'][$level];
        $tipId = Db::table('tips')->insertGetId([
            'from_user_id' => $uid ?: null,
            'from_ip' => $ip,
            'to_user_id' => $toUid,
            'amount' => $amount,
            'level' => $level,
            'pay_type' => $payType,
            'remark' => filter_sensitive($remark),
            'status' => 0, // 待确认
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // R-13：点击「我已支付」，收款方统计金额立即增加
        Db::table('users')->where('id', $toUid)->increment('tip_total_amount', $amount);

        // 通知收款方（仅登录用户打赏可定位；游客匿名）
        if ($uid) {
            Db::table('notifications')->insert([
                'user_id' => $toUid, 'type' => 7, 'title' => '收到一笔打赏',
                'content' => '有人向你打赏了 ¥' . $amount . '（待确认）', 'related_id' => $tipId, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // 经验值：打赏双方各 +5（打赏方需登录；收款方必然为注册用户）
        if ($uid) {
            add_experience($uid, 5);
        }
        add_experience($toUid, 5);

        // 积分：收款方收到打赏奖励
        $tr = (int)site_setting('tip_reward_points', 5);
        if ($tr > 0) {
            add_points($toUid, $tr, 'tip_reward', '收到打赏奖励');
        }

        return json(['code' => 0, 'msg' => '打赏记录已生成，请自行扫码完成转账']);
    }
}
