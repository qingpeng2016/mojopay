-- =====================================================================
-- 回测中心完整菜单初始化（MySQL，增量、幂等、禁止全局删除）
-- 执行数据库：MIS 主库（包含 system_menu / system_auth / system_auth_node）
--
-- 安全约束：
--   1. 不执行 DELETE，不改变已有菜单 ID。
--   2. 顶级菜单只按 pid=0 + title='回测中心' 精确定位。
--   3. 分组只按回测中心父 ID + title='快盘策略' 精确定位。
--   4. 页面只按完整 backtest/... node 定位，不按同名标题匹配。
--   5. 权限只补不存在的 auth + node，不清理任何已有授权。
-- 因此不会影响“SO策略 → 快盘策略”或其他模块的同名菜单。
-- =====================================================================

SET NAMES utf8mb4;

-- 1) 顶级菜单：回测中心。存在则沿用原 ID，不存在才新增。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT 0, '回测中心', '', '', '#', '', '_self', 10, 1
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu WHERE pid = 0 AND title = '回测中心'
);

SET @backtest_root_id := (
    SELECT id FROM system_menu
    WHERE pid = 0 AND title = '回测中心'
    ORDER BY id ASC LIMIT 1
);

UPDATE system_menu
SET icon = '', node = '', url = '#', params = '', target = '_self', sort = 10, status = 1
WHERE id = @backtest_root_id;

-- 2) 只在回测中心下定位“快盘策略”，不匹配 SO策略 下的同名分组。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT @backtest_root_id, '快盘策略', '', '', '#', '', '_self', 10, 1
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu
    WHERE pid = @backtest_root_id AND title = '快盘策略'
);

SET @backtest_fastbook_id := (
    SELECT id FROM system_menu
    WHERE pid = @backtest_root_id AND title = '快盘策略'
    ORDER BY id ASC LIMIT 1
);

UPDATE system_menu
SET icon = '', node = '', url = '#', params = '', target = '_self', sort = 10, status = 1
WHERE id = @backtest_fastbook_id;

-- 3) 页面菜单：只补缺失 node；已有 node 原位归入正确父级并更新展示属性。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT
    @backtest_fastbook_id, page.title, '', page.node, page.node, '', '_self', page.sort, 1
FROM (
    SELECT '盘口配置' AS title, 'backtest/config/index' AS node, 60 AS sort
    UNION ALL SELECT '市场信息', 'backtest/market/index', 50
    UNION ALL SELECT '铺单信息', 'backtest/placement/index', 40
    UNION ALL SELECT '对冲信息', 'backtest/hedge/index', 30
    UNION ALL SELECT 'SO文件配置', 'backtest/sofile/index', 70
) AS page
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu existing WHERE existing.node = page.node
);

UPDATE system_menu AS menu
INNER JOIN (
    SELECT '盘口配置' AS title, 'backtest/config/index' AS node, 60 AS sort
    UNION ALL SELECT '市场信息', 'backtest/market/index', 50
    UNION ALL SELECT '铺单信息', 'backtest/placement/index', 40
    UNION ALL SELECT '对冲信息', 'backtest/hedge/index', 30
    UNION ALL SELECT 'SO文件配置', 'backtest/sofile/index', 70
) AS page ON page.node = menu.node
SET
    menu.pid = @backtest_fastbook_id,
    menu.title = page.title,
    menu.icon = '',
    menu.url = page.node,
    menu.params = '',
    menu.target = '_self',
    menu.sort = page.sort,
    menu.status = 1;

-- 4) 超管角色：只补缺失权限，不删除或重复已有授权。
INSERT INTO system_auth_node (auth, node)
SELECT role.id, page.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/config/index' AS node
    UNION ALL SELECT 'backtest/market/index'
    UNION ALL SELECT 'backtest/placement/index'
    UNION ALL SELECT 'backtest/hedge/index'
    UNION ALL SELECT 'backtest/sofile/index'
) AS page
WHERE (role.title LIKE '%超管%' OR role.title LIKE '%超级管理员%')
  AND NOT EXISTS (
      SELECT 1 FROM system_auth_node existing
      WHERE existing.auth = role.id AND existing.node = page.node
  );

-- 5) 所有已有角色：补齐 SO 文件配置及密码保护流程权限。
INSERT INTO system_auth_node (auth, node)
SELECT role.id, route.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/sofile/index' AS node
    UNION ALL SELECT 'backtest/sofile/edit'
    UNION ALL SELECT 'backtest/sofile/hotreload'
    UNION ALL SELECT 'backtest/sofile/hotreloadpreview'
    UNION ALL SELECT 'backtest/sofile/password'
    UNION ALL SELECT 'backtest/sofile/hotreloadlogs'
    UNION ALL SELECT 'backtest/sofile/logs'
) AS route
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node existing
    WHERE existing.auth = role.id AND existing.node = route.node
);

-- =====================================================================
-- 6) 体育市场策略分组（幂等、可重复执行）
--    精确 node 定位，不按同名标题匹配，不影响其他模块菜单。
--    包含设计文档 §9 中定义的三项 + 新增的「体育活跃赛事」。
-- =====================================================================

-- 6.1) 只在回测中心下定位「体育市场策略」分组。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT @backtest_root_id, '体育市场策略', '', '', '#', '', '_self', 20, 1
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu
    WHERE pid = @backtest_root_id AND title = '体育市场策略'
);

SET @backtest_sports_id := (
    SELECT id FROM system_menu
    WHERE pid = @backtest_root_id AND title = '体育市场策略'
    ORDER BY id ASC LIMIT 1
);

UPDATE system_menu
SET icon = '', node = '', url = '#', params = '', target = '_self', sort = 20, status = 1
WHERE id = @backtest_sports_id;

-- 6.2) 页面菜单：只补缺失 node；已有 node 原位归入体育市场策略分组并更新展示属性。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT
    @backtest_sports_id, page.title, '', page.node, page.node, '', '_self', page.sort, 1
FROM (
    SELECT '体育市场配置' AS title, 'backtest/sports_config/index' AS node, 60 AS sort
    UNION ALL SELECT '体育市场信息', 'backtest/sports_market/index', 50
    UNION ALL SELECT '体育铺单历史', 'backtest/sports_placement/index', 40
    UNION ALL SELECT '体育活跃赛事', 'backtest/sports_active_event/index', 30
) AS page
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu existing WHERE existing.node = page.node
);

UPDATE system_menu AS menu
INNER JOIN (
    SELECT '体育市场配置' AS title, 'backtest/sports_config/index' AS node, 60 AS sort
    UNION ALL SELECT '体育市场信息', 'backtest/sports_market/index', 50
    UNION ALL SELECT '体育铺单历史', 'backtest/sports_placement/index', 40
    UNION ALL SELECT '体育活跃赛事', 'backtest/sports_active_event/index', 30
) AS page ON page.node = menu.node
SET
    menu.pid = @backtest_sports_id,
    menu.title = page.title,
    menu.icon = '',
    menu.url = page.node,
    menu.params = '',
    menu.target = '_self',
    menu.sort = page.sort,
    menu.status = 1;

-- 6.3) 所有已有角色：补齐四个 index 查看权限（菜单可见且可访问）。
INSERT INTO system_auth_node (auth, node)
SELECT role.id, page.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/sports_config/index' AS node
    UNION ALL SELECT 'backtest/sports_market/index'
    UNION ALL SELECT 'backtest/sports_placement/index'
    UNION ALL SELECT 'backtest/sports_active_event/index'
) AS page
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node existing
    WHERE existing.auth = role.id AND existing.node = page.node
);

-- 6.4) 超管角色：补齐体育模块的非菜单动作节点（配置/详情/导出/参数编辑等）。
INSERT INTO system_auth_node (auth, node)
SELECT role.id, route.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/sports_config/add' AS node
    UNION ALL SELECT 'backtest/sports_config/edit'
    UNION ALL SELECT 'backtest/sports_config/delete'
    UNION ALL SELECT 'backtest/sports_market/detail'
    UNION ALL SELECT 'backtest/sports_market/export'
    UNION ALL SELECT 'backtest/sports_market/saveParam'
    UNION ALL SELECT 'backtest/sports_market/saveHistorySetting'
    UNION ALL SELECT 'backtest/sports_placement/detail'
    UNION ALL SELECT 'backtest/sports_placement/export'
) AS route
WHERE (role.title LIKE '%超管%' OR role.title LIKE '%超级管理员%')
  AND NOT EXISTS (
      SELECT 1 FROM system_auth_node existing
      WHERE existing.auth = role.id AND existing.node = route.node
  );

-- =====================================================================
-- 7) 体育对冲测试页面（幂等、可重复执行）
--    放在「回测中心 → 体育市场策略」分组下，仅一个页面，不单独建分组。
--    精确 node 定位，不按同名标题匹配，不影响其他模块菜单。
-- =====================================================================

-- 7.1) 定位「体育市场策略」分组（回测中心下、title='体育市场策略'）。
SET @backtest_sports_id := (
    SELECT id FROM system_menu
    WHERE pid = @backtest_root_id AND title = '体育市场策略'
    ORDER BY id ASC LIMIT 1
);

-- 7.2) 仅一个「体育对冲测试」页面（node 已精确，幂等）。
INSERT INTO system_menu
    (pid, title, icon, node, url, params, target, sort, status)
SELECT
    @backtest_sports_id, page.title, '', page.node, page.node, '', '_self', page.sort, 1
FROM (
    SELECT '体育对冲测试' AS title, 'backtest/hedge_test/index' AS node, 50 AS sort
) AS page
WHERE NOT EXISTS (
    SELECT 1 FROM system_menu existing WHERE existing.node = page.node
);

UPDATE system_menu AS menu
INNER JOIN (
    SELECT '体育对冲测试' AS title, 'backtest/hedge_test/index' AS node, 50 AS sort
) AS page ON page.node = menu.node
SET
    menu.pid = @backtest_sports_id,
    menu.title = page.title,
    menu.icon = '',
    menu.url = page.node,
    menu.params = '',
    menu.target = '_self',
    menu.sort = page.sort,
    menu.status = 1;

-- 7.3) 所有已有角色：补齐对冲测试权限节点（查看 + 增删改 + 详情 + SO调用）。
INSERT INTO system_auth_node (auth, node)
SELECT role.id, route.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/hedge_test/index' AS node
    UNION ALL SELECT 'backtest/hedge_test/add'
    UNION ALL SELECT 'backtest/hedge_test/edit'
    UNION ALL SELECT 'backtest/hedge_test/delete'
    UNION ALL SELECT 'backtest/hedge_test/detail'
    UNION ALL SELECT 'backtest/hedge_test/soCall'
) AS route
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node existing
    WHERE existing.auth = role.id AND existing.node = route.node
);

-- 完成后运行：php think clear，并让已登录用户刷新页面。
