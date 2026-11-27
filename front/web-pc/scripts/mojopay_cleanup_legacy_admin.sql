-- 清理旧 fgmm 菜单，保留「系统管理」(admin) +「MojoPay」两棵树
-- 库：mojopay（ThinkAdmin 主库）
-- 可在 MySQL 客户端执行；也可用仓库内 PHP 脚本复现同样逻辑

SET @keep_system := 16;
SET @keep_mojopay := (SELECT id FROM system_menu WHERE title = 'MojoPay' AND pid = 0 LIMIT 1);

-- 无 MojoPay 根菜单时先跑 mojopay_menu_init.sql
-- DELETE ... 建议用语义清晰的临时表或应用层递归；此处为一次性参考：

-- 权限：去掉已删业务模块节点
DELETE FROM system_auth_node
WHERE node NOT LIKE 'admin/%'
  AND node NOT LIKE 'mojopay/%';

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/index' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);
INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/add' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);
INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/edit' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);
INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, 'mojopay/merchant/remove' FROM DUAL WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);