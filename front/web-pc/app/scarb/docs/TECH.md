# SC Arb 后台模块（app/scarb）

对应 Go 策略工程：`mojo-strategy-sc-arb`（价差/套利 SC 策略）。

## 数据库

- 连接名：`db_sc_arb`
- 默认库名：`mojo_strategy_sc_arb`
- 环境变量（与 `db_bitfinex` 一致）：
  - host/port：沿用 `DB_HOST` / `DB_PORT`
  - `DB_DATABASE_SC_ARB`：库名（默认 `mojo_strategy_sc_arb`）
  - `DB_USERNAME_SC_ARB` / `DB_PASSWORD_SC_ARB`：可选独立账号；未设置则沿用 `DB_USERNAME` / `DB_PASSWORD`
- YAML：`conf/database-{env}.yaml` 中 `db_sc_arb` 段；本地模板见 `database-local.yaml.template`
- 模块默认：`app/scarb/config/database.php` 将 `default` 设为 `db_sc_arb`
- 控制器内显式：`Db::connect('db_sc_arb')`

## 菜单

在主库（ThinkAdmin）执行：`scripts/scarb_menu_init.sql`

## 页面与访问 URL

ThinkAdmin SPA 前缀：`/admin.html#/`；直链：`/scarb/<controller>/index.html`（或带 `index.php`）。

| 功能 | 列表（SPA） | 新增 | 修改 |
|------|-------------|------|------|
| 首页 | `/admin.html#/scarb/dashboard/index.html` | — | — |
| 订单 | `/admin.html#/scarb/orders/index.html` | — | — |
| 持仓 | `/admin.html#/scarb/positions/index.html` | — | — |
| 成交 | `/admin.html#/scarb/trades/index.html` | — | — |
| 换仓审核 | `/admin.html#/scarb/rebalance_close_review/index.html` | — | — |
| 开仓审核 | `/admin.html#/scarb/contract_asset_review/index.html` | `.../contract_asset_review/add.html` | `.../contract_asset_review/edit.html?id=` |
| 账户资产 | `/admin.html#/scarb/account_asset/index.html` | — | — |
| 理财奖励 | `/admin.html#/scarb/earn_reward_records/index.html` | — | — |
| 兑换记录 | `/admin.html#/scarb/account_asset_converts/index.html` | — | — |
| 交易所账号 | `/admin.html#/scarb/account_exchange/index.html` | `.../account_exchange/add.html` | `.../account_exchange/edit.html?id=` |
| 脚本调度 | `/admin.html#/scarb/bot_schedule_config/index.html` | `.../bot_schedule_config/add.html` | `.../bot_schedule_config/edit.html?id=` |
| 兑换配置 | `/admin.html#/scarb/config_convert/index.html` | `.../config_convert/add.html` | `.../config_convert/edit.html?id=` |
| 策略配置 | `/admin.html#/scarb/config_strategy/index.html` | `.../config_strategy/add.html` | `.../config_strategy/edit.html?id=` |
| 全局配置 | `/admin.html#/scarb/config_common/index.html` | `.../config_common/add.html` | `.../config_common/edit.html?id=` |

实体字段定义以 `mojo-strategy-sc-arb/domain/persistent/entity` 为准。
