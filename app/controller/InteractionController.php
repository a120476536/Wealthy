<?php
namespace app\controller;

use support\Request;
use support\Db;

class InteractionController
{
    // 点赞 / 取消点赞（博文、评论）
    public function like(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $type = (int)$request->input('target_type', 0);
        $id = (int)$request->input('target_id', 0);
        if (!in_array($type, [1, 2], true) || !$id) return json(['code' => 1, 'msg' => '参数错误']);

        $exist = Db::table('likes')->where('user_id', $uid)->where('target_type', $type)->where('target_id', $id)->first();
        if ($exist) {
            Db::table('likes')->where('id', $exist->id)->delete();
            $this->likeCount($type, $id, -1);
            return json(['code' => 0, 'msg' => '已取消点赞', 'liked' => 0, 'count' => $this->getLikeCount($type, $id)]);
        }
        Db::table('likes')->insert(['user_id' => $uid, 'target_type' => $type, 'target_id' => $id, 'created_at' => date('Y-m-d H:i:s')]);
        $this->likeCount($type, $id, 1);
        if ($type == 1) {
            $post = Db::table('posts')->where('id', $id)->first();
            if ($post && $post->user_id != $uid) {
                Db::table('notifications')->insert(['user_id' => $post->user_id, 'type' => 4, 'title' => '点赞通知', 'content' => '有人赞了你的博文', 'related_id' => $id, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);
            }
        }
        return json(['code' => 0, 'msg' => '已点赞', 'liked' => 1, 'count' => $this->getLikeCount($type, $id)]);
    }

    // 收藏 / 取消收藏（仅博文）
    public function favorite(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $postId = (int)$request->input('post_id', 0);
        if (!$postId) return json(['code' => 1, 'msg' => '参数错误']);
        $exist = Db::table('favorites')->where('user_id', $uid)->where('post_id', $postId)->first();
        if ($exist) {
            Db::table('favorites')->where('id', $exist->id)->delete();
            return json(['code' => 0, 'msg' => '已取消收藏', 'faved' => 0]);
        }
        Db::table('favorites')->insert(['user_id' => $uid, 'post_id' => $postId, 'created_at' => date('Y-m-d H:i:s')]);
        return json(['code' => 0, 'msg' => '已收藏', 'faved' => 1]);
    }

    // 关注 / 取消关注
    public function follow(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $targetId = (int)$request->input('user_id', 0);
        if (!$targetId || $targetId == $uid) return json(['code' => 1, 'msg' => '参数错误']);
        $exist = Db::table('follows')->where('user_id', $targetId)->where('follower_id', $uid)->first();
        if ($exist) {
            Db::table('follows')->where('id', $exist->id)->delete();
            Db::table('users')->where('id', $targetId)->decrement('follower_count');
            Db::table('users')->where('id', $uid)->decrement('following_count');
            return json(['code' => 0, 'msg' => '已取消关注', 'following' => 0]);
        }
        Db::table('follows')->insert(['user_id' => $targetId, 'follower_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        Db::table('users')->where('id', $targetId)->increment('follower_count');
        Db::table('users')->where('id', $uid)->increment('following_count');
        Db::table('notifications')->insert(['user_id' => $targetId, 'type' => 6, 'title' => '新增关注', 'content' => '有人关注了你', 'related_id' => $uid, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        return json(['code' => 0, 'msg' => '已关注', 'following' => 1]);
    }

    private function likeCount(int $type, int $id, int $delta): void
    {
        $tbl = $type == 1 ? 'posts' : 'comments';
        $col = 'like_count';
        if ($delta > 0) Db::table($tbl)->where('id', $id)->increment($col);
        else Db::table($tbl)->where('id', $id)->decrement($col);
    }

    private function getLikeCount(int $type, int $id): int
    {
        $tbl = $type == 1 ? 'posts' : 'comments';
        $row = Db::table($tbl)->where('id', $id)->first();
        return $row ? (int)$row->like_count : 0;
    }
}
