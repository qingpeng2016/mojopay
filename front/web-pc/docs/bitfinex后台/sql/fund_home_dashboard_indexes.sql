-- FundHome Dashboard 聚合查询推荐索引（db_bitfinex）
-- 可重复执行前请先 SHOW INDEX FROM <table> 确认是否已存在同名索引

-- 1) 池实际收益：ledger 付息 SUM(product_id) WHERE description = 'Margin Funding Payment...'
--    UTC 02:00 前还会按 CONVERT_TZ(mts) 过滤 UTC+8 日历日
ALTER TABLE `fund_bitfinex_ledger_hist`
    ADD INDEX `idx_fblh_product_desc_mts` (`product_id`, `description`(64), `mts`);

-- 2) 池实际收益：在贷 ACTIVE position SUM(amount * rate * 0.85) GROUP BY product_id
ALTER TABLE `fund_bitfinex_product_loan`
    ADD INDEX `idx_fbpl_product_status` (`product_id`, `status`);

-- 3) 保底负债 / 总利润：SUM(interest_day_amount) GROUP BY product_id
--    已有 idx_fcsad_product_date(product_id, accrual_date) 通常足够；若 EXPLAIN 仍全表扫可加：
-- ALTER TABLE `fund_client_subscription_accrual_daily`
--     ADD INDEX `idx_fcsad_product_only` (`product_id`);

-- 4) 总活跃客户数：COUNT(DISTINCT client_id) WHERE status = 'ACTIVE'
ALTER TABLE `fund_client_product_subscription`
    ADD INDEX `idx_fcps_status_client` (`status`, `client_id`);

-- 5) 产品卡：当日新申购 SUM(subscribe_amount) WHERE product_id + status + subscribe_date
ALTER TABLE `fund_client_product_subscription`
    ADD INDEX `idx_fcps_product_status_subdate` (`product_id`, `status`, `subscribe_date`);

-- 6) 产品卡：当日到期赎回 SUM(redeemable_amount) WHERE product_id + status + remaining_date
ALTER TABLE `fund_client_product_subscription`
    ADD INDEX `idx_fcps_product_status_remaining` (`product_id`, `status`, `remaining_date`);

-- 7) 产品卡：活跃客户 COUNT(DISTINCT client_id) WHERE product_id + status
--    idx_fcps_product_status_subdate 前缀 (product_id, status) 已可覆盖
