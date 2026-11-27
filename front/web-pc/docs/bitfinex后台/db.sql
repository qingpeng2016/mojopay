SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- Bifinex 借贷策略 v3.0 — 理财产品业务层（表名前缀 fund_）
-- 与 docs/Bifinex借贷策略3.0.pdf 对齐；策略引擎表见 docs/数据库建表.sql
-- 依赖：accounts（策略引擎余额同步，经 fund_bitfinex_sub_accounts.accounts_id 可选关联）
--
-- 【用法】
--   空库：执行「Part 1」建表 + 示例数据 + 调度任务即可。
--   已有库：勿重复 Part 1 建表；按需执行「Part 2」增量迁移（按序号顺序）。
-- =============================================================================

-- =============================================================================
-- Part 1：空库建表 + 示例数据 + 调度任务
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 客户信息：id 为主键，业务侧以 id 关联
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client` (
                               `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                               `name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '客户名称',
                               `email` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                               `phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                               `client_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual' COMMENT 'individual|institution',
                               `kyc_status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
                               `country` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                               `external_ref` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上游系统/Bitfinex 用户别名',
                               `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|frozen|closed',
                               `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                               `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                               PRIMARY KEY (`id`),
                               KEY `idx_fund_client_email` (`email`),
                               KEY `idx_fund_client_phone` (`phone`),
                               KEY `idx_fund_client_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户信息';

-- ---------------------------------------------------------------------------
-- 客户出入金钱包
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_wallet` (
                                      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                      `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                      `currency` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD' COMMENT 'USDT|USD',
                                      `available_balance` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '已入金、未申购现金',
                                      `subscribed_balance` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '已申购、在理财产品中的本金合计',
                                      `frozen_balance` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '冻结（待核实/待划转）',
                                      `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|frozen|closed',
                                      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                      PRIMARY KEY (`id`),
                                      UNIQUE KEY `uk_fcw_client_cur` (`client_id`,`currency`),
                                      KEY `idx_fcw_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户出入金钱包';

-- ---------------------------------------------------------------------------
-- 客户保底产品专属利率（一客户 × 一保底产品一行）
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_guaranteed_rate` (
                                               `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                               `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                               `product_id` bigint unsigned NOT NULL COMMENT '引用 fund_products.id',
                                               `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                               `guaranteed_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '客户该产品保底年化（创建时默认=产品 guaranteed_rate）',
                                               `bonus` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '客户该产品 bonus（小数，如 0.01=1%）',
                                               `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                               `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                               PRIMARY KEY (`id`),
                                               UNIQUE KEY `uk_fcgr_client_product` (`client_id`,`product_id`),
                                               KEY `idx_fcgr_client` (`client_id`),
                                               KEY `idx_fcgr_product` (`product_id`),
                                               KEY `idx_fcgr_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户保底产品专属利率';

-- ---------------------------------------------------------------------------
-- 客户钱包流水
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_wallet_flow` (
                                           `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                           `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                           `currency` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
                                           `flow_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'DEPOSIT|WITHDRAW|SUBSCRIBE_OUT|REDEEM_IN|ADJUST',
                                           `amount` decimal(28,8) NOT NULL COMMENT '流水金额（正数；方向由 flow_type 区分）',
                                           `balance_after` decimal(28,8) NOT NULL DEFAULT 0.00000000,
                                           `ref_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'deposit|subscription|redemption|manual',
                                           `ref_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '关联业务主键',
                                           `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                           `operator` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '财务/系统操作人',
                                           `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                           PRIMARY KEY (`id`),
                                           KEY `idx_fcwf_client_t` (`client_id`,`created_at`),
                                           KEY `idx_fcwf_ref` (`ref_type`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户钱包流水';

-- ---------------------------------------------------------------------------
-- 理财产品：1 产品 = 1 Bitfinex 子账号（凭证见 fund_bitfinex_sub_accounts）
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_products` (
                                 `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'product_id',
                                 `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务编码，如 GUARANTEED_D30_USD',
                                 `product_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '展示名称',
                                 `product_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'GUARANTEED|FLOATING',
                                 `term` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FLEXIBLE|D7|D14|D30|D60|D90|D120',
                                 `term_days` int NOT NULL DEFAULT 0 COMMENT '名义锁定期(天)；活期=0',
                                 `bitfinex_sub_account_id` bigint unsigned DEFAULT NULL COMMENT 'fund_bitfinex_sub_accounts.id，1:1',
                                 `funding_symbol` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fUSD' COMMENT 'fUSD|fUST',
                                 `currency` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
                                 `launch_date` date DEFAULT NULL COMMENT '产品发行/上线日；浮动型 NAV 基准日',
                                 `value_timezone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Shanghai' COMMENT '计息/日终结算时区（UTC+8）',
                                 `total_shares` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '浮动型：已发行总份额',
                                 `nav_per_share` decimal(28,10) NOT NULL DEFAULT 1.0000000000 COMMENT '浮动型：每份额净值',
                                 `cash_total` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '资产合计 cash_available+cash_pending+cash_lending',
                                 `cash_available` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '闲置可部署现金（业务手动维护）',
                                 `cash_available_free` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '剩余可用资金（=cash_available-cash_pending-cash_lending）',
                                 `cash_pending` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '挂单中 PENDING offer 占用',
                                 `cash_lending` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '在借 ACTIVE 本金',
                                 `guaranteed_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '保底型：保底年化',
                                 `redemption_buffer` decimal(6,4) NOT NULL DEFAULT 0.0100 COMMENT '浮动型 O(d) buffer，默认 1%',
                                 `min_funding_offer_usd` decimal(28,8) NOT NULL DEFAULT 150.00000000 COMMENT '单笔最小挂单名义',
                                 `placement_period` int NOT NULL DEFAULT 2 COMMENT '铺单期限',
                                 `cash_reserve` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '保留闲置，Gap 计算扣减',
                                 `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|paused|closed',
                                 `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                 `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                 `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                 PRIMARY KEY (`id`),
                                 UNIQUE KEY `uk_fp_code` (`product_code`),
                                 UNIQUE KEY `uk_fp_sub_account` (`bitfinex_sub_account_id`),
                                 KEY `idx_fp_type_term` (`product_type`,`term`),
                                 KEY `idx_fp_symbol` (`funding_symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 理财产品';

-- ---------------------------------------------------------------------------
-- Bitfinex 子账号：API 凭证 + 产品绑定
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_bitfinex_sub_accounts` (
                                              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                              `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id，1:1',
                                              `account_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '子账号展示名',
                                              `external_subaccount_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Bitfinex 平台子账号 ID/别名',
                                              `accounts_id` bigint unsigned DEFAULT NULL COMMENT 'accounts.id，策略引擎余额同步（可选）',
                                              `api_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Bitfinex API Key',
                                              `api_secret` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Bitfinex API Secret（建议应用层加密后入库）',
                                              `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|disabled|closed',
                                              `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              PRIMARY KEY (`id`),
                                              UNIQUE KEY `uk_fbssa_product` (`product_id`),
                                              UNIQUE KEY `uk_fbssa_accounts` (`accounts_id`),
                                              KEY `idx_fbssa_external` (`external_subaccount_id`),
                                              KEY `idx_fbssa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 Bitfinex 子账号';

-- ---------------------------------------------------------------------------
-- 浮动型日终净值
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_product_nav_daily` (
                                          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                          `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                          `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                          `nav_date` date NOT NULL COMMENT '结算日（日终 UTC+8 对应日历日）',
                                          `nav_per_share` decimal(28,10) NOT NULL COMMENT '当日结算每份额净值',
                                          `cash_total` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '当日资产合计（cash_available+cash_pending+cash_lending 快照）',
                                          `total_shares` decimal(28,10) NOT NULL DEFAULT 0.0000000000,
                                          `r_pool_vwap` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '产品内在借加权平均日利率',
                                          `interest_accrued_day` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '当日计提利息',
                                          `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'final' COMMENT 'draft|final',
                                          `calculated_at` datetime NOT NULL COMMENT '结算完成时间',
                                          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                          PRIMARY KEY (`id`),
                                          UNIQUE KEY `uk_fpnd_product_date` (`product_id`,`nav_date`),
                                          KEY `idx_fpnd_product_t` (`product_id`,`nav_date`),
                                          KEY `idx_fpnd_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 浮动产品日终净值';

-- ---------------------------------------------------------------------------
-- 流动性曲线 O(d)/G(d)：由 fund_product_liquidity_calculator 每日写入
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_product_liquidity_daily` (
                                                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '引用 fund_products.product_code',
                                                `calc_date` date NOT NULL COMMENT '计算批次日（脚本执行所在日历日）',
                                                `liquidity_date` date NOT NULL COMMENT '曲线日期 d（O(d)/G(d) 对应的未来日）',
                                                `obligation_o` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT 'O(d)：截至 d 日累计应付现金',
                                                `return_g` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT 'G(d)：截至 d 日累计保证回笼（本金+预计利息）',
                                                `shortfall` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Flow I：max(0,gap-lock_applied)，锁仓后仍无法覆盖的兑付缺口',
                                                `calc_data_json` json NOT NULL DEFAULT (json_object()) COMMENT '计算中间值快照 {inputs,o,g,gap}',
                                                `calculated_at` datetime NOT NULL COMMENT '本条记录计算完成时间',
                                                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                PRIMARY KEY (`id`),
                                                UNIQUE KEY `uk_fpld_product_calc_liq` (`product_id`,`calc_date`,`liquidity_date`),
                                                KEY `idx_fpld_product_calc` (`product_id`,`calc_date`),
                                                KEY `idx_fpld_product_liq` (`product_id`,`liquidity_date`),
                                                KEY `idx_fpld_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品流动性曲线 O(d)/G(d) 日快照';

-- ---------------------------------------------------------------------------
-- 客户申购（负债侧）
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_product_subscription` (
                                                    `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'sub_id',
                                                    `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                    `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                                    `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                                    `currency` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
                                                    `subscribe_amount` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '当前剩余计息本金（GUARANTEED；申购时=申购额，部分赎回扣减）',
                                                    `floating_shares` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：当前剩余份额（申购时=申购份额，部分赎回扣减）',
                                                    `floating_nav_at_subscribe` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：成交所用净值',
                                                    `subscribe_date` date NOT NULL COMMENT '申购日期',
                                                    `start_interest_date` date NOT NULL COMMENT '计息开始日（含）；= subscribe_date+1，T+1 起息',
                                                    `term_days` int NOT NULL DEFAULT 0 COMMENT '期限天数；活期=0',
                                                    `end_interest_date` date DEFAULT NULL COMMENT '计息结束日（含）；活期 NULL',
                                                    `remaining_date` date DEFAULT NULL COMMENT '可赎回/提现日（= end_interest_date+1；活期 NULL）',
                                                    `guaranteed_rate_snapshot` decimal(28,10) DEFAULT NULL COMMENT '保底型：申购时锁定保底年化',
                                                    `current_interest` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT 'GUARANTEED：当前利息（止息日=valuation_date-1，不含估值当天）；FLOATING 不使用',
                                                    `redeemable_amount` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '当前可赎总额（GUARANTEED=subscribe_amount+current_interest；FLOATING=floating_shares×valuation日净值）',
                                                    `valuation_date` date DEFAULT NULL COMMENT '可赎快照估值日（与 accrual_date 对齐）',
                                                    `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|REDEEMED（剩余为 0 时 REDEEMED；部分赎回仍为 ACTIVE）',
                                                    `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                                    `remaining_jason` json NOT NULL DEFAULT (json_object('partial_redemptions', json_array(), 'full_redemptions', json_array())) COMMENT '赎回历史 {partial_redemptions:[...], full_redemptions:[...]}',
                                                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                    PRIMARY KEY (`id`),
                                                    KEY `idx_fcps_product_client` (`product_id`,`client_id`),
                                                    KEY `idx_fcps_end_interest` (`product_id`,`end_interest_date`,`status`),
                                                    KEY `idx_fcps_remaining` (`product_id`,`remaining_date`,`status`),
                                                    KEY `idx_fcps_client` (`client_id`,`status`),
                                                    KEY `idx_fcps_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户理财产品申购（负债）';

-- ---------------------------------------------------------------------------
-- 客户赎回单
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_product_redemption` (
                                                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                  `subscription_id` bigint unsigned NOT NULL COMMENT 'fund_client_product_subscription.id',
                                                  `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                  `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                                  `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                                  `currency` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
                                                  `redemption_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FULL|PARTIAL',
                                                  `due_date` date DEFAULT NULL COMMENT '计划兑付日；到期预约填申购 remaining_date',
                                                  `guaranteed_annual_rate` decimal(28,10) DEFAULT NULL COMMENT 'GUARANTEED：保底年利率快照',
                                                  `guaranteed_holding_days` int DEFAULT NULL COMMENT 'GUARANTEED：实际计息天数',
                                                  `floating_request_shares` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：赎回申请份额',
                                                  `floating_nav_date` date DEFAULT NULL COMMENT 'FLOATING：成交所用日终净值日期',
                                                  `floating_nav_per_share` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：成交 NAV',
                                                  `redemption_amount` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT '兑付金额（GUARANTEED/FLOATING 共用）',
                                                  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'scheduled|pending|nav_pending|paid|cancelled|failed',
                                                  `requested_at` datetime NOT NULL COMMENT '提交时间',
                                                  `settled_at` datetime DEFAULT NULL COMMENT '兑付完成时间',
                                                  `wallet_flow_id` bigint unsigned DEFAULT NULL COMMENT 'fund_client_wallet_flow.id（REDEEM_IN）',
                                                  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                                  `is_replacement` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已因重新铺单处理',
                                                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                  PRIMARY KEY (`id`),
                                                  KEY `idx_fcpr_sub` (`subscription_id`),
                                                  KEY `idx_fcpr_product_st` (`product_id`,`status`),
                                                  KEY `idx_fcpr_client` (`client_id`,`status`),
                                                  KEY `idx_fcpr_floating_nav_date` (`product_id`,`floating_nav_date`),
                                                  KEY `idx_fcpr_product_code` (`product_code`),
                                                  KEY `idx_fcpr_due_date` (`product_id`,`due_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户理财产品赎回单';

-- ---------------------------------------------------------------------------
-- 客户申购日计提：每笔 subscription × 每个 accrual_date 一条
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_client_subscription_accrual_daily` (
                                                          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                          `subscription_id` bigint unsigned NOT NULL COMMENT 'fund_client_product_subscription.id',
                                                          `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                          `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                                          `client_id` bigint unsigned NOT NULL COMMENT '引用 fund_client.id',
                                                          `accrual_date` date NOT NULL COMMENT '计提日（产品 value_timezone 日历日，与 fund_product_nav_daily.nav_date 对齐）',
                                                          `guaranteed_principal_base` decimal(28,8) NOT NULL DEFAULT 0.00000000 COMMENT 'GUARANTEED：当日计息本金（= subscription.subscribe_amount 快照）',
                                                          `guaranteed_annual_rate` decimal(28,10) DEFAULT NULL COMMENT 'GUARANTEED：申购锁定年化（guaranteed_rate_snapshot 快照）',
                                                          `floating_shares_base` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：当日计息份额（= subscription.floating_shares 快照）',
                                                          `floating_nav_per_share` decimal(28,10) DEFAULT NULL COMMENT 'FLOATING：当日产品 NAV（fund_product_nav_daily 快照）',
                                                          `interest_day_amount` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '当日应计利息',
                                                          `floating_market_value` decimal(28,8) DEFAULT NULL COMMENT 'FLOATING：当日市值 floating_shares_base×floating_nav_per_share',
                                                          `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'final' COMMENT 'draft|final',
                                                          `calculated_at` datetime NOT NULL COMMENT '本条计算完成时间',
                                                          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                          PRIMARY KEY (`id`),
                                                          UNIQUE KEY `uk_fcsad_sub_date` (`subscription_id`,`accrual_date`),
                                                          KEY `idx_fcsad_product_date` (`product_id`,`accrual_date`),
                                                          KEY `idx_fcsad_client_date` (`client_id`,`accrual_date`),
                                                          KEY `idx_fcsad_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 客户申购日计提';

-- ---------------------------------------------------------------------------
-- Bitfinex 产品放贷（资产侧）：结构同 funding_lending_position + product 维度
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_bitfinex_product_loan` (
                                              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                              `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                              `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '引用 fund_products.product_code',
                                              `position_id` bigint unsigned NOT NULL COMMENT 'Bitfinex funding credit ID（REST 元组 [0]）',
                                              `symbol` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                              `side` int NOT NULL DEFAULT 0 COMMENT '1=lender 等，见官方文档',
                                              `amount` decimal(28,8) NOT NULL,
                                              `rate` decimal(28,10) NOT NULL COMMENT '日利率',
                                              `period_days` int NOT NULL DEFAULT 0,
                                              `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Bitfinex STATUS 原文，如 ACTIVE',
                                              `lifecycle` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lending' COMMENT 'lending|settled；常量见 application/strategy/common/enum/enum.go',
                                              `mts_create` bigint NOT NULL DEFAULT 0,
                                              `mts_update` bigint NOT NULL DEFAULT 0,
                                              `mts_opening` bigint NOT NULL DEFAULT 0,
                                              `mts_last_payout` bigint NOT NULL DEFAULT 0 COMMENT 'Bitfinex MTS_LAST_PAYOUT（ms），与利息周期相关',
                                              `rate_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'FIXED / VAR 等',
                                              `position_pair` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'POSITION_PAIR，如 tETHUSD',
                                              `order_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '可选关联 Bitfinex funding order id',
                                              `interest_accrued` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '累计/已计提利息占位；REST 不直接给出时可保持 0，可后续接 ledger',
                                              `interest_ledger_json` json NOT NULL DEFAULT (json_array()) COMMENT '付息归因明细 [{bfx_ledger_id,amount,method,mts}]',
                                              `monthly_yield_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000,
                                              `annual_yield_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000,
                                              `raw_row_json` json NOT NULL COMMENT 'Bitfinex 返回行原样',
                                              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                              PRIMARY KEY (`id`),
                                              UNIQUE KEY `uk_fbpl_product_position` (`product_id`,`position_id`),
                                              KEY `idx_fbpl_product_lc` (`product_id`,`lifecycle`,`status`),
                                              KEY `idx_fbpl_sym_lc` (`symbol`,`lifecycle`),
                                              KEY `idx_fbpl_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品在贷 position 快照（fund_bitfinex_product_loan_sync 按子账号同步）';

-- ---------------------------------------------------------------------------
-- 产品子账号账本历史（LedgersHist per product sub-account）
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_bitfinex_ledger_hist` (
                                             `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                             `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                             `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '引用 fund_products.product_code',
                                             `bfx_ledger_id` bigint NOT NULL COMMENT 'API [0]',
                                             `currency` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '[1]',
                                             `wallet` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '[2] exchange|margin|funding',
                                             `mts` bigint NOT NULL COMMENT '[3] 毫秒时间戳',
                                             `amount` decimal(28,10) NOT NULL COMMENT '[5]',
                                             `balance_after` decimal(28,10) DEFAULT NULL COMMENT '[6]，缺省 NULL',
                                             `description` varchar(4000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '[8]',
                                             `raw_row_json` json NOT NULL COMMENT '整行 ARRAY 原样',
                                             `fetched_at_ms` bigint NOT NULL COMMENT '本批入库 Unix 毫秒',
                                             `cash_available_applied` tinyint(1) NOT NULL DEFAULT 0 COMMENT '付息是否已累加至 fund_products.cash_available',
                                             `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                             `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                             PRIMARY KEY (`id`),
                                             UNIQUE KEY `uk_fblh_product_bfx_ledger` (`product_id`,`bfx_ledger_id`),
                                             KEY `idx_fblh_product_mts` (`product_id`,`mts`),
                                             KEY `idx_fblh_wallet_mts` (`wallet`,`mts`),
                                             KEY `idx_fblh_cur_mts` (`currency`,`mts`),
                                             KEY `idx_fblh_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品子账号账本历史（fund_bitfinex_ledger_hist_ingester）';

-- ---------------------------------------------------------------------------
-- 产品子账号资金挂单 / 成交（对齐 funding_orders / funding_trades + product_id/product_code）
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_bitfinex_funding_orders` (
                                                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '引用 fund_products.product_code',
                                                `snapshot_id` bigint unsigned DEFAULT NULL COMMENT '本 placement 批 funding_orders_snapshot.id',
                                                `symbol` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `scene_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '下单场景：placement|rate_monitor 等',
                                                `scene_sub_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '子场景：placement→conservative|balanced|aggressive；rate_monitor→case1|case2|case3',
                                                `layer_code` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                                `step_index` int NOT NULL DEFAULT 0,
                                                `split_index` int NOT NULL DEFAULT 0,
                                                `order_id` bigint unsigned DEFAULT NULL COMMENT 'Bitfinex 侧 funding offer / order id',
                                                `funding_trade_ids` json NOT NULL DEFAULT (json_array()) COMMENT '本库 fund_bitfinex_funding_trades.id 列表',
                                                `amount_usd` decimal(28,8) NOT NULL,
                                                `rate` decimal(28,10) NOT NULL,
                                                `origin_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '首次落库利率（与当时 rate 一致）；递减只改 rate',
                                                `period_days` int NOT NULL,
                                                `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|submitted|failed|closed|trade',
                                                `section12_json` json NOT NULL COMMENT '与本次 snapshot 一致的 §1.2 副本',
                                                `section13_json` json NOT NULL COMMENT '与本次 snapshot 一致的 §1.3 副本',
                                                `section14_json` json NOT NULL COMMENT '与本次 snapshot 一致的 §1.4 副本',
                                                `request_json` json DEFAULT NULL,
                                                `api_response_json` json DEFAULT NULL,
                                                `error_message` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                                `calc_data_json` json NOT NULL DEFAULT (json_object()) COMMENT '计算中间值快照 {inputs,o,g,gap,step}',
                                                `decay_base_at` datetime DEFAULT NULL COMMENT '递减 idle 计时起点',
                                                `decay_count` int NOT NULL DEFAULT 0 COMMENT '递减改价次数',
                                                `decay_min_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '借方同期限最优买价快照（日率）',
                                                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                PRIMARY KEY (`id`),
                                                KEY `idx_fbfpo_product_sym` (`product_id`,`symbol`,`created_at`),
                                                KEY `idx_fbfpo_snapshot` (`snapshot_id`),
                                                KEY `idx_fbfpo_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品子账号资金 LIMIT offer（fund_bitfinex_funding_order_updater）';

CREATE TABLE `fund_bitfinex_funding_trades` (
                                                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '引用 fund_products.product_code',
                                                `funding_order_id` bigint unsigned DEFAULT NULL COMMENT '本库 fund_bitfinex_funding_orders.id',
                                                `order_id` bigint unsigned NOT NULL DEFAULT 0,
                                                `trade_id` bigint NOT NULL COMMENT 'Bitfinex funding trade ID',
                                                `symbol` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `mts_create` bigint NOT NULL DEFAULT 0 COMMENT '成交时间 ms',
                                                `amount` decimal(28,8) NOT NULL,
                                                `rate` decimal(28,10) NOT NULL,
                                                `period_days` int NOT NULL DEFAULT 0,
                                                `fee` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '手续费',
                                                `profit` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '扣费后预估放款利差',
                                                `monthly_yield_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000,
                                                `annual_yield_rate` decimal(28,10) NOT NULL DEFAULT 0.0000000000,
                                                `raw_row_json` json NOT NULL COMMENT 'Bitfinex 返回行原样 JSON',
                                                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                PRIMARY KEY (`id`),
                                                UNIQUE KEY `uk_fbfpt_product_trade` (`product_id`,`trade_id`),
                                                KEY `idx_fbfpt_fo` (`funding_order_id`),
                                                KEY `idx_fbfpt_order` (`order_id`),
                                                KEY `idx_fbfpt_sym_t` (`symbol`,`created_at`),
                                                KEY `idx_fbfpt_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品子账号资金成交（fund_bitfinex_funding_trade_updater）';

-- ---------------------------------------------------------------------------
-- 产品策略参数
-- ---------------------------------------------------------------------------
CREATE TABLE `fund_product_strategy_config` (
                                                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                                                `product_id` bigint unsigned NOT NULL COMMENT 'fund_products.id',
                                                `product_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '引用 fund_products.product_code',
                                                `item_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `item_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `value_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string' COMMENT 'string|int|float|decimal|json',
                                                `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                                                `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
                                                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                                PRIMARY KEY (`id`),
                                                UNIQUE KEY `uk_fpsc_product_key` (`product_id`,`item_key`),
                                                KEY `idx_fpsc_product` (`product_id`),
                                                KEY `idx_fpsc_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='v3 产品策略键值';

-- ---------------------------------------------------------------------------
-- 默认策略键（写入 fund_product_strategy_config；per-product）
-- cash_pool_lending_ratio                   活期资金池放贷占比
-- rate_last_eat_threshold     0.033
-- rate_top10_avg_threshold    0.03
-- auto_renew_enabled          0

-- topN 成交利率均值回溯小时数、floor_rate、rate_monitor_avg_top_10 写入 funding_config_strategy（group_name=fund）：
-- INSERT INTO `funding_config_strategy` (`group_name`, `item_key`, `item_value`, `value_type`, `description`, `sort_no`, `is_enabled`)
-- VALUES
--   ('fund', 'market_trade_top_rates_lookback_hours', '3', 'int', 'topN 成交利率均值回溯小时数', 1, 1),
--   ('fund', 'floor_rate', '0.0003301', 'float', '§5 触发阈值：最新市况成交利率 r 须严格大于该值', 2, 1),
--   ('fund', 'rate_monitor_avg_top_10', '0.0003001', 'float', 'case1/case4：近 N 小时 top10 均值比较阈值', 3, 1);

-- ---------------------------------------------------------------------------
-- 示例数据（仅空库初始化；重复执行请改 product_code 或先清理）
-- 顺序：fund_client → fund_products → fund_bitfinex_sub_accounts → 回写子账号绑定
-- ---------------------------------------------------------------------------

INSERT INTO `fund_client` (`name`, `email`, `phone`, `client_type`, `kyc_status`, `country`, `external_ref`, `status`, `remark`)
VALUES ('演示客户', 'demo@example.com', '+8613800000000', 'individual', 'approved', 'CN', '', 'active', 'v3 示例客户');

-- 14 产品 = GUARANTEED|FLOATING × FLEXIBLE|D7|D14|D30|D60|D90|D120（bitfinex_sub_account_id 稍后 UPDATE）
INSERT INTO `fund_products` (`id`, `product_code`, `product_name`, `product_type`, `term`, `term_days`, `funding_symbol`, `currency`, `launch_date`, `guaranteed_rate`, `placement_period`, `status`, `remark`)
VALUES
    (1, 'GUARANTEED_FLEXIBLE_USD', '保底活期 USD', 'GUARANTEED', 'FLEXIBLE', 0, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (2, 'GUARANTEED_D7_USD', '保底7天 USD', 'GUARANTEED', 'D7', 7, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (3, 'GUARANTEED_D14_USD', '保底14天 USD', 'GUARANTEED', 'D14', 14, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (4, 'GUARANTEED_D30_USD', '保底30天 USD', 'GUARANTEED', 'D30', 30, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (5, 'GUARANTEED_D60_USD', '保底60天 USD', 'GUARANTEED', 'D60', 60, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (6, 'GUARANTEED_D90_USD', '保底90天 USD', 'GUARANTEED', 'D90', 90, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (7, 'GUARANTEED_D120_USD', '保底120天 USD', 'GUARANTEED', 'D120', 120, 'fUSD', 'USD', NULL, 0.0800000000, 2, 'active', 'v3 示例'),
    (8, 'FLOATING_FLEXIBLE_USD', '浮动活期 USD', 'FLOATING', 'FLEXIBLE', 0, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (9, 'FLOATING_D7_USD', '浮动7天 USD', 'FLOATING', 'D7', 7, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (10, 'FLOATING_D14_USD', '浮动14天 USD', 'FLOATING', 'D14', 14, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (11, 'FLOATING_D30_USD', '浮动30天 USD', 'FLOATING', 'D30', 30, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (12, 'FLOATING_D60_USD', '浮动60天 USD', 'FLOATING', 'D60', 60, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (13, 'FLOATING_D90_USD', '浮动90天 USD', 'FLOATING', 'D90', 90, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例'),
    (14, 'FLOATING_D120_USD', '浮动120天 USD', 'FLOATING', 'D120', 120, 'fUSD', 'USD', '2026-06-01', 0.0000000000, 2, 'active', 'v3 示例');

-- 1 产品 = 1 Bitfinex 子账号（api_key/api_secret 请替换为真实凭证；accounts_id 可选关联策略引擎 accounts.id）
INSERT INTO `fund_bitfinex_sub_accounts` (`id`, `product_id`, `account_name`, `external_subaccount_id`, `accounts_id`, `api_key`, `api_secret`, `status`, `remark`)
VALUES
    (1, 1, 'GUARANTEED_FLEXIBLE_USD', 'bfx_sub_01', NULL, 'REPLACE_API_KEY_01', 'REPLACE_API_SECRET_01', 'active', 'v3 示例'),
    (2, 2, 'GUARANTEED_D7_USD', 'bfx_sub_02', NULL, 'REPLACE_API_KEY_02', 'REPLACE_API_SECRET_02', 'active', 'v3 示例'),
    (3, 3, 'GUARANTEED_D14_USD', 'bfx_sub_03', NULL, 'REPLACE_API_KEY_03', 'REPLACE_API_SECRET_03', 'active', 'v3 示例'),
    (4, 4, 'GUARANTEED_D30_USD', 'bfx_sub_04', NULL, 'REPLACE_API_KEY_04', 'REPLACE_API_SECRET_04', 'active', 'v3 示例'),
    (5, 5, 'GUARANTEED_D60_USD', 'bfx_sub_05', NULL, 'REPLACE_API_KEY_05', 'REPLACE_API_SECRET_05', 'active', 'v3 示例'),
    (6, 6, 'GUARANTEED_D90_USD', 'bfx_sub_06', NULL, 'REPLACE_API_KEY_06', 'REPLACE_API_SECRET_06', 'active', 'v3 示例'),
    (7, 7, 'GUARANTEED_D120_USD', 'bfx_sub_07', NULL, 'REPLACE_API_KEY_07', 'REPLACE_API_SECRET_07', 'active', 'v3 示例'),
    (8, 8, 'FLOATING_FLEXIBLE_USD', 'bfx_sub_08', NULL, 'REPLACE_API_KEY_08', 'REPLACE_API_SECRET_08', 'active', 'v3 示例'),
    (9, 9, 'FLOATING_D7_USD', 'bfx_sub_09', NULL, 'REPLACE_API_KEY_09', 'REPLACE_API_SECRET_09', 'active', 'v3 示例'),
    (10, 10, 'FLOATING_D14_USD', 'bfx_sub_10', NULL, 'REPLACE_API_KEY_10', 'REPLACE_API_SECRET_10', 'active', 'v3 示例'),
    (11, 11, 'FLOATING_D30_USD', 'bfx_sub_11', NULL, 'REPLACE_API_KEY_11', 'REPLACE_API_SECRET_11', 'active', 'v3 示例'),
    (12, 12, 'FLOATING_D60_USD', 'bfx_sub_12', NULL, 'REPLACE_API_KEY_12', 'REPLACE_API_SECRET_12', 'active', 'v3 示例'),
    (13, 13, 'FLOATING_D90_USD', 'bfx_sub_13', NULL, 'REPLACE_API_KEY_13', 'REPLACE_API_SECRET_13', 'active', 'v3 示例'),
    (14, 14, 'FLOATING_D120_USD', 'bfx_sub_14', NULL, 'REPLACE_API_KEY_14', 'REPLACE_API_SECRET_14', 'active', 'v3 示例');

UPDATE `fund_products` SET `bitfinex_sub_account_id` = `id` WHERE `id` BETWEEN 1 AND 14;

-- ---------------------------------------------------------------------------
-- v3 调度任务（bot_schedule_config，module=bitfinex）
-- 已实现：fund_product_liquidity_calculator、fund_product_cash_sync、fund_product_nav_settlement、fund_client_subscription_accrual_settlement、fund_client_subscription_redemption_settlement
-- ---------------------------------------------------------------------------
INSERT INTO `bot_schedule_config` (`module`, `task_name`, `interval_seconds`, `concurrency`, `description`, `is_enabled`, `is_strategy_enabled`, `last_live_time`, `last_live_time_by_machine`, `is_primary_machine_run`)
VALUES
    ('bitfinex', 'fund_product_liquidity_calculator', 3600, 1, 'v3 计算 O(d)/G(d)/shortfall 写入 fund_product_liquidity_daily', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_product_cash_sync', 300, 1, 'v3 从 Bitfinex 子账号同步 fund_products 现金字段与 nav_per_share', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_product_nav_settlement', 3600, 1, 'v3 浮动产品日终净值结算 fund_product_nav_daily', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_client_subscription_accrual_settlement', 3600, 1, 'v3 客户申购日计提 fund_client_subscription_accrual_daily', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_client_subscription_redemption_settlement', 3600, 1, 'v3 到期赎回兑付/续存：活期扫 ACTIVE 申购+赎回单；定期 remaining_date', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_bitfinex_product_loan_sync', 300, 1, 'v3 按产品子账号同步 Bitfinex 在贷 position 至 fund_bitfinex_product_loan', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_bitfinex_ledger_hist_ingester', 900, 1, 'v3 按产品子账号拉 LedgersHist 写入 fund_bitfinex_ledger_hist，付息累加 cash_available', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_bitfinex_funding_order_updater', 300, 1, 'v3 按产品子账号同步 Bitfinex offer 终态至 fund_bitfinex_funding_orders', 1, 1, '', '{}', 0),
    ('bitfinex', 'fund_bitfinex_funding_trade_updater', 300, 1, 'v3 按产品子账号拉 funding trades hist 写入 fund_bitfinex_funding_trades', 1, 1, '', '{}', 0),
    ('bitfinex', 'market_trade_top_rates_avg', 60, 1, '近3小时公共成交 top10 利率均值写入 market_trade_top_rates_avg（铺单/市况监控读取）', 1, 1, '', '{}', 0);

SET FOREIGN_KEY_CHECKS = 1;
