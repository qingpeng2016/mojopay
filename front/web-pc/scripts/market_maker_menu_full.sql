-- 内盘铺单最优档位取外档第N档 ， 档位配置 sql
DELETE FROM `config_trade` WHERE `config_name` = 'hedge_remove_best_level' and config_type = 'trading';
INSERT INTO `config_trade` (`config_type`, `config_name`, `value`, `description`, `created_at`, `updated_at`) VALUES
('trading', 'hedge_remove_best_level', '1', '移除最优档位配置', NOW(), NOW());

-- 「做市机器人」「做市机器人详情」挂在「对冲策略」下（与「数据看板」同级时，pid = 对冲策略的 id）

SET @parent_id := (SELECT id FROM system_menu WHERE title = '对冲策略' AND pid = 0 LIMIT 1);
delete from system_menu where title = '做市机器人' AND node = '';
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@parent_id, '做市机器人', 'layui-icon layui-icon-engine', '', '#', '', '_self', 0, 1, NOW());

SET @root_id := LAST_INSERT_ID();
delete from system_menu where title = '做市机器人详情' AND node = 'cool_pay2/market_maker_detail/index';
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
VALUES (@root_id, '做市机器人详情', 'layui-icon layui-icon-list', 'cool_pay2/market_maker_detail/index', 'cool_pay2/market_maker_detail/index', '', '_self', 0, 1, NOW());

DELETE FROM system_auth_node WHERE node = 'cool_pay2/market_maker_detail/index';
INSERT INTO system_auth_node (auth, node)
SELECT a.id, 'cool_pay2/market_maker_detail/index' FROM system_auth a
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';

DELETE FROM system_auth_node WHERE node = 'cool_pay2/market_maker_detail/edit';
INSERT INTO system_auth_node (auth, node)
SELECT a.id, 'cool_pay2/market_maker_detail/edit' FROM system_auth a
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';

DELETE FROM system_auth_node WHERE node = 'cool_pay2/market_maker_detail/savegroup';
-- 弹窗内「保存配置」批量保存（节点与 NodeService 扫描键一致：全小写 savegroup）
INSERT INTO system_auth_node (auth, node)
SELECT a.id, 'cool_pay2/market_maker_detail/savegroup' FROM system_auth a
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';
