-- SO placement params change log table.
-- Database: fgmm_f_data

CREATE TABLE IF NOT EXISTS `placement_params_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'auto increment id',
  `strategy`    varchar(32)     NOT NULL DEFAULT '' COMMENT 'strategy name',
  `capability`  varchar(32)     NOT NULL DEFAULT '' COMMENT 'strategy capability',
  `profile`     varchar(64)     NOT NULL DEFAULT '' COMMENT 'config profile',
  `param_key`   varchar(64)     NOT NULL DEFAULT '' COMMENT 'param key',
  `operator`    varchar(64)     NOT NULL DEFAULT '' COMMENT 'operator username',
  `old_value`   text            DEFAULT NULL COMMENT 'old param value',
  `new_value`   text            DEFAULT NULL COMMENT 'new param value',
  `created_at`  datetime        DEFAULT NULL COMMENT 'operation time',
  PRIMARY KEY (`id`),
  KEY `idx_strategy_capability_profile_key` (`strategy`, `capability`, `profile`, `param_key`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='placement params change log';
