<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;
use support\View;
use support\Db;

/**
 * 后台鉴权中间件
 * 未登录跳转到 /admin/login；注入当前管理员信息
 * 注：演示环境后台 session 与前台隔离（使用独立键 admin_id）
 */
class AdminAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        $adminId = $request->session()->get('admin_id');
        // 会话固定防护：后台令牌(HttpOnly Cookie) 必须与会话中存储的一致
        if ($adminId) {
            $sessToken = $request->session()->get('admin_auth_token');
            $cookieToken = $request->cookie('wa_admin_auth');
            if ($sessToken === null || $cookieToken === null || !hash_equals((string)$sessToken, (string)$cookieToken)) {
                $request->session()->forget(['admin_id', 'admin_name', 'admin_role', 'admin_auth_token']);
                $adminId = null;
            }
        }
        $whiteList = ['/admin/login', '/admin/do-login', '/admin/logout'];
        if (!$adminId && !in_array($path, $whiteList, true) && !str_starts_with($path, '/admin/do-login')) {
            if ($request->expectsJson()) {
                return json(['code' => 401, 'msg' => '请先登录']);
            }
            return redirect('/admin/login');
        }
        $admin = null;
        if ($adminId) {
            $a = Db::table('admins')->where('id', $adminId)->first();
            $admin = $a ? (array)$a : null;
        }
        View::assign('admin', $admin);
        // 供模板判断是否展示「系统设置/管理员管理」等高危入口（服务端门禁另在对应接口强制校验）
        View::assign('is_super', !empty($admin['role']) && $admin['role'] === 'super');
        return $handler($request);
    }
}
