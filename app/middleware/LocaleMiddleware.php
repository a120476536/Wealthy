<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;
use support\View;

/**
 * 多语言中间件（全局）
 * 解析当前语言：?lang=en > session('lang') > 默认 zh_CN。
 * 通过 session 持久化用户选择（Webman 常驻进程下不使用全局变量存当前语言，避免跨请求泄漏）。
 * 同时把 cur_lang 注入模板，供前端切换器高亮当前语言。
 * 仅翻译界面文案；用户发布的帖子/评论等数据保持原语言。
 */
class LocaleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $locale = $request->input('lang');
        if (!$locale) {
            $locale = $request->session()->get('lang');
        }
        $locale = (strtolower((string)$locale) === 'en') ? 'en' : 'zh_CN';

        // 显式切换时才写回 session，保证后续请求记住选择
        if ($request->input('lang')) {
            $request->session()->set('lang', $locale);
        }

        View::assign('cur_lang', $locale);

        return $handler($request);
    }
}
