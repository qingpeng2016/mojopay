-- MojoPay 后台菜单（全量重建）
-- 库：ThinkAdmin 主库（mysql 连接，当前与 mojopay 业务同库）
-- 会清空 system_menu 后重建；权限节点仅保留 admin/* 与 mojopay/*

DELETE FROM system_auth_node WHERE node LIKE 'mojopay/%';

DELETE FROM system_menu;

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (0, 'MojoPay', 'layui-icon layui-icon-rmb', '', '#', '', '_self', 0, 1, NOW());

SET @mojopay_root_id := LAST_INSERT_ID();

-- 上游管理（目录）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@mojopay_root_id, '上游管理', 'layui-icon layui-icon-upload', '', '#', '', '_self', 20, 1, NOW());

SET @upstream_dir_id := LAST_INSERT_ID();

-- 上游/下游二级菜单由 ProviderMenuSync、MerchantMenuSync 按库表生成（见 docs/sql/sync_mojopay_menus.php）

-- 下游商户（目录）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@mojopay_root_id, '下游商户', 'layui-icon layui-icon-group', '', '#', '', '_self', 10, 1, NOW());

-- 超级管理员角色（id=1）授权节点
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
  SELECT 'mojopay/provider_order/index' UNION ALL
  SELECT 'mojopay/provider_hub/index' UNION ALL
  SELECT 'mojopay/merchant/index' UNION ALL
  SELECT 'mojopay/merchant/add' UNION ALL
  SELECT 'mojopay/merchant/edit' UNION ALL
  SELECT 'mojopay/merchant/remove' UNION ALL
  SELECT 'mojopay/merchant_hub/index' UNION ALL
  SELECT 'mojopay/merchant_account/index' UNION ALL
  SELECT 'mojopay/merchant_account/add' UNION ALL
  SELECT 'mojopay/merchant_account/edit' UNION ALL
  SELECT 'mojopay/merchant_account/remove' UNION ALL
  SELECT 'mojopay/merchant_open_api/index' UNION ALL
  SELECT 'mojopay/merchant_open_api/add' UNION ALL
  SELECT 'mojopay/merchant_open_api/edit' UNION ALL
  SELECT 'mojopay/merchant_open_api/remove' UNION ALL
  SELECT 'mojopay/merchant_open_api/webhook_add' UNION ALL
  SELECT 'mojopay/merchant_open_api/webhook_edit' UNION ALL
  SELECT 'mojopay/merchant_open_api/webhook_remove' UNION ALL
  SELECT 'mojopay/merchant_channel_binding/index' UNION ALL
  SELECT 'mojopay/merchant_channel_binding/add' UNION ALL
  SELECT 'mojopay/merchant_channel_binding/edit' UNION ALL
  SELECT 'mojopay/merchant_channel_binding/remove' UNION ALL
  SELECT 'mojopay/merchant_event_outbox/index' UNION ALL
  SELECT 'mojopay/merchant_payment_order/index'
) AS n
WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);