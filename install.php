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
 *     php install.php --host=127.0.0.1 --port=3306 --db=wealthy \
 *        --user=root --pass=root --admin-user=admin --admin-pass=admin888
 *   导入演示数据：  php install.php --demo   （默认【不】导入，生产环境请勿加）
 *   强制重装/同步： php install.php --force
 *                 （不会删除任何数据；仅把数据库补齐/同步到完整结构：缺表创建、缺列补齐）
 *
 * 注意：
 *   - 演示数据仅含示例用户/帖子/打赏等，且【永远不】包含管理员账号。
 *     管理员仅由你下面设置的账号密码创建，绝不会被覆盖为 admin888。
 *   - 演示数据默认不导入；如导入，也只会清空示例内容表，不影响你创建的管理员。
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
// 0.5  schema 辅助：解析 install.sql 的 CREATE TABLE，确保数据库完整（幂等、不删数据）
// ------------------------------------------------------------------

/** 解析 install.sql 中所有 CREATE TABLE，返回 [table => [[列名, 列定义], ...]] */
function wf_parse_create_tables(string $sql): array
{
    // 先剥离 SQL 注释（-- 行注释 / # 行注释 / /* */ 块注释），避免注释中的
    // "CREATE TABLE IF NOT EXISTS" 字样被误识别为建表语句，导致首张表名被错判为 IF。
    $sql = preg_replace('/--[^\n]*/', ' ', $sql);
    $sql = preg_replace('/#[^\n]*/', ' ', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql);
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;\s*$/im', '', $sql);
    $sql = preg_replace('/^\s*USE\s+`?[^`;\n]+`?\s*;\s*$/im', '', $sql);
    $blocks = [];
    $len = strlen($sql);
    $i = 0;
    while (($pos = stripos($sql, 'CREATE TABLE', $i)) !== false) {
        if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', substr($sql, $pos), $mm)) {
            $i = $pos + 12;
            continue;
        }
        $tbl = $mm[1];
        $p = strpos($sql, '(', $pos);
        if ($p === false) { $i = $pos + 12; continue; }
        // 括号深度匹配，定位该表定义结束的 ')'
        $depth = 0; $inStr = false; $end = $p;
        for ($j = $p; $j < $len; $j++) {
            $ch = $sql[$j];
            if ($ch === "'" || $ch === '"') {
                $inStr = !$inStr;
            } elseif (!$inStr && $ch === '(') {
                $depth++;
            } elseif (!$inStr && $ch === ')') {
                $depth--;
                if ($depth === 0) { $end = $j; break; }
            }
        }
        $inner = substr($sql, $p + 1, $end - $p - 1);
        // 顶层逗号切分列（忽略括号内逗号与字符串内逗号）
        $cols = []; $d2 = 0; $inS2 = false; $buf = ''; $il = strlen($inner);
        for ($k = 0; $k < $il; $k++) {
            $ch = $inner[$k];
            if ($ch === "'" || $ch === '"') { $inS2 = !$inS2; $buf .= $ch; }
            elseif (!$inS2 && $ch === '(') { $d2++; $buf .= $ch; }
            elseif (!$inS2 && $ch === ')') { $d2--; $buf .= $ch; }
            elseif (!$inS2 && $d2 === 0 && $ch === ',') { $cols[] = trim($buf); $buf = ''; }
            else { $buf .= $ch; }
        }
        if (trim($buf) !== '') $cols[] = trim($buf);
        $out = [];
        foreach ($cols as $c) {
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|CONSTRAINT|INDEX|FOREIGN|CHECK|SPATIAL|FULLTEXT)\b/i', $c)) continue;
            if (!preg_match('/^`?(\w+)`?\s+(.*)$/s', $c, $cm)) continue;
            $out[] = [$cm[1], trim($cm[2])];
        }
        $blocks[] = ['table' => $tbl, 'columns' => $out];
        $i = $end;
    }
    return $blocks;
}

/** 给“已存在但缺列”的表补齐缺失列（NOT NULL 无默认值在有数据的表上失败时退化为可空） */
function wf_align_columns(PDO $pdo): array
{
    $sql = file_get_contents($GLOBALS['sqlFile']);
    $tables = wf_parse_create_tables($sql);
    $added = 0; $skipped = 0; $failed = [];
    foreach ($tables as $t) {
        $tbl = $t['table'];
        $has = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($tbl))->fetchColumn();
        if (!$has) continue; // 该表由建表步骤创建，列已齐全
        $existing = [];
        foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($tbl)) as $r) {
            $existing[] = $r['COLUMN_NAME'];
        }
        foreach ($t['columns'] as $col) {
            if (in_array($col[0], $existing, true)) { $skipped++; continue; }
            $def = $col[1];
            try {
                $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col[0]}` {$def}");
                $added++;
            } catch (PDOException $e) {
                if (stripos($def, 'NOT NULL') !== false) {
                    $def2 = str_ireplace('NOT NULL', 'NULL', $def);
                    try {
                        $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col[0]}` {$def2}");
                        $added++;
                        continue;
                    } catch (PDOException $e2) {
                        $failed[] = "{$tbl}.{$col[0]}: {$e2->getMessage()}";
                    }
                } else {
                    $failed[] = "{$tbl}.{$col[0]}: {$e->getMessage()}";
                }
            }
        }
    }
    return ['added' => $added, 'skipped' => $skipped, 'failed' => $failed];
}

/** 一步到位：CREATE TABLE IF NOT EXISTS（不删数据）+ 补齐已有表缺失列 */
function wf_create_and_align(PDO $pdo): array
{
    $sql = file_get_contents($GLOBALS['sqlFile']);
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;\s*$/im', '', $sql);
    $sql = preg_replace('/^\s*USE\s+`?[^`;\n]+`?\s*;\s*$/im', '', $sql);
    $stmts = preg_split('/;\s*(?=\n|$)/', $sql);
    $created = 0;
    foreach ($stmts as $s) {
        $s = trim($s);
        if ($s === '' || stripos($s, 'CREATE DATABASE') === 0 || stripos($s, 'USE ') === 0
            || stripos($s, 'INSERT ') === 0 || stripos($s, 'SET ') === 0) {
            continue;
        }
        if (stripos($s, 'CREATE TABLE') === 0) {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $s, $mm)) {
                $t = $mm[1];
                $exists = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($t))->fetchColumn();
                try {
                    $pdo->exec($s);
                    if (!$exists) $created++;
                } catch (PDOException $e) {
                    wf_echo("建表失败 `{$t}`：{$e->getMessage()}", 'red');
                }
            }
        }
    }
    $align = wf_align_columns($pdo);
    return [$created, $align['added'], $align['failed']];
}

/** 写入初始 system_setting（已存在则跳过，使用 INSERT IGNORE） */
function wf_seed_settings(PDO $pdo): void
{
    $sql = file_get_contents($GLOBALS['sqlFile']);
    if (preg_match('/INSERT INTO `system_setting`.*?;/is', $sql, $m)) {
        $ins = preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $m[0], 1);
        try {
            $pdo->exec($ins);
        } catch (PDOException $e) {
            wf_echo("初始配置写入失败：{$e->getMessage()}", 'red');
        }
    }
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
$demoRequested = isset($args['demo']);   // 演示数据默认关闭，需显式 --demo 才导入

wf_echo('==================================================', 'bold');
wf_echo('   造富计划 (Wealthy) 安装向导  v' . WF_INSTALLER_VERSION, 'bold');
wf_echo('==================================================', 'reset');

if (file_exists($lockFile) && !$force) {
    wf_echo("\n检测到 install.php 已完成安装（install.lock 存在）。", 'yellow');
    wf_echo("如需把数据库结构补齐/同步到最新完整版本（不会删除任何数据），请运行：", 'yellow');
    wf_echo("  php install.php --force", 'cyan');
    if (wf_prompt('确认以 --force 同步数据库结构（不删数据）？(y/N)', 'N') !== 'y') {
        wf_echo('已取消。', 'red');
        exit(0);
    }
    $force = true; // 用户确认后按“同步模式”继续（仍不删除任何数据）
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
    wf_echo("\n[警告] 部分环境检查未通过；若为“同步已有数据库结构”可忽略并继续，全新安装建议先修复。", 'yellow');
} else {
    wf_echo('环境检查通过。', 'green');
}

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
wf_step(5, '确保数据库结构完整（建缺失表 + 补缺失列，不删数据）');
if (!file_exists($sqlFile)) {
    wf_echo("未找到 {$sqlFile}", 'red');
    exit(1);
}
list($tblCreated, $colAdded, $colFailed) = wf_create_and_align($pdo);
wf_echo("数据库结构已就绪：新建表 {$tblCreated} 张，补齐缺失列 {$colAdded} 个" . ($colFailed ? "，失败 " . count($colFailed) . " 个" : ""), 'green');
foreach ($colFailed as $f) {
    wf_echo("  补齐失败：{$f}", 'red');
}
wf_seed_settings($pdo);
wf_echo("已写入初始 system_setting（已存在则跳过）", 'green');

// ------------------------------------------------------------------
// 6. 创建管理员账号
// ------------------------------------------------------------------
wf_step(6, '管理员账号');
$adminUser = $args['admin-user'] ?? '';
$adminPass = $args['admin-pass'] ?? '';
$adminPassProvided = isset($args['admin-pass']);

if ($interactive) {
    $adminUser = wf_prompt('管理员用户名', $adminUser ?: 'admin');
    $cur = $pdo->query("SELECT id FROM admins WHERE username = '{$adminUser}'")->fetchColumn();
    if ($cur) {
        wf_echo("管理员 `{$adminUser}` 已存在：将保留原密码（如需修改请加 --admin-pass=新密码 再运行）", 'yellow');
    } else {
        $adminPass = wf_prompt('管理员密码（≥6 位）', $adminPass ?: 'admin888', true);
        $adminPassProvided = true;
    }
}

if ($adminUser === '' || mb_strlen($adminUser) < 2) {
    wf_echo("管理员用户名过短", 'red');
    exit(1);
}
$adminUser = wf_escape_php($adminUser);
$cur = $pdo->query("SELECT id FROM admins WHERE username = '{$adminUser}'")->fetchColumn();

if ($cur) {
    // 已存在：仅当显式提供新密码时才更新（同步模式不会误改线上管理员密码）
    if ($adminPassProvided && $adminPass !== '' && mb_strlen($adminPass) >= 6) {
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        $pdo->exec("UPDATE admins SET password = '{$hash}', role = 'super', status = 1 WHERE id = {$cur}");
        wf_echo("管理员 `{$adminUser}` 密码已更新", 'green');
    } else {
        wf_echo("管理员 `{$adminUser}` 已存在，保留原密码", 'yellow');
    }
} else {
    if (mb_strlen($adminPass) < 6) {
        wf_echo("管理员密码至少 6 位", 'red');
        exit(1);
    }
    $hash = password_hash($adminPass, PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO admins (username, password, role, status, created_at, updated_at) VALUES ('{$adminUser}', '{$hash}', 'super', 1, NOW(), NOW())");
    wf_echo("已创建超级管理员 `{$adminUser}`", 'green');
}

// ------------------------------------------------------------------
// 7. 导入演示数据（可选）
// ------------------------------------------------------------------
wf_step(7, '演示数据（默认不导入）');
$demo = $demoRequested;
if ($interactive) {
    $demo = wf_prompt('是否导入演示数据(用户/帖子/打赏等)？建议生产环境选 n (y/N)', 'N') === 'y';
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
