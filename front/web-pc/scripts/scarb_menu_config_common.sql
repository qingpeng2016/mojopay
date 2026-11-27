-- 增量：为已有 SC Arb 菜单补上「全局配置」config_common（幂等）
-- 执行库：主库（ThinkAdmin system_menu / system_auth_node）

SET @scarb_root_id := (
    SELECT id FROM system_menu WHERE title = 'SC Arb' AND pid = 0 ORDER BY id DESC LIMIT 1
);

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @scarb_root_id, '全局配置', 'layui-icon layui-icon-cols', 'scarb/config_common/index', 'scarb/config_common/index', '', '_self', 38, 1, NOW()
FROM DUAL
WHERE @scarb_root_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM system_menu WHERE node = 'scarb/config_common/index'
  );

INSERT INTO system_auth_node (auth, node)
SELECT a.id, n.node
FROM system_auth a
CROSS JOIN (
    SELECT 'scarb/config_common/index' AS node UNION ALL
    SELECT 'scarb/config_common/add' UNION ALL
    SELECT 'scarb/config_common/edit' UNION ALL
    SELECT 'scarb/config_common/remove'
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM system_auth_node x WHERE x.auth = a.id AND x.node = n.node
);
