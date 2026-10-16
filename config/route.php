<?php
use Webman\Route;
use app\middleware\FrontMiddleware;
use app\middleware\AdminAuth;

// ===================== 前台（用户端） =====================
Route::group('', function () {
    // 首页 / 广场
    Route::any('/', [app\controller\SquareController::class, 'index']);

    // 认证
    Route::any('/login', [app\controller\AuthController::class, 'login']);
    Route::any('/register', [app\controller\AuthController::class, 'register']);
    Route::any('/logout', [app\controller\AuthController::class, 'logout']);
    Route::post('/auth/check-username', [app\controller\AuthController::class, 'checkUsername']);

    // 博文（静态路由必须置于变量路由 /post/{id} 之前，避免被 FastRoute 视为 shadowed）
    Route::any('/posts', [app\controller\PostController::class, 'index']);
    Route::post('/post/delete', [app\controller\PostController::class, 'delete']);
    Route::any('/post/create', [app\controller\PostController::class, 'create']);
    // Markdown 导入（富文本便捷发布：md→HTML，远程图片本地化）
    Route::post('/post/md-import', [app\controller\PostController::class, 'mdImport']);
    Route::any('/post/{id}/edit', [app\controller\PostController::class, 'edit']);
    Route::any('/post/{id}', [app\controller\PostController::class, 'detail']);

    // 打赏（扫码 + 积分）
    Route::post('/tip/do', [app\controller\TipController::class, 'doTip']);

    // 公开用户主页
    Route::any('/u/{id}', [app\controller\UserController::class, 'home']);

    // 统一搜索（用户 + 博文）
    Route::any('/search', [app\controller\SearchController::class, 'index']);

    // 个人中心
    Route::any('/profile', [app\controller\ProfileController::class, 'index']);
    Route::any('/profile/received', [app\controller\ProfileController::class, 'received']);
    Route::any('/profile/sent', [app\controller\ProfileController::class, 'sent']);
    Route::any('/profile/posts', [app\controller\ProfileController::class, 'posts']);
    Route::any('/profile/comments', [app\controller\ProfileController::class, 'comments']);
    Route::any('/profile/favorites', [app\controller\ProfileController::class, 'favorites']);
    Route::any('/profile/following', [app\controller\ProfileController::class, 'following']);
    Route::any('/profile/followers', [app\controller\ProfileController::class, 'followers']);
    Route::any('/profile/notifications', [app\controller\ProfileController::class, 'notifications']);
    Route::any('/profile/password', [app\controller\ProfileController::class, 'password']);
    Route::any('/profile/edit', [app\controller\ProfileController::class, 'editProfile']);
    Route::any('/profile/invite', [app\controller\ProfileController::class, 'invite']);
    Route::any('/profile/points-logs', [app\controller\ProfileController::class, 'pointsLogs']);
    Route::post('/profile/generate-invite', [app\controller\ProfileController::class, 'generateInviteCode']);
    Route::post('/profile/checkin', [app\controller\ProfileController::class, 'checkin']);
    Route::post('/profile/close-banner', [app\controller\ProfileController::class, 'closeBanner']);
    Route::post('/profile/upload-qr', [app\controller\ProfileController::class, 'uploadQr']);
    Route::post('/profile/send-message', [app\controller\ProfileController::class, 'sendMessage']);
    Route::any('/profile/message-sessions', [app\controller\ProfileController::class, 'messageSessions']);
    Route::post('/profile/message-session', [app\controller\ProfileController::class, 'messageSession']);

    // 帮助页
    Route::any('/help/merchant-qr', [app\controller\HelpController::class, 'merchantQr']);
    Route::any('/help/user-agreement', [app\controller\HelpController::class, 'agreement']);

    // API（AJAX）
    Route::post('/comment/do', [app\controller\CommentController::class, 'doComment']);
    Route::post('/comment/delete', [app\controller\CommentController::class, 'deleteComment']);
    Route::post('/interact/like', [app\controller\InteractionController::class, 'like']);
    Route::post('/interact/favorite', [app\controller\InteractionController::class, 'favorite']);
    Route::post('/interact/follow', [app\controller\InteractionController::class, 'follow']);
    Route::post('/upload/image', [app\controller\UploadController::class, 'image']);
    Route::any('/ads/click', [app\controller\SquareController::class, 'adClick']);
    Route::post('/notification/read', [app\controller\ProfileController::class, 'readNotification']);
    Route::post('/notification/delete', [app\controller\ProfileController::class, 'deleteNotification']);
});

// ===================== 后台（管理端） =====================
Route::group('/admin', function () {
    Route::any('/login', [app\controller\admin\AdminController::class, 'login']);
    Route::post('/do-login', [app\controller\admin\AdminController::class, 'doLogin']);
    Route::any('/logout', [app\controller\admin\AdminController::class, 'logout']);

    Route::any('/', [app\controller\admin\AdminController::class, 'dashboard']);
    Route::any('/users', [app\controller\admin\AdminController::class, 'users']);
    Route::any('/users/{id}', [app\controller\admin\AdminController::class, 'userDetail']);
    Route::post('/user/toggle-status', [app\controller\admin\AdminController::class, 'userToggleStatus']);
    Route::any('/qr-audit', [app\controller\admin\AdminController::class, 'qrAudit']);
    Route::post('/qr-audit/do', [app\controller\admin\AdminController::class, 'qrAuditDo']);
    Route::any('/tips', [app\controller\admin\AdminController::class, 'tips']);
    Route::post('/tips/export', [app\controller\admin\AdminController::class, 'tipsExport']);
    Route::any('/disputes', [app\controller\admin\AdminController::class, 'disputes']);
    Route::post('/disputes/do', [app\controller\admin\AdminController::class, 'disputesDo']);
    Route::any('/posts', [app\controller\admin\AdminController::class, 'posts']);
    Route::any('/post-category', [app\controller\admin\AdminController::class, 'postCategory']);
    Route::post('/post-category/save', [app\controller\admin\AdminController::class, 'postCategorySave']);
    Route::post('/post-category/delete', [app\controller\admin\AdminController::class, 'postCategoryDelete']);
    Route::any('/comments', [app\controller\admin\AdminController::class, 'comments']);
    Route::any('/sensitive-words', [app\controller\admin\AdminController::class, 'sensitiveWords']);
    Route::post('/sensitive-words/save', [app\controller\admin\AdminController::class, 'sensitiveWordsSave']);
    Route::any('/ads', [app\controller\admin\AdminController::class, 'ads']);
    Route::post('/ads/save', [app\controller\admin\AdminController::class, 'adsSave']);
    Route::post('/ads/upload', [app\controller\admin\AdminController::class, 'adsUpload']);
    Route::any('/risk-monitor', [app\controller\admin\AdminController::class, 'riskMonitor']);
    Route::post('/risk-monitor/remind', [app\controller\admin\AdminController::class, 'riskRemind']);
    Route::post('/risk-monitor/mark-merchant', [app\controller\admin\AdminController::class, 'markMerchant']);
    Route::any('/system-setting', [app\controller\admin\AdminController::class, 'systemSetting']);
    Route::post('/system-setting/save', [app\controller\admin\AdminController::class, 'systemSettingSave']);
    Route::post('/site-logo/upload', [app\controller\admin\AdminController::class, 'siteLogoUpload']);
    Route::any('/invite-codes', [app\controller\admin\AdminController::class, 'inviteCodes']);
    Route::post('/invite-codes/generate', [app\controller\admin\AdminController::class, 'inviteCodesGenerate']);
    Route::any('/admins', [app\controller\admin\AdminController::class, 'admins']);
    Route::post('/admins/save', [app\controller\admin\AdminController::class, 'adminsSave']);

    // 博文审核 / 删除
    Route::post('/posts/audit', [app\controller\admin\AdminController::class, 'postsAudit']);
    Route::post('/posts/delete', [app\controller\admin\AdminController::class, 'postsDelete']);
    // 评论审核 / 删除
    Route::post('/comments/audit', [app\controller\admin\AdminController::class, 'commentsAudit']);
    Route::post('/comments/delete', [app\controller\admin\AdminController::class, 'commentsDelete']);
    // 举报处理
    Route::any('/reports', [app\controller\admin\AdminController::class, 'reports']);
    Route::post('/reports/handle', [app\controller\admin\AdminController::class, 'reportsHandle']);
    // 站内通知 / 公告群发
    Route::any('/notifications', [app\controller\admin\AdminController::class, 'notifications']);
    Route::post('/notifications/send', [app\controller\admin\AdminController::class, 'notifySend']);
    // 通知记录（系统 + 用户）列表 / 删除
    Route::any('/notification-records', [app\controller\admin\AdminController::class, 'notificationRecords']);
    Route::post('/notification-records/delete', [app\controller\admin\AdminController::class, 'notificationDelete']);
    // 管理员操作日志
    Route::any('/admin-logs', [app\controller\admin\AdminController::class, 'adminLogs']);
    // 用户编辑 / 删除
    Route::post('/user/edit', [app\controller\admin\AdminController::class, 'userEdit']);
    Route::post('/user/delete', [app\controller\admin\AdminController::class, 'userDelete']);
    Route::post('/user/adjust-points', [app\controller\admin\AdminController::class, 'adjustPoints']);
    // 邀请码 禁用 / 删除 / 使用记录
    Route::post('/invite-codes/toggle', [app\controller\admin\AdminController::class, 'inviteCodesToggle']);
    Route::post('/invite-codes/delete', [app\controller\admin\AdminController::class, 'inviteCodesDelete']);
    Route::any('/invite-codes/used', [app\controller\admin\AdminController::class, 'inviteUsed']);
    // 敏感词批量导入
    Route::post('/sensitive-words/batch', [app\controller\admin\AdminController::class, 'sensitiveWordsBatch']);
})->middleware(AdminAuth::class);
