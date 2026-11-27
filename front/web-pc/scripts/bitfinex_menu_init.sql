-- =====================================================================
-- Bitfinex 策略 菜单初始化脚本
-- 执行数据库：主库（ThinkAdmin system_menu / system_auth_node）
-- 幂等设计：可重复执行
-- =====================================================================

DELETE FROM system_menu WHERE node IN (
    'bitfinex/dashboard/index',
    'bitfinex/fund_home/index',
    'bitfinex/fund_client/index',
    'bitfinex/client_guaranteed_rate/index',
    'bitfinex/floor_rate_config/index',
    'bitfinex/fund_sub_redeem/index',
    'bitfinex/fund_product/index',
    'bitfinex/bitfinex_account/index',
    'bitfinex/bitfinex_main_account/index',
    'bitfinex/fund_report/index',
    'bitfinex/subscription_accrual_daily/index',
    'bitfinex/product_liquidity_daily/index',
    'bitfinex/fund_weekly_statement/index',
    'bitfinex/anchor_config/index',
    'bitfinex/layer_config/index',
    'bitfinex/decay_config/index',
    'bitfinex/risk_config/index',
    'bitfinex/lending_position/index',
    'bitfinex/funding_order/index',
    'bitfinex/funding_book/index',
    'bitfinex/bot_schedule_config/index'
);

DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = 'Bitfinex' AND pid = 0
    ) AS tmp
);
DELETE FROM system_menu WHERE title = 'Bitfinex' AND pid = 0;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'Bitfinex', 'layui-icon layui-icon-dollar', '', '#', '', '_self', 0, 1, NOW());

SET @bitfinex_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES
    (@bitfinex_root_id, '主页面板',     'layui-icon layui-icon-console',    'bitfinex/dashboard/index',     'bitfinex/dashboard/index',     '', '_self', 50, 1, NOW()),
    (@bitfinex_root_id, '理财 Dashboard', 'layui-icon layui-icon-home',     'bitfinex/fund_home/index', 'bitfinex/fund_home/index', '', '_self', 55, 1, NOW()),
    (@bitfinex_root_id, '客户管理',     'layui-icon layui-icon-user',       'bitfinex/fund_client/index', 'bitfinex/fund_client/index', '', '_self', 54, 1, NOW()),
    (@bitfinex_root_id, '用户利率',     'layui-icon layui-icon-chart',    'bitfinex/client_guaranteed_rate/index', 'bitfinex/client_guaranteed_rate/index', '', '_self', 53, 1, NOW()),
    (@bitfinex_root_id, '保底利率配置', 'layui-icon layui-icon-rate',     'bitfinex/floor_rate_config/index', 'bitfinex/floor_rate_config/index', '', '_self', 53, 1, NOW()),
    (@bitfinex_root_id, '申购赎回管理', 'layui-icon layui-icon-rmb',        'bitfinex/fund_sub_redeem/index', 'bitfinex/fund_sub_redeem/index', '', '_self', 52, 1, NOW()),
    (@bitfinex_root_id, '产品列表',     'layui-icon layui-icon-app',      'bitfinex/fund_product/index', 'bitfinex/fund_product/index', '', '_self', 52, 1, NOW()),
    (@bitfinex_root_id, 'Bitfinex账号管理', 'layui-icon layui-icon-username', 'bitfinex/bitfinex_account/index', 'bitfinex/bitfinex_account/index', '', '_self', 51, 1, NOW()),
    (@bitfinex_root_id, 'Bitfinex主账号管理', 'layui-icon layui-icon-user', 'bitfinex/bitfinex_main_account/index', 'bitfinex/bitfinex_main_account/index', '', '_self', 51, 1, NOW()),
    (@bitfinex_root_id, '流水与明细报表', 'layui-icon layui-icon-table',  'bitfinex/fund_report/index', 'bitfinex/fund_report/index', '', '_self', 51, 1, NOW()),
    (@bitfinex_root_id, '客户日计提明细', 'layui-icon layui-icon-date', 'bitfinex/subscription_accrual_daily/index', 'bitfinex/subscription_accrual_daily/index', '', '_self', 51, 1, NOW()),
    (@bitfinex_root_id, '产品流动性曲线', 'layui-icon layui-icon-chart-screen', 'bitfinex/product_liquidity_daily/index', 'bitfinex/product_liquidity_daily/index', '', '_self', 51, 1, NOW()),
    (@bitfinex_root_id, '周账单',         'layui-icon layui-icon-file', 'bitfinex/fund_weekly_statement/index', 'bitfinex/fund_weekly_statement/index', '', '_self', 50, 1, NOW()),
    (@bitfinex_root_id, '利率锚定参数', 'layui-icon layui-icon-set',        'bitfinex/anchor_config/index', 'bitfinex/anchor_config/index', '', '_self', 40, 1, NOW()),
    (@bitfinex_root_id, '资金分层参数', 'layui-icon layui-icon-template-1', 'bitfinex/layer_config/index',  'bitfinex/layer_config/index',  '', '_self', 30, 1, NOW()),
    (@bitfinex_root_id, '递减规则参数', 'layui-icon layui-icon-slider',     'bitfinex/decay_config/index',  'bitfinex/decay_config/index',  '', '_self', 20, 1, NOW()),
    (@bitfinex_root_id, '风控参数',     'layui-icon layui-icon-fire',       'bitfinex/risk_config/index',   'bitfinex/risk_config/index',   '', '_self', 25, 1, NOW()),
    (@bitfinex_root_id, '借贷记录',     'layui-icon layui-icon-read',       'bitfinex/lending_position/index', 'bitfinex/lending_position/index', '', '_self', 19, 1, NOW()),
    (@bitfinex_root_id, '挂单记录',     'layui-icon layui-icon-form',       'bitfinex/funding_order/index',    'bitfinex/funding_order/index',    '', '_self', 18, 1, NOW()),
    (@bitfinex_root_id, '订单簿数据',   'layui-icon layui-icon-chart',      'bitfinex/funding_book/index',     'bitfinex/funding_book/index',     '', '_self', 17, 1, NOW()),
    (@bitfinex_root_id, '调度任务配置', 'layui-icon layui-icon-time',       'bitfinex/bot_schedule_config/index', 'bitfinex/bot_schedule_config/index', '', '_self', 16, 1, NOW());

DELETE FROM system_auth_node WHERE node IN (
    'bitfinex/dashboard/index',
    'bitfinex/dashboard/edit',
    'bitfinex/fund_home/index',
    'bitfinex/fund_client/index',
    'bitfinex/fund_client/create',
    'bitfinex/fund_client/edit',
    'bitfinex/fund_client/detail',
    'bitfinex/fund_client/deposit',
    'bitfinex/fund_client/withdraw',
    'bitfinex/client_guaranteed_rate/index',
    'bitfinex/client_guaranteed_rate/edit',
    'bitfinex/floor_rate_config/index',
    'bitfinex/floor_rate_config/edit',
    'bitfinex/fund_sub_redeem/index',
    'bitfinex/fund_sub_redeem/subscribe',
    'bitfinex/fund_sub_redeem/redeem',
    'bitfinex/fund_sub_redeem/subscription_detail',
    'bitfinex/fund_sub_redeem/edit_subscription',
    'bitfinex/fund_sub_redeem/redemption_detail',
    'bitfinex/fund_sub_redeem/remove_redemption',
    'bitfinex/fund_product/index',
    'bitfinex/fund_product/holdings',
    'bitfinex/fund_product/offers',
    'bitfinex/fund_product/cancel_offers',
    'bitfinex/fund_product/edit',
    'bitfinex/fund_product/add',
    'bitfinex/fund_product/remove',
    'bitfinex/bitfinex_account/index',
    'bitfinex/bitfinex_account/add',
    'bitfinex/bitfinex_account/edit',
    'bitfinex/bitfinex_account/transfer',
    'bitfinex/bitfinex_account/exchange',
    'bitfinex/bitfinex_main_account/index',
    'bitfinex/bitfinex_main_account/transfer',
    'bitfinex/bitfinex_main_account/exchange',
    'bitfinex/fund_report/index',
    'bitfinex/subscription_accrual_daily/index',
    'bitfinex/subscription_accrual_daily/detail',
    'bitfinex/subscription_accrual_daily/remove',
    'bitfinex/product_liquidity_daily/index',
    'bitfinex/product_liquidity_daily/detail',
    'bitfinex/product_liquidity_daily/remove',
    'bitfinex/fund_weekly_statement/index',
    'bitfinex/fund_report/export',
    'bitfinex/fund_weekly_statement/generate',
    'bitfinex/fund_weekly_statement/download',
    'bitfinex/fund_weekly_statement/preview',
    'bitfinex/anchor_config/index',
    'bitfinex/anchor_config/detail',
    'bitfinex/anchor_config/edit',
    'bitfinex/layer_config/index',
    'bitfinex/layer_config/detail',
    'bitfinex/layer_config/edit',
    'bitfinex/decay_config/index',
    'bitfinex/decay_config/detail',
    'bitfinex/decay_config/edit',
    'bitfinex/risk_config/index',
    'bitfinex/risk_config/detail',
    'bitfinex/risk_config/edit',
    'bitfinex/risk_config/strategy_detail',
    'bitfinex/risk_config/strategy_edit',
    'bitfinex/lending_position/index',
    'bitfinex/lending_position/detail',
    'bitfinex/funding_order/index',
    'bitfinex/funding_order/detail',
    'bitfinex/funding_book/index',
    'bitfinex/funding_book/detail',
    'bitfinex/funding_book/export',
    'bitfinex/bot_schedule_config/index',
    'bitfinex/bot_schedule_config/detail',
    'bitfinex/bot_schedule_config/edit'
);

INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'bitfinex/dashboard/index'        AS node UNION ALL
    SELECT 'bitfinex/dashboard/edit'                  UNION ALL
    SELECT 'bitfinex/fund_home/index'                        UNION ALL
    SELECT 'bitfinex/fund_client/index'                      UNION ALL
    SELECT 'bitfinex/fund_client/create'                   UNION ALL
    SELECT 'bitfinex/fund_client/edit'                     UNION ALL
    SELECT 'bitfinex/fund_client/detail'                   UNION ALL
    SELECT 'bitfinex/fund_client/deposit'                  UNION ALL
    SELECT 'bitfinex/fund_client/withdraw'                 UNION ALL
    SELECT 'bitfinex/client_guaranteed_rate/index'           UNION ALL
    SELECT 'bitfinex/client_guaranteed_rate/edit'            UNION ALL
    SELECT 'bitfinex/floor_rate_config/index'                UNION ALL
    SELECT 'bitfinex/floor_rate_config/edit'                 UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/index'                  UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/subscribe'            UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/redeem'               UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/subscription_detail'  UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/edit_subscription'    UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/redemption_detail'    UNION ALL
    SELECT 'bitfinex/fund_sub_redeem/remove_redemption'    UNION ALL
    SELECT 'bitfinex/fund_product/index'                     UNION ALL
    SELECT 'bitfinex/fund_product/holdings'                  UNION ALL
    SELECT 'bitfinex/fund_product/offers'                    UNION ALL
    SELECT 'bitfinex/fund_product/cancel_offers'             UNION ALL
    SELECT 'bitfinex/fund_product/edit'                      UNION ALL
    SELECT 'bitfinex/fund_product/add'                       UNION ALL
    SELECT 'bitfinex/fund_product/remove'                    UNION ALL
    SELECT 'bitfinex/bitfinex_account/index'                 UNION ALL
    SELECT 'bitfinex/bitfinex_account/add'                   UNION ALL
    SELECT 'bitfinex/bitfinex_account/edit'                  UNION ALL
    SELECT 'bitfinex/bitfinex_account/transfer'              UNION ALL
    SELECT 'bitfinex/bitfinex_account/exchange'              UNION ALL
    SELECT 'bitfinex/bitfinex_main_account/index'            UNION ALL
    SELECT 'bitfinex/bitfinex_main_account/transfer'         UNION ALL
    SELECT 'bitfinex/bitfinex_main_account/exchange'         UNION ALL
    SELECT 'bitfinex/fund_report/index'                      UNION ALL
    SELECT 'bitfinex/subscription_accrual_daily/index'       UNION ALL
    SELECT 'bitfinex/subscription_accrual_daily/detail'      UNION ALL
    SELECT 'bitfinex/subscription_accrual_daily/remove'      UNION ALL
    SELECT 'bitfinex/product_liquidity_daily/index'        UNION ALL
    SELECT 'bitfinex/product_liquidity_daily/detail'       UNION ALL
    SELECT 'bitfinex/product_liquidity_daily/remove'       UNION ALL
    SELECT 'bitfinex/fund_report/export'                     UNION ALL
    SELECT 'bitfinex/fund_weekly_statement/index'              UNION ALL
    SELECT 'bitfinex/fund_weekly_statement/generate'           UNION ALL
    SELECT 'bitfinex/fund_weekly_statement/download'           UNION ALL
    SELECT 'bitfinex/fund_weekly_statement/preview'            UNION ALL
    SELECT 'bitfinex/anchor_config/index'             UNION ALL
    SELECT 'bitfinex/anchor_config/detail'            UNION ALL
    SELECT 'bitfinex/anchor_config/edit'              UNION ALL
    SELECT 'bitfinex/layer_config/index'              UNION ALL
    SELECT 'bitfinex/layer_config/detail'             UNION ALL
    SELECT 'bitfinex/layer_config/edit'               UNION ALL
    SELECT 'bitfinex/decay_config/index'              UNION ALL
    SELECT 'bitfinex/decay_config/detail'             UNION ALL
    SELECT 'bitfinex/decay_config/edit'               UNION ALL
    SELECT 'bitfinex/risk_config/index'               UNION ALL
    SELECT 'bitfinex/risk_config/detail'              UNION ALL
    SELECT 'bitfinex/risk_config/edit'                UNION ALL
    SELECT 'bitfinex/risk_config/strategy_detail'   UNION ALL
    SELECT 'bitfinex/risk_config/strategy_edit'     UNION ALL
    SELECT 'bitfinex/lending_position/index'          UNION ALL
    SELECT 'bitfinex/lending_position/detail'         UNION ALL
    SELECT 'bitfinex/funding_order/index'             UNION ALL
    SELECT 'bitfinex/funding_order/detail'            UNION ALL
    SELECT 'bitfinex/funding_book/index'              UNION ALL
    SELECT 'bitfinex/funding_book/detail'             UNION ALL
    SELECT 'bitfinex/funding_book/export'                    UNION ALL
    SELECT 'bitfinex/bot_schedule_config/index'              UNION ALL
    SELECT 'bitfinex/bot_schedule_config/detail'             UNION ALL
    SELECT 'bitfinex/bot_schedule_config/edit'
) n
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';

-- =====================================================================
-- 完成后：php think clear，浏览器硬刷新
-- =====================================================================
