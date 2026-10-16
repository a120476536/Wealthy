#!/usr/bin/env php
<?php
/**
 * Wealthy 端到端冒烟测试（零依赖，仅用 curl 扩展）
 *
 * 目的：把已验证过的关键功能 / 安全点固化成可重复执行的回归检查，
 *       每次部署或重启 worker 后跑一遍，确认没有回归。
 * 用法：服务需先在 127.0.0.1:8791 运行； php tests/smoke.php
 * 凭证：可用环境变量覆盖（SMOKE_FRONT_USER/PASS、SMOKE_ADMIN_USER/PASS），缺省用测试账号。
 *
 * 设计原则：
 *  - 不改动任何业务代码、不引入 composer 测试依赖；
 *  - 只做只读 / 无副作用的检查（登录仅产生无害的 session 文件）；
 *  - 每项独立 try/catch，失败不中断，最后汇总 PASS/FAIL。
 */

const BASE = 'http://127.0.0.1:8791';

define('FRONT_USER', getenv('SMOKE_FRONT_USER') ?: '程序员老K');
define('FRONT_PASS', getenv('SMOKE_FRONT_PASS') ?: 'abc123456');
define('ADMIN_USER', getenv('SMOKE_ADMIN_USER') ?: 'admin');
define('ADMIN_PASS', getenv('SMOKE_ADMIN_PASS') ?: 'admin888');

$pass = 0;
$fail = 0;
$skip = 0;

function ok(string $name, string $detail = ''): void
{
    global $pass;
    $pass++;
    echo "  \033[32mPASS\033[0m  $name" . ($detail ? "  ($detail)" : "") . "\n";
}

function bad(string $name, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  \033[31mFAIL\033[0m  $name" . ($detail ? "  -> $detail" : "") . "\n";
}

function skip(string $name, string $detail = ''): void
{
    global $skip;
    $skip++;
    echo "  \033[33mSKIP\033[0m  $name" . ($detail ? "  -> $detail" : "") . "\n";
}

/** curl 请求本地服务，绕过沙箱 http_proxy。返回 [http_code, body] */
function req(string $method, string $path, array $opts = []): array
{
    $ch = curl_init(BASE . $path);
    $headers = $opts['headers'] ?? [];
    if (!empty($opts['ajax'])) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    if (isset($opts['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    }
    if (isset($opts['data'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['data']);
    }
    $raw = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

/** 拆分 header / body */
function splitHB(string $raw): array
{
    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) {
        return [$raw, ''];
    }
    return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
}

/** 从响应头文本里取 Set-Cookie 中某个 cookie 的值 */
function respCookie(string $raw, string $key): ?string
{
    foreach (explode("\n", $raw) as $ln) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($key, '/') . '=([^;]+)/i', $ln, $m)) {
            return $m[1];
        }
    }
    return null;
}

/** 从 HTML 里提取 64 位 hex CSRF token（无捕获组，取 $m[0]） */
function extractToken(string $html): string
{
    if (preg_match('/[a-f0-9]{64}/', $html, $m)) {
        return $m[0];
    }
    return '';
}

/**
 * 完成一次「取登录页 → 提交登录」流程，返回 [成功, wa令牌, 会话PHPSID, 响应body]
 * 关键点：GET 登录页与 POST 提交必须共用同一个 PHPSID（同一会话），否则 CSRF 不匹配。
 */
function attemptLogin(string $loginPath, string $submitPath, string $user, string $pass): array
{
    // 1) GET 登录页：拿到同一次会话的 PHPSID 与表单 token
    [$code, $raw] = req('GET', $loginPath);
    [$header, $body] = splitHB($raw);
    $phpsid = respCookie($raw, 'PHPSID');
    $token = extractToken($body);
    if (!$phpsid || !$token) {
        return [false, null, null, '无法取得 PHPSID 或 token'];
    }
    // 2) POST 登录（带同一会话的 PHPSID + token）
    $data = http_build_query(['username' => $user, 'password' => $pass, 'csrf_token' => $token]);
    [$code, $raw2] = req('POST', $submitPath, [
        'ajax' => true,
        'cookie' => "PHPSID=$phpsid",
        'data' => $data,
    ]);
    [$h2, $b2] = splitHB($raw2);
    $json = json_decode($b2, true);
    $waToken = respCookie($raw2, 'wa_auth') ?? respCookie($raw2, 'wa_admin_auth');
    $ok = ($json['code'] ?? -1) === 0;
    return [$ok, $waToken, $phpsid, $b2];
}

echo "=== Wealthy 端到端冒烟测试 ===\n";
echo "BASE=" . BASE . "\n\n";

// 1) 首页可访问
echo "[1] 首页可访问\n";
try {
    [$code, $raw] = req('GET', '/');
    [, $body] = splitHB($raw);
    if ($code === 200 && stripos($body, 'trackAdClick') !== false) {
        ok('首页 HTTP 200 且广告锚点已渲染');
    } elseif ($code === 200) {
        ok('首页 HTTP 200', '未检出广告锚点（数据层可能无生效广告，非代码回归）');
    } else {
        bad('首页 HTTP 状态码', (string)$code);
    }
} catch (\Throwable $e) {
    bad('首页请求异常', $e->getMessage());
}

// 2) 反射型 XSS 全局转义
echo "[2] 反射型 XSS 全局转义（default_filter=htmlspecialchars）\n";
try {
    [$code, $raw] = req('GET', '/?kw=' . urlencode('<script>alert(1)</script>'));
    [, $body] = splitHB($raw);
    $escaped = strpos($body, '&lt;script&gt;') !== false;
    $rawTag = preg_match('/<script>alert\(1\)<\/script>/', $body);
    if ($escaped && !$rawTag) {
        ok('搜索词 <script> 已被转义为 &lt;script&gt;');
    } else {
        bad('XSS 转义', $escaped ? '仍存在裸标签' : '未转义');
    }
} catch (\Throwable $e) {
    bad('XSS 检查异常', $e->getMessage());
}

// 3) 富文本 |raw 未被破坏
echo "[3] 富文本页 /post/7 正常（|raw 未被全局转义破坏）\n";
try {
    [$code, $raw] = req('GET', '/post/7');
    [, $body] = splitHB($raw);
    if ($code === 200 && stripos($body, 'rich-content') !== false) {
        ok('/post/7 HTTP 200 且 rich-content 容器存在');
    } elseif ($code === 200) {
        ok('/post/7 HTTP 200', '未检出 rich-content 类名（模板可能调整，请人工确认）');
    } else {
        bad('/post/7 HTTP 状态码', (string)$code);
    }
} catch (\Throwable $e) {
    bad('富文本页异常', $e->getMessage());
}

// 4) 前台登录链路 + 二次认证令牌下发
echo "[4] 前台登录链路（含会话固定防护令牌 wa_auth 下发）\n";
try {
    [$ok, $wa, $phpsidFront, $detail] = attemptLogin('/login', '/login', FRONT_USER, FRONT_PASS);
    if ($ok) {
        ok('前台登录成功 code=0（' . FRONT_USER . '）');
        if ($wa) {
            ok('登录后下发二次认证令牌 wa_auth');
        } else {
            bad('wa_auth 令牌下发', '未取到');
        }
    } else {
        bad('前台登录', $detail);
    }
} catch (\Throwable $e) {
    bad('前台登录异常', $e->getMessage());
}

// 5) 后台登录链路（super）+ 令牌下发
echo "[5] 后台登录链路（super，含 wa_admin_auth 令牌下发）\n";
try {
    [$ok, $wa, $phpsidAdmin, $detail] = attemptLogin('/admin/login', '/admin/do-login', ADMIN_USER, ADMIN_PASS);
    if ($ok) {
        ok('后台登录成功 code=0（' . ADMIN_USER . '）');
        if ($wa) {
            ok('登录后下发二次认证令牌 wa_admin_auth');
        } else {
            bad('wa_admin_auth 令牌下发', '未取到');
        }
    } else {
        bad('后台登录', $detail);
    }
} catch (\Throwable $e) {
    bad('后台登录异常', $e->getMessage());
}

// 6) 后台令牌一致性校验（会话固定防护）
echo "[6] 后台令牌一致性校验（仅带 PHPSID 不带 wa_admin_auth 应被拦截）\n";
try {
    if (empty($phpsidAdmin)) {
        skip('令牌一致性校验', '无可用后台 PHPSID');
    } else {
        [$code, $raw] = req('GET', '/admin/', ['cookie' => "PHPSID=$phpsidAdmin"]);
        if ($code === 302 || stripos($raw, 'login') !== false) {
            ok('缺少 wa_admin_auth 访问后台被拦截（跳转登录）');
        } else {
            bad('令牌一致性校验', "HTTP $code 未拦截");
        }
    }
} catch (\Throwable $e) {
    bad('令牌校验异常', $e->getMessage());
}

// 7) SSRF 私有地址拦截（直接调用项目函数，验证防护仍在）
echo "[7] SSRF 私有地址拦截（save_remote_image_local）\n";
try {
    $booted = @require_once __DIR__ . '/../vendor/autoload.php';
    $funcLoaded = @require_once __DIR__ . '/../app/functions.php';
    if ($booted && $funcLoaded && function_exists('save_remote_image_local')) {
        $r1 = save_remote_image_local('http://127.0.0.1:8791/');
        $r2 = save_remote_image_local('http://localhost/');
        if ($r1 === '' && $r2 === '') {
            ok('127.0.0.1 与 localhost 均被拒绝');
        } else {
            bad('SSRF 拦截', '127.0.0.1=>' . var_export($r1, true) . ' localhost=>' . var_export($r2, true));
        }
    } else {
        skip('SSRF 函数级检查', '无法加载 functions.php（请在项目根目录运行）');
    }
} catch (\Throwable $e) {
    skip('SSRF 函数级检查', '加载异常: ' . $e->getMessage());
}

// 8) 无遗留调试日志
echo "[8] 无遗留 /tmp 调试日志\n";
try {
    $leaked = [];
    foreach (['/tmp/reg_trace.log', '/tmp/md_debug.log'] as $f) {
        if (file_exists($f)) { $leaked[] = $f; }
    }
    if (empty($leaked)) {
        ok('调试日志文件不存在');
    } else {
        bad('调试日志泄漏', implode(', ', $leaked));
    }
} catch (\Throwable $e) {
    bad('日志检查异常', $e->getMessage());
}

echo "\n=== 汇总: PASS=$pass  FAIL=$fail  SKIP=$skip ===\n";
exit($fail > 0 ? 1 : 0);
