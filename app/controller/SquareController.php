<?php
namespace app\controller;

use support\Request;
use support\View;
use support\Db;

class SquareController
{
    public function index(Request $request)
    {
        View::assign('active_menu', 'square');
        $kw = trim($request->input('kw', ''));
        $sort = $request->input('sort', 'reg');
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 12;

        $query = Db::table('users')
            ->where('status', 1)
            ->where('deleted_at', null);

        if ($kw) {
            $query->where('username', 'like', '%' . $kw . '%');
        }

        $total = $query->count();
        $totalPages = (int)ceil($total / $perPage);
        $pages = build_page_window($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        if ($sort === 'amount') {
            $query->orderBy('tip_total_amount', 'desc');
        } elseif ($sort === 'post') {
            $query->orderBy('post_count', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
        $users = db_arr($query->limit($perPage)->offset($offset)->get());

        // 预计算收款码预览与是否可打赏
        foreach ($users as &$u) {
            $u['qr_preview'] = $u['wechat_qr'] ?: $u['alipay_qr'];
            $u['has_wechat'] = !empty($u['wechat_qr']);
            $u['has_alipay'] = !empty($u['alipay_qr']);
            $u['can_tip'] = ($u['has_wechat'] || $u['has_alipay']) ? 1 : 0;
        }
        unset($u);

        // 首页双榜单（富豪榜/善人榜）开关：后台 system_setting 配置，默认关闭
        $richEnabled = site_setting('富豪榜开关', 'off') === 'on' ? 1 : 0;
        $kindEnabled = site_setting('善人榜开关', 'off') === 'on' ? 1 : 0;

        return view('square/index', [
            'users' => $users,
            'kw' => $kw,
            'sort' => $sort,
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
            'pages' => $pages,
            'prevPage' => $page > 1 ? $page - 1 : 1,
            'nextPage' => $page < $totalPages ? $page + 1 : $totalPages,
            'perPage' => $perPage,
            'rich_enabled' => $richEnabled,
            'kind_enabled' => $kindEnabled,
            'show_lists' => ($richEnabled || $kindEnabled) ? 1 : 0,
            'rich_list' => $richEnabled ? $this->leaderboard('to_user_id') : [],   // 富豪榜：收款总额 TOP10（含待确认）
            'kind_list' => $kindEnabled ? $this->leaderboard('from_user_id') : [],  // 善人榜：打赏出去 TOP10
        ]);
    }

    /**
     * 通用榜单：按 tips 某方向(user_id 字段)汇总金额，取前 10。
     * 口径：排除异常单(status=2)；待确认(0)与已收到(1)都计入。
     * @param string $field 'to_user_id'=收款侧(富豪榜) | 'from_user_id'=打赏侧(善人榜)
     */
    private function leaderboard(string $field): array
    {
        $agg = db_arr(Db::table('tips')
            ->selectRaw($field . ' as uid, SUM(amount) as total')
            ->where('status', '!=', 2)
            ->whereNotNull($field)
            ->groupBy('uid')
            ->orderByDesc('total')
            ->limit(10)
            ->get());
        if (empty($agg)) {
            return [];
        }
        $ids = array_column($agg, 'uid');
        $users = db_arr(Db::table('users')
            ->whereIn('id', $ids)
            ->where('deleted_at', null)
            ->get());
        $map = [];
        foreach ($users as $u) {
            $map[$u['id']] = $u;
        }
        $out = [];
        foreach ($agg as $i => $a) {
            if (!isset($map[$a['uid']])) {
                continue;
            }
            $u = $map[$a['uid']];
            $u['total'] = $a['total'];
            $u['rank'] = $i + 1;
            $out[] = $u;
        }
        return $out;
    }

    /**
     * 广告点击统计（前台 trackAdClick 经 sendBeacon 异步调用，不阻塞跳转）
     */
    public function adClick(Request $request)
    {
        $ip = client_ip($request);
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return response('', 204);
        }
        // 防刷量：单 IP 每分钟最多计 120 次；同一 IP+广告 10 秒内最多计 5 次
        if (rl_get('adclick:ip:' . $ip) >= 120) {
            return response('', 204);
        }
        if (rl_get('adclick:ip:' . $ip . ':ad:' . $id) >= 5) {
            return response('', 204);
        }
        rl_count('adclick:ip:' . $ip . ':ad:' . $id, 10);
        rl_count('adclick:ip:' . $ip, 60);
        try {
            Db::table('ads')->where('id', $id)->increment('click_count');
        } catch (\Throwable $e) {}
        return response('', 204);
    }
}
