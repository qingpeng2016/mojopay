-- SO 热更新密码功能配套菜单权限迁移。
-- 执行数据库：MIS 主库（包含 system_auth / system_auth_node）。
--
-- “修改发布密码”位于现有 SO 文件配置页面内，不新增 system_menu 侧栏菜单。
-- 本迁移只为所有已有角色增量补充两个新接口权限，不删除或修改其他权限。

SET NAMES utf8mb4;

INSERT INTO system_auth_node (auth, node)
SELECT role.id, route.node
FROM system_auth AS role
CROSS JOIN (
    SELECT 'backtest/sofile/hotreloadpreview' AS node
    UNION ALL
    SELECT 'backtest/sofile/password'
) AS route
WHERE NOT EXISTS (
    SELECT 1
    FROM system_auth_node existing
    WHERE existing.auth = role.id
      AND existing.node = route.node
);

-- 执行完成后运行：php think clear，并让已登录用户刷新页面。
