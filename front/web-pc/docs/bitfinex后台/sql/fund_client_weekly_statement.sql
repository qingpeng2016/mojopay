-- 3.6.x 客户周账单（需在 db_bitfinex 库执行）
CREATE TABLE IF NOT EXISTS `fund_client_weekly_statement` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `client_id` bigint unsigned NOT NULL COMMENT 'fund_client.id',
    `week_start` date NOT NULL COMMENT '周起始（周一）',
    `week_end` date NOT NULL COMMENT '周结束（周日）',
    `statement_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FLOATING|GUARANTEED',
    `total_invest` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '列表展示：期末持仓本金',
    `total_profit` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '列表展示：本期净收益',
    `closing_principal` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '期末持仓本金（生成 PDF 时写入；下一周期初优先取此值）',
    `metrics_json` json NOT NULL COMMENT '生成 PDF 时的指标快照',
    `pdf_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `pdf_content` longblob DEFAULT NULL COMMENT 'PDF 二进制',
    `pdf_size` int unsigned NOT NULL DEFAULT 0 COMMENT 'PDF 字节数',
    `generated_at` datetime DEFAULT NULL,
    `generated_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fcws_client_week_type` (`client_id`,`week_start`,`statement_type`),
    KEY `idx_fcws_week` (`week_start`,`week_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户周账单';
