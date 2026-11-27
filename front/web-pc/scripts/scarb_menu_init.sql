-- =====================================================================
-- SC Arb 策略后台 菜单初始化脚本
-- 执行数据库：主库（ThinkAdmin system_menu / system_auth_node）
-- 幂等设计：可重复执行
-- =====================================================================

DELETE FROM system_menu WHERE node IN (
    'scarb/dashboard/index',
    'scarb/orders/index',
    'scarb/positions/index',
    'scarb/trades/index',
    'scarb/rebalance_close_review/index',
    'scarb/contract_asset_review/index',
    'scarb/account_asset/index',
    'scarb/earn_reward_records/index',
    'scarb/account_asset_converts/index',
    'scarb/account_exchange/index',
    'scarb/bot_schedule_config/index',
    'scarb/config_convert/index',
    'scarb/config_strategy/index',
    'scarb/config_common/index'
);

DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = 'SC Arb' AND pid = 0
    ) AS tmp
);
DELETE FROM system_menu WHERE title = 'SC Arb' AND pid = 0;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'SC Arb', 'layui-icon layui-icon-rate', '', '#', '', '_self', 0, 1, NOW());

SET @scarb_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES
    (@scarb_root_id, '首页',           'layui-icon layui-icon-home', 'scarb/dashboard/index',    'scarb/dashboard/index',    '', '_self', 60, 1, NOW()),
    (@scarb_root_id, '订单记录',       'layui-icon layui-icon-form',  'scarb/orders/index',       'scarb/orders/index',       '', '_self', 50, 1, NOW()),
    (@scarb_root_id, '持仓',           'layui-icon layui-icon-chart','scarb/positions/index',      'scarb/positions/index',      '', '_self', 49, 1, NOW()),
    (@scarb_root_id, '成交记录',       'layui-icon layui-icon-log',  'scarb/trades/index',         'scarb/trades/index',         '', '_self', 48, 1, NOW()),
    (@scarb_root_id, '换仓审核',       'layui-icon layui-icon-survey','scarb/rebalance_close_review/index', 'scarb/rebalance_close_review/index', '', '_self', 47, 1, NOW()),
    (@scarb_root_id, '开仓审核',       'layui-icon layui-icon-ok',   'scarb/contract_asset_review/index', 'scarb/contract_asset_review/index', '', '_self', 46, 1, NOW()),
    (@scarb_root_id, '账户资产',       'layui-icon layui-icon-rmb',  'scarb/account_asset/index',  'scarb/account_asset/index',  '', '_self', 45, 1, NOW()),
    (@scarb_root_id, '理财奖励',       'layui-icon layui-icon-dollar','scarb/earn_reward_records/index', 'scarb/earn_reward_records/index', '', '_self', 44, 1, NOW()),
    (@scarb_root_id, '兑换记录',       'layui-icon layui-icon-transfer','scarb/account_asset_converts/index', 'scarb/account_asset_converts/index', '', '_self', 43, 1, NOW()),
    (@scarb_root_id, '交易所账号',     'layui-icon layui-icon-username','scarb/account_exchange/index','scarb/account_exchange/index','', '_self', 42, 1, NOW()),
    (@scarb_root_id, '脚本调度',       'layui-icon layui-icon-time', 'scarb/bot_schedule_config/index','scarb/bot_schedule_config/index','', '_self', 41, 1, NOW()),
    (@scarb_root_id, '兑换配置',       'layui-icon layui-icon-set',  'scarb/config_convert/index', 'scarb/config_convert/index', '', '_self', 40, 1, NOW()),
    (@scarb_root_id, '策略配置',       'layui-icon layui-icon-engine','scarb/config_strategy/index','scarb/config_strategy/index','', '_self', 39, 1, NOW()),
    (@scarb_root_id, '全局配置',       'layui-icon layui-icon-cols', 'scarb/config_common/index',  'scarb/config_common/index',  '', '_self', 38, 1, NOW());

DELETE FROM system_auth_node WHERE node IN (
    'scarb/dashboard/index',
    'scarb/orders/index',
    'scarb/positions/index',
    'scarb/trades/index',
    'scarb/rebalance_close_review/index',
    'scarb/rebalance_close_review/approve',
    'scarb/rebalance_close_review/cancel',
    'scarb/contract_asset_review/index',
    'scarb/contract_asset_review/add',
    'scarb/contract_asset_review/edit',
    'scarb/contract_asset_review/approve',
    'scarb/account_asset/index',
    'scarb/earn_reward_records/index',
    'scarb/account_asset_converts/index',
    'scarb/account_asset_converts/confirm',
    'scarb/account_exchange/index',
    'scarb/account_exchange/add',
    'scarb/account_exchange/edit',
    'scarb/account_exchange/remove',
    'scarb/bot_schedule_config/index',
    'scarb/bot_schedule_config/add',
    'scarb/bot_schedule_config/edit',
    'scarb/bot_schedule_config/remove',
    'scarb/config_convert/index',
    'scarb/config_convert/add',
    'scarb/config_convert/edit',
    'scarb/config_strategy/index',
    'scarb/config_strategy/add',
    'scarb/config_strategy/edit',
    'scarb/config_common/index',
    'scarb/config_common/add',
    'scarb/config_common/edit',
    'scarb/config_common/remove'
);

INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'scarb/dashboard/index' AS node UNION ALL
    SELECT 'scarb/orders/index' UNION ALL
    SELECT 'scarb/positions/index' UNION ALL
    SELECT 'scarb/trades/index' UNION ALL
    SELECT 'scarb/rebalance_close_review/index' UNION ALL
    SELECT 'scarb/rebalance_close_review/approve' UNION ALL
    SELECT 'scarb/rebalance_close_review/cancel' UNION ALL
    SELECT 'scarb/contract_asset_review/index' UNION ALL
    SELECT 'scarb/contract_asset_review/add' UNION ALL
    SELECT 'scarb/contract_asset_review/edit' UNION ALL
    SELECT 'scarb/contract_asset_review/approve' UNION ALL
    SELECT 'scarb/account_asset/index' UNION ALL
    SELECT 'scarb/earn_reward_records/index' UNION ALL
    SELECT 'scarb/account_asset_converts/index' UNION ALL
    SELECT 'scarb/account_asset_converts/confirm' UNION ALL
    SELECT 'scarb/account_exchange/index' UNION ALL
    SELECT 'scarb/account_exchange/add' UNION ALL
    SELECT 'scarb/account_exchange/edit' UNION ALL
    SELECT 'scarb/account_exchange/remove' UNION ALL
    SELECT 'scarb/bot_schedule_config/index' UNION ALL
    SELECT 'scarb/bot_schedule_config/add' UNION ALL
    SELECT 'scarb/bot_schedule_config/edit' UNION ALL
    SELECT 'scarb/bot_schedule_config/remove' UNION ALL
    SELECT 'scarb/config_convert/index' UNION ALL
    SELECT 'scarb/config_convert/add' UNION ALL
    SELECT 'scarb/config_convert/edit' UNION ALL
    SELECT 'scarb/config_strategy/index' UNION ALL
    SELECT 'scarb/config_strategy/add' UNION ALL
    SELECT 'scarb/config_strategy/edit' UNION ALL
    SELECT 'scarb/config_common/index' UNION ALL
    SELECT 'scarb/config_common/add' UNION ALL
    SELECT 'scarb/config_common/edit' UNION ALL
    SELECT 'scarb/config_common/remove'
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node x WHERE x.auth = a.id AND x.node = n.node
);
