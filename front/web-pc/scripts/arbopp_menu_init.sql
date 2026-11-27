-- =====================================================================
-- ArbOpp 策略后台 菜单初始化脚本
-- 执行数据库：主库（ThinkAdmin system_menu / system_auth_node）
-- 幂等设计：可重复执行
-- =====================================================================

DELETE FROM system_menu WHERE node IN (
    'arbopp/dashboard/index',
    'arbopp/account/index',
    'arbopp/arb_binary_opportunity/index',
    'arbopp/config_strategy/index',
    'arbopp/config_strategy_fee/index',
    'arbopp/config_strategy_threshold/index',
    'arbopp/market/index',
    'arbopp/market_category/index',
    'arbopp/market_outcome/index',
    'arbopp/market_order_book/index',
    'arbopp/market_order_book_ws/index',
    'arbopp/orders/index',
    'arbopp/stat_kv/index',
    'arbopp/platform/index'
);

DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = 'ArbOpp' AND pid = 0
    ) AS tmp
);
DELETE FROM system_menu WHERE title = 'ArbOpp' AND pid = 0;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'ArbOpp', 'layui-icon layui-icon-fire', '', '#', '', '_self', 55, 1, NOW());

SET @arbopp_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at) VALUES
    (@arbopp_root_id, '首页',           'layui-icon layui-icon-home', 'arbopp/dashboard/index', 'arbopp/dashboard/index', '', '_self', 71, 1, NOW()),
    (@arbopp_root_id, '下单账户',       'layui-icon layui-icon-username', 'arbopp/account/index', 'arbopp/account/index', '', '_self', 70, 1, NOW()),
    (@arbopp_root_id, '交易平台',       'layui-icon layui-icon-website', 'arbopp/platform/index', 'arbopp/platform/index', '', '_self', 69, 1, NOW()),
    (@arbopp_root_id, '策略键值',       'layui-icon layui-icon-set', 'arbopp/config_strategy/index', 'arbopp/config_strategy/index', '', '_self', 68, 1, NOW()),
    (@arbopp_root_id, '扫描阈值',       'layui-icon layui-icon-slider', 'arbopp/config_strategy_threshold/index', 'arbopp/config_strategy_threshold/index', '', '_self', 67, 1, NOW()),
    (@arbopp_root_id, '手续费系数',     'layui-icon layui-icon-rmb', 'arbopp/config_strategy_fee/index', 'arbopp/config_strategy_fee/index', '', '_self', 66, 1, NOW()),
    (@arbopp_root_id, '联赛分类',       'layui-icon layui-icon-tabs', 'arbopp/market_category/index', 'arbopp/market_category/index', '', '_self', 65, 1, NOW()),
    (@arbopp_root_id, '市场场次',       'layui-icon layui-icon-table', 'arbopp/market/index', 'arbopp/market/index', '', '_self', 64, 1, NOW()),
    (@arbopp_root_id, '市场盘口',       'layui-icon layui-icon-list', 'arbopp/market_outcome/index', 'arbopp/market_outcome/index', '', '_self', 63, 1, NOW()),
    (@arbopp_root_id, 'REST盘口',       'layui-icon layui-icon-chart', 'arbopp/market_order_book/index', 'arbopp/market_order_book/index', '', '_self', 62, 1, NOW()),
    (@arbopp_root_id, 'WS盘口',         'layui-icon layui-icon-chart-screen', 'arbopp/market_order_book_ws/index', 'arbopp/market_order_book_ws/index', '', '_self', 61, 1, NOW()),
    (@arbopp_root_id, '套利机会',       'layui-icon layui-icon-diamond', 'arbopp/arb_binary_opportunity/index', 'arbopp/arb_binary_opportunity/index', '', '_self', 60, 1, NOW()),
    (@arbopp_root_id, '下单记录',       'layui-icon layui-icon-form', 'arbopp/orders/index', 'arbopp/orders/index', '', '_self', 59, 1, NOW()),
    (@arbopp_root_id, '统计键值',       'layui-icon layui-icon-read', 'arbopp/stat_kv/index', 'arbopp/stat_kv/index', '', '_self', 58, 1, NOW());

DELETE FROM system_auth_node WHERE node IN (
    'arbopp/dashboard/index',
    'arbopp/account/index', 'arbopp/account/add', 'arbopp/account/edit', 'arbopp/account/remove',
    'arbopp/platform/index', 'arbopp/platform/add', 'arbopp/platform/edit',
    'arbopp/config_strategy/index', 'arbopp/config_strategy/add', 'arbopp/config_strategy/edit',
    'arbopp/config_strategy_threshold/index', 'arbopp/config_strategy_threshold/add', 'arbopp/config_strategy_threshold/edit',
    'arbopp/config_strategy_fee/index', 'arbopp/config_strategy_fee/add', 'arbopp/config_strategy_fee/edit',
    'arbopp/market_category/index', 'arbopp/market/index', 'arbopp/market_outcome/index',
    'arbopp/market_order_book/index', 'arbopp/market_order_book_ws/index',
    'arbopp/arb_binary_opportunity/index', 'arbopp/orders/index', 'arbopp/stat_kv/index'
);

INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'arbopp/dashboard/index' AS node UNION ALL
    SELECT 'arbopp/account/index' AS node UNION ALL SELECT 'arbopp/account/add' UNION ALL SELECT 'arbopp/account/edit' UNION ALL SELECT 'arbopp/account/remove' UNION ALL
    SELECT 'arbopp/platform/index' UNION ALL SELECT 'arbopp/platform/add' UNION ALL SELECT 'arbopp/platform/edit' UNION ALL
    SELECT 'arbopp/config_strategy/index' UNION ALL SELECT 'arbopp/config_strategy/add' UNION ALL SELECT 'arbopp/config_strategy/edit' UNION ALL
    SELECT 'arbopp/config_strategy_threshold/index' UNION ALL SELECT 'arbopp/config_strategy_threshold/add' UNION ALL SELECT 'arbopp/config_strategy_threshold/edit' UNION ALL
    SELECT 'arbopp/config_strategy_fee/index' UNION ALL SELECT 'arbopp/config_strategy_fee/add' UNION ALL SELECT 'arbopp/config_strategy_fee/edit' UNION ALL
    SELECT 'arbopp/market_category/index' UNION ALL
    SELECT 'arbopp/market/index' UNION ALL
    SELECT 'arbopp/market_outcome/index' UNION ALL
    SELECT 'arbopp/market_order_book/index' UNION ALL
    SELECT 'arbopp/market_order_book_ws/index' UNION ALL
    SELECT 'arbopp/arb_binary_opportunity/index' UNION ALL
    SELECT 'arbopp/orders/index' UNION ALL
    SELECT 'arbopp/stat_kv/index'
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node x WHERE x.auth = a.id AND x.node = n.node
);