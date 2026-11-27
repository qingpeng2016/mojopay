-- SO 热更新密码保护、有效期、错误限流及密码修改审计。
-- 数据库：fgmm_f_data
-- 初始密码哈希首次使用时从 config/so_hotreload.php 写入数据库。
--
-- 密码功能的菜单权限位于 MIS 主库，可能与 fgmm_f_data 不在同一服务器。
-- 使用 scripts/apply_so_hotreload_password_20260916.sh，可分别通过对应数据库
-- 连接执行本 DDL 和配套权限迁移。

CREATE TABLE IF NOT EXISTS `fgmm_f_data`.`placement_params_hotreload_auth_config` (
  `id`                  tinyint unsigned NOT NULL COMMENT '单例主键，固定为 1',
  `password_hash`       varchar(255) NOT NULL COMMENT 'SO 热更新发布密码哈希',
  `password_changed_at` datetime NOT NULL COMMENT '密码最后修改时间，也是有效期起算时间',
  `created_at`          datetime NOT NULL COMMENT '记录创建时间',
  `updated_at`          datetime NOT NULL COMMENT '记录更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SO 热更新发布密码配置';

CREATE TABLE IF NOT EXISTS `fgmm_f_data`.`placement_params_hotreload_auth_attempt` (
  `attempt_key`     char(64) NOT NULL COMMENT '后台账号与来源 IP 组合后生成的 SHA-256 标识',
  `operator`        varchar(64) NOT NULL DEFAULT '' COMMENT '后台操作账号',
  `ip_address`      varchar(45) NOT NULL DEFAULT '' COMMENT '请求来源 IP 地址',
  `failed_attempts` int unsigned NOT NULL DEFAULT 0 COMMENT '当前统计窗口内连续密码错误次数',
  `first_failed_at` datetime NOT NULL COMMENT '当前统计窗口首次密码错误时间',
  `locked_until`    datetime NULL DEFAULT NULL COMMENT '锁定截止时间，未锁定时为空',
  `updated_at`      datetime NOT NULL COMMENT '记录更新时间',
  PRIMARY KEY (`attempt_key`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SO 热更新发布密码错误尝试及锁定状态';

CREATE TABLE IF NOT EXISTS `fgmm_f_data`.`placement_params_hotreload_password_log` (
  `id`                bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `operator`          varchar(64) NOT NULL DEFAULT '' COMMENT '修改密码的后台操作账号',
  `ip_address`        varchar(45) NOT NULL DEFAULT '' COMMENT '请求来源 IP 地址',
  `old_password_hash` varchar(255) NOT NULL COMMENT '修改前的密码哈希',
  `new_password_hash` varchar(255) NOT NULL COMMENT '修改后的密码哈希',
  `created_at`        datetime NOT NULL COMMENT '密码修改时间',
  PRIMARY KEY (`id`),
  KEY `idx_operator_created` (`operator`, `created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SO 热更新发布密码修改审计日志';
