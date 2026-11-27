-- MojoPay 后台菜单初始化（ThinkAdmin system_menu）
-- 执行库：与 ThinkAdmin 主连接相同（当前 mojopay 库）
-- 建议先执行 mojopay_cleanup_legacy_admin.sql
-- 可重复执行

DELETE FROM system_menu WHERE node IN ('mojopay/merchant/index');
DELETE FROM system_auth_node WHERE node LIKE 'mojopay/merchant/%';

DELETE FROM system_menu WHERE pid IN (
    SELECT id FROM (
        SELECT id FROM system_menu WHERE title = 'MojoPay' AND pid = 0
    ) AS tmp
);
DELETE FROM system_menu WHERE title = 'MojoPay' AND pid = 0;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'MojoPay', 'layui-icon layui-icon-rmb', '', '#', '', '_self', 0, 1, NOW());

SET @mojopay_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@mojopay_root_id, '商户', 'layui-icon layui-icon-group', 'mojopay/merchant/index', 'mojopay/merchant/index', '', '_self', 100, 1, NOW());

-- 为超级管理员角色（id=1，可按需改）授权商户页
INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/index' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/add' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/edit' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/remove' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);