-- =====================================================================
-- 数据中心菜单初始化
-- 执行库：MIS 主库（ThinkAdmin system_menu / system_auth_node）
-- 覆盖范围：fgmm_data 数据中心配置表；auth 敏感字段不进后台。
-- 可重复执行。123123
-- =====================================================================

SET NAMES utf8mb4;

-- 清空本脚本维护的数据中心权限节点，后面重新授权给所有角色。
-- 不使用 data/% 前缀删除，避免误删其它 data 模块。
DELETE FROM system_auth_node WHERE node IN (
    'data/config_center/index',
    'data/config_center/platform',
    'data/config_center/collect',
    'data/config_center/collectMachine',
    'data/config_center/mapping',
    'data/config_center/mappingMachine',
    'data/config_center/entity',
    'data/config_center/sportsEntity',
    'data/config_center/sportsEntitySource',
    'data/config_center/sportsMarketType',
    'data/config_center/sportsEvent',
    'data/config_center/sportsEventSource',
    'data/config_center/targetEvent',
    'data/config_center/targetMarket',
    'data/config_center/targetEventSelect',
    'data/config_center/targetMarketSelect',
    'data/normalized/index',
    'data/config_center/add',
    'data/config_center/edit',
    'data/config_center/toggle',
    'data/config_center/remove'
);

-- 清空旧数据中心菜单树。当前菜单最多三级，按孙级 -> 子级 -> 根级删除。
DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu
        WHERE pid IN (
            SELECT id FROM (
                SELECT id FROM system_menu WHERE title = '数据中心'
            ) AS root_for_grandchildren
        )
    ) AS tmp
);
DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = '数据中心'
    ) AS root_tmp
);
DELETE FROM system_menu WHERE title = '数据中心';

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
VALUES (0, '数据中心', 'layui-icon layui-icon-template-1', '', '#', '', '_self', 12, 1);
SET @data_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
VALUES (@data_root_id, '接入配置', '', '', '#', '', '_self', 100, 1);
SET @data_ingest_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
VALUES (@data_root_id, '映射底座', '', '', '#', '', '_self', 90, 1);
SET @data_canonical_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
VALUES (@data_root_id, '归一化配置', '', '', '#', '', '_self', 80, 1);
SET @data_mapping_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status)
VALUES (@data_root_id, '数据查询', '', '', '#', '', '_self', 70, 1);
SET @data_query_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status) VALUES
(@data_ingest_id,    '平台配置',       '', 'data/config_center/platform',           'data/config_center/platform',           '', '_self', 100, 1),
(@data_ingest_id,    '采集任务',       '', 'data/config_center/collect',            'data/config_center/collect',            '', '_self', 90, 1),
(@data_ingest_id,    '采集机器分配',   '', 'data/config_center/collectMachine',     'data/config_center/collectMachine',     '', '_self', 80, 1),

(@data_canonical_id, '标准实体',       '', 'data/config_center/sportsEntity',       'data/config_center/sportsEntity',       '', '_self', 100, 1),
(@data_canonical_id, '来源实体映射',   '', 'data/config_center/sportsEntitySource', 'data/config_center/sportsEntitySource', '', '_self', 90, 1),
(@data_canonical_id, '玩法映射',       '', 'data/config_center/sportsMarketType',   'data/config_center/sportsMarketType',   '', '_self', 80, 1),
(@data_canonical_id, '标准赛事',       '', 'data/config_center/sportsEvent',        'data/config_center/sportsEvent',        '', '_self', 70, 1),
(@data_canonical_id, '来源赛事映射',   '', 'data/config_center/sportsEventSource',  'data/config_center/sportsEventSource',  '', '_self', 60, 1),
(@data_canonical_id, '实体映射审计',   '', 'data/config_center/entity',             'data/config_center/entity',             '', '_self', 50, 1),

(@data_mapping_id,   '映射任务',       '', 'data/config_center/mapping',            'data/config_center/mapping',            '', '_self', 100, 1),
(@data_mapping_id,   '映射机器分配',   '', 'data/config_center/mappingMachine',     'data/config_center/mappingMachine',     '', '_self', 90, 1),
(@data_mapping_id,   '目标赛事',       '', 'data/config_center/targetEvent',        'data/config_center/targetEvent',        '', '_self', 80, 1),
(@data_mapping_id,   '目标盘口',       '', 'data/config_center/targetMarket',       'data/config_center/targetMarket',       '', '_self', 70, 1),

(@data_query_id,     '归一化数据',     '', 'data/normalized/index',                 'data/normalized/index',                 '', '_self', 100, 1);

INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'data/config_center/index' AS node UNION ALL
    SELECT 'data/config_center/platform' UNION ALL
    SELECT 'data/config_center/collect' UNION ALL
    SELECT 'data/config_center/collectMachine' UNION ALL
    SELECT 'data/config_center/mapping' UNION ALL
    SELECT 'data/config_center/mappingMachine' UNION ALL
    SELECT 'data/config_center/entity' UNION ALL
    SELECT 'data/config_center/sportsEntity' UNION ALL
    SELECT 'data/config_center/sportsEntitySource' UNION ALL
    SELECT 'data/config_center/sportsMarketType' UNION ALL
    SELECT 'data/config_center/sportsEvent' UNION ALL
    SELECT 'data/config_center/sportsEventSource' UNION ALL
    SELECT 'data/config_center/targetEvent' UNION ALL
    SELECT 'data/config_center/targetMarket' UNION ALL
    SELECT 'data/config_center/targetEventSelect' UNION ALL
    SELECT 'data/config_center/targetMarketSelect' UNION ALL
    SELECT 'data/normalized/index' UNION ALL
    SELECT 'data/config_center/add' UNION ALL
    SELECT 'data/config_center/edit' UNION ALL
    SELECT 'data/config_center/toggle' UNION ALL
    SELECT 'data/config_center/remove'
) n;


-- 执行后运行：php think clear
