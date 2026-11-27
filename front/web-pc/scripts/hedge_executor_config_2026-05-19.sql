-- =====================================================================
-- 对冲执行机器人 — 新增 4 条 config_trade 配置
-- 需求：3.3.3.2 对冲执行机器人配置（2026-05-19）
-- 执行数据库：cool_pay2 业务分片（mm / mm1 / ...） —— 每个分片都要跑一遍
-- 幂等设计：可重复执行，不重复插入；description/config_type 会被覆盖，value 不动（避免重置运营改过的值）
-- 执行后用 `php think shard:repair` 验证跨片一致
-- =====================================================================

INSERT INTO `config_trade` (`config_type`, `config_name`, `description`, `value`) VALUES
('hedge', 'hedge_trigger_threshold',        '对冲触发阈值：未对冲份额累计超过此值时触发一次对冲',                    '10'),
('hedge', 'hedge_tail_disable_enabled',     '尾盘不对冲开关：1=开启，0=关闭（仅作用于通用类对冲市场）',              '0'),
('hedge', 'hedge_tail_time_threshold_pct',  '尾盘时间阈值：当市场剩余时间百分比低于此值时开启尾盘不对冲（单位：%）',  '10'),
('hedge', 'hedge_poly_low_price_threshold', '外盘低价阈值：当 Poly 上 asks 最优档价格低于此值时不对冲',              '0.05')
ON DUPLICATE KEY UPDATE
  -- description / config_type 始终覆盖到最新版（这两个字段管理员不会改）
  description = VALUES(description),
  config_type = VALUES(config_type);
  -- value 故意不写到 ON DUPLICATE KEY 子句里：
  -- 已存在的行保留运营当前的值，避免重跑脚本重置生产配置
