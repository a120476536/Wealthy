<?php
namespace app\controller;

use support\Request;
use support\View;
use support\Db;

class PostController
{
    public function index(Request $request)
    {
        View::assign('active_menu', 'posts');
        $kw = trim($request->input('kw', ''));
        $catId = (int)$request->input('cat', 0);
        $tag = trim($request->input('tag', ''));
        $sort = $request->input('sort', 'hot');
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 10;

        $query = Db::table('posts')->where('status', 2)->where('deleted_at', null);
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('title', 'like', '%' . $kw . '%')->orWhere('content', 'like', '%' . $kw . '%');
            });
        }
        if ($catId) $query->where('category_id', $catId);
        if ($tag) $query->where('tags', 'like', '%' . $tag . '%');

        $total = $query->count();
        $totalPages = (int)ceil($total / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages; // 越界页码钳制到末页，避免空页死胡同
        }
        $pages = $this->buildPages($page, $totalPages);

        if ($sort === 'hot') {
            // 热度分：阅读量用 SQRT 压缩（1600阅读≈40分，避免碾压），点赞、评论按真实互动加权（评论>点赞），让热度真正反映互动质量
            $query->orderByRaw('(ROUND(SQRT(view_count)) * 4 + like_count * 8 + comment_count * 12) desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
        $posts = db_arr($query->limit($perPage)->offset(($page - 1) * $perPage)->get());

        // 作者信息
        $authorIds = array_column($posts, 'user_id');
        $authors = [];
        if ($authorIds) {
            foreach (Db::table('users')->whereIn('id', array_unique($authorIds))->get() as $a) {
                $authors[$a->id] = (array)$a;
            }
        }
        foreach ($posts as &$p) {
            $p['author'] = $authors[$p['user_id']] ?? null;
            $p['cat'] = $this->catName($p['category_id']);
        }
        unset($p);

        $categories = db_arr(Db::table('post_categories')->where('status', 1)->orderBy('sort', 'desc')->get());

        return view('post/index', [
            'posts' => $posts, 'kw' => $kw, 'cat' => $catId, 'tag' => $tag, 'sort' => $sort,
            'page' => $page, 'pages' => $pages, 'totalPages' => $totalPages, 'total' => $total, 'categories' => $categories,
            'prevPage' => $page > 1 ? $page - 1 : 1,
            'nextPage' => $page < $totalPages ? $page + 1 : $totalPages,
        ]);
    }

    /**
     * 生成带省略号的页码数组（应对数据量很大的情况）。
     * 规则：始终显示第 1 页与末页；显示当前页 ±1；页数 <=7 时直接列出全部，否则用 '...' 折叠中间段。
     * @return array<int|string> 元素为页码整数或字符串 '...'
     */
    private function buildPages(int $current, int $totalPages): array
    {
        return build_page_window($current, $totalPages);
    }

    public function detail(Request $request, $id)
    {
        $post = Db::table('posts')->where('id', $id)->where('deleted_at', null)->first();
        if (!$post) {
            return response('<h1 style="padding:40px;font-family:sans-serif">博文不存在或已删除</h1>', 404);
        }
        $uid = $request->session()->get('user_id');
        $isOwner = $uid && $uid == $post->user_id;
        if ($post->status != 2 && !$isOwner) {
            return response('<h1 style="padding:40px;font-family:sans-serif">博文审核中或不可见</h1>', 403);
        }
        // 浏览计数
        Db::table('posts')->where('id', $id)->increment('view_count');

        $post = (array)$post;
        $post['content'] = sanitize_rich_html($post['content']);
        $author = (array)Db::table('users')->where('id', $post['user_id'])->first();
        $post['cat'] = $this->catName($post['category_id']);
        $post['tag_arr'] = $post['tags'] ? array_values(array_filter(array_map('trim', explode(',', $post['tags'])))) : [];
        // 作者已审核收款码（供打赏弹窗使用）
        $authorWx = ($author['wechat_qr_status'] == 2 && $author['wechat_qr']) ? qr_asset($author['wechat_qr']) : '';
        $authorAli = ($author['alipay_qr_status'] == 2 && $author['alipay_qr']) ? qr_asset($author['alipay_qr']) : '';

        // 评论（两级）
        $top = db_arr(Db::table('comments')->where('target_type', 1)->where('target_id', $id)->where('parent_id', 0)->where('status', 1)->whereNull('deleted_at')->orderBy('created_at', 'desc')->get());
        $replyIds = array_column($top, 'id');
        $replies = [];
        if ($replyIds) {
            $rs = db_arr(Db::table('comments')->where('target_type', 1)->where('target_id', $id)->whereIn('parent_id', $replyIds)->where('status', 1)->whereNull('deleted_at')->orderBy('created_at', 'asc')->get());
            foreach ($rs as $r) { $replies[$r['parent_id']][] = $r; }
        }
        // 评论用户信息
        $uids = array_unique(array_merge(array_column($top, 'user_id'), array_column($replies ? call_user_func_array('array_merge', array_values($replies)) : [], 'user_id')));
        $users = [];
        if ($uids) { foreach (Db::table('users')->whereIn('id', $uids)->get() as $u) { $users[$u->id] = (array)$u; } }

        // 互动状态
        $liked = 0; $faved = 0;
        if ($uid) {
            $liked = Db::table('likes')->where('user_id', $uid)->where('target_type', 1)->where('target_id', $id)->exists() ? 1 : 0;
            $faved = Db::table('favorites')->where('user_id', $uid)->where('post_id', $id)->exists() ? 1 : 0;
        }
        // 相关博文（同作者）
        $related = db_arr(Db::table('posts')->where('user_id', $post['user_id'])->where('id', '<>', $id)->where('status', 2)->where('deleted_at', null)->orderBy('created_at', 'desc')->limit(5)->get());

        return view('post/detail', [
            'post' => $post, 'author' => $author, 'author_wx' => $authorWx, 'author_ali' => $authorAli,
            'top' => $top, 'replies' => $replies, 'users' => $users,
            'liked' => $liked, 'faved' => $faved, 'related' => $related, 'isOwner' => $isOwner,
            'cm_target_type' => 1, 'cm_target_id' => $id, 'cm_top' => $top, 'cm_replies' => $replies, 'cm_users' => $users,
        ]);
    }

    public function create(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return redirect('/login');
        if ($request->method() === 'POST') {
            return $this->savePost($request, $uid, 0);
        }
        $categories = db_arr(Db::table('post_categories')->where('status', 1)->orderBy('sort', 'desc')->get());
        return view('post/edit', ['categories' => $categories, 'post' => null, 'isEdit' => 0]);
    }

    public function edit(Request $request, $id)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return redirect('/login');
        $post = Db::table('posts')->where('id', $id)->where('deleted_at', null)->first();
        if (!$post || $post->user_id != $uid) {
            return response('<h1 style="padding:40px;font-family:sans-serif">无权操作</h1>', 403);
        }
        if ($request->method() === 'POST') {
            return $this->savePost($request, $uid, $id);
        }
        $categories = db_arr(Db::table('post_categories')->where('status', 1)->orderBy('sort', 'desc')->get());
        return view('post/edit', ['categories' => $categories, 'post' => (array)$post, 'isEdit' => 1]);
    }

    private function savePost(Request $request, $uid, $id)
    {
        $title = trim($request->input('title', ''));
        $content = trim($request->input('content', ''));
        $catId = (int)$request->input('category_id', 0);
        $tagsRaw = trim($request->input('tags', ''));
        $action = $request->input('action', 'publish');
        $strip = strip_tags($content);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 100) return json(['code' => 1, 'msg' => '标题需 2-100 字']);
        if (mb_strlen($strip) < 10) return json(['code' => 1, 'msg' => '正文至少 10 字']);
        if (!$catId) return json(['code' => 1, 'msg' => '请选择分类']);

        $tags = '';
        if ($tagsRaw) {
            $tags = implode(',', array_slice(array_map('trim', explode(',', $tagsRaw)), 0, 5));
        }
        $summary = mb_substr(strip_tags($content), 0, 120);
        $auditSwitch = site_setting('blog_audit_switch', 'off');
        $status = $action === 'draft' ? 0 : ($auditSwitch === 'on' ? 1 : 2);

        $data = [
            'title' => $title, 'content' => $content, 'category_id' => $catId, 'tags' => $tags,
            'summary' => $summary, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id) {
            Db::table('posts')->where('id', $id)->update($data);
            $pid = $id;
        } else {
            $data['user_id'] = $uid;
            $data['status'] = $status;
            $data['created_at'] = date('Y-m-d H:i:s');
            $pid = Db::table('posts')->insertGetId($data);
            if ($status == 2) {
                Db::table('users')->where('id', $uid)->increment('post_count');
                add_experience($uid, 10); // 发帖成功 +10 经验
                $pr = (int)site_setting('post_reward_points', 10);
                if ($pr > 0) {
                    add_points($uid, $pr, 'post_reward', '发布博文奖励');
                }
            }
        }
        return json(['code' => 0, 'msg' => $status == 0 ? '草稿已保存' : ($status == 1 ? '已提交审核' : '发布成功'), 'url' => '/post/' . $pid]);
    }

    public function delete(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $id = (int)$request->input('id', 0);
        $post = Db::table('posts')->where('id', $id)->where('deleted_at', null)->first();
        if (!$post || $post->user_id != $uid) return json(['code' => 1, 'msg' => '无权操作']);
        Db::table('posts')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        if ($post->status == 2) {
            Db::table('users')->where('id', $uid)->where('post_count', '>', 0)->decrement('post_count');
        }
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * Markdown 导入：接收前端 marked 转换后的 HTML，按需在服务端把远程图片本地化，
     * 再经 sanitize_rich_html 净化后返回，可直接填充到富文本编辑器。
     */
    public function mdImport(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) {
            return json(['code' => 1, 'msg' => '请先登录']);
        }
        $html = (string)$request->input('html', '');
        $localize = (int)$request->input('localize', 1);
        if (mb_strlen(strip_tags($html)) < 1) {
            return json(['code' => 1, 'msg' => '内容为空']);
        }
        $stats = ['ok' => 0, 'fail' => 0, 'local' => 0];

        // 1) 处理随附上传的本地图片（md 中以相对 / 本地路径引用的图，无法被服务器下载）
        $meta = json_decode((string)$request->input('img_meta', '[]'), true);
        if (is_array($meta)) {
            foreach ($meta as $m) {
                $src = (string)($m['src'] ?? '');
                $field = (string)($m['field'] ?? '');
                if ($src === '' || $field === '') {
                    continue;
                }
                $file = $request->file($field);
                if (!$file || !$file->isValid()) {
                    $stats['fail']++;
                    continue;
                }
                $url = save_uploaded_image_local($file, $uid);
                if ($url) {
                    $html = $this->replaceImgSrc($html, $src, $url);
                    $stats['local']++;
                    $stats['ok']++;
                } else {
                    $stats['fail']++;
                }
            }
        }

        // 2) 处理剩余 http(s) 外链图片（可选）
        if ($localize) {
            $html = $this->localizeImages($html, $uid, $stats);
        }

        $html = sanitize_rich_html($html);
        return json([
            'code'      => 0,
            'html'      => $html,
            'localized' => $stats['ok'],
            'failed'    => $stats['fail'],
            'local'     => $stats['local'],
        ]);
    }

    /**
     * 把 HTML 中某个 <img> 的 src（精确匹配原路径，全部替换）改写为新 URL。
     */
    private function replaceImgSrc(string $html, string $oldSrc, string $newSrc): string
    {
        $q = preg_quote($oldSrc, '#');
        return preg_replace_callback('#src=["\']' . $q . '["\']#i', function ($m) use ($newSrc) {
            $quote = ($m[0][4] ?? '"') === '"' ? '"' : "'";
            return 'src=' . $quote . $newSrc . $quote;
        }, $html) ?? $html;
    }

    /**
     * 把 HTML 中的远程 <img> 下载到本站 uploads/md，改写 src；失败的原样保留（后续会被 sanitize 过滤）。
     */
    private function localizeImages(string $html, int $uid, array &$stats): string
    {
        $seen = [];
        $html = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($uid, &$stats, &$seen) {
            $tag = $m[0];
            if (!preg_match('#src=["\']([^"\']+)["\']#i', $tag, $s)) {
                return $tag;
            }
            $url = trim($s[1]);
            if (!preg_match('#^https?://#i', $url)) {
                return $tag;
            }
            if (isset($seen[$url])) {
                return str_replace($s[0], 'src="' . $seen[$url] . '"', $tag);
            }
            $local = save_remote_image_local($url, $uid);
            if ($local) {
                $seen[$url] = $local;
                $stats['ok']++;
                return str_replace($s[0], 'src="' . $local . '"', $tag);
            }
            $stats['fail']++;
            return $tag;
        }, $html);
        return $html;
    }

    private function catName($id)
    {
        if (!$id) return '未分类';
        $c = Db::table('post_categories')->where('id', $id)->first();
        return $c ? $c->name : '未分类';
    }
}
