<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;
use support\View;
use support\Db;

/**
 * 前台全局中间件
 * 注入当前登录用户、未读通知数、站点名称/标语、搜索词、风控横幅、广告位等模板变量
 */
class FrontMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $uid = $request->session()->get('user_id');
        $user = null;
        $unread = 0;
        $showRiskBanner = 0;
        if ($uid) {
            // 会话固定防护：会话内令牌(HttpOnly Cookie) 必须与会话中存储的一致，否则视为被固定的无效会话并强制登出
            $sessToken = $request->session()->get('front_auth_token');
            $cookieToken = $request->cookie('wa_auth');
            if ($sessToken === null || $cookieToken === null || !hash_equals((string)$sessToken, (string)$cookieToken)) {
                $request->session()->forget(['user_id', 'front_auth_token']);
                $uid = null;
            }
        }
        if ($uid) {
            try {
                $row = Db::table('users')->where('id', $uid)->where('deleted_at', null)->first();
                if ($row) {
                    $user = (array)$row;
                    $unread = Db::table('notifications')->where('user_id', $uid)->where('is_read', 0)->whereNull('deleted_at')->count();
                    // 风控横幅：个人码 + 未关闭/超过7天
                    if ($user['qr_type'] == 1) {
                        $closed = $user['risk_banner_closed_at'];
                        if (!$closed || strtotime($closed) < strtotime('-7 days')) {
                            $showRiskBanner = 1;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $user = null;
            }
        }

        // 广告位（转为数组供模板访问；始终包含全部 slot 键，无广告时为 null）
        $slots = ['square_top','square_mid','square_bottom','posts_top','posts_mid','posts_bottom','post_side','profile_side','user_top','user_side'];
        $ads = [];
        foreach ($slots as $slot) {
            $ad = ads_for($slot);
            $ads[$slot] = $ad ? (array)$ad : null;
        }

        View::assign('user', $user);
        View::assign('uid', $uid ?? 0);
        View::assign('my_points', $user ? (int)$user['points'] : 0);
        View::assign('unread_count', $unread);
        View::assign('show_risk_banner', $showRiskBanner);
        View::assign('ads', $ads);
        View::assign('kw', $request->input('kw', ''));
        View::assign('site_name', site_setting('site_name', '造富计划'));
        View::assign('site_slogan', site_setting('site_slogan', '人人都可以收获来自社区的支持'));
        View::assign('site_logo', (string)site_setting('site_logo', ''));
        View::assign('active_menu', '');
        return $handler($request);
    }
}
