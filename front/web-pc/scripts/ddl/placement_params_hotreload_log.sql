-- SO hot reload operation log.
-- Database: fgmm_f_data
-- Records who triggered hot reload, when it happened, the Jenkins result,
-- and the placement_params snapshot at that moment.

CREATE TABLE IF NOT EXISTS `fgmm_f_data`.`placement_params_hotreload_log` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'auto increment id',
  `operator`      varchar(64)     NOT NULL DEFAULT '' COMMENT 'operator username',
  `status`        tinyint(1)      NOT NULL DEFAULT 0 COMMENT 'hot reload result: 1 success, 0 failed',
  `http_code`     int             NOT NULL DEFAULT 0 COMMENT 'Jenkins HTTP status code',
  `error_message` varchar(1024)   NOT NULL DEFAULT '' COMMENT 'failure reason or Jenkins response summary',
  `so_config`     json            NOT NULL COMMENT 'SO config snapshot when hot reload was triggered',
  `created_at`    datetime        NOT NULL COMMENT 'hot reload time',
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_operator_created` (`operator`, `created_at`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SO hot reload operation log';
