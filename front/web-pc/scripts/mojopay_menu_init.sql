-- MojoPay 后台菜单（仅 MojoPay 模块）
-- 库：ThinkAdmin 主库（mysql 连接，当前与 mojopay 业务同库）
-- 会清空 system_menu 后重建，避免旧 fgmm 菜单导致 iframe 404
-- 可重复执行

DELETE FROM system_auth_node WHERE node LIKE 'mojopay/merchant/%';

DELETE FROM system_menu;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'MojoPay', 'layui-icon layui-icon-rmb', '', '#', '', '_self', 0, 1, NOW());

SET @mojopay_root_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@mojopay_root_id, '商户', 'layui-icon layui-icon-group', 'mojopay/merchant/index', 'mojopay/merchant/index', '', '_self', 100, 1, NOW());

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/index' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/add' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/edit' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/remove' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);