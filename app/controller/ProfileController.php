<?php
namespace app\controller;

use support\Request;
use support\Db;
use support\Response;

class ProfileController
{
    private function requireUser(Request $request): ?array
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) {
            if ($request->isAjax() || strtoupper($request->method()) === 'POST') {
                return null;
            }
            redirect('/login');
            return null;
        }
        $user = Db::table('users')->where('id', $uid)->where('deleted_at', null)->first();
        if (!$user) {
            redirect('/login');
            return null;
        }
        return user_points_safe((array)$user);
    }

    public function index(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');

        $uid = $user['id'];
        $stats = [
            'posts' => Db::table('posts')->where('user_id', $uid)->where('deleted_at', null)->where('status', '<>', 0)->count(),
            'received' => Db::table('tips')->where('to_user_id', $uid)->where('status', 1)->count(),
            'received_amount' => Db::table('tips')->where('to_user_id', $uid)->where('status', 1)->sum('amount') ?: 0,
            'sent' => Db::table('tips')->where('from_user_id', $uid)->count(),
            'favorites' => Db::table('favorites')->where('user_id', $uid)->count(),
            'followers' => $user['follower_count'],
            'following' => $user['following_count'],
            'unread' => Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->count(),
        ];

        return view('profile/index', ['u' => $user, 'stats' => $stats, 'active' => 'index', 'lp' => level_progress($user['experience'])]);
    }

    public function received(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $tips = db_arr(Db::table('tips')->where('to_user_id', $uid)->orderBy('created_at', 'desc')->limit(50)->get());
        $uids = array_column($tips, 'from_user_id');
        $users = $this->userMap($uids);
        return view('profile/tips', ['tips' => $tips, 'users' => $users, 'mode' => 'received', 'active' => 'received', 'u' => $user]);
    }

    public function sent(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $tips = db_arr(Db::table('tips')->where('from_user_id', $uid)->orderBy('created_at', 'desc')->limit(50)->get());
        $uids = array_column($tips, 'to_user_id');
        $users = $this->userMap($uids);
        return view('profile/tips', ['tips' => $tips, 'users' => $users, 'mode' => 'sent', 'active' => 'sent', 'u' => $user]);
    }

    public function posts(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $posts = db_arr(Db::table('posts')->where('user_id', $uid)->where('deleted_at', null)->orderBy('created_at', 'desc')->limit(50)->get());
        foreach ($posts as &$p) { $p['cat'] = $this->catName($p['category_id']); }
        unset($p);
        return view('profile/posts', ['posts' => $posts, 'active' => 'posts', 'u' => $user]);
    }

    public function comments(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $comments = db_arr(Db::table('comments')->where('user_id', $uid)->where('target_type', 1)->whereNull('deleted_at')->orderBy('created_at', 'desc')->limit(50)->get());
        $ids = array_column($comments, 'target_id');
        $postMap = [];
        if ($ids) { foreach (Db::table('posts')->whereIn('id', array_unique($ids))->get() as $po) { $postMap[$po->id] = (array)$po; } }
        return view('profile/comments', ['comments' => $comments, 'postMap' => $postMap, 'active' => 'comments', 'u' => $user]);
    }

    public function favorites(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $favs = db_arr(Db::table('favorites')->where('user_id', $uid)->orderBy('created_at', 'desc')->limit(100)->get());
        $ids = array_column($favs, 'post_id');
        $posts = [];
        if ($ids) { foreach (Db::table('posts')->whereIn('id', array_unique($ids))->where('deleted_at', null)->get() as $po) { $p = (array)$po; $p['cat'] = $this->catName($p['category_id']); $posts[] = $p; } }
        return view('profile/favorites', ['posts' => $posts, 'active' => 'favorites', 'u' => $user]);
    }

    public function following(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        return $this->followView($uid, 'following', 'following');
    }

    public function followers(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        return $this->followView($uid, 'followers', 'followers');
    }

    private function followView($uid, $col, $mode)
    {
        if ($mode === 'following') {
            $rows = db_arr(Db::table('follows')->where('follower_id', $uid)->orderBy('created_at', 'desc')->limit(100)->get());
            $otherKey = 'user_id';
        } else {
            $rows = db_arr(Db::table('follows')->where('user_id', $uid)->orderBy('created_at', 'desc')->limit(100)->get());
            $otherKey = 'follower_id';
        }
        $ids = array_column($rows, $otherKey);
        $users = $this->userMap($ids);
        return view('profile/follow', ['users' => $users, 'mode' => $mode, 'active' => $mode, 'u' => (array)Db::table('users')->where('id', $uid)->first()]);
    }

    public function notifications(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $tab = $request->input('tab', 'sys') === 'pm' ? 'pm' : 'sys';
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        if ($tab === 'pm') {
            // 私信列表（type=12），与系统通知一致的平铺列表
            $q = Db::table('notifications')->where('user_id', $uid)->where('type', 12)->whereNull('deleted_at');
            $total = (clone $q)->count();
            $rows = db_arr($q->orderBy('created_at', 'desc')->offset($offset)->limit($perPage)->get());
            $map = $this->userMap(array_column($rows, 'from_user_id'));
            foreach ($rows as &$n) {
                $sender = $map[$n['from_user_id']] ?? null;
                $n['row_title'] = $sender ? $sender['username'] : '某用户';
                $n['data_from'] = (int)$n['from_user_id'];
            }
            unset($n);
        } else {
            // 系统通知：排除用户私信（type=12）
            $q = Db::table('notifications')->where('user_id', $uid)->where('type', '<>', 12)->whereNull('deleted_at');
            $total = (clone $q)->count();
            $rows = db_arr($q->orderBy('created_at', 'desc')->offset($offset)->limit($perPage)->get());
            foreach ($rows as &$n) { $n['row_title'] = ''; $n['data_from'] = 0; }
            unset($n);
        }

        $pmUnread = Db::table('notifications')->where('user_id', $uid)->where('type', 12)->where('is_read', 0)->whereNull('deleted_at')->count();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $pager = $this->frontPager($page, $totalPages, ['tab' => $tab]);
        // tab 高亮样式预计算（避免模板 if 写进 class 属性）
        $tabSysClass = $tab === 'sys' ? 'px-4 py-1.5 rounded-lg text-sm font-medium bg-white shadow-sm text-slate-900' : 'px-4 py-1.5 rounded-lg text-sm font-medium text-slate-500 hover:text-slate-800';
        $tabPmClass  = $tab === 'pm'  ? 'px-4 py-1.5 rounded-lg text-sm font-medium bg-white shadow-sm text-slate-900' : 'px-4 py-1.5 rounded-lg text-sm font-medium text-slate-500 hover:text-slate-800';
        return view('profile/notifications', [
            'notes' => $rows, 'tab' => $tab, 'total' => $total, 'pager' => $pager,
            'pmUnread' => $pmUnread, 'tabSysClass' => $tabSysClass, 'tabPmClass' => $tabPmClass,
            'active' => 'notifications', 'u' => $user,
        ]);
    }

    // 前台简单分页条（与后台 buildPager 同样式）
    private function frontPager(int $page, int $totalPages, array $params = []): string
    {
        if ($totalPages <= 1) return '';
        $mkUrl = function ($p) use ($params) {
            $q = $params;
            $q['page'] = $p;
            return '/profile/notifications?' . http_build_query($q);
        };
        $html = '<nav class="flex flex-wrap items-center gap-1 mt-4 text-sm">';
        $html .= '<a href="' . ($page > 1 ? $mkUrl($page - 1) : 'javascript:void(0)') . '" class="px-3 py-1.5 rounded-lg border ' . ($page > 1 ? 'border-slate-200 text-slate-600 hover:bg-slate-50' : 'border-slate-100 text-slate-300 cursor-not-allowed') . '">上一页</a>';
        if ($totalPages <= 7) {
            $pages = range(1, $totalPages);
        } else {
            $pages = [1, 2];
            if ($page > 4) $pages[] = '...';
            for ($i = max(3, $page - 1); $i <= min($totalPages - 2, $page + 1); $i++) $pages[] = $i;
            if ($page < $totalPages - 3) $pages[] = '...';
            $pages[] = $totalPages - 1;
            $pages[] = $totalPages;
        }
        foreach ($pages as $p) {
            if ($p === '...') { $html .= '<span class="px-2 text-slate-400">…</span>'; continue; }
            $on = $p == $page;
            $html .= '<a href="' . $mkUrl($p) . '" class="px-3 py-1.5 rounded-lg border ' . ($on ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50') . '">' . $p . '</a>';
        }
        $html .= '<a href="' . ($page < $totalPages ? $mkUrl($page + 1) : 'javascript:void(0)') . '" class="px-3 py-1.5 rounded-lg border ' . ($page < $totalPages ? 'border-slate-200 text-slate-600 hover:bg-slate-50' : 'border-slate-100 text-slate-300 cursor-not-allowed') . '">下一页</a>';
        $html .= '</nav>';
        return $html;
    }

    // 软删除自己的通知/私信：删除后仅本人不可见
    public function deleteNotification(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $id = (int)$request->input('id', 0);
        if ($id <= 0) return json(['code' => 1, 'msg' => '参数错误']);
        Db::table('notifications')
            ->where('id', $id)->where('user_id', $uid)->whereNull('deleted_at')
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $unread = Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->whereNull('deleted_at')->count();
        return json(['code' => 0, 'msg' => '已删除', 'unread' => $unread]);
    }

    // 私信会话列表（按发送者聚合，同一人多条合并为一条会话）
    public function messageSessions(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return json(['code' => 1, 'msg' => '请先登录']);
        $uid = $user['id'];
        $rows = Db::table('notifications')
            ->where('user_id', $uid)
            ->where('type', 12)
            ->whereNull('deleted_at')
            ->selectRaw('from_user_id, MAX(id) AS last_id, COUNT(*) AS cnt, SUM(is_read=0) AS unread, MAX(created_at) AS last_at')
            ->groupBy('from_user_id')
            ->orderBy('last_at', 'desc')
            ->get();
        $sessions = [];
        foreach ($rows as $r) {
            $fu = Db::table('users')->where('id', $r->from_user_id)->first();
            $last = Db::table('notifications')->where('id', $r->last_id)->whereNull('deleted_at')->first();
            $sessions[] = [
                'from_user_id'  => $r->from_user_id,
                'from_username' => $fu ? $fu->username : '某用户',
                'last_content'  => $last ? $last->content : '',
                'last_at'       => $r->last_at,
                'unread'        => (int)$r->unread,
                'total'         => (int)$r->cnt,
            ];
        }
        $unread = Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->whereNull('deleted_at')->count();
        return json(['code' => 0, 'sessions' => $sessions, 'unread' => $unread]);
    }

    // 私信会话详情：取与某人的全部私信并标记已读
    public function messageSession(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return json(['code' => 1, 'msg' => '请先登录']);
        $uid = $user['id'];
        $fromUid = (int)$request->input('from_user_id', 0);
        if ($fromUid <= 0) return json(['code' => 1, 'msg' => '参数错误']);
        $msgs = db_arr(Db::table('notifications')
            ->where('user_id', $uid)
            ->where('from_user_id', $fromUid)
            ->where('type', 12)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->limit(200)
            ->get());
        Db::table('notifications')
            ->where('user_id', $uid)
            ->where('from_user_id', $fromUid)
            ->where('type', 12)
            ->whereNull('deleted_at')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);
        $fu = Db::table('users')->where('id', $fromUid)->first();
        $unread = Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->whereNull('deleted_at')->count();
        return json([
            'code'          => 0,
            'from_username' => $fu ? $fu->username : '某用户',
            'messages'      => $msgs,
            'unread'        => $unread,
        ]);
    }

    // 用户间私信（复用 notifications 表，type=12）
    public function sendMessage(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $toUid = (int)$request->input('to_user_id', 0);
        $content = trim($request->input('content', ''));
        if ($toUid <= 0 || $toUid == $uid) return json(['code' => 1, 'msg' => '收件人无效']);
        if ($content === '') return json(['code' => 1, 'msg' => '消息内容不能为空']);
        if (mb_strlen($content) > 500) return json(['code' => 1, 'msg' => '消息不超过 500 字']);
        $to = Db::table('users')->where('id', $toUid)->where('deleted_at', null)->where('status', 1)->first();
        if (!$to) return json(['code' => 1, 'msg' => '收件人不存在或已被封禁']);
        // 私信关注限制：开启时需先关注对方才能发私信（防止绕过前端）
        if (site_setting('pm_follow_required', 'off') === 'on') {
            $isFollowing = Db::table('follows')->where('user_id', $toUid)->where('follower_id', $uid)->exists();
            if (!$isFollowing) {
                return json(['code' => 1, 'msg' => '请先关注对方后再发私信']);
            }
        }
        // 发送私信：扣除积分（系统设置 pm_cost_points，默认 5）；余额不足拦截
        $pmCost = (int)(site_setting('pm_cost_points', '5') ?? 5);
        if ($pmCost > 0) {
            $sender = Db::table('users')->where('id', $uid)->first();
            $balance = $sender ? (int)$sender->points : 0;
            if ($balance < $pmCost) {
                return json(['code' => 1, 'msg' => '当前积分不足']);
            }
            if (!add_points($uid, -$pmCost, 'pm_send', '发送私信扣除' . $pmCost . '积分')) {
                return json(['code' => 1, 'msg' => '当前积分不足']);
            }
        }
        Db::table('notifications')->insert([
            'user_id' => $toUid,
            'from_user_id' => $uid,
            'type' => 12,
            'title' => '私信',
            'content' => $content,
            'related_id' => $uid,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return json(['code' => 0, 'msg' => '消息已发送']);
    }

    public function password(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) {
            if (strtoupper($request->method()) === 'POST' || $request->isAjax()) return json(['code' => 1, 'msg' => '请先登录']);
            return redirect('/login');
        }
        if (strtoupper($request->method()) !== 'POST') {
            return view('profile/settings', ['active' => 'password', 'tab' => 'password', 'u' => $user]);
        }
        $old = $request->input('old_password', '');
        $new = $request->input('new_password', '');
        $confirm = $request->input('confirm_password', '');
        if (!password_verify($old, $user['password'])) return json(['code' => 1, 'msg' => '原密码错误']);
        if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d).{6,32}$/', $new)) return json(['code' => 1, 'msg' => '新密码需 6-32 位且含字母和数字']);
        if ($new !== $confirm) return json(['code' => 1, 'msg' => '两次输入不一致']);
        Db::table('users')->where('id', $user['id'])->update([
            'password' => password_hash($new, PASSWORD_BCRYPT), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(['code' => 0, 'msg' => '密码已修改']);
    }

    public function editProfile(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) {
            if (strtoupper($request->method()) === 'POST' || $request->isAjax()) return json(['code' => 1, 'msg' => '请先登录']);
            return redirect('/login');
        }
        if (strtoupper($request->method()) !== 'POST') {
            return view('profile/settings', ['active' => 'edit', 'tab' => 'edit', 'u' => $user]);
        }
        $bio = trim($request->input('bio', ''));
        $username = trim($request->input('username', ''));
        if (mb_strlen($bio) > 200) return json(['code' => 1, 'msg' => '简介不超过 200 字']);
        $data = ['bio' => $bio, 'updated_at' => date('Y-m-d H:i:s')];
        if ($username && $username !== $user['username']) {
            if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z_]{4,20}$/u', $username) || ctype_digit($username)) {
                return json(['code' => 1, 'msg' => '用户名格式不正确']);
            }
            if (Db::table('users')->where('username', $username)->where('id', '<>', $user['id'])->exists()) {
                return json(['code' => 1, 'msg' => '用户名已被占用']);
            }
            $data['username'] = $username;
        }
        Db::table('users')->where('id', $user['id'])->update($data);
        return json(['code' => 0, 'msg' => '资料已更新']);
    }

    public function closeBanner(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        Db::table('users')->where('id', $uid)->update(['risk_banner_closed_at' => date('Y-m-d H:i:s')]);
        return json(['code' => 0, 'msg' => 'ok']);
    }

    // ===================== 积分 & 邀请 =====================
    public function invite(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $cost = (int)site_setting('invite_code_cost', 100);
        $checkinSwitch = site_setting('checkin_switch', 'on');
        $checkedToday = !empty($user['last_checkin_at']) && date('Y-m-d', strtotime($user['last_checkin_at'])) === date('Y-m-d');
        $codes = db_arr(Db::table('invite_codes')->where('owner_user_id', $uid)->where('deleted_at', null)
            ->orderBy('created_at', 'desc')->limit(50)->get());
        return view('profile/invite', [
            'u' => $user, 'active' => 'invite', 'cost' => $cost,
            'checkin_switch' => $checkinSwitch, 'checked_today' => $checkedToday, 'codes' => $codes,
        ]);
    }

    public function generateInviteCode(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return json(['code' => 1, 'msg' => '请先登录']);
        $uid = $user['id'];
        $cost = (int)site_setting('invite_code_cost', 100);
        if ($cost > 0 && $user['points'] < $cost) {
            return json(['code' => 1, 'msg' => '积分不足，生成邀请码需 ' . $cost . ' 积分']);
        }
        if ($cost > 0 && !add_points($uid, -$cost, 'invite_code_cost', '生成邀请码')) {
            return json(['code' => 1, 'msg' => '积分不足，无法生成']);
        }
        $code = strtoupper(substr(md5(uniqid() . rand()), 0, 10));
        Db::table('invite_codes')->insert([
            'code' => $code, 'owner_user_id' => $uid, 'total_quota' => 1, 'used_count' => 0,
            'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $points = (int)Db::table('users')->where('id', $uid)->value('points');
        return json(['code' => 0, 'msg' => '邀请码已生成', 'code_str' => $code, 'points' => $points]);
    }

    public function checkin(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return json(['code' => 1, 'msg' => '请先登录']);
        $uid = $user['id'];
        if (site_setting('checkin_switch', 'on') !== 'on') {
            return json(['code' => 1, 'msg' => '签到功能未开启']);
        }
        if (!empty($user['last_checkin_at']) && date('Y-m-d', strtotime($user['last_checkin_at'])) === date('Y-m-d')) {
            return json(['code' => 1, 'msg' => '今日已签到']);
        }
        $add = (int)site_setting('daily_checkin_points', 5);
        add_points($uid, $add, 'checkin', '每日签到');
        $points = (int)Db::table('users')->where('id', $uid)->value('points');
        Db::table('users')->where('id', $uid)->update(['last_checkin_at' => date('Y-m-d H:i:s')]);
        return json(['code' => 0, 'msg' => '签到成功，+ ' . $add . ' 积分', 'points' => $points]);
    }

    public function pointsLogs(Request $request)
    {
        $user = $this->requireUser($request);
        if (!$user) return redirect('/login');
        $uid = $user['id'];
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $total = Db::table('points_logs')->where('user_id', $uid)->count();
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $logs = db_arr(Db::table('points_logs')->where('user_id', $uid)
            ->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pages = build_page_window($page, $totalPages);
        return view('profile/points_logs', [
            'u' => $user, 'active' => 'points', 'logs' => $logs, 'total' => $total,
            'page' => $page, 'totalPages' => $totalPages, 'pages' => $pages,
        ]);
    }

    public function uploadQr(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $qrType = (int)$request->input('qr_type', 1);
        // 双渠道：微信/支付宝可任传一个或同时提交（wechat_url / alipay_url）
        $wxUrl = trim($request->input('wechat_url', ''));
        $aliUrl = trim($request->input('alipay_url', ''));
        $risk = (int)$request->input('risk_confirmed', 0);
        if (!$wxUrl && !$aliUrl) return json(['code' => 1, 'msg' => '请至少上传一个渠道的收款码图片']);
        if (!$risk) return json(['code' => 1, 'msg' => '请确认风险提示']);
        if (!in_array($qrType, [1, 2], true)) return json(['code' => 1, 'msg' => '收款码类型错误']);

        // 存储格式归一化：统一存成 qr/xxx.jpg（不带 uploads/ 前缀），
        // 展示统一用 qr_asset() 拼 /uploads/ 前缀。上传接口返回 /uploads/qr/...，
        // 若只 ltrim('/') 会存成 uploads/qr/...，导致后台 /uploads/ uploads/ 双前缀 404。
        $normPath = function ($url) {
            $p = ltrim(trim($url), '/');
            return strpos($p, 'uploads/') === 0 ? substr($p, strlen('uploads/')) : $p;
        };
        $upd = [
            'qr_type' => $qrType,
            'risk_banner_closed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $logs = [];
        if ($wxUrl) {
            $upd['wechat_qr'] = $normPath($wxUrl);
            $upd['wechat_qr_status'] = 1;
            $upd['wechat_qr_reason'] = null;
            $logs[] = [1, $normPath($wxUrl)];
        }
        if ($aliUrl) {
            $upd['alipay_qr'] = $normPath($aliUrl);
            $upd['alipay_qr_status'] = 1;
            $upd['alipay_qr_reason'] = null;
            $logs[] = [2, $normPath($aliUrl)];
        }
        Db::table('users')->where('id', $uid)->update($upd);
        // 审核日志（每渠道一条）
        foreach ($logs as $l) {
            Db::table('qr_audit_logs')->insert([
                'user_id' => $uid, 'channel' => $l[0], 'qr_type' => $qrType,
                'qr_path' => ltrim($l[1], '/'), 'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return json(['code' => 0, 'msg' => '已提交审核']);
    }

    public function readNotification(Request $request)
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) return json(['code' => 1, 'msg' => '请先登录']);
        $id = (int)$request->input('id', 0);
        if ($id) {
            Db::table('notifications')->where('id', $id)->where('user_id', $uid)->whereNull('deleted_at')->update(['is_read' => 1]);
        } else {
            Db::table('notifications')->where('user_id', $uid)->whereNull('deleted_at')->update(['is_read' => 1]);
        }
        $unread = Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->whereNull('deleted_at')->count();
        return json(['code' => 0, 'msg' => 'ok', 'unread' => $unread]);
    }

    // ---------- 工具 ----------
    private function userMap(array $ids): array
    {
        $map = [];
        if ($ids) {
            foreach (Db::table('users')->whereIn('id', array_unique($ids))->get() as $u) {
                $a = (array)$u;
                $a['wechat_qr_url'] = ($a['wechat_qr_status'] == 2 && !empty($a['wechat_qr'])) ? qr_asset($a['wechat_qr']) : '';
                $a['alipay_qr_url'] = ($a['alipay_qr_status'] == 2 && !empty($a['alipay_qr'])) ? qr_asset($a['alipay_qr']) : '';
                $map[$u->id] = $a;
            }
        }
        return $map;
    }

    private function catName($id)
    {
        if (!$id) return '未分类';
        $c = Db::table('post_categories')->where('id', $id)->first();
        return $c ? $c->name : '未分类';
    }
}
