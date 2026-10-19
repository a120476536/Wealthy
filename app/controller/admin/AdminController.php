<?php
namespace app\controller\admin;

use support\Request;
use support\Db;
use support\Response;

class AdminController
{
    private function active(string $key)
    {
        View_setActive($key);
    }

    private function aid(): int
    {
        return (int)request()->session()->get('admin_id', 0);
    }

    /**
     * 写入管理员操作审计日志（admin_logs）
     */
    private function logAdmin(Request $request, string $action, string $detail = '')
    {
        try {
            Db::table('admin_logs')->insert([
                'admin_id'   => $this->aid(),
                'admin_name' => request()->session()->get('admin_name', '') ?? '',
                'action'     => $action,
                'detail'     => $detail,
                'ip'         => $request->getRealIp() ?? '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计日志写入失败不影响主流程
        }
    }

    /**
     * 通用分页器（生成 Tailwind 风格分页 HTML 字符串）
     */
    private function buildPager(int $page, int $totalPages, string $baseUrl, array $params = []): string
    {
        if ($totalPages <= 1) {
            return '';
        }
        $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; }, ARRAY_FILTER_USE_BOTH);
        $mkUrl = function ($p) use ($baseUrl, $params) {
            $q = $params;
            $q['page'] = $p;
            return $baseUrl . '?' . http_build_query($q);
        };
        $html = '<nav class="flex flex-wrap items-center gap-1 mt-4 text-sm">';
        $html .= '<a href="' . ($page > 1 ? $mkUrl($page - 1) : 'javascript:void(0)') . '" class="px-3 py-1.5 rounded-lg border ' . ($page > 1 ? 'border-slate-200 text-slate-600 hover:bg-slate-50' : 'border-slate-100 text-slate-300 cursor-not-allowed') . '">上一页</a>';
        if ($totalPages <= 7) {
            $pages = range(1, $totalPages);
        } else {
            $pages = [1, 2];
            if ($page > 4) {
                $pages[] = '...';
            }
            for ($i = max(3, $page - 1); $i <= min($totalPages - 2, $page + 1); $i++) {
                $pages[] = $i;
            }
            if ($page < $totalPages - 3) {
                $pages[] = '...';
            }
            $pages[] = $totalPages - 1;
            $pages[] = $totalPages;
        }
        foreach ($pages as $p) {
            if ($p === '...') {
                $html .= '<span class="px-2 text-slate-400">…</span>';
                continue;
            }
            $on = $p == $page;
            $html .= '<a href="' . $mkUrl($p) . '" class="px-3 py-1.5 rounded-lg border ' . ($on ? 'bg-blue-600 text-white border-blue-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50') . '">' . $p . '</a>';
        }
        $html .= '<a href="' . ($page < $totalPages ? $mkUrl($page + 1) : 'javascript:void(0)') . '" class="px-3 py-1.5 rounded-lg border ' . ($page < $totalPages ? 'border-slate-200 text-slate-600 hover:bg-slate-50' : 'border-slate-100 text-slate-300 cursor-not-allowed') . '">下一页</a>';
        $html .= '</nav>';
        return $html;
    }

    public function login(Request $request)
    {
        if (strtoupper($request->method()) === 'POST') {
            return $this->attemptLogin($request);
        }
        return view('admin/login', []);
    }

    public function doLogin(Request $request)
    {
        return $this->attemptLogin($request);
    }

    private function attemptLogin(Request $request)
    {
        $ip = client_ip($request);
        $username = trim($request->input('username', ''));
        $password = $request->input('password', '');

        // 服务端登录失败锁定（基于 runtime 文件，清 cookie 也有效）
        $failKey = 'admin_login_fail:user:' . $username;
        $ipKey = 'admin_login_fail:ip:' . $ip;
        if (rl_get($failKey) >= 5 || rl_get($ipKey) >= 30) {
            if ($request->isAjax()) return json(['code' => 1, 'msg' => '尝试次数过多，请 15 分钟后再试']);
            return view('admin/login', ['error' => '尝试次数过多，请 15 分钟后再试']);
        }

        $admin = Db::table('admins')->where('username', $username)->where('status', 1)->first();
        if (!$admin || !password_verify($password, $admin->password)) {
            rl_count($failKey, 900);
            rl_count($ipKey, 900);
            if ($request->isAjax()) return json(['code' => 1, 'msg' => '账号或密码错误']);
            return view('admin/login', ['error' => '账号或密码错误']);
        }

        // 会话固定防护：登录成功后签发全新认证令牌（写入会话 + HttpOnly Cookie）
        $authToken = bin2hex(random_bytes(32));
        $request->session()->set('admin_id', $admin->id);
        $request->session()->set('admin_name', $admin->username);
        $request->session()->set('admin_role', $admin->role);
        $request->session()->set('admin_auth_token', $authToken);
        Db::table('admins')->where('id', $admin->id)->update(['last_login_at' => date('Y-m-d H:i:s')]);
        if ($request->isAjax()) {
            $resp = json(['code' => 0, 'msg' => '登录成功', 'url' => '/admin/']);
        } else {
            $resp = redirect('/admin/');
        }
        $resp->cookie('wa_admin_auth', $authToken, 7 * 24 * 3600, '/', '', false, true, 'Lax');
        return $resp;
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['admin_id', 'admin_name', 'admin_role', 'admin_auth_token']);
        $resp = redirect('/admin/login');
        $resp->cookie('wa_admin_auth', '', -1, '/', '', false, true, 'Lax');
        return $resp;
    }

    // ===================== 仪表盘 =====================
    public function dashboard(Request $request)
    {
        $this->active('dashboard');
        $today = date('Y-m-d');
        $stats = [
            'users' => Db::table('users')->where('deleted_at', null)->count(),
            'posts' => Db::table('posts')->where('deleted_at', null)->where('status', 2)->count(),
            'tips_amount' => Db::table('tips')->where('status', 1)->sum('amount') ?: 0,
            'qr_pending' => Db::table('users')->where(function ($q) { $q->where('wechat_qr_status', 1)->orWhere('alipay_qr_status', 1); })->count(),
            'disputes' => Db::table('tips')->where('status', 2)->count() + Db::table('tips')->where('appeal_status', '>', 0)->where('appeal_status', '<', 3)->count(),
            'reports' => Db::table('reports')->where('status', 0)->count(),
        ];
        // 近 7 天打赏笔数 & 新增用户
        $days = []; $tipsSeries = []; $userSeries = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[] = date('m-d', strtotime($d));
            $tipsSeries[] = Db::table('tips')->whereDate('created_at', $d)->count();
            $userSeries[] = Db::table('users')->whereDate('created_at', $d)->count();
        }
        return view('admin/dashboard', [
            'stats' => $stats, 'days' => $days, 'tipsSeries' => $tipsSeries, 'userSeries' => $userSeries,
        ]);
    }

    // ===================== 用户管理 =====================
    public function users(Request $request)
    {
        $this->active('users');
        $kw = trim($request->input('kw', ''));
        $status = $request->input('status', '');
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('users')->where('deleted_at', null);
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('username', 'like', '%' . $kw . '%')
                                    ->orWhere('phone', 'like', '%' . $kw . '%');
            });
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        $total = $query->count();
        $users = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $users = array_map('user_points_safe', $users);
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/users', ['kw' => $kw, 'status' => $status]);
        return view('admin/users', ['users' => $users, 'kw' => $kw, 'status' => $status, 'total' => $total, 'pager' => $pager]);
    }

    public function userDetail(Request $request, $id)
    {
        $this->active('users');
        $user = Db::table('users')->where('id', $id)->first();
        if (!$user) return response('<h1>用户不存在</h1>', 404);
        $tips = db_arr(Db::table('tips')->where('to_user_id', $id)->orderBy('created_at', 'desc')->limit(20)->get());
        $posts = db_arr(Db::table('posts')->where('user_id', $id)->where('deleted_at', null)->orderBy('created_at', 'desc')->limit(20)->get());
        return view('admin/user_detail', ['u' => user_points_safe((array)$user), 'tips' => $tips, 'posts' => $posts]);
    }

    public function userToggleStatus(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $u = Db::table('users')->where('id', $id)->first();
        if (!$u) return json(['code' => 1, 'msg' => '用户不存在']);
        $new = $u->status == 1 ? 0 : 1;
        Db::table('users')->where('id', $id)->update(['status' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'user_toggle_status', "id={$id} -> {$new}");
        return json(['code' => 0, 'msg' => '已' . ($new ? '启用' : '禁用'), 'status' => $new]);
    }

    public function userEdit(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $u = Db::table('users')->where('id', $id)->first();
        if (!$u) return json(['code' => 1, 'msg' => '用户不存在']);
        $data = [];
        $password = $request->input('password', '');
        if ($password !== '') {
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $bio = trim($request->input('bio', ''));
        if ($bio !== '') {
            $data['bio'] = $bio;
        }
        $avatar = trim($request->input('avatar', ''));
        if ($avatar !== '') {
            $data['avatar'] = ltrim($avatar, '/');
        }
        $status = $request->input('status', '');
        if ($status !== '') {
            $data['status'] = (int)$status;
        }
        $risk = $request->input('risk_remind_count', '');
        if ($risk !== '') {
            $data['risk_remind_count'] = (int)$risk;
        }
        if ($data) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            Db::table('users')->where('id', $id)->update($data);
            $this->logAdmin($request, 'user_edit', "id={$id} " . implode(',', array_keys($data)));
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    public function userDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $u = Db::table('users')->where('id', $id)->first();
        if (!$u) return json(['code' => 1, 'msg' => '用户不存在']);
        Db::table('users')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'user_delete', "id={$id} username={$u->username}");
        return json(['code' => 0, 'msg' => '已删除（软删除）']);
    }

    // 管理员调整用户积分（正负均可，扣减时余额不足则失败）
    public function adjustPoints(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $delta = (int)$request->input('delta', 0);
        $remark = trim($request->input('remark', ''));
        if ($delta == 0) return json(['code' => 1, 'msg' => '调整值不能为 0']);
        $u = Db::table('users')->where('id', $id)->first();
        if (!$u) return json(['code' => 1, 'msg' => '用户不存在']);
        $type = $delta > 0 ? 'admin_grant' : 'admin_deduct';
        $ok = add_points($id, $delta, $type, $remark ?: ($delta > 0 ? '管理员发放' : '管理员扣减'));
        if (!$ok) return json(['code' => 1, 'msg' => '操作失败，可能余额不足']);
        $points = (int)Db::table('users')->where('id', $id)->value('points');
        $this->logAdmin($request, 'user_points', "id={$id} delta={$delta} -> {$points}");
        return json(['code' => 0, 'msg' => '已调整，当前积分 ' . $points, 'points' => $points]);
    }

    // ===================== 收款码审核 =====================
    public function qrAudit(Request $request)
    {
        $this->active('qr_audit');
        $status = $request->input('status', '1');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('qr_audit_logs');
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            if ($kwUids) { $query->whereIn('user_id', $kwUids); } else { $query->whereRaw('1=0'); }
        }
        $total = $query->count();
        $rows = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $uids = array_column($rows, 'user_id');
        $users = [];
        if ($uids) { foreach (Db::table('users')->whereIn('id', array_unique($uids))->get() as $x) { $users[$x->id] = (array)$x; } }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/qr-audit', ['status' => $status, 'kw' => $kw]);
        return view('admin/qr_audit', ['rows' => $rows, 'users' => $users, 'status' => $status, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function qrAuditDo(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $channel = (int)$request->input('channel', 0);
        $act = $request->input('act', '');
        $reason = trim($request->input('reason', ''));
        $row = Db::table('qr_audit_logs')->where('id', $id)->first();
        if (!$row) return json(['code' => 1, 'msg' => '记录不存在']);
        $statusField = $channel === 1 ? 'wechat_qr_status' : 'alipay_qr_status';
        $reasonField = $channel === 1 ? 'wechat_qr_reason' : 'alipay_qr_reason';
        $newStatus = $act === 'pass' ? 2 : 3;
        Db::table('qr_audit_logs')->where('id', $id)->update(['status' => $newStatus, 'reviewed_at' => date('Y-m-d H:i:s')]);
        Db::table('users')->where('id', $row->user_id)->update([
            $statusField => $newStatus,
            $reasonField => $act === 'reject' ? $reason : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logAdmin($request, 'qr_audit', "log_id={$id} channel={$channel} act={$act}");
        return json(['code' => 0, 'msg' => '已' . ($act === 'pass' ? '通过' : '驳回')]);
    }

    // ===================== 打赏管理 =====================
    public function tips(Request $request)
    {
        $this->active('tips');
        $status = $request->input('status', '');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('tips');
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwUids, $kw) {
                if ($kwUids) { $q->whereIn('from_user_id', $kwUids)->orWhereIn('to_user_id', $kwUids); }
                $q->orWhere('remark', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $tips = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $fromIds = array_column($tips, 'from_user_id');
        $toIds = array_column($tips, 'to_user_id');
        $users = [];
        $all = array_filter(array_merge($fromIds, $toIds));
        if ($all) { foreach (Db::table('users')->whereIn('id', array_unique($all))->get() as $x) { $users[$x->id] = (array)$x; } }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/tips', ['status' => $status, 'kw' => $kw]);
        return view('admin/tips', ['tips' => $tips, 'users' => $users, 'status' => $status, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function tipsExport(Request $request)
    {
        $status = $request->input('status', '');
        $query = Db::table('tips');
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        $tips = db_arr($query->orderBy('created_at', 'desc')->limit(5000)->get());
        $fromIds = array_column($tips, 'from_user_id');
        $toIds = array_column($tips, 'to_user_id');
        $users = [];
        $all = array_filter(array_merge($fromIds, $toIds));
        if ($all) { foreach (Db::table('users')->whereIn('id', array_unique($all))->get() as $x) { $users[$x->id] = (array)$x; } }
        $csv = "\xEF\xBB\xBFID,付款方,收款方,金额,档位,渠道,状态,备注,时间\n";
        // CSV 公式注入防护：首字符为 = + - @ 时前置单引号，避免管理员用 Excel 打开即执行
        $csvSafe = function ($v) {
            $v = (string)$v;
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                $v = "'" . $v;
            }
            return $v;
        };
        foreach ($tips as $t) {
            $from = $t['from_user_id'] ? ($users[$t['from_user_id']]['username'] ?? '游客') : '游客';
            $to = $users[$t['to_user_id']]['username'] ?? '未知';
            $csv .= sprintf("%d,%s,%s,%s,%d,%s,%s,%s,%s\n",
                $t['id'], $csvSafe($from), $csvSafe($to), $t['amount'], $t['level'], pay_type_text($t['pay_type']),
                tip_status_text($t['status']), $csvSafe(str_replace(["\r","\n",","], [' ',' ',' '], $t['remark'] ?? '')), $t['created_at']);
        }
        $filename = 'tips_' . date('Ymd_His') . '.csv';
        $this->logAdmin($request, 'tips_export', "status={$status} rows=" . count($tips));
        return response($csv)->withHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ===================== 纠纷处理 =====================
    public function disputes(Request $request)
    {
        $this->active('disputes');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('tips')->where(function ($q) {
            $q->where('status', 2)->orWhere(function ($q2) { $q2->where('appeal_status', '>', 0)->where('appeal_status', '<', 3); });
        });
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwUids, $kw) {
                if ($kwUids) { $q->whereIn('from_user_id', $kwUids)->orWhereIn('to_user_id', $kwUids); }
                $q->orWhere('remark', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $tips = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $fromIds = array_column($tips, 'from_user_id');
        $toIds = array_column($tips, 'to_user_id');
        $users = [];
        $all = array_filter(array_merge($fromIds, $toIds));
        if ($all) { foreach (Db::table('users')->whereIn('id', array_unique($all))->get() as $x) { $users[$x->id] = (array)$x; } }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/disputes', ['kw' => $kw]);
        return view('admin/disputes', ['tips' => $tips, 'users' => $users, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function disputesDo(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $act = $request->input('act', ''); // confirm(裁决收到) / reject(维持异常)
        $t = Db::table('tips')->where('id', $id)->first();
        if (!$t) return json(['code' => 1, 'msg' => '记录不存在']);
        if ($act === 'confirm') {
            $prev = $t->status;
            Db::table('tips')->where('id', $id)->update([
                'status' => 1, 'appeal_status' => 2, 'confirm_at' => date('Y-m-d H:i:s'),
            ]);
            // 仅当由「异常(2)」恢复为有效时补回金额；待确认(0)/已收到(1)在打赏创建时已计入，避免重复
            if ($prev == 2) {
                Db::table('users')->where('id', $t->to_user_id)->where('tip_total_amount', '>=', 0)->increment('tip_total_amount', $t->amount);
            }
            $this->logAdmin($request, 'dispute_confirm', "tip_id={$id}");
        } else {
            $prev = $t->status;
            Db::table('tips')->where('id', $id)->update([
                'status' => 2, 'appeal_status' => 3,
            ]);
            // 由有效(0/1)判为异常，需从「有效收款」中扣除；原本就是异常则不动
            if ($prev != 2) {
                Db::table('users')->where('id', $t->to_user_id)->where('tip_total_amount', '>', 0)->decrement('tip_total_amount', $t->amount);
            }
            $this->logAdmin($request, 'dispute_reject', "tip_id={$id}");
        }
        return json(['code' => 0, 'msg' => '已处理']);
    }

    // ===================== 博文管理 =====================
    public function posts(Request $request)
    {
        $this->active('posts');
        $status = $request->input('status', '');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('posts')->where('deleted_at', null);
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwUids, $kw) {
                $q->where('title', 'like', '%' . $kw . '%')->orWhere('content', 'like', '%' . $kw . '%');
                if ($kwUids) { $q->orWhereIn('user_id', $kwUids); }
            });
        }
        $total = $query->count();
        $posts = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $uids = array_column($posts, 'user_id');
        $users = [];
        if ($uids) { foreach (Db::table('users')->whereIn('id', array_unique($uids))->get() as $x) { $users[$x->id] = (array)$x; } }
        $cats = [];
        foreach (Db::table('post_categories')->get() as $c) { $cats[$c->id] = $c->name; }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/posts', ['status' => $status, 'kw' => $kw]);
        return view('admin/posts', ['posts' => $posts, 'users' => $users, 'cats' => $cats, 'status' => $status, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function postsAudit(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $act = $request->input('act', ''); // pass / reject
        $reason = trim($request->input('reason', ''));
        $p = Db::table('posts')->where('id', $id)->first();
        if (!$p) return json(['code' => 1, 'msg' => '博文不存在']);
        if ($act === 'pass') {
            Db::table('posts')->where('id', $id)->update(['status' => 2, 'updated_at' => date('Y-m-d H:i:s')]);
            Db::table('post_audit_logs')->insert(['post_id' => $id, 'status' => 1, 'reason' => $reason, 'admin_id' => $this->aid(), 'created_at' => date('Y-m-d H:i:s')]);
            // 由待审核(1)变为已发布(2)时计入博文数（原本已发布则不重复）
            if ($p->status != 2) {
                Db::table('users')->where('id', $p->user_id)->increment('post_count');
                $pr = (int)site_setting('post_reward_points', 10);
                if ($pr > 0) {
                    add_points($p->user_id, $pr, 'post_reward', '发布博文奖励');
                }
            }
            $this->logAdmin($request, 'post_audit_pass', "post_id={$id}");
        } else {
            Db::table('posts')->where('id', $id)->update(['status' => 3, 'updated_at' => date('Y-m-d H:i:s')]);
            Db::table('post_audit_logs')->insert(['post_id' => $id, 'status' => 2, 'reason' => $reason, 'admin_id' => $this->aid(), 'created_at' => date('Y-m-d H:i:s')]);
            // 由已发布(2)驳回为不通过(3)时扣减博文数
            if ($p->status == 2) {
                Db::table('users')->where('id', $p->user_id)->where('post_count', '>', 0)->decrement('post_count');
            }
            $this->logAdmin($request, 'post_audit_reject', "post_id={$id} reason={$reason}");
        }
        return json(['code' => 0, 'msg' => '已' . ($act === 'pass' ? '通过' : '驳回')]);
    }

    public function postsDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $p = Db::table('posts')->where('id', $id)->first();
        if (!$p) return json(['code' => 1, 'msg' => '博文不存在']);
        Db::table('posts')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        if ($p->status == 2) {
            Db::table('users')->where('id', $p->user_id)->where('post_count', '>', 0)->decrement('post_count');
        }
        $this->logAdmin($request, 'post_delete', "post_id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ===================== 博文分类 =====================
    public function postCategory(Request $request)
    {
        $this->active('post_category');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('post_categories');
        if ($kw) {
            $query->where('name', 'like', '%' . $kw . '%');
        }
        $total = $query->count();
        $cats = db_arr($query->orderBy('sort', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/post-category', ['kw' => $kw]);
        return view('admin/post_category', ['cats' => $cats, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function postCategorySave(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $name = trim($request->input('name', ''));
        $sort = (int)$request->input('sort', 0);
        $status = (int)$request->input('status', 1);
        if (!$name) return json(['code' => 1, 'msg' => '名称必填']);
        if ($id) {
            Db::table('post_categories')->where('id', $id)->update(['name' => $name, 'sort' => $sort, 'status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->logAdmin($request, 'category_save', "id={$id}");
        } else {
            Db::table('post_categories')->insert(['name' => $name, 'sort' => $sort, 'status' => $status, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->logAdmin($request, 'category_add', "name={$name}");
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    public function postCategoryDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        Db::table('post_categories')->where('id', $id)->delete();
        $this->logAdmin($request, 'category_delete', "id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ===================== 评论管理 =====================
    public function comments(Request $request)
    {
        $this->active('comments');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('comments')->where('deleted_at', null);
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwUids, $kw) {
                $q->where('content', 'like', '%' . $kw . '%');
                if ($kwUids) { $q->orWhereIn('user_id', $kwUids); }
            });
        }
        $total = $query->count();
        $comments = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $uids = array_column($comments, 'user_id');
        $users = [];
        if ($uids) { foreach (Db::table('users')->whereIn('id', array_unique($uids))->get() as $x) { $users[$x->id] = (array)$x; } }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/comments', ['kw' => $kw]);
        return view('admin/comments', ['comments' => $comments, 'users' => $users, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function commentsAudit(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $act = $request->input('act', ''); // pass / reject
        $c = Db::table('comments')->where('id', $id)->first();
        if (!$c) return json(['code' => 1, 'msg' => '评论不存在']);
        $new = $act === 'pass' ? 1 : 2;
        Db::table('comments')->where('id', $id)->update(['status' => $new]);
        Db::table('comment_audit_logs')->insert(['comment_id' => $id, 'status' => $new, 'reason' => '', 'admin_id' => $this->aid(), 'created_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'comment_audit', "comment_id={$id} act={$act}");
        return json(['code' => 0, 'msg' => '已' . ($act === 'pass' ? '通过' : '驳回')]);
    }

    public function commentsDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $c = Db::table('comments')->where('id', $id)->first();
        if (!$c) return json(['code' => 1, 'msg' => '评论不存在']);
        Db::table('comments')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'comment_delete', "comment_id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ===================== 敏感词 =====================
    public function sensitiveWords(Request $request)
    {
        $this->active('sensitive_words');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = (int)$request->input('perPage', 20);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 20;
        }
        $query = Db::table('sensitive_words');
        if ($kw) {
            $query->where('word', 'like', '%' . $kw . '%');
        }
        $total = $query->count();
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $words = db_arr($query->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pager = $this->buildPager($page, $totalPages, '/admin/sensitive-words', ['perPage' => $perPage, 'kw' => $kw]);
        return view('admin/sensitive_words', [
            'words' => $words, 'page' => $page, 'perPage' => $perPage, 'kw' => $kw,
            'total' => $total, 'totalPages' => $totalPages, 'pager' => $pager,
        ]);
    }

    public function sensitiveWordsSave(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $word = trim($request->input('word', ''));
        $status = (int)$request->input('status', 1);
        if (!$word) return json(['code' => 1, 'msg' => '词必填']);
        if ($id) {
            Db::table('sensitive_words')->where('id', $id)->update(['word' => $word, 'status' => $status]);
            $this->logAdmin($request, 'sensitive_save', "id={$id}");
        } else {
            Db::table('sensitive_words')->insert(['word' => $word, 'status' => $status, 'created_at' => date('Y-m-d H:i:s')]);
            $this->logAdmin($request, 'sensitive_add', "word={$word}");
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    public function sensitiveWordsBatch(Request $request)
    {
        $text = trim($request->input('words', ''));
        if (!$text) return json(['code' => 1, 'msg' => '请粘贴词语']);
        $list = array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text))));
        if (!$list) return json(['code' => 1, 'msg' => '没有有效词语']);
        $rows = array_map(function ($w) { return ['word' => $w, 'status' => 1, 'created_at' => date('Y-m-d H:i:s')]; }, $list);
        try {
            Db::table('sensitive_words')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            // 老版本不支持 insertOrIgnore 时退回逐条插入
            foreach ($rows as $r) {
                try { Db::table('sensitive_words')->insert($r); } catch (\Throwable $e2) {}
            }
        }
        $this->logAdmin($request, 'sensitive_batch', 'count=' . count($rows));
        return json(['code' => 0, 'msg' => '已导入 ' . count($rows) . ' 条（重复自动忽略）']);
    }

    // ===================== 广告管理 =====================
    public function ads(Request $request)
    {
        $this->active('ads');
        // 广告位推荐尺寸（依据前台展示容器宽度与固定高度，object-cover 会裁切多余部分）
        $slotMeta = [
            'square_top'    => ['label' => '广场·顶部通栏',   'w' => 1120, 'h' => 90],
            'square_mid'    => ['label' => '广场·中部通栏',   'w' => 1120, 'h' => 80],
            'square_bottom' => ['label' => '广场·底部通栏',   'w' => 1120, 'h' => 80],
            'posts_top'     => ['label' => '社区·顶部通栏',   'w' => 1120, 'h' => 80],
            'posts_mid'     => ['label' => '社区·中部通栏',   'w' => 1120, 'h' => 80],
            'posts_bottom'  => ['label' => '社区·底部通栏',   'w' => 1120, 'h' => 80],
            'post_side'     => ['label' => '文章·侧边栏',     'w' => 320,  'h' => 128],
            'profile_side'  => ['label' => '个人主页·侧边栏', 'w' => 320,  'h' => 128],
            'user_top'      => ['label' => '用户主页·顶部',   'w' => 992,  'h' => 80],
            'user_side'     => ['label' => '用户主页·侧边栏', 'w' => 320,  'h' => 128],
        ];
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('ads');
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('title', 'like', '%' . $kw . '%')->orWhere('slot', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $ads = db_arr($query->orderBy('slot', 'asc')->orderBy('sort', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        foreach ($ads as &$ad) {
            $m = $slotMeta[$ad['slot']] ?? null;
            $ad['rec_w'] = $m ? $m['w'] : 0;
            $ad['rec_h'] = $m ? $m['h'] : 0;
        }
        unset($ad);
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/ads', ['kw' => $kw]);
        return view('admin/ads', ['ads' => $ads, 'slotMeta' => $slotMeta, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function adsSave(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $slot = trim($request->input('slot', ''));
        $title = trim($request->input('title', ''));
        $url = trim($request->input('url', ''));
        // 广告跳转链接：允许配置完整外链（含 http(s)://）或站内相对路径（/ 开头）。
        // 若配置的是缺协议的裸域名（如 www.baidu.com），自动补 https://，
        // 避免浏览器将其当作站内相对路径而错误拼接本站域名作为前缀。
        if ($url !== '' && !preg_match('#^(https?://|/)#i', $url) && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $image = trim($request->input('image', ''));
        $startAt = trim($request->input('start_at', ''));
        $endAt = trim($request->input('end_at', ''));
        $sort = (int)$request->input('sort', 0);
        $status = (int)$request->input('status', 1);
        if (!$slot) return json(['code' => 1, 'msg' => '广告位必填']);
        if (!$startAt) $startAt = date('Y-m-d H:i:s');
        if (!$endAt) $endAt = date('Y-m-d H:i:s', strtotime('+1 year'));
        $data = [
            'slot' => $slot, 'title' => $title, 'url' => $url, 'image' => $image,
            'start_at' => $startAt, 'end_at' => $endAt, 'sort' => $sort, 'status' => $status,
        ];
        if ($id) {
            Db::table('ads')->where('id', $id)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->logAdmin($request, 'ads_save', "id={$id}");
        } else {
            Db::table('ads')->insert($data + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->logAdmin($request, 'ads_add', "slot={$slot}");
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    public function adsDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        Db::table('ads')->where('id', $id)->delete();
        $this->logAdmin($request, 'ads_delete', "id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // 广告图片上传（后台专用，落地 public/uploads/ads，存相对路径 ads/xxx.ext）
    public function adsUpload(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return json(['code' => 1, 'msg' => '上传失败']);
        }
        $ext = strtolower($file->getUploadExtension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return json(['code' => 1, 'msg' => '仅支持 JPG/PNG/WEBP']);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => '图片不能超过 5MB']);
        }
        $dir = base_path() . '/public/uploads/ads';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = md5(uniqid((string)request()->session()->get('admin_id', 0), true)) . '.' . $ext;
        $file->move($dir . '/' . $name);
        if (!file_exists($dir . '/' . $name)) {
            return json(['code' => 1, 'msg' => '保存失败']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'path' => 'ads/' . $name, 'url' => '/uploads/ads/' . $name]);
    }

    // 站点 Logo 上传（后台专用，落地 public/uploads/logo，存相对路径 logo/xxx.ext）
    public function siteLogoUpload(Request $request)
    {
        if (!admin_is_super()) {
            return json(['code' => 1, 'msg' => '仅超级管理员可执行此操作']);
        }
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return json(['code' => 1, 'msg' => lang('上传失败')]);
        }
        $ext = strtolower($file->getUploadExtension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return json(['code' => 1, 'msg' => lang('仅支持 JPG/PNG/WEBP')]);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return json(['code' => 1, 'msg' => lang('图片不能超过 5MB')]);
        }
        $dir = base_path() . '/public/uploads/logo';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = md5(uniqid((string)request()->session()->get('admin_id', 0), true)) . '.' . $ext;
        $file->move($dir . '/' . $name);
        if (!file_exists($dir . '/' . $name)) {
            return json(['code' => 1, 'msg' => lang('保存失败')]);
        }
        // 返回完整可访问 URL（以 / 开头，保存时不会被补协议），同时回传相对路径
        return json(['code' => 0, 'msg' => 'ok', 'path' => 'logo/' . $name, 'url' => '/uploads/logo/' . $name]);
    }

    // ===================== 风控监控 =====================
    public function riskMonitor(Request $request)
    {
        $this->active('risk_monitor');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('users')
            ->where('deleted_at', null)
            ->where(function ($q) { $q->where('risk_remind_count', '>', 0)->orWhere('tip_total_amount', '>=', 1000); });
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('username', 'like', '%' . $kw . '%')
                                    ->orWhere('phone', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $users = db_arr($query->orderBy('risk_remind_count', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/risk-monitor', ['kw' => $kw]);
        return view('admin/risk_monitor', ['users' => $users, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function riskRemind(Request $request)
    {
        $id = (int)$request->input('id', 0);
        Db::table('users')->where('id', $id)->increment('risk_remind_count');
        Db::table('users')->where('id', $id)->update(['risk_remind_last_at' => date('Y-m-d H:i:s')]);
        $u = Db::table('users')->where('id', $id)->first();
        Db::table('risk_remind_logs')->insert([
            'user_id' => $id, 'remind_count' => $u->risk_remind_count ?? 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logAdmin($request, 'risk_remind', "user_id={$id}");
        return json(['code' => 0, 'msg' => '已提醒']);
    }

    public function markMerchant(Request $request)
    {
        $id = (int)$request->input('id', 0);
        Db::table('users')->where('id', $id)->update(['merchant_qr_verified' => 1, 'qr_type' => 2, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'mark_merchant', "user_id={$id}");
        return json(['code' => 0, 'msg' => '已标记商户']);
    }

    // ===================== 举报处理 =====================
    public function reports(Request $request)
    {
        $this->active('reports');
        $status = $request->input('status', '');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('reports');
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwUids, $kw) {
                if ($kwUids) { $q->whereIn('reporter_id', $kwUids); }
                $q->orWhere('reason', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $rows = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $uids = [];
        $targetIds = [];
        foreach ($rows as &$r) {
            if ($r['reporter_id']) $uids[] = $r['reporter_id'];
            $targetIds[$r['target_type']][] = $r['target_id'];
            $r['target_text'] = '';
            $r['target_url'] = '';
        }
        $users = [];
        if ($uids) { foreach (Db::table('users')->whereIn('id', array_unique($uids))->get() as $x) { $users[$x->id] = (array)$x; } }
        // 关联目标内容
        if (!empty($targetIds[1])) {
            foreach (Db::table('posts')->whereIn('id', array_unique($targetIds[1]))->get() as $x) {
                foreach ($rows as &$r) { if ($r['target_type'] == 1 && $r['target_id'] == $x->id) { $r['target_text'] = $x->title; $r['target_url'] = '/post/' . $x->id; } }
            }
        }
        if (!empty($targetIds[2])) {
            foreach (Db::table('comments')->whereIn('id', array_unique($targetIds[2]))->get() as $x) {
                foreach ($rows as &$r) { if ($r['target_type'] == 2 && $r['target_id'] == $x->id) { $r['target_text'] = mb_substr($x->content, 0, 60); $r['target_url'] = ''; } }
            }
        }
        if (!empty($targetIds[3])) {
            foreach (Db::table('users')->whereIn('id', array_unique($targetIds[3]))->get() as $x) {
                foreach ($rows as &$r) { if ($r['target_type'] == 3 && $r['target_id'] == $x->id) { $r['target_text'] = '收款码 - ' . $x->username; $r['target_url'] = '/u/' . $x->id; } }
            }
        }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/reports', ['status' => $status, 'kw' => $kw]);
        return view('admin/reports', ['rows' => $rows, 'users' => $users, 'status' => $status, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function reportsHandle(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $act = $request->input('act', ''); // process / ignore
        $note = trim($request->input('handle_note', ''));
        $r = Db::table('reports')->where('id', $id)->first();
        if (!$r) return json(['code' => 1, 'msg' => '举报不存在']);
        Db::table('reports')->where('id', $id)->update(['status' => 1, 'handle_note' => $note, 'admin_id' => $this->aid(), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'report_handle', "report_id={$id} act={$act}");
        return json(['code' => 0, 'msg' => '已处理']);
    }

    // ===================== 站内通知 / 公告群发 =====================
    public function notifications(Request $request)
    {
        $this->active('notifications');
        return view('admin/notifications', []);
    }

    public function notifySend(Request $request)
    {
        $scope = $request->input('scope', 'all');
        $title = trim($request->input('title', ''));
        $content = trim($request->input('content', ''));
        if (!$title || !$content) return json(['code' => 1, 'msg' => '标题和内容必填']);
        $now = date('Y-m-d H:i:s');
        if ($scope === 'single') {
            $username = trim($request->input('username', ''));
            if (!$username) return json(['code' => 1, 'msg' => '请填写接收用户名']);
            $u = Db::table('users')->where('username', $username)->where('deleted_at', null)->first();
            if (!$u) return json(['code' => 1, 'msg' => '用户不存在']);
            Db::table('notifications')->insert(['user_id' => $u->id, 'type' => 11, 'title' => $title, 'content' => $content, 'is_read' => 0, 'created_at' => $now]);
            $count = 1;
        } else {
            $count = 0;
            Db::table('users')->where('deleted_at', null)->where('status', 1)->chunkById(500, function ($us) use ($now, $title, $content, &$count) {
                $rows = [];
                foreach ($us as $x) { $rows[] = ['user_id' => $x->id, 'type' => 11, 'title' => $title, 'content' => $content, 'is_read' => 0, 'created_at' => $now]; $count++; }
                if ($rows) Db::table('notifications')->insert($rows);
            });
        }
        $this->logAdmin($request, 'notify_send', "scope={$scope} count={$count}");
        return json(['code' => 0, 'msg' => "已发送，覆盖 {$count} 名用户"]);
    }

    // ===================== 通知记录（系统 + 用户） =====================
    public function notificationRecords(Request $request)
    {
        $this->active('notification_records');
        $source = $request->input('source', ''); // '' 全部 / system 系统 / user 用户
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;

        // 通知类型语义（install.sql 注释为准）
        $typeLabels = [
            1  => '评论', 2 => '回复', 3 => '@提及', 4 => '点赞', 5 => '收藏',
            6  => '关注', 7 => '收到打赏', 8 => '打赏异常', 9 => '申诉结果',
            10 => '商户风控', 11 => '系统公告', 12 => '用户私信', 14 => '收到积分打赏',
        ];

        $query = Db::table('notifications');
        if ($source === 'system') {
            $query->whereNull('from_user_id');
        } elseif ($source === 'user') {
            $query->whereNotNull('from_user_id');
        }
        if ($kw) {
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where('username', 'like', '%' . $kw . '%')
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kw, $kwUids) {
                $q->where('title', 'like', '%' . $kw . '%')
                  ->orWhere('content', 'like', '%' . $kw . '%');
                if ($kwUids) {
                    $q->orWhereIn('user_id', $kwUids)
                      ->orWhereIn('from_user_id', $kwUids);
                }
            });
        }
        $total = $query->count();
        $rows = db_arr($query->orderBy('id', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());

        $uids = [];
        foreach ($rows as &$n) {
            $n['type_label'] = lang($typeLabels[$n['type']] ?? '其他');
            $n['source'] = $n['from_user_id'] ? 'user' : 'system';
            if ($n['user_id']) {
                $uids[] = $n['user_id'];
            }
            if ($n['from_user_id']) {
                $uids[] = $n['from_user_id'];
            }
        }
        unset($n);
        $users = [];
        if ($uids) {
            foreach (Db::table('users')->whereIn('id', array_unique($uids))->get() as $x) {
                $users[$x->id] = (array)$x;
            }
        }

        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/notification-records', ['source' => $source, 'kw' => $kw]);
        return view('admin/notification_records', [
            'rows' => $rows, 'users' => $users, 'source' => $source, 'kw' => $kw,
            'total' => $total, 'pager' => $pager,
        ]);
    }

    public function notificationDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $n = Db::table('notifications')->where('id', $id)->first();
        if (!$n) {
            return json(['code' => 1, 'msg' => '记录不存在']);
        }
        Db::table('notifications')->where('id', $id)->delete();
        $this->logAdmin($request, 'notify_delete', "id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ===================== 管理员操作日志 =====================
    public function adminLogs(Request $request)
    {
        $this->active('admin_logs');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 30;
        $query = Db::table('admin_logs');
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('admin_name', 'like', '%' . $kw . '%')
                  ->orWhere('action', 'like', '%' . $kw . '%')
                  ->orWhere('detail', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $logs = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/admin-logs', ['kw' => $kw]);
        return view('admin/admin_logs', ['logs' => $logs, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    // ===================== 系统设置 =====================
    public function systemSetting(Request $request)
    {
        $this->active('system_setting');
        $settings = db_arr(Db::table('system_setting')->orderBy('id', 'asc')->get());
        return view('admin/system_setting', ['settings' => $settings]);
    }

    public function systemSettingSave(Request $request)
    {
        if (!admin_is_super()) {
            return json(['code' => 1, 'msg' => '仅超级管理员可执行此操作']);
        }
        $rows = $request->input('items', []);
        foreach ($rows as $key => $val) {
            $val = trim((string)$val);
            // Logo 地址归一化：缺协议的裸域名自动补 https://，避免浏览器当作站内相对路径拼上本站域名
            if ($key === 'site_logo' && $val !== '' && !preg_match('#^(https?://|/|data:image/)#i', $val) && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $val)) {
                $val = 'https://' . ltrim($val, '/');
            }
            Db::table('system_setting')->where('setting_key', $key)->update(['setting_value' => $val]);
        }
        $this->logAdmin($request, 'system_setting_save', 'keys=' . implode(',', array_keys($rows)));
        return json(['code' => 0, 'msg' => '已保存']);
    }

    // ===================== 邀请码 =====================
    public function inviteCodes(Request $request)
    {
        $this->active('invite_codes');
        $status = $request->input('status', '');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('invite_codes')->where('deleted_at', null);
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('code', 'like', '%' . $kw . '%')->orWhere('batch', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $codes = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $ownerIds = array_filter(array_column($codes, 'owner_user_id'));
        $owners = [];
        if ($ownerIds) {
            foreach (Db::table('users')->whereIn('id', array_unique($ownerIds))->get() as $x) {
                $owners[$x->id] = $x->username;
            }
        }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/invite-codes', ['status' => $status, 'kw' => $kw]);
        return view('admin/invite_codes', ['codes' => $codes, 'owners' => $owners, 'status' => $status, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function inviteCodesGenerate(Request $request)
    {
        $num = max(1, min(100, (int)$request->input('num', 10)));
        $batch = uniqid('b');
        for ($i = 0; $i < $num; $i++) {
            Db::table('invite_codes')->insert([
                'code' => strtoupper(substr(md5(uniqid() . rand()), 0, 10)),
                'batch' => $batch, 'status' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->logAdmin($request, 'invite_generate', "num={$num} batch={$batch}");
        return json(['code' => 0, 'msg' => "已生成 {$num} 个邀请码"]);
    }

    public function inviteCodesToggle(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $c = Db::table('invite_codes')->where('id', $id)->first();
        if (!$c) return json(['code' => 1, 'msg' => '记录不存在']);
        $new = $c->status == 1 ? 0 : 1;
        Db::table('invite_codes')->where('id', $id)->update(['status' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'invite_toggle', "id={$id} -> {$new}");
        return json(['code' => 0, 'msg' => '已' . ($new ? '启用' : '禁用'), 'status' => $new]);
    }

    public function inviteCodesDelete(Request $request)
    {
        $id = (int)$request->input('id', 0);
        $c = Db::table('invite_codes')->where('id', $id)->first();
        if (!$c) return json(['code' => 1, 'msg' => '记录不存在']);
        Db::table('invite_codes')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->logAdmin($request, 'invite_delete', "id={$id}");
        return json(['code' => 0, 'msg' => '已删除']);
    }

    public function inviteUsed(Request $request)
    {
        $this->active('invite_codes');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 30;
        $query = Db::table('invite_used_logs');
        if ($kw) {
            $kwCodeIds = Db::table('invite_codes')->where('code', 'like', '%' . $kw . '%')->orWhere('batch', 'like', '%' . $kw . '%')->pluck('id')->toArray();
            $kwUids = Db::table('users')->where('deleted_at', null)
                ->where(function ($q) use ($kw) { $q->where('username', 'like', '%' . $kw . '%'); })
                ->pluck('id')->toArray();
            $query->where(function ($q) use ($kwCodeIds, $kwUids) {
                if ($kwCodeIds) { $q->whereIn('invite_code_id', $kwCodeIds); }
                if ($kwUids) { $q->orWhereIn('used_user_id', $kwUids); }
                if (!$kwCodeIds && !$kwUids) { $q->whereRaw('1=0'); }
            });
        }
        $total = $query->count();
        $logs = db_arr($query->orderBy('created_at', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $codeIds = array_column($logs, 'invite_code_id');
        $userIds = array_column($logs, 'used_user_id');
        $codes = [];
        if ($codeIds) { foreach (Db::table('invite_codes')->whereIn('id', array_unique($codeIds))->get() as $x) { $codes[$x->id] = $x->code; } }
        $users = [];
        if ($userIds) { foreach (Db::table('users')->whereIn('id', array_unique($userIds))->get() as $x) { $users[$x->id] = (array)$x; } }
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/invite-codes/used', ['kw' => $kw]);
        return view('admin/invite_used', ['logs' => $logs, 'codes' => $codes, 'users' => $users, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    // ===================== 管理员 =====================
    public function admins(Request $request)
    {
        $this->active('admins');
        $kw = trim($request->input('kw', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $query = Db::table('admins');
        if ($kw) {
            $query->where(function ($q) use ($kw) {
                $q->where('username', 'like', '%' . $kw . '%')->orWhere('role', 'like', '%' . $kw . '%');
            });
        }
        $total = $query->count();
        $admins = db_arr($query->orderBy('id', 'asc')->offset(($page - 1) * $perPage)->limit($perPage)->get());
        $pager = $this->buildPager($page, (int)ceil($total / $perPage), '/admin/admins', ['kw' => $kw]);
        return view('admin/admins', ['admins' => $admins, 'kw' => $kw, 'total' => $total, 'pager' => $pager]);
    }

    public function adminsSave(Request $request)
    {
        if (!admin_is_super()) {
            return json(['code' => 1, 'msg' => '仅超级管理员可管理管理员账号']);
        }
        $id = (int)$request->input('id', 0);
        $username = trim($request->input('username', ''));
        $password = $request->input('password', '');
        $role = trim($request->input('role', 'admin'));
        $status = (int)$request->input('status', 1);
        if (!$username) return json(['code' => 1, 'msg' => '用户名必填']);
        if ($id) {
            $data = ['username' => $username, 'role' => $role, 'status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
            if ($password) $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            Db::table('admins')->where('id', $id)->update($data);
            $this->logAdmin($request, 'admin_save', "id={$id}");
        } else {
            if (!$password) return json(['code' => 1, 'msg' => '新增管理员需设置密码']);
            Db::table('admins')->insert([
                'username' => $username, 'password' => password_hash($password, PASSWORD_BCRYPT),
                'role' => $role, 'status' => $status, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->logAdmin($request, 'admin_add', "username={$username}");
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }
}

if (!function_exists('View_setActive')) {
    function View_setActive(string $key)
    {
        \support\View::assign('active', $key);
    }
}
