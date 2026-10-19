<?php
/**
 * 造富计划 - 全局公共函数
 * 前台/后台模板与控制器均可调用
 */

use support\Db;
use support\Request;

if (!function_exists('csrf_token')) {
    /**
     * 获取 / 生成 CSRF 令牌（存储于 session）
     */
    function csrf_token(): string
    {
        $request = request();
        $session = $request->session();
        $token = $session->get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $session->set('csrf_token', $token);
        }
        return $token;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * 输出 CSRF 隐藏域（模板内使用 {$csrf_field} 时由控制器注入，这里提供函数备用）
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('site_setting')) {
    /**
     * 读取全局配置项。
     * 注意：Webman 常驻 worker，$GLOBALS/static 会跨请求残留，
     * 因此这里每次都直接查库（system_setting 仅数行，开销可忽略），
     * 保证后台改动即时生效，无需重启。
     */
    function site_setting(string $key, $default = null)
    {
        try {
            $row = Db::table('system_setting')->where('setting_key', $key)->first();
            return $row ? $row->setting_value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('tip_level_map')) {
    /** 打赏档位金额映射（后端维护，前端只传档位编号） */
    function tip_level_map(): array
    {
        return [1 => '0.01', 2 => '0.10', 3 => '1.00'];
    }
}

if (!function_exists('tip_level_text')) {
    function tip_level_text(int $level): string
    {
        return tip_level_map()[$level] ?? '0.00';
    }
}

if (!function_exists('pay_type_text')) {
    function pay_type_text(int $type): string
    {
        return $type === 1 ? '微信' : ($type === 2 ? '支付宝' : '未知');
    }
}

if (!function_exists('tip_status_text')) {
    /** 打赏状态：0待确认 1已收到 2异常 */
    function tip_status_text(int $status): string
    {
        return [0 => '待确认', 1 => '已收到', 2 => '异常'][$status] ?? '未知';
    }
}

if (!function_exists('post_status_text')) {
    /** 博文状态：0草稿 1待审核 2已发布 3驳回 */
    function post_status_text(int $status): string
    {
        return [0 => '草稿', 1 => '待审核', 2 => '已发布', 3 => '驳回'][$status] ?? '未知';
    }
}

if (!function_exists('qr_status_text')) {
    /** 收款码审核状态：0未上传 1待审核 2通过 3驳回 */
    function qr_status_text(int $status): string
    {
        return [0 => '未上传', 1 => '待审核', 2 => '通过', 3 => '驳回'][$status] ?? '未知';
    }
}

if (!function_exists('audit_status_badge')) {
    /** 通用审核状态徽章（返回 Tailwind class 组合） */
    function audit_status_badge(int $status): string
    {
        return [0 => 'bg-slate-100 text-slate-500', 1 => 'bg-amber-100 text-amber-700', 2 => 'bg-green-100 text-green-700', 3 => 'bg-red-100 text-red-600'][$status] ?? 'bg-slate-100 text-slate-500';
    }
}

if (!function_exists('avatar_url')) {
    function avatar_url($user): string
    {
        if (is_object($user)) {
            $avatar = $user->avatar ?? '';
        } else {
            $avatar = $user['avatar'] ?? '';
        }
        if ($avatar) {
            return '/uploads/' . ltrim($avatar, '/');
        }
        // 默认头像（ui-avatars 风格本地占位）
        return 'https://ui-avatars.com/api/?background=2563eb&color=fff&size=128&bold=true&name=' . urlencode(is_object($user) ? ($user->username ?? '?') : ($user['username'] ?? '?'));
    }
}

if (!function_exists('qr_asset')) {
    function qr_asset(?string $path): string
    {
        if (!$path) {
            return '';
        }
        return '/uploads/' . ltrim($path, '/');
    }
}

if (!function_exists('format_amount')) {
    function format_amount($n): string
    {
        return number_format((float)$n, 2, '.', '');
    }
}

if (!function_exists('time_ago')) {
    function time_ago($datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $ts = is_numeric($datetime) ? $datetime : strtotime($datetime);
        if ($ts === false) {
            return (string)$datetime;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' 小时前';
        }
        if ($diff < 86400 * 30) {
            return floor($diff / 86400) . ' 天前';
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('ads_for')) {
    /**
     * 获取指定广告位当前生效的一条广告
     * slot: square_top / square_mid / square_bottom / posts_top / posts_mid / posts_bottom / post_side / profile_side / user_side
     *
     * 注意：Webman 常驻 worker，绝不可用 $GLOBALS / static 做跨请求缓存
     * （会导致后台改广告不刷新、多 worker 之间数据不一致，与 site_setting() 刻意不缓存的原则一致）。
     * 这里每次实时查库——按 slot+status+时间区间走索引，开销极小。
     */
    function ads_for(string $slot)
    {
        try {
            $now = date('Y-m-d H:i:s');
            return Db::table('ads')
                ->where('slot', $slot)
                ->where('status', 1)
                ->where('start_at', '<=', $now)
                ->where('end_at', '>=', $now)
                ->orderBy('sort', 'desc')
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('is_admin_login')) {
    function is_admin_login(): bool
    {
        return (bool)request()->session()->get('admin_id');
    }
}

if (!function_exists('current_uid')) {
    function current_uid(): ?int
    {
        return request()->session()->get('user_id');
    }
}

if (!function_exists('client_ip')) {
    /**
     * 取客户端真实 IP（兼容反向代理 X-Forwarded-For / X-Real-IP）
     */
    function client_ip(\support\Request $request): string
    {
        $xff = $request->header('x-forwarded-for');
        if ($xff) {
            $ip = trim(explode(',', (string)$xff)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        $rip = $request->header('x-real-ip');
        if ($rip && filter_var($rip, FILTER_VALIDATE_IP)) {
            return $rip;
        }
        return (string)$request->getRealIp();
    }
}

if (!function_exists('rl_count')) {
    /**
     * 基于 runtime 文件的服务端计数/限频（无外部依赖，跨 worker 有效，清 cookie 不影响）。
     * 用于登录失败锁定与广告点击限频。返回本次计入后的计数；TTL 过期自动清零。
     */
    function rl_count(string $key, int $ttlSec): int
    {
        $dir = runtime_path() . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . md5($key) . '.json';
        $now = time();
        $data = [];
        if (is_file($file)) {
            $dec = @json_decode(@file_get_contents($file), true);
            if (is_array($dec) && ($dec['exp'] ?? 0) >= $now) {
                $data = $dec;
            }
        }
        $data['count'] = ($data['count'] ?? 0) + 1;
        $data['exp'] = $now + $ttlSec;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return (int)$data['count'];
    }

    function rl_get(string $key): int
    {
        $file = runtime_path() . '/ratelimit/' . md5($key) . '.json';
        if (!is_file($file)) {
            return 0;
        }
        $dec = @json_decode(@file_get_contents($file), true);
        if (!is_array($dec) || ($dec['exp'] ?? 0) < time()) {
            return 0;
        }
        return (int)($dec['count'] ?? 0);
    }

    function rl_reset(string $key): void
    {
        $file = runtime_path() . '/ratelimit/' . md5($key) . '.json';
        @unlink($file);
    }
}

if (!function_exists('admin_is_super')) {
    /**
     * 当前登录管理员是否为超级管理员（role=super）。后台高危操作门禁。
     */
    function admin_is_super(): bool
    {
        return (request()->session()->get('admin_role') ?? '') === 'super';
    }
}

if (!function_exists('db_arr')) {
    /**
     * 将 DB 查询结果（Collection / 数组，元素为 stdClass）统一转为「数组的数组」
     * 以便 ThinkTemplate 以 {$row.field} 方式访问
     */
    function db_arr($items): array
    {
        if ($items instanceof \Illuminate\Support\Collection) {
            $items = $items->all();
        }
        $out = [];
        foreach ((array)$items as $it) {
            $out[] = is_object($it) ? (array)$it : $it;
        }
        return $out;
    }
}

if (!function_exists('sanitize_rich_html')) {
    /**
     * 轻量富文本白名单过滤（演示用；生产环境建议替换为 ezyang/htmlpurifier）
     * 允许：段落/标题/加粗/斜体/下划线/删除线/列表/引用/代码/表格/图片(a 外链加 nofollow)
     */
    function sanitize_rich_html(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $allowedTags = [
            'p','br','strong','b','em','i','u','s','del','span','div','h1','h2','h3','h4',
            'ul','ol','li','blockquote','code','pre','hr','table','thead','tbody','tr','td','th',
            'img','a','figure','figcaption'
        ];
        // 先去除 script/style/iframe 等危险标签及其内容
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|link|meta)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|link|meta)[^>]*/>#is', '', $html);
        $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');
        // 可信站点域名（Webman 常驻进程下 $_SERVER 不可靠，优先用请求上下文 host）
        $host = '';
        if (function_exists('request')) {
            try {
                $host = (string)request()->host();
            } catch (\Throwable $e) {
                $host = '';
            }
        }
        // 仅允许本站图片，外部图片过滤
        $html = preg_replace_callback('#<img[^>]*>#i', function ($m) use ($host) {
            $tag = $m[0];
            if (!preg_match('#src=["\']([^"\']+)["\']#i', $tag, $src)) {
                return '';
            }
            $url = $src[1];
            $isLocal = preg_match('#^(/uploads/|/static/|data:image/)#i', $url)
                || ($host !== '' && strpos($url, $host) !== false);
            if (!$isLocal) {
                return '';
            }
            // 全局补 alt：优先保留原文 alt，否则给一个通用描述（避免无障碍/SEO 缺失）
            $alt = '';
            if (preg_match('#\balt=["\']([^"\']*)["\']#i', $tag, $am)) {
                $alt = trim($am[1]);
            }
            if ($alt === '') {
                $alt = '图片';
            }
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES)
                . '" alt="' . htmlspecialchars($alt, ENT_QUOTES)
                . '" class="max-w-full h-auto rounded-lg">';
        }, $html);
        // a 标签：仅放行安全协议（http/https/相对/锚点/mailto/tel），过滤 javascript: 等危险协议；
        // 外链强制加 rel="nofollow noopener"
        $html = preg_replace_callback('#<a[^>]*>#i', function ($m) use ($host) {
            $tag = $m[0];
            if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $h)) {
                $href = $h[1];
                if (!preg_match('~^(https?://|/|#|mailto:|tel:)~i', $href)) {
                    // 危险协议（javascript: 等）：直接剥离 href 属性，避免点击执行
                    $tag = preg_replace('#\shref=["\'][^"\']*["\']#i', '', $tag);
                    return $tag;
                }
                if (preg_match('#^https?://#i', $href) && ($host === '' || strpos($href, $host) === false)) {
                    $tag = preg_replace('#<a#i', '<a rel="nofollow noopener" target="_blank"', $tag);
                }
            }
            return $tag;
        }, $html);
        return $html;
    }
}

if (!function_exists('store_image_from_tmp')) {
    /**
     * 把一个已落地磁盘的临时图片文件做安全重编码 + 压缩后存入 public/uploads/{$subdir}，
     * 返回站内 URL 或 ''（失败 / 非图片）。png 无损，其它压成 JPEG（≤100KB）。
     * 远程下载图片与随文上传的本地图片都复用它。
     */
    function store_image_from_tmp(string $tmpPath, int $uid = 0, string $subdir = 'md'): string
    {
        $info = @getimagesize($tmpPath);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return '';
        }
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $dir = base_path() . '/public/uploads/' . trim($subdir, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $name = md5(uniqid((string)$uid . mt_rand(), true)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        $ok = false;
        if (extension_loaded('gd')) {
            $src = null;
            switch ($info[2]) {
                case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmpPath); break;
                case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmpPath); break;
                case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null; break;
            }
            if ($src) {
                $maxDim = 1600;
                $w = imagesx($src);
                $h = imagesy($src);
                if ($w > $maxDim || $h > $maxDim) {
                    $ratio = min($maxDim / $w, $maxDim / $h);
                    $nw = max(1, (int)round($w * $ratio));
                    $nh = max(1, (int)round($h * $ratio));
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($src);
                    $src = $dst;
                }
                if ($ext === 'png') {
                    $ok = @imagepng($src, $dest, 9);
                } else {
                    $quality = 85;
                    do {
                        $ok = @imagejpeg($src, $dest, $quality);
                        $quality -= 10;
                    } while ($ok && file_exists($dest) && filesize($dest) > 100 * 1024 && $quality >= 45);
                }
                imagedestroy($src);
            }
        }
        if (!$ok || !file_exists($dest)) {
            return '';
        }
        return '/uploads/' . trim($subdir, '/') . '/' . $name;
    }
}

if (!function_exists('save_remote_image_local')) {
    /**
     * 把远程图片下载并本地化到 public/uploads/md，返回站内 URL 或 ''（失败 / 非图片 / 内网地址）。
     * 用于 Markdown / 富文本导入场景，把外链图片搬到本站，避免被 sanitize_rich_html 过滤掉。
     *
     * SSRF 防护：
     * - 关闭自动跟随重定向（follow_location=false），改为手动逐跳校验；
     * - 每一跳都解析真实 IP 并拒绝内网 / 私有 / 保留地址，限制端口为 80/443/8080/8443；
     * - 避免「初地址合法 → 302 跳转到内网」的绕过。
     */
    function save_remote_image_local(string $url, int $uid = 0): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        $maxRedirects = 3;
        $bin = '';
        for ($i = 0; $i <= $maxRedirects; $i++) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $port = (int)($parts['port'] ?? (stripos($url, 'https://') === 0 ? 443 : 80));
            if (!in_array($port, [80, 443, 8080, 8443], true)) {
                return '';
            }
            // 解析真实 IP，拒绝内网 / 私有 / 保留地址（每一跳都校验）
            $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return '';
            }
            $opts = [
                'http'  => ['timeout' => 15, 'follow_location' => false, 'header' => "User-Agent: Mozilla/5.0 (compatible; WealthyBot/1.0)\r\n"],
                'https' => ['timeout' => 15, 'follow_location' => false, 'header' => "User-Agent: Mozilla/5.0 (compatible; WealthyBot/1.0)\r\n"],
            ];
            $bin = @file_get_contents($url, false, stream_context_create($opts));
            if ($bin === false) {
                return '';
            }
            $code = 200;
            $location = '';
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $code = (int)$m[1];
                } elseif (preg_match('#^Location:\s*(.+)$#i', $h, $m)) {
                    $location = trim($m[1]);
                }
            }
            if ($code >= 300 && $code < 400 && $location !== '' && $i < $maxRedirects) {
                // 相对重定向补全为绝对地址
                if (!preg_match('#^https?://#i', $location)) {
                    $base = $parts['scheme'] . '://' . $host . (in_array($port, [80, 443], true) ? '' : ':' . $port);
                    $location = rtrim($base, '/') . '/' . ltrim($location, '/');
                }
                $url = $location;
                continue;
            }
            break;
        }
        if (strlen($bin) < 100 || strlen($bin) > 5 * 1024 * 1024) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mdimg');
        if ($tmp === false) {
            return '';
        }
        file_put_contents($tmp, $bin);
        $url = store_image_from_tmp($tmp, $uid, 'md');
        @unlink($tmp);
        return $url;
    }
}

if (!function_exists('save_uploaded_image_local')) {
    /**
     * 把用户随 Markdown 一起上传的本地图片文件存入 public/uploads/md，返回站内 URL 或 ''。
     * 用于 Markdown 中以相对 / 本地路径引用的图片（这类无法被服务器下载，必须随文上传）。
     */
    function save_uploaded_image_local($file, int $uid = 0): string
    {
        if (!$file || !method_exists($file, 'isValid') || !$file->isValid()) {
            return '';
        }
        $ext = strtolower($file->getUploadExtension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return '';
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return '';
        }
        return store_image_from_tmp($file->getPathname(), $uid, 'md');
    }
}

if (!function_exists('filter_sensitive')) {
    /**
     * 敏感词过滤（演示：命中即替换为 ***）
     * 生产可结合 system_setting 策略：拒绝 / 替换
     */
    function filter_sensitive(string $text): string
    {
        try {
            $words = Db::table('sensitive_words')->where('status', 1)->pluck('word');
            if ($words) {
                foreach ($words as $w) {
                    $text = str_replace($w, '***', $text);
                }
            }
        } catch (\Throwable $e) {
            // 表不存在时忽略
        }
        return $text;
    }
}

if (!function_exists('user_level_map')) {
    /**
     * 用户等级经验阈值表（等级 => 升到该等级所需累计经验，Lv1 从 0 起步）
     */
    function user_level_map(): array
    {
        return [
            1 => 0,
            2 => 50,
            3 => 150,
            4 => 300,
            5 => 500,
            6 => 800,
            7 => 1200,
            8 => 2000,
            9 => 3500,
            10 => 6000,
        ];
    }
}

if (!function_exists('calc_level')) {
    /**
     * 根据累计经验计算等级（最低 Lv1，最高为阈值表上限 Lv10）
     */
    function calc_level(int $exp): int
    {
        $map = user_level_map();
        $lv = 1;
        foreach ($map as $level => $need) {
            if ($exp >= $need) {
                $lv = $level;
            } else {
                break;
            }
        }
        return $lv;
    }
}

if (!function_exists('level_text')) {
    /** 等级展示文本，例如 2 => "Lv2" */
    function level_text(int $lv): string
    {
        return 'Lv' . max(1, (int)$lv);
    }
}

if (!function_exists('level_progress')) {
    /**
     * 等级进度信息：当前等级、经验、距离下一级还需经验、进度百分比、是否满级
     * 返回 ['level','level_text','exp','cur','next','pct','is_max']
     */
    function level_progress(int $exp): array
    {
        $map = user_level_map();
        $lv = calc_level($exp);
        $curNeed = $map[$lv] ?? 0;
        $nextNeed = $map[$lv + 1] ?? null;
        if ($nextNeed === null) {
            return [
                'level' => $lv, 'level_text' => level_text($lv), 'exp' => $exp,
                'cur' => $exp - $curNeed, 'next' => 0, 'pct' => 100, 'is_max' => true,
            ];
        }
        $span = max(1, $nextNeed - $curNeed);
        $pct = (int)min(100, max(0, (($exp - $curNeed) / $span) * 100));
        return [
            'level' => $lv, 'level_text' => level_text($lv), 'exp' => $exp,
            'cur' => $exp - $curNeed, 'next' => $nextNeed - $exp, 'pct' => $pct, 'is_max' => false,
        ];
    }
}

if (!function_exists('add_experience')) {
    /**
     * 给用户增加经验值并自动重算等级（原子自增经验 + 重算写入等级）
     * 经验获取点：发帖成功 +10；打赏（双方）各 +5
     */
    function add_experience(int $uid, int $amount): void
    {
        if ($amount <= 0 || $uid <= 0) {
            return;
        }
        try {
            Db::table('users')->where('id', $uid)->increment('experience', $amount);
            $exp = (int)Db::table('users')->where('id', $uid)->value('experience');
            Db::table('users')->where('id', $uid)->update(['level' => calc_level($exp)]);
        } catch (\Throwable $e) {
            // 忽略异常，避免影响主业务流程
        }
    }
}

if (!function_exists('user_points_safe')) {
    /**
     * 给单条用户数组补默认 points 键。
     * 生产库若因 install.sql 过期缺少 points 列（见 database/migrate_production_columns.sql），
     * 模板里 {$u.points} 会报 “Undefined array key” 导致渲染 500；此函数做兜底，避免崩溃。
     * 注意：这仅是渲染兜底，真实积分值仍需补齐该列后才会正确显示（否则积分相关操作会失败）。
     */
    function user_points_safe(array $u): array
    {
        if (!array_key_exists('points', $u)) {
            $u['points'] = 0;
        }
        return $u;
    }
}

if (!function_exists('add_points')) {
    /**
     * 增减用户积分，并写入 points_logs 流水。
     * 使用事务 + 行锁，保证并发下余额不为负、流水准确。
     * @param int    $uid    用户 ID
     * @param int    $amount 变动值（正数=增加，负数=扣除）
     * @param string $type   业务类型，见 points_logs.type 注释
     * @param string $remark 备注
     * @return bool 成功返回 true；扣除时余额不足返回 false
     */
    function add_points(int $uid, int $amount, string $type, string $remark = ''): bool
    {
        if ($uid <= 0 || $amount == 0) {
            return false;
        }
        try {
            return (bool)Db::transaction(function () use ($uid, $amount, $type, $remark) {
                $user = Db::table('users')->where('id', $uid)->lockForUpdate()->first();
                if (!$user) {
                    return false;
                }
                if ($amount < 0 && $user->points < abs($amount)) {
                    return false; // 余额不足
                }
                $balance = $user->points + $amount;
                if ($balance < 0) {
                    $balance = 0;
                }
                Db::table('users')->where('id', $uid)->update([
                    'points' => $balance,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Db::table('points_logs')->insert([
                    'user_id' => $uid,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => $balance,
                    'remark' => $remark,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return true;
            });
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('points_type_text')) {
    /** 积分类型中文文案 */
    function points_type_text(string $type): string
    {
        $map = [
            'invite_code_cost'   => '生成邀请码',
            'invite_reward'      => '邀请好友奖励',
            'invitee_bonus'      => '邀请注册奖励',
            'checkin'            => '每日签到',
            'post_reward'        => '发布博文',
            'tip_reward'         => '收到打赏',
            'admin_grant'        => '管理员发放',
            'admin_deduct'       => '管理员扣减',
        ];
        return $map[$type] ?? $type;
    }
}

if (!function_exists('build_page_window')) {
    /**
     * 生成「窗口式」分页页码数组：始终含首页/末页，中间仅保留当前页 ±1，
     * 其余以 '...' 占位。用于前台列表分页，避免页码过多时横向溢出布局。
     * 模板遍历时对值为 '...' 的元素渲染为不可点击的省略号。
     * @param int $page 当前页（1 起）
     * @param int $totalPages 总页数
     * @return array<int|string>
     */
    function build_page_window(int $page, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return [1];
        }
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }
        $pages = [1];
        $start = max(2, $page - 1);
        $end = min($totalPages - 1, $page + 1);
        if ($start > 2) {
            $pages[] = '...';
        }
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }
        if ($end < $totalPages - 1) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;
        return $pages;
    }
}

if (!function_exists('lang')) {
    /**
     * 多语言翻译辅助（仅界面文案）。
     * 以「中文原文」为 key，app/lang/<locale>.php 提供对应译文；
     * zh_CN 直接返回原文；en 查表，缺失则回退中文原文（未翻译项仍显示中文，不报错）。
     * 支持 %s 占位符：lang('共 %s 条', [$n])。
     * 当前语言从 request()->session()->get('lang') 读取（请求级，常驻进程安全）。
     *
     * @param string $key  中文原文（同时作为 zh_CN 的展示文本与 en 的查找键）
     * @param array  $vars 占位符替换值
     */
    function lang(string $key, array $vars = []): string
    {
        $locale = 'zh_CN';
        if (function_exists('request')) {
            try {
                $req = request();
                $l = $req->session()->get('lang');
                if ($l) {
                    $locale = $l;
                }
            } catch (\Throwable $e) {
                // 非请求上下文（如 CLI）按默认中文处理
            }
        }
        if ($locale === 'zh_CN') {
            $text = $key;
        } else {
            static $cache = [];
            if (!isset($cache[$locale])) {
                $file = base_path() . '/app/lang/' . $locale . '.php';
                $cache[$locale] = is_file($file) ? (array)require $file : [];
            }
            $text = $cache[$locale][$key] ?? $key;
        }
        if ($vars) {
            $text = vsprintf($text, $vars);
        }
        return $text;
    }
}

if (!function_exists('i18n_js_dict')) {
    /**
     * 返回当前语言的 JS 端翻译字典（JSON 字符串），供前端 t() 使用。
     * zh_CN 返回 "{}"（t() 直接回退原文）；en 返回 en.php 的「中文=>英文」映射。
     * 注意：与 lang() 同理，en.php 改动后需重启 worker（static 缓存）。
     */
    function i18n_js_dict(): string
    {
        $locale = 'zh_CN';
        if (function_exists('request')) {
            try {
                $l = request()->session()->get('lang');
                if ($l) {
                    $locale = $l;
                }
            } catch (\Throwable $e) {
                // 非请求上下文（如 CLI）按默认中文处理
            }
        }
        if ($locale === 'zh_CN') {
            return '{}';
        }
        static $cache = [];
        if (!isset($cache[$locale])) {
            $file = base_path() . '/app/lang/' . $locale . '.php';
            $cache[$locale] = is_file($file) ? (array)require $file : [];
        }
        return json_encode($cache[$locale], JSON_UNESCAPED_UNICODE);
    }
}
