-- SO placement params schema migration.
-- Database: fgmm_f_data
--
-- Run this before publishing the new SO config page code.
-- This keeps placement_params data, migrating market_id into profile.
-- placement_params_log is rebuilt with the latest key fields because old logs
-- are not compatible with strategy + capability + profile + param_key.

ALTER TABLE `fgmm_f_data`.`placement_params`
    ADD COLUMN `strategy` varchar(32) NOT NULL DEFAULT 'fastbook' COMMENT 'strategy name' FIRST,
    ADD COLUMN `capability` varchar(32) NOT NULL DEFAULT 'placement' COMMENT 'strategy capability: placement/hedge/etc' AFTER `strategy`,
    ADD COLUMN `profile` varchar(64) NULL COMMENT 'config profile, migrated from old market_id' AFTER `capability`;

UPDATE `fgmm_f_data`.`placement_params`
SET `profile` = `market_id`
WHERE `profile` IS NULL OR `profile` = '';

ALTER TABLE `fgmm_f_data`.`placement_params`
    MODIFY COLUMN `profile` varchar(64) NOT NULL COMMENT 'config profile',
    MODIFY COLUMN `param_value` text NOT NULL COMMENT 'param value',
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (`strategy`, `capability`, `profile`, `param_key`);

ALTER TABLE `fgmm_f_data`.`placement_params`
    DROP COLUMN `market_id`;

DROP TABLE IF EXISTS `fgmm_f_data`.`placement_params_log`;

CREATE TABLE `fgmm_f_data`.`placement_params_log` (
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
