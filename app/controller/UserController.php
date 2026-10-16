<?php
namespace app\controller;

use support\Request;
use support\View;
use support\Db;

class UserController
{
    public function home(Request $request, $id)
    {
        $id = (int)$id;
        $user = Db::table('users')->where('id', $id)->where('deleted_at', null)->first();
        if (!$user || $user->status != 1) {
            return response('<h1 style="padding:40px;font-family:sans-serif">用户不存在</h1>', 404);
        }
        $user = (array)$user;

        // 已审核收款码
        $wx = ($user['wechat_qr_status'] == 2 && $user['wechat_qr']) ? qr_asset($user['wechat_qr']) : '';
        $ali = ($user['alipay_qr_status'] == 2 && $user['alipay_qr']) ? qr_asset($user['alipay_qr']) : '';
        $hasQr = !empty($wx) || !empty($ali);

        // 博文列表
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 12;
        $total = Db::table('posts')->where('user_id', $id)->where('status', 2)->where('deleted_at', null)->count();
        $totalPages = (int)ceil($total / $perPage);
        $pages = build_page_window($page, $totalPages);
        $posts = db_arr(Db::table('posts')
            ->where('user_id', $id)->where('status', 2)->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->limit($perPage)->offset(($page - 1) * $perPage)->get());
        $catMap = [];
        foreach ($posts as &$p) { $catMap[$p['category_id']] = $catMap[$p['category_id']] ?? $this->catName($p['category_id']); $p['cat'] = $catMap[$p['category_id']]; }
        unset($p);

        // 当前用户是否关注
        $uid = $request->session()->get('user_id');
        $following = 0;
        if ($uid && $uid != $id) {
            $following = Db::table('follows')->where('user_id', $id)->where('follower_id', $uid)->exists() ? 1 : 0;
        }

        return view('user/home', [
            'u' => $user, 'wx' => $wx, 'ali' => $ali, 'hasQr' => $hasQr,
            'posts' => $posts, 'page' => $page, 'total' => $total, 'pages' => $pages, 'totalPages' => $totalPages,
            'prevPage' => $page > 1 ? $page - 1 : 1,
            'nextPage' => $page < $totalPages ? $page + 1 : $totalPages,
            'following' => $following, 'isSelf' => ($uid == $id),
            'pmFollowRequired' => site_setting('pm_follow_required', 'off') === 'on',
        ]);
    }

    private function catName($id)
    {
        if (!$id) return '未分类';
        $c = Db::table('post_categories')->where('id', $id)->first();
        return $c ? $c->name : '未分类';
    }
}
