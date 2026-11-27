-- 客户各保底产品专属利率（client_guaranteed_rate）
-- 新建客户时按 fund_products.guaranteed_rate 初始化；申购时锁定为 guaranteed_rate_snapshot

CREATE TABLE IF NOT EXISTS `fund_client_guaranteed_rate` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
    `product_id` bigint unsigned NOT NULL COMMENT '引用 fund_products.id',
    `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
    `guaranteed_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '客户该产品保底年化（创建时默认=产品 guaranteed_rate）',
    `bonus` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '客户该产品 bonus（小数，如 0.01=1%；可在用户利率页修改）',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fcgr_client_product` (`client_id`, `product_id`),
    KEY `idx_fcgr_client` (`client_id`),
    KEY `idx_fcgr_product` (`product_id`),
    KEY `idx_fcgr_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户保底产品专属利率';

-- 已有客户补数（可选，按需执行一次）：
-- INSERT INTO fund_client_guaranteed_rate (client_id, product_id, product_code, guaranteed_rate)
-- SELECT c.id, p.id, p.product_code, p.guaranteed_rate
-- FROM fund_client c
-- CROSS JOIN fund_products p
-- WHERE p.product_type = 'GUARANTEED'
--   AND NOT EXISTS (
--       SELECT 1 FROM fund_client_guaranteed_rate r
--       WHERE r.client_id = c.id AND r.product_id = p.id
--   );
