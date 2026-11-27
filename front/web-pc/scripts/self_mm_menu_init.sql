-- =============================================================================
-- 自做市策略（cool_pay3）菜单初始化 —— 「自做市策略 / 数据看板 / 目标市场」
--
-- 与对冲策略（cool_pay2）的「对冲策略 / 数据看板 / 目标市场」保持一致的层级结构。
-- 幂等：可重复执行。
--
-- 注意：
--   * 菜单 / 权限表在「主库 mysql」(mm)，不是 db_pay3 (fgmm_self_mm)。
--   * 执行后请清后台缓存：php think clear，并硬刷新浏览器。
--   * system_auth 的超管标题历史上有「超管」「超级管理员」两种写法，下方两者都兼容。
-- =============================================================================

SET NAMES utf8mb4;

-- ── Step 1. 定位「自做市策略」顶级菜单 ───────────────────────────────────────
SET @selfmm_top := (SELECT id FROM system_menu WHERE title = '自做市策略' AND pid = 0 LIMIT 1);

-- ── Step 2. 确保「数据看板」子菜单存在（自做市侧，folder 节点 node='' url='#'） ──
-- 子查询包一层派生表，规避 MySQL「INSERT...SELECT 不能引用目标表」(error 1093)。
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @selfmm_top, '数据看板', 'layui-icon layui-icon-chart', '', '#', '', '_self', -10, 1, NOW()
FROM DUAL
WHERE @selfmm_top IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM (
          SELECT id FROM system_menu WHERE pid = @selfmm_top AND title = '数据看板'
      ) t
  );

SET @dash_id := (SELECT id FROM system_menu WHERE pid = @selfmm_top AND title = '数据看板' LIMIT 1);

-- ── Step 3.「目标市场」叶子节点（先删后插，保证幂等） ────────────────────────
DELETE FROM system_menu WHERE node = 'cool_pay3/target_trade_records/index';
INSERT INTO system_menu (pid, title, icon, node, url, params, target, sort, status, create_at)
SELECT @dash_id, '目标市场', 'layui-icon layui-icon-template-1',
       'cool_pay3/target_trade_records/index', 'cool_pay3/target_trade_records/index',
       '', '_self', 0, 1, NOW()
FROM DUAL
WHERE @dash_id IS NOT NULL;

-- ── Step 4. 权限节点（index 列表 + export 导出），授予超管 ─────────────────────
DELETE FROM system_auth_node
 WHERE node IN ('cool_pay3/target_trade_records/index', 'cool_pay3/target_trade_records/export');

INSERT INTO system_auth_node (auth, node)
SELECT a.id, 'cool_pay3/target_trade_records/index' FROM system_auth a
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';

INSERT INTO system_auth_node (auth, node)
SELECT a.id, 'cool_pay3/target_trade_records/export' FROM system_auth a
WHERE a.title LIKE '%超管%' OR a.title LIKE '%超级管理员%';
