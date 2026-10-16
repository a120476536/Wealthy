<?php
namespace app\controller;

use support\Request;
use support\Db;

class CommentController
{
    // 发表评论（仅登录用户；二级结构，最多两级）
    public function doComment(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) {
            return json(['code' => 1, 'msg' => '请先登录后再评论']);
        }
        // 账号封禁（被拉黑）检查：封禁用户禁止评论
        $me = Db::table('users')->where('id', $uid)->first();
        if (!$me || $me->status != 1) {
            return json(['code' => 1, 'msg' => '账号已被封禁，暂时无法评论']);
        }
        $targetType = (int)$request->input('target_type', 0);
        $targetId = (int)$request->input('target_id', 0);
        $parentId = (int)$request->input('parent_id', 0);
        $content = trim($request->input('content', ''));
        if (!in_array($targetType, [1, 2, 3], true) || !$targetId) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        if ($content === '' || mb_strlen($content) > 500) {
            return json(['code' => 1, 'msg' => '评论内容 1-500 字']);
        }
        // 二级回复只能挂在一级评论下
        if ($parentId) {
            $parent = Db::table('comments')->where('id', $parentId)->where('target_type', $targetType)->where('target_id', $targetId)->first();
            if (!$parent || $parent->parent_id != 0) {
                return json(['code' => 1, 'msg' => '回复目标不合法']);
            }
        }
        // 频率限制 10 秒一条
        $since = date('Y-m-d H:i:s', time() - 10);
        if (Db::table('comments')->where('user_id', $uid)->where('created_at', '>=', $since)->exists()) {
            return json(['code' => 1, 'msg' => '评论过于频繁，请稍后再试']);
        }

        $auditSwitch = site_setting('comment_audit_switch', 'off');
        $status = $auditSwitch === 'on' ? 0 : 1;
        $isAudit = $auditSwitch === 'on' ? 1 : 0;

        $cid = Db::table('comments')->insertGetId([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'user_id' => $uid,
            'parent_id' => $parentId,
            'content' => filter_sensitive($content),
            'status' => $status,
            'is_audit' => $isAudit,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::table('users')->where('id', $uid)->increment('comment_count');

        // 通知目标对象作者
        $ownerId = 0;
        if ($targetType == 1) {
            $post = Db::table('posts')->where('id', $targetId)->first();
            $ownerId = $post ? $post->user_id : 0;
        } elseif ($targetType == 2) {
            $tip = Db::table('tips')->where('id', $targetId)->first();
            $ownerId = $tip ? $tip->to_user_id : 0;
        } elseif ($targetType == 3) {
            $ownerId = $targetId;
        }
        if ($ownerId && $ownerId != $uid) {
            Db::table('notifications')->insert([
                'user_id' => $ownerId,
                'type' => $parentId ? 2 : 1,
                'title' => $parentId ? '有人回复了你' : '新的评论',
                'content' => mb_substr($content, 0, 60),
                'related_id' => $cid, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return json(['code' => 0, 'msg' => $status == 1 ? '评论成功' : '评论已提交，审核通过后展示', 'id' => $cid]);
    }

    // 删除自己的评论（软删除，仅本人可删；删除一级评论时级联软删其子回复）
    public function deleteComment(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) {
            return json(['code' => 1, 'msg' => '请先登录后再操作']);
        }
        $id = (int)$request->input('id', 0);
        $c = Db::table('comments')->where('id', $id)->where('deleted_at', null)->first();
        if (!$c) {
            return json(['code' => 1, 'msg' => '评论不存在或已删除']);
        }
        if ($c->user_id != $uid) {
            return json(['code' => 1, 'msg' => '只能删除自己的评论']);
        }
        $now = date('Y-m-d H:i:s');
        $delIds = [$c->id];
        // 一级评论：级联软删其子回复
        if ($c->parent_id == 0) {
            $childIds = db_arr(Db::table('comments')->where('parent_id', $c->id)->where('deleted_at', null)->pluck('id'));
            if ($childIds) {
                Db::table('comments')->whereIn('id', $childIds)->update(['deleted_at' => $now]);
                $delIds = array_merge($delIds, $childIds);
            }
        }
        Db::table('comments')->where('id', $c->id)->update(['deleted_at' => $now]);
        // 同步扣减本人评论数（仅扣真实存在的条数）
        Db::table('users')->where('id', $uid)->where('comment_count', '>=', count($delIds))
            ->decrement('comment_count', count($delIds));
        return json(['code' => 0, 'msg' => '已删除', 'del_count' => count($delIds)]);
    }
}
