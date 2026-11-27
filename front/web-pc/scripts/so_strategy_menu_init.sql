-- =====================================================================
-- SO 策略菜单初始化脚本
-- 执行数据库：MIS 主库（ThinkAdmin system_menu / system_auth_node）
-- 菜单层级：SO策略（顶级目录） -> 快盘策略（分组） -> 页面
-- 说明：生产已收缩为以下 5 个页面，测试环境保持与之完全一致；
--       回测中心下也含同名「快盘策略」菜单，但其顶层目录为回测中心（pid 不同），
--       本脚本只维护 SO策略 下的快盘策略，二者互不干扰，顶层目录以 SO策略 区分。
-- 安全约束：只使用完整 node 白名单 + pid/title/node 精确定位；不使用 LIKE。
-- 幂等设计：可重复执行（已存在的菜单/授权跳过，不再维护的旧页面清理后不重建）。
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- 1) 清理本脚本不再维护的权限节点（旧页面）。完整白名单不会影响其他策略。
DELETE FROM system_auth_node WHERE node IN (
    'so_strategy/fastbook_data/marketconfig',
    'so_strategy/fastbook_data/marketoptions',
    'so_strategy/fastbook_data/marketresults',
    'so_strategy/fastbook_data/accountpnl',
    'so_strategy/fastbook_data/sysconfig',
    'so_strategy/fastbook_data/marketoutcomes',
    'so_strategy/fastbook_data/marketsettlementrecords'
);

-- 2) 清理本脚本不再维护的页面菜单（旧页面）。
DELETE FROM system_menu WHERE node IN (
    'so_strategy/fastbook_data/marketconfig',
    'so_strategy/fastbook_data/marketoptions',
    'so_strategy/fastbook_data/marketresults',
    'so_strategy/fastbook_data/accountpnl',
    'so_strategy/fastbook_data/sysconfig',
    'so_strategy/fastbook_data/marketoutcomes',
    'so_strategy/fastbook_data/marketsettlementrecords'
);

-- 3) 确保独立顶级目录 SO策略 存在，并固定为启用状态。
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT 0, 'SO策略', 'layui-icon layui-icon-engine', '', '#', '', '_self', 10, 1
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu WHERE pid = 0 AND title = 'SO策略' AND node = ''
);
SET @so_root_id := (
    SELECT id FROM system_menu
    WHERE pid = 0 AND title = 'SO策略' AND node = ''
    ORDER BY id LIMIT 1
);
UPDATE system_menu
SET icon = 'layui-icon layui-icon-engine', url = '#', params = '', target = '_self', sort = 10, status = 1
WHERE id = @so_root_id AND pid = 0 AND title = 'SO策略' AND node = '';

-- 4) 确保 SO策略 下的 快盘策略 分组存在（注意：回测中心下另有同名菜单，但 pid 不同，互不干扰）。
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @so_root_id, '快盘策略', 'layui-icon layui-icon-chart-screen', '', '#', '', '_self', 100, 1
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu
    WHERE pid = @so_root_id AND title = '快盘策略' AND node = ''
);
SET @fastbook_id := (
    SELECT id FROM system_menu
    WHERE pid = @so_root_id AND title = '快盘策略' AND node = ''
    ORDER BY id LIMIT 1
);
UPDATE system_menu
SET icon = 'layui-icon layui-icon-chart-screen', url = '#', params = '', target = '_self', sort = 100, status = 1
WHERE id = @fastbook_id AND pid = @so_root_id AND title = '快盘策略' AND node = '';

-- 5) 重建 SO 快盘页面菜单（幂等：已存在则跳过，不重复插入）。
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @fastbook_id, '数据洞察',     '', 'so_strategy/fastbook_insight/index',       'so_strategy/fastbook_insight/index',       '', '_self', 100, 1
WHERE NOT EXISTS (SELECT 1 FROM system_menu WHERE node = 'so_strategy/fastbook_insight/index');
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @fastbook_id, '用户盈亏报表', '', 'so_strategy/fastbook_pnl_report/userpnl',    'so_strategy/fastbook_pnl_report/userpnl',    '', '_self', 96, 1
WHERE NOT EXISTS (SELECT 1 FROM system_menu WHERE node = 'so_strategy/fastbook_pnl_report/userpnl');
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @fastbook_id, '盘口盈亏报表', '', 'so_strategy/fastbook_pnl_report/outcomepnl', 'so_strategy/fastbook_pnl_report/outcomepnl', '', '_self', 95, 1
WHERE NOT EXISTS (SELECT 1 FROM system_menu WHERE node = 'so_strategy/fastbook_pnl_report/outcomepnl');
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @fastbook_id, '市场信息',     '', 'so_strategy/fastbook_data/marketinfo',      'so_strategy/fastbook_data/marketinfo',      '', '_self', 90, 1
WHERE NOT EXISTS (SELECT 1 FROM system_menu WHERE node = 'so_strategy/fastbook_data/marketinfo');
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
SELECT @fastbook_id, '成交记录',     '', 'so_strategy/fastbook_data/traderecords',    'so_strategy/fastbook_data/traderecords',    '', '_self', 70, 1
WHERE NOT EXISTS (SELECT 1 FROM system_menu WHERE node = 'so_strategy/fastbook_data/traderecords');

-- 6) SO 快盘所有页面及接口授予全部现有角色（幂等：已授权则跳过）。
INSERT IGNORE INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'so_strategy/fastbook_insight/index' AS node UNION ALL
    SELECT 'so_strategy/fastbook_insight/period'         UNION ALL
    SELECT 'so_strategy/fastbook_insight/cards'          UNION ALL
    SELECT 'so_strategy/fastbook_data/marketinfo'        UNION ALL
    SELECT 'so_strategy/fastbook_data/traderecords'      UNION ALL
    SELECT 'so_strategy/fastbook_data/export'            UNION ALL
    SELECT 'so_strategy/fastbook_pnl_report/userpnl'     UNION ALL
    SELECT 'so_strategy/fastbook_pnl_report/outcomepnl'  UNION ALL
    SELECT 'so_strategy/fastbook_pnl_report/export'
) n;

COMMIT;

-- 执行后运行：php think clear，浏览器硬刷新。
