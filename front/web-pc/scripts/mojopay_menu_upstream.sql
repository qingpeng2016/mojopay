-- 在已有 MojoPay 菜单上追加「上游管理」目录及子菜单（可重复执行）
-- 不删除原有菜单；若仅有一条「商户」挂在根下，会保留并另建「下游商户」目录

SET @mojopay_root_id := (SELECT id FROM system_menu WHERE title = 'MojoPay' AND pid = 0 ORDER BY id ASC LIMIT 1);

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @mojopay_root_id, '上游管理', 'layui-icon layui-icon-upload', '', '#', '', '_self', 20, 1, NOW()
FROM DUAL
WHERE @mojopay_root_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM system_menu WHERE pid = @mojopay_root_id AND title = '上游管理' LIMIT 1);

SET @upstream_dir_id := (
  SELECT id FROM system_menu WHERE pid = @mojopay_root_id AND title = '上游管理' ORDER BY id ASC LIMIT 1
);

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @upstream_dir_id, t.title, t.icon, t.node, t.url, '', '_self', t.sort, 1, NOW()
FROM (
  SELECT 10 AS sort, '上游商家' AS title, 'layui-icon layui-icon-app' AS icon, 'mojopay/provider/index' AS node, 'mojopay/provider/index' AS url UNION ALL
  SELECT 20, '主/子账号', 'layui-icon layui-icon-user', 'mojopay/provider_account/index', 'mojopay/provider_account/index' UNION ALL
  SELECT 30, '凭据与回调', 'layui-icon layui-icon-password', 'mojopay/provider_credential/index', 'mojopay/provider_credential/index' UNION ALL
  SELECT 40, '通道能力', 'layui-icon layui-icon-slider', 'mojopay/channel_capability/index', 'mojopay/channel_capability/index' UNION ALL
  SELECT 50, '余额流水', 'layui-icon layui-icon-list', 'mojopay/provider_event_inbox/index', 'mojopay/provider_event_inbox/index' UNION ALL
  SELECT 55, '订单记录', 'layui-icon layui-icon-form', 'mojopay/provider_order/index', 'mojopay/provider_order/index'
) AS t
WHERE @upstream_dir_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM system_menu m WHERE m.pid = @upstream_dir_id AND m.node = t.node LIMIT 1);

-- 下游商户目录（可选，与全量 init 对齐）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @mojopay_root_id, '下游商户', 'layui-icon layui-icon-group', '', '#', '', '_self', 10, 1, NOW()
FROM DUAL
WHERE @mojopay_root_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM system_menu WHERE pid = @mojopay_root_id AND title = '下游商户' LIMIT 1);

SET @merchant_dir_id := (
  SELECT id FROM system_menu WHERE pid = @mojopay_root_id AND title = '下游商户' ORDER BY id ASC LIMIT 1
);

UPDATE system_menu
SET pid = @merchant_dir_id, title = '商户主数据', sort = 10,
    node = 'mojopay/merchant/index', url = 'mojopay/merchant/index'
WHERE @merchant_dir_id IS NOT NULL
  AND pid = @mojopay_root_id
  AND (node = 'mojopay/merchant/index' OR url = 'mojopay/merchant/index')
  AND title IN ('商户', '商户主数据');

INSERT IGNORE INTO system_auth_node (auth, node)
SELECT 1, n.node FROM (
  SELECT 'mojopay/provider/index' AS node UNION ALL
  SELECT 'mojopay/provider/add' UNION ALL
  SELECT 'mojopay/provider/edit' UNION ALL
  SELECT 'mojopay/provider/remove' UNION ALL
  SELECT 'mojopay/provider_account/index' UNION ALL
  SELECT 'mojopay/provider_account/add' UNION ALL
  SELECT 'mojopay/provider_account/edit' UNION ALL
  SELECT 'mojopay/provider_account/remove' UNION ALL
  SELECT 'mojopay/provider_credential/index' UNION ALL
  SELECT 'mojopay/provider_credential/edit' UNION ALL
  SELECT 'mojopay/channel_capability/index' UNION ALL
  SELECT 'mojopay/channel_capability/add' UNION ALL
  SELECT 'mojopay/channel_capability/edit' UNION ALL
  SELECT 'mojopay/channel_capability/remove' UNION ALL
  SELECT 'mojopay/provider_event_inbox/index' UNION ALL
  SELECT 'mojopay/provider_order/index'
) AS n
WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);

UPDATE system_menu SET title = '上游商家' WHERE node = 'mojopay/provider/index' AND title IN ('上游档案', '上游服务商');