<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;
use support\View;

/**
 * 全局会话 + CSRF 令牌中间件
 * - 确保每个请求都有 session
 * - 生成并向前台模板注入 csrf_token
 * - 校验写请求（POST/PUT/DELETE）的 csrf_token
 */
class SessionCsrf implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $session = $request->session();
        $token = $session->get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $session->set('csrf_token', $token);
        }
        View::assign('csrf_token', $token);
        // 全局注入站点名 / 标语 / Logo（前台与后台模板均依赖）
        View::assign('site_name', site_setting('site_name', '造富计划'));
        View::assign('site_slogan', site_setting('site_slogan', '人人都可以收获来自社区的支持'));
        View::assign('site_logo', (string)site_setting('site_logo', ''));

        // 匿名无副作用的公开计数器接口（如广告点击埋点）跳过 CSRF 校验
        $noCsrfPaths = ['/ads/click'];
        if (in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)
            && !in_array($request->path(), $noCsrfPaths, true)) {
            $submitted = $request->post('csrf_token');
            if (!$submitted && $request->header('x-csrf-token')) {
                $submitted = $request->header('x-csrf-token');
            }
            if (!$submitted || !hash_equals($token, (string)$submitted)) {
                if ($request->expectsJson() || $request->header('x-requested-with') === 'XMLHttpRequest') {
                    return json(['code' => 419, 'msg' => '页面已过期，请刷新后重试']);
                }
                return response('<h1 style="font-family:sans-serif;padding:40px">419 - 页面已过期，请刷新重试</h1>', 419);
            }
        }
        return $handler($request);
    }
}
