-- ============================================================
-- 造富计划 (Wealthy) 数据库建表脚本（完整 schema，幂等，可重复执行）
-- 字符集 utf8mb4 / InnoDB；所有建表均为 CREATE TABLE IF NOT EXISTS，重跑不会删除数据。
-- 推荐用 install.php 安装/修复（会自动补齐已有表缺失的列）；也可 mysql 客户端直接导入建全新库。
-- 执行：mysql -uroot -proot < database/install.sql   （或 php install.php / php install.php --repair）
-- ============================================================

CREATE DATABASE IF NOT EXISTS `wealthy` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `wealthy`;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `admin_id` bigint DEFAULT NULL,
  `admin_name` varchar(50) DEFAULT '',
  `action` varchar(100) DEFAULT '',
  `detail` text,
  `ip` varchar(64) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'admin' COMMENT 'super/admin',
  `status` tinyint DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ads` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `slot` varchar(40) NOT NULL COMMENT '广告位标识',
  `title` varchar(100) DEFAULT '',
  `image` varchar(255) DEFAULT '',
  `url` varchar(255) DEFAULT '',
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `sort` int DEFAULT '0',
  `status` tinyint DEFAULT '1',
  `click_count` int DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slot` (`slot`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `comment_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `comment_id` bigint NOT NULL,
  `status` tinyint DEFAULT '0',
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `target_type` tinyint NOT NULL COMMENT '1博文 2打赏记录 3用户主页',
  `target_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `parent_id` bigint DEFAULT '0' COMMENT '0一级评论；二级回复指向一级评论id',
  `content` varchar(500) NOT NULL,
  `like_count` int DEFAULT '0',
  `status` tinyint DEFAULT '1' COMMENT '0待审核 1通过 2驳回',
  `is_audit` tinyint DEFAULT '0' COMMENT '0免审 1需审',
  `created_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `favorites` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `post_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fav` (`user_id`,`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `follows` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL COMMENT '被关注者',
  `follower_id` bigint NOT NULL COMMENT '关注者',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follow` (`user_id`,`follower_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `invite_codes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `batch` varchar(32) DEFAULT NULL COMMENT '批次号',
  `owner_user_id` bigint DEFAULT NULL,
  `total_quota` int NOT NULL DEFAULT '1',
  `used_count` int NOT NULL DEFAULT '0',
  `expire_at` datetime DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用 0禁用',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `invite_used_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `invite_code_id` bigint NOT NULL,
  `used_user_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`invite_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `likes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `target_type` tinyint NOT NULL COMMENT '1博文 2评论',
  `target_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_like` (`user_id`,`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `type` tinyint NOT NULL COMMENT '1评论 2回复 3@ 4点赞 5收藏 6关注 7收到打赏 8打赏异常 9申诉结果 10商户风控',
  `title` varchar(200) DEFAULT '',
  `content` varchar(500) DEFAULT '',
  `related_id` bigint DEFAULT '0',
  `from_user_id` bigint DEFAULT NULL COMMENT '发送者ID，系统/管理员通知为NULL',
  `is_read` tinyint DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `points_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `type` varchar(32) NOT NULL COMMENT 'register_invite/invite_reward/invitee_bonus/checkin/post_reward/tip_reward/invite_code_cost/admin_grant/admin_deduct',
  `amount` int NOT NULL COMMENT '正数=增加，负数=扣除',
  `balance_after` int NOT NULL DEFAULT '0',
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `post_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `post_id` bigint NOT NULL,
  `status` tinyint DEFAULT '0' COMMENT '1通过 2驳回',
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `post_categories` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `sort` int DEFAULT '0',
  `status` tinyint DEFAULT '1' COMMENT '1启用 0禁用',
  `is_default` tinyint DEFAULT '0' COMMENT '默认分类禁止删除',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `title` varchar(200) NOT NULL,
  `summary` varchar(500) DEFAULT '',
  `content` mediumtext COMMENT '富文本原始HTML（输出时过滤）',
  `cover` varchar(255) DEFAULT NULL,
  `category_id` bigint DEFAULT '0',
  `tags` varchar(255) DEFAULT '' COMMENT '逗号分隔，最多5个',
  `status` tinyint DEFAULT '2' COMMENT '0草稿 1待审核 2已发布 3驳回',
  `view_count` int DEFAULT '0',
  `like_count` int DEFAULT '0',
  `comment_count` int DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `qr_audit_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `channel` tinyint NOT NULL COMMENT '1微信 2支付宝',
  `qr_type` tinyint NOT NULL DEFAULT '1' COMMENT '1个人 2商户',
  `qr_path` varchar(255) DEFAULT '',
  `status` tinyint DEFAULT '0' COMMENT '0待审 1通过 2驳回',
  `reason` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `target_type` tinyint NOT NULL COMMENT '1博文 2评论 3收款码',
  `target_id` bigint NOT NULL,
  `reporter_id` bigint DEFAULT NULL,
  `reason` varchar(255) DEFAULT '',
  `status` tinyint DEFAULT '0' COMMENT '0待处理 1已处理',
  `handle_note` varchar(255) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `risk_remind_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `remind_type` tinyint NOT NULL COMMENT '1弹窗 2横幅 3站内信',
  `remark` varchar(500) DEFAULT NULL,
  `admin_id` bigint DEFAULT NULL COMMENT '管理员手动发送填id；系统自动为null',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sensitive_words` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `word` varchar(100) NOT NULL,
  `strategy` tinyint DEFAULT '2' COMMENT '1拒绝发布 2替换***',
  `status` tinyint DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_setting` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text,
  `remark` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tip_confirm_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tip_id` bigint NOT NULL,
  `action` varchar(60) DEFAULT '' COMMENT '标记已收到/标记异常/撤销异常/裁决收到/裁决维持',
  `operator_type` tinyint DEFAULT '1' COMMENT '1用户 2管理员',
  `operator_id` bigint DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tip` (`tip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tips` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `from_user_id` bigint DEFAULT NULL COMMENT '游客可为null',
  `from_ip` varchar(64) DEFAULT '',
  `to_user_id` bigint NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `level` tinyint NOT NULL COMMENT '1/2/3 档位',
  `pay_type` tinyint NOT NULL COMMENT '1微信 2支付宝',
  `remark` varchar(100) DEFAULT NULL,
  `status` tinyint DEFAULT '0' COMMENT '0待确认 1已收到 2异常',
  `confirm_at` datetime DEFAULT NULL,
  `confirm_by` bigint DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_count` int DEFAULT '0',
  `appeal_status` tinyint DEFAULT '0' COMMENT '0无 1申诉中 2裁决收到 3裁决维持',
  `appeal_reason` text,
  `comment_count` int DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_to` (`to_user_id`),
  KEY `idx_from` (`from_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text,
  `wechat_qr` varchar(255) DEFAULT NULL,
  `wechat_qr_status` tinyint DEFAULT '0' COMMENT '0未上传 1待审核 2通过 3驳回',
  `wechat_qr_reason` varchar(255) DEFAULT NULL,
  `alipay_qr` varchar(255) DEFAULT NULL,
  `alipay_qr_status` tinyint DEFAULT '0',
  `alipay_qr_reason` varchar(255) DEFAULT NULL,
  `qr_type` tinyint DEFAULT '1' COMMENT '1个人码 2商户码',
  `merchant_qr_verified` tinyint DEFAULT '0',
  `risk_remind_count` int DEFAULT '0',
  `risk_remind_last_at` datetime DEFAULT NULL,
  `risk_banner_closed_at` datetime DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user',
  `status` tinyint DEFAULT '1' COMMENT '1正常 0禁用',
  `tip_total_amount` decimal(12,2) DEFAULT '0.00' COMMENT '有效收款总额（异常已扣减）',
  `tip_confirmed_count` int DEFAULT '0',
  `tip_rejected_count` int DEFAULT '0',
  `sent_tip_count` int DEFAULT '0',
  `post_count` int DEFAULT '0',
  `comment_count` int DEFAULT '0',
  `follower_count` int DEFAULT '0',
  `following_count` int DEFAULT '0',
  `last_login_at` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `experience` int NOT NULL DEFAULT '0',
  `level` int NOT NULL DEFAULT '1',
  `points` int NOT NULL DEFAULT '0',
  `last_checkin_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -------------------- 全局系统配置（初始值） --------------------
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

SET FOREIGN_KEY_CHECKS = 1;
