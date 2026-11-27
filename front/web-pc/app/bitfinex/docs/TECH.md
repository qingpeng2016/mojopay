# Bitfinex 模块技术备忘

**路径**：`app/bitfinex/`  
**策略库连接**：`db_bitfinex`（环境变量：`DB_DATABASE_BITFINEX`，默认 `fgmm_strategy_bitfinex`）

## 环境与配置

- 全局：`config/database.php` 在非 local 环境与 `mysql`/`db_rmpd` 等共用 `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`。
- **若 Bitfinex 库使用单独 MySQL 用户**：在非 local 环境下可设 **`DB_USERNAME_BITFINEX`**、**`DB_PASSWORD_BITFINEX`**（不配则沿用 `DB_USERNAME` / `DB_PASSWORD`）。
- **`DB_DATABASE_BITFINEX`**：策略库 schema 名，默认 `fgmm_strategy_bitfinex`。
- **`db_fgmm_runtime`（cool_pay2 分片控制库）**：可选 **`DB_USERNAME_FGMM_RUNTIME` / `DB_PASSWORD_FGMM_RUNTIME`**（分别判断：不配则沿用主库 **`DB_USERNAME` / `DB_PASSWORD`**）。若想省凭证，可在 MySQL 里给同一用户 **`GRANT` 三个库**。
- YAML：`conf/database-{env}.yaml` 中新增 `db_bitfinex` 段；模板见 `database-local.yaml.template`。
- 模块默认：`app/bitfinex/config/database.php` 将 `default` 设为 `db_bitfinex`。

## 已实现页面

| 路由 node | 说明 |
|-----------|------|
| `bitfinex/dashboard/index` | 3.5.1 主页面板：`accounts` 汇总卡片 + `funding_wallet_yield` 到账利润年化率 + 筛选 + 分页列表 |
| `bitfinex/dashboard/edit` | 修改 `funding_available_balance`（可借贷金额，≤ `funding_balance` 借贷钱包金额） |
| `bitfinex/anchor_config/index` | 3.5.2 利率锚定参数：`funding_config_strategy` 中 `group_name=anchor` 列表 + 展示/修改 |
| `bitfinex/anchor_config/detail` | 参数只读展示（弹窗） |
| `bitfinex/anchor_config/edit` | 修改 `item_value` |
| `bitfinex/layer_config/index` | 3.5.3 资金分层参数：`funding_config_layer` 列表 + 展示/修改 |
| `bitfinex/layer_config/detail` | 分层参数只读展示（弹窗） |
| `bitfinex/layer_config/edit` | 修改 `weight_ratio` / `rate_multipliers` / `period_days`（`symbol`/`layer_code` 不可改） |
| `bitfinex/decay_config/index` | 3.5.4 递减规则参数：`funding_config_layer` 衰减字段列表 + 展示/修改 |
| `bitfinex/decay_config/detail` | 递减规则只读展示（弹窗） |
| `bitfinex/decay_config/edit` | 修改 `decay_idle_minutes` / `decay_rate_step`（`id`/`symbol`/`layer_code` 不可改） |
| `bitfinex/risk_config/index` | 3.5.5 风控参数：`funding_config_strategy`（`placement`/`risk`） |
| `bitfinex/risk_config/detail` | 只读展示；`?scope=strategy` 为策略参数 |
| `bitfinex/risk_config/edit` | 修改；`?scope=strategy` 改 `item_value`（含 `obligation_day_cutoff_hour_utc` 0–23） |
| `bitfinex/risk_config/strategy_detail` | 兼容旧链，等同 `detail?scope=strategy` |
| `bitfinex/risk_config/strategy_edit` | 兼容旧链，等同 `edit?scope=strategy` |

控制器内需显式 `Db::connect('db_bitfinex')`（与 RMPd 写法一致）。

### 只读数据页

| 路由 node | 说明 |
|-----------|------|
| `bitfinex/lending_position/index` | 借贷记录：`funding_lending_position` 分页列表（默认近 30 天）+ 筛选 |
| `bitfinex/lending_position/detail` | 借贷 position 详情（弹窗，含 raw JSON） |
| `bitfinex/funding_order/index` | 挂单记录：`funding_orders` 分页列表（默认近 30 天）+ 筛选 |
| `bitfinex/funding_order/detail` | 挂单详情（弹窗，含 section/request JSON） |
| `bitfinex/funding_book/index` | 订单簿数据：`funding_book` 按 `bucket_start` 分页（默认近 30 天） |
| `bitfinex/funding_book/detail` | 订单簿快照详情（借入/借出 book JSON） |
| `bitfinex/funding_book/export` | 导出 CSV（**必须**选择日期范围，**最多 31 天**，禁止全量下载） |

## 菜单初始化

在项目根目录对**主库**执行 `scripts/bitfinex_menu_init.sql`，然后 `php think clear`。
