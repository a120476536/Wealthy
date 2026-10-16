-- ============================================================
-- 造富计划 (Wealthy) 数据库建表脚本  v1.7
-- 字符集 utf8mb4 / InnoDB
-- 执行：mysql -uroot -proot < database/install.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `wealthy` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `wealthy`;

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------- 用户表 --------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `wechat_qr` varchar(255) DEFAULT NULL,
  `wechat_qr_status` tinyint DEFAULT 0 COMMENT '0未上传 1待审核 2通过 3驳回',
  `wechat_qr_reason` varchar(255) DEFAULT NULL,
  `alipay_qr` varchar(255) DEFAULT NULL,
  `alipay_qr_status` tinyint DEFAULT 0,
  `alipay_qr_reason` varchar(255) DEFAULT NULL,
  `qr_type` tinyint DEFAULT 1 COMMENT '1个人码 2商户码',
  `merchant_qr_verified` tinyint DEFAULT 0,
  `risk_remind_count` int DEFAULT 0,
  `risk_remind_last_at` datetime DEFAULT NULL,
  `risk_banner_closed_at` datetime DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user',
  `status` tinyint DEFAULT 1 COMMENT '1正常 0禁用',
  `tip_total_amount` decimal(12,2) DEFAULT 0.00 COMMENT '有效收款总额（异常已扣减）',
  `tip_confirmed_count` int DEFAULT 0,
  `tip_rejected_count` int DEFAULT 0,
  `sent_tip_count` int DEFAULT 0,
  `post_count` int DEFAULT 0,
  `comment_count` int DEFAULT 0,
  `follower_count` int DEFAULT 0,
  `following_count` int DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL COMMENT '注册手机号',
  `experience` int NOT NULL DEFAULT 0 COMMENT '经验值',
  `level` int NOT NULL DEFAULT 1 COMMENT '等级（1=Lv1）',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 打赏记录表 --------------------
DROP TABLE IF EXISTS `tips`;
CREATE TABLE `tips` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `from_user_id` bigint DEFAULT NULL COMMENT '游客可为null',
  `from_ip` varchar(64) DEFAULT '',
  `to_user_id` bigint NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `level` tinyint NOT NULL COMMENT '1/2/3 档位',
  `pay_type` tinyint NOT NULL COMMENT '1微信 2支付宝',
  `remark` varchar(100) DEFAULT NULL,
  `status` tinyint DEFAULT 0 COMMENT '0待确认 1已收到 2异常',
  `confirm_at` datetime DEFAULT NULL,
  `confirm_by` bigint DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_count` int DEFAULT 0,
  `appeal_status` tinyint DEFAULT 0 COMMENT '0无 1申诉中 2裁决收到 3裁决维持',
  `appeal_reason` text DEFAULT NULL,
  `comment_count` int DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_to` (`to_user_id`),
  KEY `idx_from` (`from_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 博文表 --------------------
DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `title` varchar(200) NOT NULL,
  `summary` varchar(500) DEFAULT '',
  `content` mediumtext COMMENT '富文本原始HTML（输出时过滤）',
  `cover` varchar(255) DEFAULT NULL,
  `category_id` bigint DEFAULT 0,
  `tags` varchar(255) DEFAULT '' COMMENT '逗号分隔，最多5个',
  `status` tinyint DEFAULT 2 COMMENT '0草稿 1待审核 2已发布 3驳回',
  `view_count` int DEFAULT 0,
  `like_count` int DEFAULT 0,
  `comment_count` int DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 博文分类表 --------------------
DROP TABLE IF EXISTS `post_categories`;
CREATE TABLE `post_categories` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `sort` int DEFAULT 0,
  `status` tinyint DEFAULT 1 COMMENT '1启用 0禁用',
  `is_default` tinyint DEFAULT 0 COMMENT '默认分类禁止删除',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 多态评论表 --------------------
DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `target_type` tinyint NOT NULL COMMENT '1博文 2打赏记录 3用户主页',
  `target_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `parent_id` bigint DEFAULT 0 COMMENT '0一级评论；二级回复指向一级评论id',
  `content` varchar(500) NOT NULL,
  `like_count` int DEFAULT 0,
  `status` tinyint DEFAULT 1 COMMENT '0待审核 1通过 2驳回',
  `is_audit` tinyint DEFAULT 0 COMMENT '0免审 1需审',
  `created_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 点赞表 --------------------
DROP TABLE IF EXISTS `likes`;
CREATE TABLE `likes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `target_type` tinyint NOT NULL COMMENT '1博文 2评论',
  `target_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_like` (`user_id`,`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 收藏表 --------------------
DROP TABLE IF EXISTS `favorites`;
CREATE TABLE `favorites` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `post_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fav` (`user_id`,`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 关注表 --------------------
DROP TABLE IF EXISTS `follows`;
CREATE TABLE `follows` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL COMMENT '被关注者',
  `follower_id` bigint NOT NULL COMMENT '关注者',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follow` (`user_id`,`follower_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 站内通知表 --------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `type` tinyint NOT NULL COMMENT '1评论 2回复 3@ 4点赞 5收藏 6关注 7收到打赏 8打赏异常 9申诉结果 10商户风控 12用户私信',
  `title` varchar(200) DEFAULT '',
  `content` varchar(500) DEFAULT '',
  `related_id` bigint DEFAULT 0,
  `from_user_id` bigint DEFAULT NULL COMMENT '发送者ID，系统/管理员通知为NULL',
  `is_read` tinyint DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 广告表 --------------------
DROP TABLE IF EXISTS `ads`;
CREATE TABLE `ads` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `slot` varchar(40) NOT NULL COMMENT '广告位标识',
  `title` varchar(100) DEFAULT '',
  `image` varchar(255) DEFAULT '',
  `url` varchar(255) DEFAULT '',
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `sort` int DEFAULT 0,
  `status` tinyint DEFAULT 1,
  `click_count` int DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slot` (`slot`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 收款码审核日志 --------------------
DROP TABLE IF EXISTS `qr_audit_logs`;
CREATE TABLE `qr_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `channel` tinyint NOT NULL COMMENT '1微信 2支付宝',
  `qr_type` tinyint NOT NULL DEFAULT 1 COMMENT '1个人 2商户',
  `qr_path` varchar(255) DEFAULT '',
  `status` tinyint DEFAULT 0 COMMENT '0待审 1通过 2驳回',
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 打赏核账裁决日志 --------------------
DROP TABLE IF EXISTS `tip_confirm_logs`;
CREATE TABLE `tip_confirm_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tip_id` bigint NOT NULL,
  `action` varchar(60) DEFAULT '' COMMENT '标记已收到/标记异常/撤销异常/裁决收到/裁决维持',
  `operator_type` tinyint DEFAULT 1 COMMENT '1用户 2管理员',
  `operator_id` bigint DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tip` (`tip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 博文审核日志 --------------------
DROP TABLE IF EXISTS `post_audit_logs`;
CREATE TABLE `post_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `post_id` bigint NOT NULL,
  `status` tinyint DEFAULT 0 COMMENT '1通过 2驳回',
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 评论审核日志 --------------------
DROP TABLE IF EXISTS `comment_audit_logs`;
CREATE TABLE `comment_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `comment_id` bigint NOT NULL,
  `status` tinyint DEFAULT 0,
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 举报表 --------------------
DROP TABLE IF EXISTS `reports`;
CREATE TABLE `reports` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `target_type` tinyint NOT NULL COMMENT '1博文 2评论 3收款码',
  `target_id` bigint NOT NULL,
  `reporter_id` bigint DEFAULT NULL,
  `reason` varchar(255) DEFAULT '',
  `status` tinyint DEFAULT 0 COMMENT '0待处理 1已处理',
  `handle_note` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 敏感词库 --------------------
DROP TABLE IF EXISTS `sensitive_words`;
CREATE TABLE `sensitive_words` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `word` varchar(100) NOT NULL,
  `strategy` tinyint DEFAULT 2 COMMENT '1拒绝发布 2替换***',
  `status` tinyint DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 管理员操作审计日志 --------------------
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `admin_id` bigint DEFAULT NULL,
  `admin_name` varchar(50) DEFAULT '',
  `action` varchar(100) DEFAULT '',
  `detail` text DEFAULT NULL,
  `ip` varchar(64) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 风控提醒日志 --------------------
DROP TABLE IF EXISTS `risk_remind_logs`;
CREATE TABLE `risk_remind_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `remind_type` tinyint NOT NULL COMMENT '1弹窗 2横幅 3站内信',
  `remark` varchar(500) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL COMMENT '管理员手动发送填id；系统自动为null',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 全局系统配置 --------------------
DROP TABLE IF EXISTS `system_setting`;
CREATE TABLE `system_setting` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `remark` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `system_setting` (`setting_key`,`setting_value`,`remark`) VALUES
('blog_audit_switch','off','博文审核总开关 on/off'),
('comment_audit_switch','off','评论审核总开关 on/off'),
('ip_reg_limit','5','同一IP每日最大注册数'),
('site_name','造富计划','站点名称（导航栏、标题、页脚）'),
('site_slogan','人人都可以收获来自社区的支持','站点描述/标语（导航栏副标题、meta description）'),
('site_logo','','站点 Logo 图片地址（/uploads/... 相对路径或完整外链，留空显示默认字标）'),
('risk_threshold1_days7_count','20','第一档7日笔数阈值'),
('risk_threshold1_days7_amount','10.00','第一档7日金额阈值'),
('risk_threshold2_days7_count','50','第二档7日笔数阈值'),
('risk_threshold2_days7_amount','30.00','第二档7日金额阈值'),
('register_invite_switch','off','on=仅邀请注册 off=公开注册'),
('pm_follow_required','off','on=发私信需先关注对方 off=任意用户可发私信'),
('qr_audit_mode','manual','manual=人工审核(需管理员手动审核) auto=自动审核(超时未审则系统自动通过)'),
('qr_auto_audit_minutes','5','自动审核模式下，超时多少分钟未人工审核则系统自动通过(默认5)'),
('富豪榜开关','off','on=在广场首页展示富豪榜 off=隐藏富豪榜(默认关闭)'),
('善人榜开关','off','on=在广场首页展示善人榜 off=隐藏善人榜(默认关闭)');

-- -------------------- 邀请码表 --------------------
DROP TABLE IF EXISTS `invite_codes`;
CREATE TABLE `invite_codes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `batch` varchar(32) DEFAULT NULL COMMENT '批次号',
  `owner_user_id` bigint DEFAULT NULL,
  `total_quota` int NOT NULL DEFAULT 1,
  `used_count` int NOT NULL DEFAULT 0,
  `expire_at` datetime DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 邀请码使用流水 --------------------
DROP TABLE IF EXISTS `invite_used_logs`;
CREATE TABLE `invite_used_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invite_code_id` bigint NOT NULL,
  `used_user_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`invite_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------- 管理员账号表 --------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'admin' COMMENT 'super/admin',
  `status` tinyint DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
