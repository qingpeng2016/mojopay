# ArbOpp 后台模块（app/arbopp）

对应 Go 策略工程：`arbopp`（跨预测市场套利）。

## 数据库

- 连接名：`db_arbopp`
- 默认库名：`fgmm_strategy_less_one`
- 环境变量：`DB_DATABASE_ARBOPP`、`DB_USERNAME_ARBOPP`、`DB_PASSWORD_ARBOPP`（规则同 `db_sc_arb`）

## 菜单

主库执行：`scripts/arbopp_menu_init.sql`

## 页面一览（默认分页 20 条/页）

| 功能 | 路由 | 说明 |
|------|------|------|
| 首页 | `/admin.html#/arbopp/dashboard/index.html` | 进行中 ACTIVE 场次，按 platform Tab |

| 表 | 路由 | 新增 |
|----|------|------|
| account | `/admin.html#/arbopp/account/index.html` | 有 |
| platform | `.../platform/index.html` | 有 |
| config_strategy | `.../config_strategy/index.html` | 有 |
| config_strategy_threshold | `.../config_strategy_threshold/index.html` | 有 |
| config_strategy_fee | `.../config_strategy_fee/index.html` | 有 |
| market_category | `.../market_category/index.html` | 只读列表 |
| market | `.../market/index.html` | 只读列表 |
| market_outcome | `.../market_outcome/index.html` | 只读列表 |
| market_order_book | `.../market_order_book/index.html` | 只读列表 |
| market_order_book_ws | `.../market_order_book_ws/index.html` | 只读列表 |
| arb_binary_opportunity | `.../arb_binary_opportunity/index.html` | 只读列表 |
| orders | `.../orders/index.html` | 只读列表 |
| stat_kv | `.../stat_kv/index.html` | 只读列表 |

大表未填 ID 时默认筛选最近 30 天（`date_range` 可覆盖）。