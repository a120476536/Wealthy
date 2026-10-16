<?php
namespace app\controller;

use support\Request;
use support\Db;

class AuthController
{
    public function login(Request $request)
    {
        if ($request->method() === 'POST') {
            $ip = client_ip($request);
            $username = trim($request->input('username', ''));
            $password = (string)$request->input('password', '');

            // 服务端登录失败锁定（基于 runtime 文件，清 cookie 也有效，可抵御暴力破解）
            $failKey = 'login_fail:user:' . $username;
            $ipKey = 'login_fail:ip:' . $ip;
            if (rl_get($failKey) >= 5 || rl_get($ipKey) >= 30) {
                return json(['code' => 1, 'msg' => '尝试次数过多，请 15 分钟后再试']);
            }

            $user = Db::table('users')->where('username', $username)->where('deleted_at', null)->first();
            if (!$user || !password_verify($password, $user->password)) {
                rl_count($failKey, 900);
                rl_count($ipKey, 900);
                return json(['code' => 1, 'msg' => '用户名或密码错误']);
            }
            if ($user->status != 1) {
                return json(['code' => 1, 'msg' => '该账号已被禁用，请联系管理员']);
            }

            // 解锁计数 + 会话固定防护：登录成功后签发全新认证令牌（写入会话 + HttpOnly Cookie）
            rl_reset($failKey);
            rl_reset($ipKey);
            $authToken = bin2hex(random_bytes(32));
            $request->session()->set('user_id', $user->id);
            $request->session()->set('front_auth_token', $authToken);
            Db::table('users')->where('id', $user->id)->update(['last_login_at' => date('Y-m-d H:i:s')]);
            $resp = json(['code' => 0, 'msg' => '登录成功', 'url' => '/']);
            $resp->cookie('wa_auth', $authToken, 7 * 24 * 3600, '/', '', false, true, 'Lax');
            return $resp;
        }
        return view('auth/login', ['invite_switch' => site_setting('register_invite_switch', 'off')]);
    }

    public function register(Request $request)
    {
        if ($request->method() === 'POST') {
            $username = trim($request->input('username', ''));
            $password = (string)$request->input('password', '');
            $confirm = (string)$request->input('confirm_password', '');
            $invite = trim($request->input('invite_code', ''));
            $phone = trim($request->input('phone', ''));

            // 手机号校验（必填、格式、唯一）
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                return json(['code' => 1, 'msg' => '请输入正确的 11 位手机号']);
            }
            if (Db::table('users')->where('phone', $phone)->where('deleted_at', null)->exists()) {
                return json(['code' => 1, 'msg' => '该手机号已被注册']);
            }

            // 基础校验
            if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z_]{4,20}$/u', $username) || ctype_digit($username)) {
                return json(['code' => 1, 'msg' => '用户名需 4-20 位中文/字母/下划线，且不可为纯数字']);
            }
            if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d).{6,32}$/', $password)) {
                return json(['code' => 1, 'msg' => '密码需 6-32 位且同时包含字母与数字']);
            }
            if ($password !== $confirm) {
                return json(['code' => 1, 'msg' => '两次输入的密码不一致']);
            }

            // 邀请码：开启邀请注册模式时必填；开放模式下若填写则校验并生效（用于邀请奖励）
            $inviteSwitch = site_setting('register_invite_switch', 'off');
            $inviteCodeRow = null;
            if ($inviteSwitch === 'on' && !$invite) {
                return json(['code' => 1, 'msg' => '当前为邀请注册模式，请填写邀请码']);
            }
            if ($invite) {
                $inviteCodeRow = Db::table('invite_codes')->where('code', $invite)->where('status', 1)->first();
                if (!$inviteCodeRow) {
                    return json(['code' => 1, 'msg' => '邀请码无效']);
                }
                if ($inviteCodeRow->expire_at && strtotime($inviteCodeRow->expire_at) < time()) {
                    return json(['code' => 1, 'msg' => '邀请码已过期']);
                }
                if ($inviteCodeRow->used_count >= $inviteCodeRow->total_quota) {
                    return json(['code' => 1, 'msg' => '邀请码名额已用尽']);
                }
            }

            if (Db::table('users')->where('username', $username)->where('deleted_at', null)->exists()) {
                return json(['code' => 1, 'msg' => '用户名已被占用']);
            }

            // IP 每日注册限制（演示：基于注册日志，未启用独立日志表时放行，规则保留）
            // 写入用户
            $uid = Db::table('users')->insertGetId([
                'username' => $username,
                'phone' => $phone,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'role' => 'user',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($inviteCodeRow) {
                Db::table('invite_codes')->where('id', $inviteCodeRow->id)->increment('used_count');
                Db::table('invite_used_logs')->insert([
                    'invite_code_id' => $inviteCodeRow->id,
                    'used_user_id' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                // 邀请奖励：邀请人（码主）返还 + 被邀请人注册奖励
                if ($inviteCodeRow->owner_user_id) {
                    $ownerId = (int)$inviteCodeRow->owner_user_id;
                    $inviteeBonus = (int)site_setting('invitee_bonus_points', 20);
                    $inviterReward = (int)site_setting('invite_reward_points', 50);
                    if ($inviteeBonus > 0) {
                        add_points($uid, $inviteeBonus, 'invitee_bonus', '使用邀请码注册奖励');
                    }
                    if ($inviterReward > 0) {
                        add_points($ownerId, $inviterReward, 'invite_reward', '邀请好友「' . $username . '」注册成功');
                    }
                }
            }

            $request->session()->set('user_id', $uid);
            return json(['code' => 0, 'msg' => '注册成功', 'url' => '/profile']);
        }
        return view('auth/register', ['invite_switch' => site_setting('register_invite_switch', 'off')]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['user_id', 'front_auth_token']);
        $resp = redirect('/');
        // 清除认证令牌 Cookie
        $resp->cookie('wa_auth', '', -1, '/', '', false, true, 'Lax');
        return $resp;
    }

    public function checkUsername(Request $request)
    {
        $username = trim($request->input('username', ''));
        $exists = Db::table('users')->where('username', $username)->where('deleted_at', null)->exists();
        return json(['code' => 0, 'exists' => $exists]);
    }
}
