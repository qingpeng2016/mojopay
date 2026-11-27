-- =====================================================================
-- RMPD 策略 菜单初始化脚本
-- 执行数据库：主库（mm @ 172.16.39.253）
-- 幂等设计：可重复执行，不产生重复数据
-- =====================================================================

-- Step 1：清理旧的 RMPD 子菜单（按 node 精确匹配，安全删除）
DELETE FROM system_menu WHERE node IN (
    'rmpd/market/index',
    'rmpd/market_intake/index',
    'rmpd/market_pair/index',
    'rmpd/arb_opportunity/index',
    'rmpd/arb_record/index',
    'rmpd/arb_trade_records/index',
    'rmpd/account/index',
    'rmpd/account_position/index',
    'rmpd/pnl_report/index',
    'rmpd/ai_call_log/index',
    'rmpd/ai_cost_daily/index',
    'rmpd/ai_model/index',
    'rmpd/risk_config/index'
);
-- 注：ai_call_log（AI 扫描日志）已下线，仍保留在上方删除列表中以清除历史菜单行（Step 2 也会按 pid 清子项）。

-- Step 2：清理旧的 RMPD 顶级菜单（含通过 pid 挂载的残留子项）
-- 注意：MySQL 不允许在 DELETE 的 WHERE 子查询中直接引用同一张表，
--       需要用派生表（别名 tmp）绕过限制
DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = 'RMPD策略' AND pid = 0
    ) AS tmp
);
DELETE FROM system_menu WHERE title = 'RMPD策略' AND pid = 0;

-- Step 3：插入顶级菜单（sort=0，排在对冲策略/自做市策略后面）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'RMPD策略', 'layui-icon layui-icon-engine', '', '#', '', '_self', 0, 1, NOW());

SET @rmpd_root_id := LAST_INSERT_ID();

-- Step 4：插入 12 个子菜单（sort DESC → 数字越大越靠前）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES
    (@rmpd_root_id, '本地市场',       'layui-icon layui-icon-template-1', 'rmpd/market/index',          'rmpd/market/index',            '', '_self', 55, 1, NOW()),
    (@rmpd_root_id, '新市场分流统计', 'layui-icon layui-icon-chart-screen', 'rmpd/market_intake/index',   'rmpd/market_intake/index',     '', '_self', 52, 1, NOW()),
    (@rmpd_root_id, '市场对审核',     'layui-icon layui-icon-list',     'rmpd/market_pair/index',       'rmpd/market_pair/index',       '', '_self', 50, 1, NOW()),
    (@rmpd_root_id, '套利机会快照',   'layui-icon layui-icon-cols',     'rmpd/arb_opportunity/index',   'rmpd/arb_opportunity/index',   '', '_self', 45, 1, NOW()),
    (@rmpd_root_id, '交易记录',       'layui-icon layui-icon-list',     'rmpd/arb_record/index',        'rmpd/arb_record/index',        '', '_self', 40, 1, NOW()),
    (@rmpd_root_id, '成交流水',     'layui-icon layui-icon-table',    'rmpd/arb_trade_records/index', 'rmpd/arb_trade_records/index', '', '_self', 35, 1, NOW()),
    (@rmpd_root_id, '账户信息',     'layui-icon layui-icon-rmb',      'rmpd/account/index',           'rmpd/account/index',           '', '_self', 32, 1, NOW()),
    (@rmpd_root_id, '账户持仓',     'layui-icon layui-icon-cart',     'rmpd/account_position/index',  'rmpd/account_position/index',  '', '_self', 31, 1, NOW()),
    (@rmpd_root_id, 'PNL 报表',    'layui-icon layui-icon-chart',    'rmpd/pnl_report/index',        'rmpd/pnl_report/index',        '', '_self', 30, 1, NOW()),
    (@rmpd_root_id, 'AI 费用日报', 'layui-icon layui-icon-rmb',      'rmpd/ai_cost_daily/index',     'rmpd/ai_cost_daily/index',     '', '_self', 18, 1, NOW()),
    (@rmpd_root_id, 'AI 模型配置', 'layui-icon layui-icon-set',      'rmpd/ai_model/index',          'rmpd/ai_model/index',          '', '_self', 15, 1, NOW()),
    (@rmpd_root_id, '风控配置',    'layui-icon layui-icon-engine',   'rmpd/risk_config/index',       'rmpd/risk_config/index',       '', '_self', 10, 1, NOW());

-- Step 5：清理旧权限节点（幂等）
DELETE FROM system_auth_node WHERE node IN (
    'rmpd/market/index',
    'rmpd/market_intake/index',
    'rmpd/market_pair/index',
    'rmpd/market_pair/approve',
    'rmpd/market_pair/reject',
    'rmpd/arb_opportunity/index',
    'rmpd/arb_record/index',
    'rmpd/arb_trade_records/index',
    'rmpd/account/index',
    'rmpd/account_position/index',
    'rmpd/pnl_report/index',
    'rmpd/ai_call_log/index',
    'rmpd/ai_cost_daily/index',
    'rmpd/ai_model/index',
    'rmpd/risk_config/index'
);

-- Step 6：将 14 个权限节点授权给超管角色
-- 注意：本库历史角色 title 既出现过「超管」也出现过「超级管理员」，两种都要兼容。
-- CROSS JOIN 一次性批量插入，避免多条单独 INSERT。
INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'rmpd/market/index'            AS node UNION ALL
    SELECT 'rmpd/market_intake/index'              UNION ALL
    SELECT 'rmpd/market_pair/index'                UNION ALL
    SELECT 'rmpd/market_pair/approve'              UNION ALL
    SELECT 'rmpd/market_pair/reject'               UNION ALL
    SELECT 'rmpd/arb_opportunity/index'            UNION ALL
    SELECT 'rmpd/arb_record/index'                 UNION ALL
    SELECT 'rmpd/arb_trade_records/index'          UNION ALL
    SELECT 'rmpd/account/index'                    UNION ALL
    SELECT 'rmpd/pnl_report/index'                 UNION ALL
    SELECT 'rmpd/ai_cost_daily/index'              UNION ALL
    SELECT 'rmpd/ai_model/index'                   UNION ALL
    SELECT 'rmpd/risk_config/index'
) n
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';

-- =====================================================================
-- 执行完成后：
-- 1. 清除后台缓存：在管理后台 → 系统设置 → 清除缓存
--    或在容器内运行：docker exec fg-mis-local php /var/www/html/think clear
-- 2. 刷新浏览器（Ctrl+F5），左侧菜单会出现「RMPD策略」
-- =====================================================================
