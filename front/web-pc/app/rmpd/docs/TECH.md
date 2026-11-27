# RMPD 模块技术文档 v1.0

**项目**：fg-marketmaker-mis（ThinkPHP 管理后台）  
**模块路径**：`app/rmpd/`  
**数据库**：RMPD 策略库（独立连接 `db_rmpd`）  
**文档版本**：2026-05-09

---

## 一、目录结构

```
app/rmpd/
├── config/
│   └── database.php            # 覆盖模块默认连接为 db_rmpd
├── const/
│   └── Enum.php                # 所有枚举常量
├── controller/
│   ├── MarketPair.php          # 市场对审核（列表 + 批量审核）
│   ├── ArbRecord.php           # 套利交易记录明细
│   ├── PnlReport.php           # PNL 报表（汇总卡片 + 趋势图 + 分组统计）
│   ├── RiskConfig.php          # 风控配置（只读）
│   └── AiCallLog.php           # AI 扫描日志
├── trait/
│   └── SafeQueryTrait.php      # 输入安全过滤工具
├── view/
│   ├── market_pair/
│   │   ├── index.html          # 市场对列表 + 审核弹窗
│   │   └── index_search.html   # 搜索表单
│   ├── arb_record/
│   │   ├── index.html          # 交易记录列表
│   │   └── index_search.html   # 搜索表单
│   ├── pnl_report/
│   │   └── index.html          # PNL 报表页（含 ECharts 趋势图）
│   ├── risk_config/
│   │   └── index.html          # 风控配置只读页
│   └── ai_call_log/
│       ├── index.html          # AI 日志列表 + 汇总卡片
│       └── index_search.html   # 搜索表单
├── Service.php                 # 模块服务入口（注册到 app/service.php）
└── docs/
    ├── PRD.md                  # 产品需求文档
    └── TECH.md                 # 本技术文档
```

---

## 二、数据库连接

### 2.1 连接配置

| 配置项 | 说明 |
|--------|------|
| 连接名 | `db_rmpd` |
| 类型 | MySQL（utf8mb4）|
| 模块覆盖文件 | `app/rmpd/config/database.php` → `['default' => 'db_rmpd']` |
| YAML 定义 | `conf/database-develop.yaml` / `conf/database-prod.yaml` |
| 环境变量 | `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` / `DB_DATABASE_RMPD`（默认值 `fgmm_strategy_rmpd`）|

环境变量注入逻辑在 `config/database.php`（全局文件）中处理，非 local 环境下自动覆盖 `db_rmpd` 的连接参数。

### 2.2 控制器中的连接方式

所有控制器均使用显式连接，不依赖模块 default 覆盖：

```php
$db = Db::connect('db_rmpd');
$query = Db::connect('db_rmpd')->name('market_pair');
```

---

## 三、数据库表结构

### 3.1 market_pair（市场对）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| market_id_a | VARCHAR(255) | Polymarket 市场 UUID（A） |
| market_id_b | VARCHAR(255) | Polymarket 市场 UUID（B） |
| canonical_key | VARCHAR(512) UNIQUE | 规范唯一键 `min(id_a,id_b)_max(...)`，策略侧去重写入用（**UNIQUE**，勿与之冲突） |
| question_a | TEXT | A 市场预测问题 |
| question_b | TEXT | B 市场预测问题 |
| has_dependency | TINYINT(1) | 是否存在依赖关系（0/1） |
| dependency_type | VARCHAR(50) | 依赖类型：`subset` / `implies` / `mutual_exclusive` / `equivalent` / `none` |
| direction | VARCHAR(50) | 方向：`A_subset_of_B` / `B_subset_of_A` / `A_implies_B` / `B_implies_A` / `equivalent` / `none` |
| legal_combinations | JSON | 合法结果组合列表，如 `[{"market_a":"YES","market_b":"YES"},...]`（审核弹窗中展示） |
| confidence | DECIMAL(4,3) | AI 置信度，范围 0.000 ~ 1.000 |
| reasoning | TEXT | AI 推理说明 |
| status | VARCHAR(50) | 当前状态（见枚举），默认 `rejected` |
| review_action | VARCHAR(50) | 人工操作结果：`approved` / `rejected`（**NOT NULL，默认空串** 表示未审核） |
| review_note | TEXT | 人工审核备注（最多 500 字） |
| reviewed_at | DATETIME | 人工审核时间 |
| run_machine_name | VARCHAR(100) | 负责该市场对的机器名（由 market_pair_distributor 分配，admin 不写） |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间（on update CURRENT_TIMESTAMP） |

**status 枚举**

| 值 | 说明 | 页面标签颜色 |
|----|------|------------|
| `review` | AI 低置信度，等待人工复核 | 橙色 |
| `ai_review` | AI 复核中，同样等待人工确认（与 `review` 同为待复核） | 蓝色 |
| `monitoring` | 已通过审核，进入套利监控 | 绿色 |
| `rejected` | 人工拒绝 | 红色（默认） |
| `expired` | 已过期 | 红色 |
| `archived` | 已归档（过期/失效后归档，生产库中数量最多，只读留档） | 灰色 |

> **数据异常提示（2026-06-09）**：生产库 `market_pair.status` 存在 11 行脏值 `ali`（review_action 为空、reviewed_at NULL），疑似历史误操作产生，非合法枚举，代码未纳入白名单；如需清理请由策略侧在生产库直接处理（admin 侧只读）。

**dependency_type 枚举**

| 值 | 中文 | 标签颜色 |
|----|------|---------|
| `subset` | 子集 | 蓝色 `#1E9FFF` |
| `implies` | 蕴含 | 紫色 `#9900ff` |
| `mutual_exclusive` | 互斥 | 橙色 |
| `equivalent` | 等价 | 绿色 |
| `none` | 无 | 灰色 |

---

### 3.2 arb_record（套利交易记录）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT PK | 主键 |
| market_pair_id | BIGINT | 关联 market_pair.id |
| market_id_a / market_id_b | VARCHAR | 市场 UUID |
| dependency_type | VARCHAR(50) | 依赖类型（同 market_pair） |
| side_a / side_b | VARCHAR(10) | 方向：`YES` / `NO` |
| price_a / price_b | DECIMAL(10,6) | 限价 |
| quantity | DECIMAL(18,6) | 计划下单量 |
| fill_qty_a / fill_qty_b | DECIMAL(18,6) | A/B 腿实际成交量 |
| net_qty_a / net_qty_b | DECIMAL(18,6) | A/B 腿净持仓量 |
| exposure_qty | DECIMAL(18,6) | 单腿暴露数量 |
| est_profit | DECIMAL(18,6) | 预估利润 |
| actual_profit | DECIMAL(18,6) | 实际利润（结算后写入） |
| price_deviation | DECIMAL(10,6) | 触发时的价格偏差（列表已展示） |
| status | VARCHAR(50) | 执行状态（见枚举），默认 `pending` |
| fail_reason | TEXT | 失败原因 |
| retry_count | INT | 重试次数（>0 列表中红色，已展示） |
| dependency_type | VARCHAR(50) | 依赖类型（**可为 NULL**） |
| token_id_a / token_id_b | VARCHAR(255) | A/B 腿 Polymarket token id |
| neg_risk_a / neg_risk_b | TINYINT(1) | A/B 腿是否 neg-risk 市场 |
| assigned_machine | VARCHAR(100) | 执行机器名（`MACHINE_NAME` 环境变量） |
| account_id | BIGINT UNSIGNED | 执行账户 id |
| order_id_a | VARCHAR(128) | A 腿订单 id |
| claimed_at | DATETIME | 被执行机领取时间 |
| single_leg_exposed_at | DATETIME | 进入单腿暴露状态时间 |
| executed_at | DATETIME | 下单执行时间 |
| created_at | DATETIME | 记录创建时间 |
| updated_at | DATETIME | 更新时间（on update CURRENT_TIMESTAMP） |

**status 枚举**

| 值 | 中文 | 标签颜色 |
|----|------|---------|
| `pending` | 待执行 | 蓝灰 `#8e9daa` |
| `executed` | 双腿已下单 | 蓝色 `#1E9FFF` |
| `completed` | 已结算完成 | 绿色 |
| `cancelled` | 已取消 | 灰色 |
| `failed` | 执行失败 | 红色 `#ff5722` |
| `single_leg_exposed` | 单腿暴露中（风险⚠）| 橙色 |
| `single_leg_closed` | 单腿已平仓 | 黄色 `#e6b800` |
| `archived` | 已归档 | 灰色 |

---

### 3.3 pnl_daily（每日 PNL 快照）

由 fgmm-rmpd 策略每日凌晨 00:05 自动写入，只读。

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| date | VARCHAR(10) | 统计日期（`YYYY-MM-DD` 字符串，不是 DATE 类型）|
| total_trades | INT | 当日总交易笔数 |
| executed_count | INT | 当日双腿已下单笔数（status=`executed`）|
| failed_count | INT | 当日执行失败笔数（status=`failed`）|
| closed_count | INT | 当日单腿已平笔数（status=`single_leg_closed`）|
| single_leg_count | INT | 当日单腿暴露中笔数（status=`single_leg_exposed`）|
| success_rate | DECIMAL(6,4) | 当日成功率（预计算，0.0000~1.0000）|
| total_est_profit | DECIMAL(18,6) | 当日预估利润汇总 |
| total_actual_profit | DECIMAL(18,6) | 当日实际利润汇总 |
| avg_profit_per_trade | DECIMAL(18,6) | 当日单笔平均利润 |
| total_capital | DECIMAL(18,6) | 当日总投入资金 |
| created_at / updated_at | DATETIME | 快照写入与更新时间 |

---

### 3.4 pnl_daily_dim（按维度分组的每日 PNL）

> **注意**：本表只按状态拆分了三列计数（`executed_count` / `failed_count` / `closed_count`），**没有** `total_trades` / `success_count` 字段。需要在查询里用表达式聚合：
> - 当日该维度总交易数 = `executed_count + failed_count + closed_count`
> - 当日该维度成功数   = `executed_count + closed_count`（与 arb_record 状态枚举中"成功"的定义一致：`executed` + `single_leg_closed`，`completed` 已合并进 executed_count）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| date | VARCHAR(10) | 统计日期（`YYYY-MM-DD` 字符串）|
| dim_type | VARCHAR(20) | 维度类型，当前仅 `dependency_type` |
| dim_value | VARCHAR(255) | 维度值（如 `subset`、`implies`、`mutual_exclusive`、`equivalent`）|
| executed_count | INT | 当日该维度 status=`executed` 笔数 |
| failed_count | INT | 当日该维度 status=`failed` 笔数 |
| closed_count | INT | 当日该维度 status=`single_leg_closed` 笔数 |
| total_actual_profit | DECIMAL(18,6) | 当日该维度实际利润 |
| total_capital | DECIMAL(18,6) | 当日该维度投入资金 |
| success_rate | DECIMAL(6,4) | 当日该维度成功率（预计算）|
| created_at / updated_at | DATETIME | 快照写入与更新时间 |

---

### 3.5 accounts（Polymarket 账户）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| vendor | VARCHAR | 平台：`POLYMARKET`（**实际存储为大写**，代码中用此值精确匹配）|
| balance | DECIMAL | 总余额 |
| available_balance | DECIMAL | 可用余额 |
| updated_at | DATETIME | 最后同步时间（每 5 分钟同步） |

---

### 3.6 risk_config（风控配置）

单行表，策略运行时读取，只通过直接操作数据库修改。

| 字段 | 类型 | 说明 |
|------|------|------|
| is_enabled | TINYINT(1) | 0=未启用，1=已启用（策略总开关） |
| is_paused | TINYINT(1) | 0=运行中，1=已暂停 |
| pair_review_mode | VARCHAR(10) | **已废弃**（代码不再读取，DB 列暂物理保留待后续 DROP）。旧义：市场对审核模式 `ai`/`manual` |
| human_review_required | TINYINT(1) | AI 二审通过后是否还需人工确认（2026-06-13 新增）。`1`=转 `review` 人工确认后才 monitoring；`0`=直接 monitoring（全自动）。AI 二审已改为**必经**，拒绝的一律直接 rejected，不受此字段影响。默认 1，当前生产置 0 |
| max_position_per_trade | DECIMAL(18,6) | 单笔最大仓位（USD） |
| max_concurrent_pairs | INT | 同时监控对数上限 |
| min_position_per_trade | DECIMAL(18,6) | 最小下单金额-单腿（USD） |
| min_order_quantity | INT | 最小下单数量-单腿（份，**INT**） |
| max_daily_loss | DECIMAL(18,6) | 单日最大亏损止损（USD） |
| max_daily_capital | DECIMAL(18,6) | 单日最大投入资金（USD） |
| min_profit_threshold | DECIMAL(10,6) | 最低利润门槛（价格差） |
| min_price_deviation | DECIMAL(10,6) | 触发套利的最小价格偏差 |
| llm_confidence_threshold | DECIMAL(4,3) | AI 自动通过阈值（字段仍在表中，但 2026-06-11 起 admin 风控页**不再展示**——产品已弃用该字段） |
| llm_save_confidence_threshold | DECIMAL(4,3) | AI 最低保存阈值，低于此值不入库 |
| close_worst_price | DECIMAL(4,3) | 单腿平仓可接受的最差价 |
| max_single_leg_exposure_sec | INT | 单腿暴露最大容忍时间（秒） |
| monitor_inline_execute | TINYINT(1) | 监控发现机会是否当场下单（2026-06-22 新增）。`1`=监控即执行：arb_monitor 写 pending 后立即 claim 并用最新盘口直接下单，arb_executor 兜底未完成/竞争失败的记录；`0`=解耦：monitor 只写 pending，由 arb_executor 认领后重校验再下单。动机：monitor 4s/轮、executor 2s/轮，两轮间机会可能消失，inline 执行消除该空窗。默认 1 |
| created_at / updated_at | DATETIME | 创建/最后更新时间 |

> **注意**：旧版文档中的 `run_mode` 字段在当前生产库中**已不存在**；只读页改用 `is_enabled` + `is_paused` + `human_review_required` 展示运行态（原 `pair_review_mode` 已废弃，不再展示）。

---

### 3.7 ai_call_log（AI 调用日志）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT PK | 主键 |
| model | VARCHAR | Claude 模型名（如 `claude-opus-4-7`） |
| scan_mode | VARCHAR | 扫描模式。当前在跑两种：`arb_ai_scan`（套利机会扫描）/ `ai_pair_review`（市场对依赖审核）；`incremental`（增量，2026-05-30 后停用）与 `full_batch_N_of_M`（早期一次性批量）为历史值 |
| input_tokens | INT | 输入 Token 数 |
| output_tokens | INT | 输出 Token 数 |
| cache_write_tokens | INT | 缓存写入 Token 数 |
| cache_read_tokens | INT | 缓存命中 Token 数 |
| rounds | INT | 对话轮次 |
| elapsed | VARCHAR | 耗时字符串，如 `1m32s` |
| estimated_cost_usd | DECIMAL(12,6) | 本次调用估算费用（USD） |
| pairs_count | INT | 本次发现的市场对数量 |
| created_at | DATETIME | 扫描时间 |

---

## 四、控制器 API

### 4.1 MarketPair（市场对审核）

**路由前缀**：`/admin/rmpd/market_pair/`  
**页面地址**：`/admin.html#/rmpd/market_pair/index.html`

#### `GET index`

列出市场对。**默认队列（2026-06-13）**：未选状态（或选"全部"）时，仅显示 `status='review'`（待人工审核）。`ai_review`（AI 复核中）、`monitoring`（监控中）及终态 `rejected`/`expired`/`archived` 默认不显示，需在下拉里单独选。

**排序规则（2026-06-13）**：默认队列视图（`review`）按 `confidence DESC, id DESC` 排序——领导要求优先处理高置信度的市场对。选了具体状态时仍按 `id DESC`（最新在前）。

**URL 参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| status | string | 状态过滤（默认/空=仅 `review`，可选具体值 `review`/`ai_review`/`monitoring`/`rejected`/`expired`/`archived`） |
| dependency_type | string | 依赖类型过滤（枚举值，空=全部） |
| question | string | 模糊匹配 `question_a` OR `question_b` |
| date_range | string | 格式 `YYYY-MM-DD - YYYY-MM-DD`，过滤 `created_at` |
| page | int | 页码，默认 1 |
| pageSize | int | 每页条数，默认 20 |

**视图变量**

| 变量 | 类型 | 说明 |
|------|------|------|
| `$list` | array | 当页记录，`created_at`/`reviewed_at` 已格式化为 `Y-m-d H:i:s` |
| `$pagehtml` | string | 分页 HTML |
| `$get` | array | 原始 GET 参数（供搜索表单回显） |
| `$statuses` | array | `[status_value => '中文标签']` |
| `$dep_types` | array | `[dep_type_value => '中文(英文)标签']` |

#### `POST approve`

通过审核：待复核态（`review` / `ai_review`）→ `monitoring`，`review_action: approved`。

**请求体**

| 参数 | 类型 | 说明 |
|------|------|------|
| ids | string | 逗号分隔的 ID 列表，如 `1` 或 `1,2,3` |
| review_note | string | 审核备注（可选，最多 500 字） |

**响应**（ThinkAdmin JSON 格式）

```json
{"code": 1, "msg": "成功处理 N 条，跳过 M 条（非待复核状态）"}
```

**核心 SQL**

```sql
UPDATE market_pair
SET status='monitoring', review_action='approved', review_note=?, reviewed_at=NOW()
WHERE id IN (?) AND status IN ('review','ai_review')
```

#### `POST reject`

拒绝审核：任何非终态（`review` / `ai_review` / `monitoring`）→ `rejected`，`review_action: rejected`。终态（`rejected`/`expired`/`archived`）不可拒绝。

**请求体**：同 `approve`。

**核心 SQL**

```sql
UPDATE market_pair
SET status='rejected', review_action='rejected', review_note=?, reviewed_at=NOW()
WHERE id IN (?) AND status IN ('review','ai_review','monitoring')
```

> **并发安全 + 终态保护**：WHERE 条件加状态白名单（approve 为 `review`/`ai_review`，reject 为 `review`/`ai_review`/`monitoring`），防止并发下重复覆写已处理记录，并阻止对终态行的误操作。`affected` 行数与 `count(ids)` 差值即跳过数量，响应消息中告知用户。

---

### 4.2 ArbRecord（交易记录明细）

**路由前缀**：`/admin/rmpd/arb_record/`  
**页面地址**：`/admin.html#/rmpd/arb_record/index.html`

#### `GET index`

**防全表扫描默认行为**：当无任何过滤条件（`$hasFilter = false`）时，自动追加 `created_at >= NOW() - INTERVAL 30 DAY`。

**URL 参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| market_pair_id | int | 精确匹配 market_pair_id |
| status | string | 执行状态枚举 |
| dependency_type | string | 依赖类型枚举 |
| assigned_machine | string | 精确匹配执行机器名（经 sanitizeLike 转义，=号比较） |
| date_range | string | 格式同 MarketPair |
| page / pageSize | int | 同 MarketPair |

**视图变量**

| 变量 | 说明 |
|------|------|
| `$list` | 当页记录，`executed_at`/`created_at` 已格式化 |
| `$statuses` | arb_record 状态标签 |
| `$dep_types` | 依赖类型标签（仅 4 种，不含 `none`） |

---

### 4.3 PnlReport（PNL 报表）

**路由前缀**：`/admin/rmpd/pnl_report/`  
**页面地址**：`/admin.html#/rmpd/pnl_report/index.html`

#### `GET index`

**URL 参数**

| 参数 | 值 | 说明 |
|------|----|------|
| days | 7 / 30（默认）/ 90 | 趋势图 & 分组统计的时间窗口 |

**数据查询说明**

| 数据集 | 来源 | 是否受 days 参数影响 |
|--------|------|---------------------|
| 顶部汇总卡片（10 个指标）| arb_record + market_pair + accounts | 否（全量实时） |
| 年化收益率 | arb_record 首笔 created_at 至今 | 否 |
| 趋势图数据 | pnl_daily WHERE date >= NOW()-days | 是 |
| 期间小汇总 | pnl_daily (trades) + arb_record (successes) | 是 |
| 分组统计 | pnl_daily_dim WHERE date >= NOW()-days | 是 |

**汇总卡片计算逻辑**

```
成功率 = COUNT(status IN executed/completed/single_leg_closed)
       / COUNT(status != pending) × 100%

年化收益率 = (累计实际利润 / 总投入资金) × (365 / 运行天数) × 100%
  运行天数 = CEIL((NOW - MIN(arb_record.created_at)) / 86400)

总投入资金 = SUM(price_a × fill_qty_a + price_b × fill_qty_b)
  WHERE status IN (executed, completed, single_leg_closed)
```

**视图变量**

| 变量 | 类型 | 说明 |
|------|------|------|
| `$days` | int | 当前时间范围（7/30/90） |
| `$monitoringCount` | int | 监控中市场对数 |
| `$singleLegCount` | int | 单腿暴露中笔数（>0 时卡片橙色警告） |
| `$totalTrades` | int | 总交易笔数 |
| `$successRate` | float | 成功率（%，保留1位小数） |
| `$totalEstProfit` | float | 总预估利润（USD） |
| `$totalActualProfit` | float | 累计实际利润（USD） |
| `$totalCapital` | float | 总投入资金（USD） |
| `$annualizedReturn` | string | 年化收益率（`X.XX%` 或 `N/A`） |
| `$accountBalance` | float | Polymarket 账户总余额 |
| `$accountAvailable` | float | Polymarket 账户可用余额 |
| `$accountUpdatedAt` | string | 余额最后同步时间 |
| `$chartDates` | array | 趋势图 X 轴日期 |
| `$chartActual` | array | 趋势图实际利润数列 |
| `$chartEst` | array | 趋势图预估利润数列 |
| `$periodActual` | float | 期间总实际利润 |
| `$periodTrades` | int | 期间总交易数（来自 pnl_daily） |
| `$periodRate` | string | 期间成功率（混合来源，见注意） |
| `$periodRoi` | string | 期间 ROI |
| `$dimRows` | array | 分组统计行，每行含 trades/successes/success_rate/actual_profit/capital/roi |

> **ECharts 加载策略**：优先使用页面已加载的全局 `echarts` 对象；若不存在，动态从 `jsdelivr CDN` 加载。

---

### 4.4 RiskConfig（风控配置只读）

**路由前缀**：`/admin/rmpd/risk_config/`  
**页面地址**：`/admin.html#/rmpd/risk_config/index.html`

#### `GET index`

读取 `risk_config` 表第一行（`find()`），赋值 `$config` 数组到视图。无数据时 `$config = []`，视图显示"暂无数据"占位。

**只读说明**：本页不提供任何写入入口。视图右上角显示"⚠ 只读模式，如需修改请直接操作数据库"。

---

### 4.5 AiCallLog（AI 扫描日志）

**路由前缀**：`/admin/rmpd/ai_call_log/`  
**页面地址**：`/admin.html#/rmpd/ai_call_log/index.html`

#### `GET index`

**顶部汇总卡片**：固定统计近 30 天，不受搜索条件影响。

```sql
SELECT COUNT(*), SUM(estimated_cost_usd), AVG(estimated_cost_usd), SUM(pairs_count)
FROM ai_call_log
WHERE created_at >= NOW() - INTERVAL 30 DAY
```

**列表查询**：若未提供 `date_range`，默认限制为近 30 天。

**URL 参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| date_range | string | 格式同 MarketPair，默认近 30 天 |
| scan_mode | string | 扫描模式过滤（精确匹配） |
| page / pageSize | int | 同 MarketPair |

**费用高亮**：`estimated_cost_usd > $5.00` 时列表中该格显示红色加粗。

---

## 五、公共基础设施

### 5.1 Enum 常量（`app/rmpd/const/Enum.php`）

```
市场对状态:  MP_STATUS_REVIEW / AI_REVIEW / MONITORING / REJECTED / EXPIRED / ARCHIVED
审核动作:    MP_ACTION_APPROVED / REJECTED
依赖类型:    DEP_TYPE_SUBSET / IMPLIES / MUTUAL_EXCLUSIVE / EQUIVALENT / NONE
套利状态:    ARB_PENDING / EXECUTED / COMPLETED / CANCELLED / FAILED
             ARB_SINGLE_LEG_EXPOSED / SINGLE_LEG_CLOSED
```

静态方法：`getAllMpStatuses()` / `getAllDepTypes()` / `getAllArbStatuses()` 返回对应枚举值数组，供 `sanitizeEnum()` 白名单校验使用。

### 5.2 SafeQueryTrait（`app/rmpd/trait/SafeQueryTrait.php`）

| 方法 | 用途 |
|------|------|
| `sanitizeLike(string $value, int $maxLength = 100): string` | 截断 + 转义 LIKE 特殊字符（`%` `_` `\`），防通配符注入 |
| `sanitizeId($value): ?int` | 验证正整数，非法值返回 null |
| `sanitizeEnum($value, array $allowed): ?string` | 严格类型白名单校验，不在列表中返回 null |
| `sanitizeDateRange(string $range): ?array` | 解析 `YYYY-MM-DD - YYYY-MM-DD` 格式，返回 `[start, end]` 或 null |

所有 5 个控制器中，用户输入的过滤条件均必须经过以上方法之一过滤后才能用于查询。

### 5.3 视图层规范

| 规范项 | 说明 |
|--------|------|
| 模板继承 | `{extend name="../../admin/view/main"}` |
| block 占位符 | `button`（工具栏）、`content`（主内容）、`script`（样式和JS） |
| 搜索表单 | 独立 `index_search.html`，通过 `{include file="..."}` 嵌入 |
| 分页 | ThinkPHP `paginate()` 生成，`$pagehtml|raw` 输出；`{empty name='list'}` 空状态 |
| 数字格式 | 价格/利润用 `number_format(value, N)`；Token 数用 `number_format((int)value)` |
| 截断展示 | 超过阈值字数用 `mb_substr(value, 0, N)…`，`title` 属性展示全文（hover tooltip） |
| 枚举 badge | `layui-badge` 类 + `layui-bg-{color}` 或内联 `style="background:..."` |
| 依赖类型颜色 | subset=蓝 / implies=紫 / mutual_exclusive=橙 / equivalent=绿 / none=灰 |
| 审核状态颜色 | review=橙 / ai_review=蓝 / monitoring=绿 / rejected=默认(红) / expired=红 / archived=灰 |
| 套利状态颜色 | pending=蓝灰 / executed=蓝 / completed=绿 / cancelled=灰 / failed=红 / single_leg_exposed=橙 / single_leg_closed=黄 |

---

## 六、安全规范

| 规范 | 实现方式 |
|------|---------|
| SQL 注入防护 | 所有用户输入经 SafeQueryTrait 过滤后使用参数绑定 |
| 状态并发写保护 | 审核 SQL 加状态白名单（approve: `review`/`ai_review`；reject: `review`/`ai_review`/`monitoring`），`affected` 行数 ≠ `count(ids)` 时提示跳过 |
| 敏感字段隔离 | URL 参数不暴露 market_id_a/b 长字段，只传 id |
| 登录鉴权 | 所有 action 加 `@auth true` 注解，由 ThinkAdmin 框架拦截未登录请求 |
| 全表扫描防护 | arb_record、ai_call_log 无条件时强制 30 天默认范围 |

---

## 七、前端交互规范（市场对审核）

### 7.1 审核弹窗（通过 / 拒绝共用）

弹窗 DOM（`#review-modal-body`）通过 `data-*` 属性传参，无 AJAX 预取：

```
btn[data-id]      → POST ids=
btn[data-qa]      → #rm-qa（市场A问题）
btn[data-qb]      → #rm-qb（市场B问题）
btn[data-dep]     → #rm-dep（依赖类型）
btn[data-conf]    → #rm-conf（AI置信度）
btn[data-reason]  → #rm-reason（AI推理）
```

### 7.2 批量操作流程

```
勾选 checkbox（仅 status=review 行可勾选）
  → 点击"批量通过"/"批量拒绝"
  → 弹窗显示"已选 N 条"
  → 用户可输入统一审核备注
  → POST ids=1,2,3&review_note=...
  → 响应提示"成功 N 条，跳过 M 条"
  → location.reload()
```

---

## 八、关键业务逻辑

### 8.1 RMPD 策略整体流程

```
Claude AI 扫描 Polymarket 市场对依赖关系
  ├─ 高置信度（>= llm_confidence_threshold）→ market_pair.status = 'monitoring'（直接进入）
  └─ 低置信度（< llm_confidence_threshold）→ market_pair.status = 'review'（进入人工审核队列）

人工审核（本系统）：
  ├─ 通过 → UPDATE status='monitoring', review_action='approved'
  └─ 拒绝 → UPDATE status='rejected', review_action='rejected'

套利执行（fgmm-rmpd 服务）：
  监控 status='monitoring' 的市场对价格
  发现套利机会 → 创建 arb_record → 双腿下单
  arb_record.status 转换：
    pending → executed → completed（正常）
                       → single_leg_exposed → single_leg_closed（风险处置）
                       → failed（执行失败）
```

### 8.2 PNL 数据流

```
arb_record（实时写入）
  ↓ 每日 00:05 汇总
pnl_daily（按日 PNL 快照）
pnl_daily_dim（按依赖类型分组 PNL 快照）

accounts（每 5 分钟同步 Polymarket 账户余额）
```

---

## 九、部署注意事项

### 9.1 必须配置的环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `DB_HOST` | 数据库主机（所有连接共用） | 空 |
| `DB_PORT` | 数据库端口 | 空 |
| `DB_USERNAME` | 数据库用户名 | 空 |
| `DB_PASSWORD` | 数据库密码 | 空 |
| `DB_DATABASE_RMPD` | RMPD 库名 | `fgmm_strategy_rmpd` |

### 9.2 ECharts 依赖

PNL 报表页需要 ECharts 5。优先读取页面已全局加载的 `echarts` 对象；若不存在，从 jsDelivr CDN 动态加载。**生产环境建议在项目入口模板中统一引入 ECharts，避免 CDN 依赖。**

---

## 十、变更记录

| 版本 | 日期 | 说明 |
|------|------|------|
| v1.0 | 2026-05-09 | 初版，覆盖 P0-P5 全部模块 |
