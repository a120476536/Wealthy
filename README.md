# 造富计划 · Wealthy

> 基于 Webman 的高性能 **社区内容 + 价值打赏 + 创作者激励** 平台

「造富计划」是一个面向内容创作者与读者的社区平台，核心围绕 **内容创作 → 价值打赏 → 创作者激励 → 平台变现** 的完整闭环构建。用户在社区发布图文博文、互相点赞收藏评论，并通过收款码进行打赏；平台借助富豪榜 / 善人榜、风控审核、广告变现等机制，构建健康的创作者生态。

---

## 快速安装

本项目提供一键安装向导 [`install.php`](./install.php)（纯 CLI，不依赖框架启动）。它会自动完成**环境检查 → 建库建表 → 写入数据库配置 → 创建管理员 → 可选导入演示数据**全流程。

```bash
# 1) 安装依赖
composer install

# 2) 运行安装向导（交互式，一路回车用默认值：127.0.0.1 / 3306 / wealthy / root / root）
php install.php

# 非交互 / 脚本化安装：
php install.php --host=127.0.0.1 --port=3306 --db=wealthy \
  --user=root --pass=root --admin-user=admin --admin-pass=admin888

# 导入演示数据（默认【不】导入，需显式加 --demo；生产环境请勿加）：
php install.php --demo

# 同步 / 对齐数据库结构（--force：仅创建缺失的表、补齐缺失的列，绝不删数据；
#   已存在的管理员若未传 --admin-pass 则保留原密码。适合首次部署后补表、或升级旧库）：
php install.php --force
```

> ⚠️ 安全建议：确认安装无误、后续不再需要重跑安装向导后，请**删除 `install.php` 与 `install.lock`**，避免生产环境被重复安装或配置泄露。
> 注意：`install.php --force` 可随时安全地重新对齐数据库结构（建缺失表 / 补缺失列，**不删数据**），线上升级旧库也用它，无需删库重建。
> 数据库结构见 [`database/install.sql`](./database/install.sql)（23 张表，全部 `CREATE TABLE IF NOT EXISTS`、幂等可重复执行），演示数据见 [`database/seed.php`](./database/seed.php)。

## 功能模块截图（演示）

> 以下截图位于 [`assets/`](./assets) 目录，由 `tests/screenshots.cjs`（Playwright 自动抓取）生成，覆盖前台与后台主要功能模块，便于快速了解产品形态。

### 前台（用户端）

<table>
  <tr>
    <td align="center"><img src="assets/03-home.png" width="360"/><br/>首页 / 广场</td>
    <td align="center"><img src="assets/01-login.png" width="360"/><br/>登录</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/02-register.png" width="360"/><br/>注册</td>
    <td align="center"><img src="assets/04-posts.png" width="360"/><br/>帖子列表</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/05-post-detail.png" width="360"/><br/>帖子详情</td>
    <td align="center"><img src="assets/06-user-home.png" width="360"/><br/>用户主页</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/07-search.png" width="360"/><br/>搜索</td>
    <td align="center"><img src="assets/08-help-merchant-qr.png" width="360"/><br/>商家收款码说明</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/09-help-agreement.png" width="360"/><br/>用户协议</td>
    <td align="center"><img src="assets/10-profile.png" width="360"/><br/>个人中心</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/11-profile-received.png" width="360"/><br/>收到的打赏</td>
    <td align="center"><img src="assets/12-profile-posts.png" width="360"/><br/>我的帖子</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/13-profile-messages.png" width="360"/><br/>消息会话</td>
    <td align="center"><img src="assets/14-profile-invite.png" width="360"/><br/>邀请好友</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/15-profile-points.png" width="360"/><br/>积分日志</td>
    <td align="center">—</td>
  </tr>
</table>

### 后台（运营端）

<table>
  <tr>
    <td align="center"><img src="assets/16-admin-login.png" width="360"/><br/>后台登录</td>
    <td align="center"><img src="assets/17-admin-dashboard.png" width="360"/><br/>仪表盘</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/18-admin-users.png" width="360"/><br/>用户管理</td>
    <td align="center"><img src="assets/19-admin-qr-audit.png" width="360"/><br/>收款码审核</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/20-admin-tips.png" width="360"/><br/>打赏管理</td>
    <td align="center"><img src="assets/21-admin-disputes.png" width="360"/><br/>纠纷处理</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/22-admin-posts.png" width="360"/><br/>文章管理</td>
    <td align="center"><img src="assets/23-admin-post-category.png" width="360"/><br/>文章分类</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/24-admin-comments.png" width="360"/><br/>评论管理</td>
    <td align="center"><img src="assets/25-admin-sensitive-words.png" width="360"/><br/>敏感词</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/26-admin-ads.png" width="360"/><br/>广告管理</td>
    <td align="center"><img src="assets/27-admin-risk-monitor.png" width="360"/><br/>风控监控</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/28-admin-system-setting.png" width="360"/><br/>系统设置</td>
    <td align="center"><img src="assets/29-admin-invite-codes.png" width="360"/><br/>邀请码</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/30-admin-admins.png" width="360"/><br/>管理员</td>
    <td align="center"><img src="assets/31-admin-reports.png" width="360"/><br/>举报处理</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/32-admin-notifications.png" width="360"/><br/>通知发送</td>
    <td align="center"><img src="assets/33-admin-notification-records.png" width="360"/><br/>通知记录</td>
  </tr>
  <tr>
    <td align="center"><img src="assets/34-admin-logs.png" width="360"/><br/>操作日志</td>
    <td align="center">—</td>
  </tr>
</table>

---

## 一、这个项目主要做什么

一句话概括：**一个让创作者发内容、读者用「收款码」直接打赏、平台统一做风控与变现的社区网站。**

它把传统博客社区和"打赏 / 付费支持"结合，并补齐了运营侧所需的内容审核、敏感词、风控、广告、纠纷处理、通知、操作日志等整套后台能力。

---

## 二、核心功能详解

### 1. 内容社区
- **社区广场（`/posts`）**：博文列表，支持分类筛选、关键词搜索、按「热度 / 最新」排序，分页浏览（每页 10 条 + 带省略号页码指示器）。
- **写博文（`/post/create` 新建、`/post/{id}/edit` 编辑）**：富文本图文编辑（HTML 服务端净化、上传图片 ≤5MB 服务端压缩）；支持**一键从 Markdown 导入**、**导入 Word/PDF 文档**（`.docx` 用 mammoth 保留标题/列表/表格/图片，`.pdf` 用 pdf.js 抽取正文文本；二者均在前端净化后回填编辑器，避免大文档往返卡顿）；发布时选择分类，可直接发布或进入审核队列。
- **文章详情（`/post/detail`）**：浏览正文、点赞 ❤、收藏 ⭐、评论 💬，实时统计阅读量。
- **分类体系**：分类由管理员在后台维护，用户发布时下拉选择（不开放用户自建）。

### 2. 打赏与创作者激励
- **收款码**：用户上传个人收款码（二维码图片），展示在个人主页，供读者扫码打赏。
- **打赏（`tips`）**：读者向作者发起打赏，记录金额、状态（待确认 / 已收到），可导出。
- **富豪榜 / 善人榜**（社区首页）：
  - 富豪榜 = 收款总额 TOP10（接收打赏最多的人）；
  - 善人榜 = 打赏支出 TOP10（打赏出去最多的人）；
  - 两榜均计入「未实际确认」的打赏，最多各显示 10 位。

### 3. 用户与社交
- 注册 / 登录（全局 CSRF 防护）。
- **个人主页（`/user/home`）**：资料、收款码、动态展示。
- **个人中心（`/profile`）**：我的博文、收藏、关注 / 粉丝、消息通知、打赏记录、账号设置。
- 关注 / 粉丝、点赞、收藏、评论等社交互动。

### 4. 风控与安全
- **收款码审核（`qr_audit`）**：上传收款码需后台审核通过后才生效。
- **风控监控（`risk_monitor`）**：对异常收款行为进行监控与提醒。
- **敏感词过滤（`sensitive_words`）**：内置多类敏感词库（政治类 / 色情类 / 广告类 / 涉枪涉爆，共 1000+ 条），命中后可「替换 `***`」或「拒绝发布」。
- **举报处理（`reports`）**：对博文 / 评论 / 收款码的举报进行查看、处理、忽略并记录处理备注。
- **内容审核**：博文审核、评论审核（可在系统设置中开关，开关打开后内容需审核通过才展示）。
- **上传与内容净化**：图片上传经「类型白名单 + GD 像素重编码（图片马失效）+ 强制站内扩展名」三重处理；富文本经 `sanitize_rich_html()` 服务端净化（剥离 `script/style/iframe`、仅放行站内 / 白名单图片、外链加 `rel="nofollow noopener"`、过滤 `javascript:` 等危险协议），降低存储型 XSS 风险。

### 5. 运营与变现
- **广告管理（`ads`）**：10 个广告位（广场顶部 / 中 / 底、社区顶部 / 中 / 底、文章侧栏、个人主页侧栏 / 顶部等），支持：
  - 真实图片文件上传（落地 `public/uploads/ads`，自动压缩）；
  - 每个广告位标注**推荐尺寸**（如通栏 1120×80、侧栏 320×128），上传时自动校验比例是否适配；
  - 点击跳转 URL（留空则纯展示不可点；有 URL 新窗口打开并统计点击）；
  - 后台列表展示每条广告累计点击数。
- **邀请码（`invite_codes`）**：生成 / 禁用 / 删除，使用流水查看（`invite_used_logs`）。
- **站内通知 / 公告（`notifications`）**：全站群发或定向推送给指定用户，前台「消息通知」可见。

### 5.1 积分、签到与等级体系
- **积分（`points`）**：用户行为产生积分变动并写入 `points_logs` 流水，类型涵盖：每日签到、发布博文、收到打赏、邀请奖励（含邀请注册奖励）、生成邀请码扣减、私信扣减、管理员发放 / 扣减。个人中心「积分日志」（`/profile/points-logs`）可查看全部明细。
- **每日签到（`/profile/checkin`）**：由系统设置 `checkin_switch` 开关控制；开启后每日签到得 `daily_checkin_points`（默认 5）积分，按 `users.last_checkin_at` 判重防重复签到。
- **经验与等级（`experience` / `level`）**：发布、互动等行为累积经验，按阈值自动升级；`calc_level()` / `level_progress()` 计算等级与进度。
- **管理员调积分**：后台 `/user/adjust-points` 可对用户积分发放或扣减（分别记 `admin_grant` / `admin_deduct`）。

### 6. 管理后台（`/admin`）
后台已用 **Tailwind CSS** 全面重写（替代原 AdminLTE），包含以下模块：

| 模块 | 说明 |
|---|---|
| 仪表盘 Dashboard | 核心数据概览（用户数、打赏、待处理举报、待审内容等） |
| 用户管理 | 启用 / 禁用、编辑资料、重置密码、风控计数、查看收款码、软删除 |
| 打赏管理 | 打赏记录、筛选、导出 |
| 纠纷处理 | 打赏争议申诉的确认 / 驳回 |
| 博文管理 | 列表、审核通过 / 驳回、软删除 |
| 评论管理 | 列表、审核通过 / 驳回、删除 |
| 分类管理 | 博文分类的维护 |
| 广告管理 | 多广告位投放、上传、点击统计 |
| 敏感词 | 增删改、批量导入 |
| 风控监控 | 异常收款监控 |
| 举报处理 | 举报查看与处理 |
| 站内通知 | 群发 / 定向推送 |
| 操作日志 | 管理员关键操作的审计追溯（admin_logs） |
| 邀请码 | 生成、禁用、使用流水 |
| 系统设置 | 站点名称、标语、审核开关等 |
| 管理员 | 后台账号管理 |

> 默认账号：管理员 `admin / admin888`；测试用户 `程序员老K / abc123456`。

---

## 三、技术栈

| 层 | 技术 |
|---|---|
| 运行环境 | PHP ≥ 8.1 |
| 后端框架 | **Webman 2.x**（基于 Workerman 的常驻内存高性能 PHP 框架，单进程可扛高并发） |
| 模板引擎 | **ThinkTemplate 3.x**（富文本输出使用 `\|raw` 避免被转义） |
| 数据库 | MySQL（使用 `webman/database`，即 Laravel 的 `illuminate/database` 组件） |
| 缓存 / 存储 | Redis（`webman/redis`） |
| 前端样式 | **Tailwind CSS**（Play CDN）+ jQuery + Chart.js（后台图表） |
| 其他组件 | monolog（日志）、webman/validation（校验）、symfony/var-dumper |

---

## 四、环境要求

- PHP ≥ 8.1，并启用以下扩展：`pdo_mysql`、`redis`、`gd`（图片压缩）、`mbstring`、`pcntl`（Webman 需要）
- MySQL 5.7+ / 8.0
- Redis 5+
- Composer

---

## 五、安装与运行

```bash
# 1. 安装依赖
composer install

# 2. 创建数据库并导入表结构（共 23 张表）
mysql -u<user> -p <database_name> < database/install.sql

# 3. 配置数据库连接与 Redis
#    编辑 config/database.php 与 config/redis.php

# 4. 启动服务（默认端口 8791）
php start.php start        # 开发模式（常驻前台）
php start.php start -d     # 守护模式（后台运行）
php start.php stop         # 停止
php start.php restart -d   # 重启（改了配置 / 路由后需要）

# 5. 访问
#    前台：http://127.0.0.1:8791
#    后台：http://127.0.0.1:8791/admin   （admin / admin888）
```

> 注意：修改了模板文件后，如果启用了模板缓存，需清空 `runtime/views/` 再重启。

---

## 六、目录结构

```
Wealthy/
├── app/
│   ├── controller/          # 控制器
│   │   ├── IndexController.php       # 首页
│   │   ├── SquareController.php      # 社区广场 / 广告取数 / 广告点击统计
│   │   ├── PostController.php        # 博文列表 / 详情 / 发布 / 分页
│   │   ├── CommentController.php     # 评论
│   │   ├── InteractionController.php # 点赞 / 收藏 / 关注
│   │   ├── TipController.php         # 打赏
│   │   ├── ProfileController.php     # 个人中心 / 消息
│   │   ├── UserController.php        # 用户主页
│   │   ├── AuthController.php        # 注册 / 登录
│   │   ├── UploadController.php      # 图片上传（含服务端压缩）
│   │   ├── HelpController.php        # 帮助 / 协议页
│   │   ├── SearchController.php      # 搜索（博文 / 用户）
│   │   └── admin/AdminController.php # 全部后台管理功能
│   ├── middleware/          # 中间件（FrontMiddleware / AdminAuth / SessionCsrf / LocaleMiddleware 等）
│   ├── view/                # 视图（ThinkTemplate）
│   │   ├── layout/          # 前台布局（_header / _footer / front_layout）
│   │   ├── square/ post/ profile/ user/ auth/ help/  # 前台各模块
│   │   └── admin/           # 后台（Tailwind 重写）
│   └── functions.php        # 全局辅助函数（site_setting / ads_for / sanitize_rich_html / add_points / add_experience / 等级经验 / 敏感词过滤 等）
├── config/                  # 配置（route / database / redis / ...）
├── database/install.sql     # 数据库建表 SQL（23 张表）
├── public/                  # Web 根目录（uploads/ 上传文件存放于此）
├── runtime/                 # 运行时（日志 / 视图缓存 / 会话）
├── assets/                  # 功能模块截图（README 演示用，由 tests/screenshots.cjs 生成）
├── tests/                   # smoke.php（零依赖冒烟测试）/ screenshots.cjs（功能截图脚本）
├── start.php                # 启动入口
└── composer.json
```

---

## 七、数据模型（23 张表）

| 分类 | 表 |
|---|---|
| 用户 | `users`、`admins`、`invite_codes`、`invite_used_logs` |
| 内容 | `posts`、`post_categories`、`comments`、`post_audit_logs`、`comment_audit_logs` |
| 互动 | `likes`、`favorites`、`follows` |
| 打赏 | `tips`、`tip_confirm_logs` |
| 风控 / 安全 | `qr_audit_logs`、`risk_remind_logs`、`sensitive_words`、`reports` |
| 运营 / 积分 | `ads`、`notifications`、`points_logs` |
| 系统 | `system_setting`、`admin_logs` |

---

## 八、核心业务流程

**发布博文**
写博文 →（若开启审核）进入 `post_audit_logs` 待审 → 管理员审核通过 → 展示到社区广场 → 读者点赞 / 收藏 / 评论。

**打赏**
读者在作者个人主页看到收款码 → 发起打赏（写入 `tips`，状态「待确认」）→ 作者确认收款（状态「已收到」）→ 计入富豪榜 / 善人榜；如有争议可发起纠纷申诉，后台裁决。

**广告投放**
后台选广告位 → 上传图片（自动压缩 + 尺寸校验）→ 填跳转 URL → 前台对应位置展示并统计点击 → 后台查看累计点击数。

---

## 九、License

本项目基于 **MIT** 协议开源，详见 [LICENSE](./LICENSE)。
