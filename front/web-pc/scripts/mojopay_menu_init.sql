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
VALUES (@mojopay_root_id, '上游管理', 'layui-icon layui-icon-upload', '', '#', '', '_self', 10, 1, NOW());

SET @upstream_dir_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at) VALUES
(@upstream_dir_id, '上游商家', 'layui-icon layui-icon-app', 'mojopay/provider/index', 'mojopay/provider/index', '', '_self', 10, 1, NOW()),
(@upstream_dir_id, '主/子账号', 'layui-icon layui-icon-user', 'mojopay/provider_account/index', 'mojopay/provider_account/index', '', '_self', 20, 1, NOW()),
(@upstream_dir_id, '凭据与回调', 'layui-icon layui-icon-password', 'mojopay/provider_credential/index', 'mojopay/provider_credential/index', '', '_self', 30, 1, NOW()),
(@upstream_dir_id, '通道能力', 'layui-icon layui-icon-slider', 'mojopay/channel_capability/index', 'mojopay/channel_capability/index', '', '_self', 40, 1, NOW()),
(@upstream_dir_id, '余额流水', 'layui-icon layui-icon-list', 'mojopay/provider_event_inbox/index', 'mojopay/provider_event_inbox/index', '', '_self', 50, 1, NOW()),
(@upstream_dir_id, '订单记录', 'layui-icon layui-icon-form', 'mojopay/provider_order/index', 'mojopay/provider_order/index', '', '_self', 55, 1, NOW());

-- 下游商户（目录）
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@mojopay_root_id, '下游商户', 'layui-icon layui-icon-group', '', '#', '', '_self', 20, 1, NOW());

SET @merchant_dir_id := LAST_INSERT_ID();

INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@merchant_dir_id, '商户主数据', 'layui-icon layui-icon-template-1', 'mojopay/merchant/index', 'mojopay/merchant/index', '', '_self', 10, 1, NOW());

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
  SELECT 'mojopay/merchant/index' UNION ALL
  SELECT 'mojopay/merchant/add' UNION ALL
  SELECT 'mojopay/merchant/edit' UNION ALL
  SELECT 'mojopay/merchant/remove'
) AS n
WHERE EXISTS (SELECT 1 FROM system_auth WHERE id = 1);