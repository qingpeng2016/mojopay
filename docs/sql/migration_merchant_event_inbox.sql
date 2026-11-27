CREATE TABLE IF NOT EXISTS `merchant_event_inbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `dedup_key` VARCHAR(180) COLLATE utf8mb4_bin NOT NULL COMMENT '余额/流水事件去重键',
  `event_type` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `payload` JSON NOT NULL COMMENT '余额流水事件数据',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '事件处理状态',
  `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '接收时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_inbox_dedup` (`merchant_account_id`, `dedup_key`),
  KEY `idx_merchant_inbox_status` (`status`, `received_at`),
  CONSTRAINT `fk_merchant_inbox_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商户余额快照与变动接收记录';