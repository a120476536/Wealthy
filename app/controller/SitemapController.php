<?php
namespace app\controller;

use support\Request;
use support\Db;
use support\Response;

/**
 * SEO：动态站点地图
 * 路由：GET /sitemap.xml（无需登录，返回 application/xml）
 * 内容：静态/栏目页 + 已发布博文（status=2 且未软删）。
 * Webman 常驻内存，取域名用 request()->host()（CDN/反代下即访客实际域名），
 * 避免依赖不可靠的 $_SERVER。
 */
class SitemapController
{
    public function index(Request $request): Response
    {
        $host = $request->host();
        $scheme = 'https';
        if (($fwd = $request->header('x-forwarded-proto')) && strtolower($fwd) === 'http') {
            $scheme = 'http';
        }
        $base = $scheme . '://' . $host;

        $urls = [];
        // 首页 / 广场
        $urls[] = ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0'];
        // 博文列表
        $urls[] = ['loc' => $base . '/posts', 'changefreq' => 'daily', 'priority' => '0.8'];

        // 分类页
        $cats = Db::table('post_categories')->where('status', 1)->get();
        foreach ($cats as $c) {
            $urls[] = [
                'loc' => $base . '/posts?cat=' . (int)$c->id,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        // 已发布博文（status=2，未软删），最多 5000 篇
        $posts = Db::table('posts')
            ->where('status', 2)
            ->where('deleted_at', null)
            ->orderBy('id', 'desc')
            ->limit(5000)
            ->get();
        foreach ($posts as $p) {
            $lastmod = !empty($p->updated_at) ? date('c', strtotime($p->updated_at)) : '';
            $urls[] = [
                'loc' => $base . '/post/' . (int)$p->id,
                'lastmod' => $lastmod,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>' . "\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . '</lastmod>' . "\n";
            }
            $xml .= '    <changefreq>' . $u['changefreq'] . '</changefreq>' . "\n";
            $xml .= '    <priority>' . $u['priority'] . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>';

        return response($xml)->withHeaders([
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
