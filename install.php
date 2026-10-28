#!/usr/bin/env php
<?php
/**
 * 造富计划 (Wealthy) 安装向导
 * ------------------------------------------------------------------
 * 纯 CLI 脚本，不依赖 Webman 框架启动，使用 PDO + GD 直接完成安装。
 *
 * 用法：
 *   交互（一路回车用默认值）：
 *     php install.php
 *   非交互（脚本化 / CI）：
 *     php install.php --host=127.0.0.1 --port=3306 --db=wealthy --user=root --pass=root --admin-user=admin --admin-pass=admin888
 *   跳过演示数据：  php install.php --no-demo
 *   强制重装：      php install.php --force
 *
 * 完成后请删除本文件（install.php），避免生产环境被重复安装或信息泄露。
 */

declare(strict_types=1);

const WF_INSTALLER_VERSION = '1.0';

$root = __DIR__;
$lockFile = $root . '/install.lock';
$cfgFile = $root . '/config/database.php';
$sqlFile = $root . '/database/install.sql';
$seedFile = $root . '/database/seed.php';

// ------------------------------------------------------------------
// 小工具
// ------------------------------------------------------------------
function wf_echo(string $msg = '', string $color = ''): void
{
    $colors = [
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];
    $c = $colors[$color] ?? '';
    $r = $colors['reset'] ?? '';
    fwrite(STDOUT, $c . $msg . $r . "\n");
}

function wf_step(int $n, string $title): void
{
    wf_echo("\n[" . $n . "] " . $title, 'bold');
}

/** 读取命令行参数 --key=value */
function wf_parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--', 2) === 0) {
            $eq = strpos($a, '=');
            if ($eq !== false) {
                $out[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
            } else {
                $out[substr($a, 2)] = true;
            }
        }
    }
    return $out;
}

/** 交互输入：支持默认值；密码隐藏（仅在真实 TTY 下） */
function wf_prompt(string $label, string $default = '', bool $secret = false): string
{
    $disp = $default !== '' ? " [{$default}]" : '';
    wf_echo($label . $disp . ': ', 'cyan');
    if ($secret && function_exists('posix_isatty') && posix_isatty(STDIN)) {
        system('stty -echo');
        $fh = fopen('php://stdin', 'r');
        $val = trim((string) fgets($fh));
        fclose($fh);
        system('stty echo');
        wf_echo('');
    } else {
        $fh = fopen('php://stdin', 'r');
        $val = trim((string) fgets($fh));
        fclose($fh);
    }
    return $val === '' ? $default : $val;
}

function wf_escape_php(string $v): string
{
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $v);
}

// ------------------------------------------------------------------
// 0. 环境与锁检查
// ------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    wf_echo('请通过命令行运行：php install.php', 'red');
    exit(1);
}

$args = wf_parse_args($argv);
$force = isset($args['force']);
$noDemo = isset($args['no-demo']);

wf_echo('==================================================', 'bold');
wf_echo('   造富计划 (Wealthy) 安装向导  v' . WF_INSTALLER_VERSION, 'bold');
wf_echo('==================================================', 'reset');

if (file_exists($lockFile) && !$force) {
    wf_echo("\n检测到 install.lock，系统似乎已安装。", 'yellow');
    wf_echo("如需重新安装，请先删除 install.lock 或加 --force 参数。", 'yellow');
    wf_echo("继续将清空目标数据库中已存在的同名表并重建。", 'yellow');
    if (wf_prompt('确认强制重装？(y/N)', 'N') !== 'y') {
        wf_echo('已取消。', 'red');
        exit(0);
    }
}

wf_step(1, '环境检查');
$ok = true;
$checks = [
    'PHP >= 8.1'            => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO_MySQL 扩展'         => extension_loaded('pdo_mysql'),
    'mbstring 扩展'          => extension_loaded('mbstring'),
    'GD 扩展（头像/收款码/广告图生成）' => extension_loaded('gd'),
    'config/ 可写'           => is_writable($cfgFile) || (!file_exists($cfgFile) && is_writable(dirname($cfgFile))),
    'runtime/ 可写'          => is_dir($root . '/runtime') ? is_writable($root . '/runtime') : @mkdir($root . '/runtime', 0777, true),
    'public/uploads/ 可写'   => is_dir($root . '/public/uploads') ? is_writable($root . '/public/uploads') : @mkdir($root . '/public/uploads', 0777, true),
];
foreach ($checks as $name => $pass) {
    wf_echo(($pass ? '  [✓] ' : '  [✗] ') . $name, $pass ? 'green' : 'red');
    if (!$pass) $ok = false;
}
if (!$ok) {
    wf_echo("\n环境检查未通过，请先解决上述问题后再运行安装。", 'red');
    exit(1);
}
wf_echo('环境检查通过。', 'green');

// ------------------------------------------------------------------
// 2. 收集数据库配置
// ------------------------------------------------------------------
wf_step(2, '数据库配置');

$dbHost = $args['host'] ?? '';
$dbPort = $args['port'] ?? '';
$dbName = $args['db'] ?? '';
$dbUser = $args['user'] ?? '';
$dbPass = $args['pass'] ?? '';

$interactive = !($dbHost && $dbPort && $dbName && $dbUser && isset($args['pass']));
if ($interactive) {
    wf_echo('（直接回车使用方括号内默认值）', 'yellow');
    $dbHost = wf_prompt('数据库主机', $dbHost ?: '127.0.0.1');
    $dbPort = wf_prompt('数据库端口', $dbPort ?: '3306');
    $dbName = wf_prompt('数据库名', $dbName ?: 'wealthy');
    $dbUser = wf_prompt('数据库用户名', $dbUser ?: 'root');
    $dbPass = wf_prompt('数据库密码', $dbPass ?: 'root', true);
}

// 校验库名（仅允许安全字符，避免 SQL 注入）
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    wf_echo("数据库名仅允许字母、数字、下划线：{$dbName}", 'red');
    exit(1);
}
$dbHost = wf_escape_php($dbHost);
$dbPort = wf_escape_php($dbPort);
$dbName = wf_escape_php($dbName);
$dbUser = wf_escape_php($dbUser);
$dbPass = wf_escape_php($dbPass);

// ------------------------------------------------------------------
// 3. 连接 + 建库
// ------------------------------------------------------------------
wf_step(3, '连接数据库并创建库');
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    wf_echo("已连接 MySQL @ {$dbHost}:{$dbPort}", 'green');
} catch (PDOException $e) {
    wf_echo("连接失败：{$e->getMessage()}", 'red');
    exit(1);
}

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `{$dbName}`");
    wf_echo("数据库 `{$dbName}` 就绪", 'green');
} catch (PDOException $e) {
    wf_echo("建库失败：{$e->getMessage()}", 'red');
    exit(1);
}

// ------------------------------------------------------------------
// 4. 写入 config/database.php
// ------------------------------------------------------------------
wf_step(4, '写入数据库配置 (config/database.php)');
if (!file_exists($cfgFile)) {
    wf_echo("未找到 config/database.php，跳过写入（请手动配置连接）。", 'yellow');
} else {
    $content = file_get_contents($cfgFile);
    $content = preg_replace("/('host'\s*=>\s*)'[^']*'/", "$1'{$dbHost}'", $content);
    $content = preg_replace("/('port'\s*=>\s*)'[^']*'/", "$1'{$dbPort}'", $content);
    $content = preg_replace("/('database'\s*=>\s*)'[^']*'/", "$1'{$dbName}'", $content);
    $content = preg_replace("/('username'\s*=>\s*)'[^']*'/", "$1'{$dbUser}'", $content);
    $content = preg_replace("/('password'\s*=>\s*)'[^']*'/", "$1'{$dbPass}'", $content);
    file_put_contents($cfgFile, $content);
    wf_echo("已更新 config/database.php 连接信息", 'green');
}

// ------------------------------------------------------------------
// 5. 执行 install.sql（建表 + 初始配置）
// ------------------------------------------------------------------
wf_step(5, '创建数据表并写入初始配置');
if (!file_exists($sqlFile)) {
    wf_echo("未找到 {$sqlFile}", 'red');
    exit(1);
}
$sql = file_get_contents($sqlFile);
// 去掉 CREATE DATABASE / USE 语句（安装程序已自行建库选库）
$sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;\s*$/im', '', $sql);
$sql = preg_replace('/^\s*USE\s+`?[^`;\n]+`?\s*;\s*$/im', '', $sql);

$statements = preg_split('/;\s*(?=\n|$)/', $sql);
$count = 0;
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || stripos($stmt, 'CREATE DATABASE') === 0 || stripos($stmt, 'USE ') === 0) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (PDOException $e) {
        wf_echo("SQL 执行失败：" . substr($stmt, 0, 60) . " ...", 'red');
        wf_echo("错误：{$e->getMessage()}", 'red');
        exit(1);
    }
}
wf_echo("已执行 {$count} 条 SQL（建表 + 初始 system_setting）", 'green');

// ------------------------------------------------------------------
// 6. 创建管理员账号
// ------------------------------------------------------------------
wf_step(6, '创建管理员账号');
$adminUser = $args['admin-user'] ?? '';
$adminPass = $args['admin-pass'] ?? '';
if ($interactive) {
    $adminUser = wf_prompt('管理员用户名', $adminUser ?: 'admin');
    $adminPass = wf_prompt('管理员密码（≥6 位）', $adminPass ?: 'admin888', true);
}
if (mb_strlen($adminUser) < 2) {
    wf_echo("管理员用户名过短", 'red');
    exit(1);
}
if (mb_strlen($adminPass) < 6) {
    wf_echo("管理员密码至少 6 位", 'red');
    exit(1);
}
$adminUser = wf_escape_php($adminUser);
$hash = password_hash($adminPass, PASSWORD_BCRYPT);

// 若已存在同名管理员则更新，否则插入
$exists = $pdo->query("SELECT id FROM admins WHERE username = '{$adminUser}'")->fetchColumn();
if ($exists) {
    $pdo->exec("UPDATE admins SET password = '{$hash}', role = 'super', status = 1 WHERE id = {$exists}");
    wf_echo("管理员 `{$adminUser}` 已更新为超级管理员", 'green');
} else {
    $pdo->exec("INSERT INTO admins (username, password, role, status, created_at, updated_at) VALUES ('{$adminUser}', '{$hash}', 'super', 1, NOW(), NOW())");
    wf_echo("已创建超级管理员 `{$adminUser}`", 'green');
}

// ------------------------------------------------------------------
// 7. 导入演示数据（可选）
// ------------------------------------------------------------------
wf_step(7, '演示数据');
$demo = !$noDemo;
if ($interactive && $demo) {
    $demo = wf_prompt('是否导入演示数据(用户/帖子/打赏等)？(Y/n)', 'Y') !== 'n';
}
if ($demo) {
    if (!file_exists($seedFile)) {
        wf_echo("未找到 {$seedFile}，跳过演示数据", 'yellow');
    } else {
        wf_echo("正在导入演示数据（生成头像/收款码/广告图，可能需要几秒）...", 'cyan');
        putenv("WF_DB_HOST={$dbHost}");
        putenv("WF_DB_PORT={$dbPort}");
        putenv("WF_DB_NAME={$dbName}");
        putenv("WF_DB_USER={$dbUser}");
        putenv("WF_DB_PASS={$dbPass}");
        $cmd = PHP_BINARY . ' ' . escapeshellarg($seedFile) . ' 2>&1';
        $output = shell_exec($cmd);
        if ($output === null || stripos($output, 'DONE') === false) {
            wf_echo("演示数据导入可能失败，输出：\n" . $output, 'yellow');
        } else {
            wf_echo("演示数据导入完成", 'green');
        }
    }
} else {
    wf_echo("跳过演示数据（系统仅含管理员与基础配置）", 'yellow');
}

// ------------------------------------------------------------------
// 8. 写安装锁
// ------------------------------------------------------------------
wf_step(8, '完成');
file_put_contents($lockFile, json_encode([
    'version'      => WF_INSTALLER_VERSION,
    'installed_at' => date('Y-m-d H:i:s'),
    'db_host'      => $dbHost,
    'db_name'      => $dbName,
    'admin_user'   => $adminUser,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

wf_echo("\n==================================================", 'bold');
wf_echo("  安装成功！", 'green');
wf_echo("==================================================", 'reset');
wf_echo("数据库：{$dbHost}:{$dbPort} / {$dbName}");
wf_echo("后台地址：/admin/   管理员：{$adminUser}");
wf_echo("启动服务：php start.php start -d");
wf_echo("首页访问：http://127.0.0.1:8791/", 'cyan');
wf_echo("\n[重要] 出于安全，请删除 install.php 与 install.lock 之外不要外泄。", 'yellow');
wf_echo("若需重新安装，运行：php install.php --force", 'yellow');
