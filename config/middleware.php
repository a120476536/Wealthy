<?php
return [
    // 全局中间件：会话 + CSRF 令牌 + 前台模板变量（空字符串键表示全局生效）
    // 注意：前台变量中间件必须全局，否则未显式注册、被 Webman 按约定自动分发的路径
    // （如 /square 自动路由到 SquareController::index）会绕过路由组中间件导致布局变量缺失 500。
    '' => [
        app\middleware\SessionCsrf::class,
        app\middleware\LocaleMiddleware::class,
        app\middleware\FrontMiddleware::class,
    ],
];
