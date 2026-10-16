<?php
use support\view\ThinkPHP;

return [
    'handler' => ThinkPHP::class,
    'options' => [
        'view_suffix' => 'html',
        // 全局默认输出过滤：所有 {$var} 自动 htmlspecialchars，防存储型/反射型 XSS。
        // 富文本正文、后台分页等需原样输出 HTML 的地方显式用 |raw 声明。
        'default_filter' => 'htmlspecialchars',
        // 沿用 ThinkTemplate 默认 { } 定界符
        'tpl_begin'   => '{',
        'tpl_end'     => '}',
        'taglib_begin' => '{',
        'taglib_end'   => '}',
        // 模板编译缓存目录
        'cache_path'  => runtime_path() . '/views/',
        // 关闭 PHP 原生代码，纯模板语法更安全
        'tpl_deny_php' => true,
    ]
];
