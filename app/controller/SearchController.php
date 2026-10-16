<?php
namespace app\controller;

use support\Request;
use support\View;
use support\Db;

class SearchController
{
    /**
     * 统一搜索：同时搜「用户」(按 username) 与「博文」(标题/正文/作者名)。
     * 前台 header 搜索框指向本页。
     */
    public function index(Request $request)
    {
        View::assign('active_menu', 'search');
        $kw = trim($request->input('kw', ''));

        $users = [];
        $posts = [];

        if ($kw !== '') {
            // —— 用户：按 username 模糊匹配（status=1 且未删除）——
            $userRows = Db::table('users')
                ->where('status', 1)
                ->where('deleted_at', null)
                ->where('username', 'like', '%' . $kw . '%')
                ->orderBy('created_at', 'desc')
                ->limit(24)
                ->get();
            $users = db_arr($userRows);
            foreach ($users as &$u) {
                $u['qr_preview'] = $u['wechat_qr'] ?: $u['alipay_qr'];
                $u['has_wechat'] = !empty($u['wechat_qr']);
                $u['has_alipay'] = !empty($u['alipay_qr']);
                $u['can_tip'] = ($u['has_wechat'] || $u['has_alipay']) ? 1 : 0;
            }
            unset($u);

            // —— 博文：标题/正文 或 作者 username 命中（status=2 且未删除）——
            $postRows = Db::table('posts')
                ->where('status', 2)
                ->where('deleted_at', null)
                ->where(function ($q) use ($kw) {
                    $q->where('title', 'like', '%' . $kw . '%')
                        ->orWhere('content', 'like', '%' . $kw . '%')
                        ->orWhereIn('user_id', function ($sub) use ($kw) {
                            $sub->select('id')->from('users')->where('username', 'like', '%' . $kw . '%');
                        });
                })
                ->orderBy('created_at', 'desc')
                ->limit(24)
                ->get();
            $posts = db_arr($postRows);

            // 作者信息
            $authorIds = array_column($posts, 'user_id');
            $authors = [];
            if ($authorIds) {
                foreach (Db::table('users')->whereIn('id', array_unique($authorIds))->get() as $a) {
                    $authors[$a->id] = (array)$a;
                }
            }
            $catMap = $this->catNameMap();
            foreach ($posts as &$p) {
                $p['author'] = $authors[$p['user_id']] ?? null;
                $p['cat'] = $catMap[$p['category_id']] ?? '';
            }
            unset($p);
        }

        return view('search/index', [
            'kw' => $kw,
            'users' => $users,
            'posts' => $posts,
        ]);
    }

    /**
     * 分类 id => 名称 映射（缓存于单次请求内数组即可，数据量小）。
     */
    private function catNameMap(): array
    {
        $map = [];
        $rows = Db::table('post_categories')->where('status', 1)->get();
        foreach ($rows as $r) {
            $map[$r->id] = $r->name;
        }
        return $map;
    }
}
