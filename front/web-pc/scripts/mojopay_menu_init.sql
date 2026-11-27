-- MojoPay 后台菜单初始化（ThinkAdmin system_menu）
-- 执行库：主库 mysql（system_menu 表）
-- 可重复执行

DELETE FROM system_menu WHERE node IN ('mojopay/merchant/index');

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