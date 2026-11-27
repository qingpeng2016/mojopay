# RMPD 管理后台 — 产品需求文档 v1.0

**项目**：fg-marketmaker-mis（ThinkPHP 管理后台）  
**模块路径**：`app/rmpd/`  
**开发分支**：`develop-ali`  
**数据库**：RMPD 策略库（独立连接，`app/rmpd/config/database.php`）  
**文档版本**：2026-05-09

---

## 一、背景与目标

### 1.1 背景

fgmm-rmpd 是 Foregate 的 Polymarket 预测市场套利策略系统，核心流程如下：

```
AI 扫描市场对依赖关系
    ↓ 低置信度 → review 队列（需人工审核）
    ↓ 高置信度 → monitoring（直接进入套利监控）
套利执行（arb_monitor / arb_executor）
    ↓
结算 → PNL 记录
```

目前运营人员需要直接操作数据库才能完成市场对审核和收益查看，效率低且存在误操作风险。

### 1.2 目标

在现有 fg-marketmaker-mis 管理后台中新增 RMPD 策略模块，提供：
- **市场对人工审核**：处理 AI 低置信度输出，确认或拒绝依赖关系
- **交易记录明细**：查看每笔套利执行过程，排查异常
- **PNL 报表**：监控策略盈亏健康状况
- **风控配置查看**：只读展示当前运行参数
- **AI 扫描成本监控**：跟踪 Claude API 使用费用

---

## 二、整体架构

### 2.1 菜单结构

```
RMPD 策略管理
├── 市场对审核       → controller/MarketPair.php
├── 交易记录明细     → controller/ArbRecord.php
├── PNL 报表         → controller/PnlReport.php
├── 风控配置         → controller/RiskConfig.php（只读）
└── AI 扫描日志      → controller/AiCallLog.php
```

### 2.2 目录结构

```
app/rmpd/
├── config/
│   └── database.php            # 切换 RMPD 策略库连接
├── const/
│   └── Enum.php                # 状态常量、枚举值
├── controller/
│   ├── MarketPair.php          # 市场对审核（含批量操作）
│   ├── ArbRecord.php           # 交易记录明细
│   ├── PnlReport.php           # PNL 报表
│   ├── RiskConfig.php          # 风控配置只读
│   └── AiCallLog.php           # AI 扫描日志
├── model/
│   ├── MarketPair.php
│   ├── ArbRecord.php
│   ├── PnlDaily.php
│   └── AiCallLog.php
├── trait/
│   └── SafeQueryTrait.php      # 防 SQL 注入（复制自 cool_pay3）
├── view/
│   ├── market_pair/
│   │   ├── index.html
│   │   └── index_search.html
│   ├── arb_record/
│   │   ├── index.html
│   │   └── index_search.html
│   ├── pnl_report/
│   │   └── index.html
│   ├── risk_config/
│   │   └── index.html
│   └── ai_call_log/
│       ├── index.html
│       └── index_search.html
├── docs/
│   └── PRD.md                  # 本文档
└── Service.php                 # 注册到 app 容器
```

### 2.3 技术规范

| 规范项 | 说明 |
|--------|------|
| 框架 | ThinkPHP + ThinkAdmin，参照 cool_pay3 模块结构 |
| 数据库访问 | `Db::name('table_name')`，通过模块 database.php 切换连接 |
| 防注入 | 所有用户输入必须通过 `SafeQueryTrait::sanitizeLike` / `sanitizeDateRange` 过滤 |
| 分页 | 默认 20 条/页，支持 20 / 50 / 100 切换 |
| 视图继承 | `{extend name="../../admin/view/main"}` |
| 默认排序 | 所有列表 `id DESC` |

---

## 三、数据库表说明

> 所有表来自 RMPD 策略库，通过模块级数据库连接访问。

| 表名 | 用途 | 使用模块 |
|------|------|---------|
| `market_pair` | 市场对依赖关系，含审核状态 | 市场对审核、PNL 报表 |
| `arb_record` | 套利执行记录 | 交易记录明细、PNL 报表 |
| `pnl_daily` | 每日 PNL 快照（策略自动写入） | PNL 报表趋势图 |
| `pnl_daily_dim` | 按维度分组的每日 PNL 快照 | PNL 报表分组统计 |
| `accounts` | Polymarket 账户信息（含余额） | PNL 报表账户余额卡片 |
| `risk_config` | 策略风控参数配置 | 风控配置 |
| `ai_call_log` | Claude API 调用记录 | AI 扫描日志 |

---

## 四、模块一：市场对审核（MarketPair）

**数据表**：`market_pair`

**核心价值**：处理 AI 低置信度输出（review 状态），决定是否让该市场对进入套利监控。

### 4.1 market_pair 关键字段

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT | 主键 |
| market_id_a / market_id_b | VARCHAR(255) | Polymarket 市场 UUID |
| question_a / question_b | TEXT | 市场问题文本 |
| dependency_type | VARCHAR(50) | subset / implies / mutual_exclusive / equivalent / none |
| direction | VARCHAR(50) | A_subset_of_B / B_subset_of_A / A_implies_B / B_implies_A / equivalent / none |
| confidence | DECIMAL(4,3) | AI 置信度 0.000~1.000 |
| reasoning | TEXT | AI 推理说明（≤60字） |
| status | VARCHAR(50) | monitoring / rejected / review / expired |
| review_action | VARCHAR(50) | approved / rejected（人工操作后写入） |
| review_note | TEXT | 人工审核备注 |
| reviewed_at | DATETIME | 人工审核时间 |
| created_at | DATETIME | 创建时间 |

### 4.2 列表展示

**默认过滤**：status = 'review'（打开页面直接看待审核队列）

| 列名 | 字段 | 展示规则 |
|------|------|---------|
| ID | id | 数字 |
| 市场A问题 | question_a | 超 35 字截断，title 属性显示全文（hover tooltip） |
| 市场B问题 | question_b | 同上 |
| 依赖类型 | dependency_type | 彩色标签（见 §4.5） |
| 方向 | direction | 文本原样显示 |
| AI 置信度 | confidence | 保留 2 位小数，如 `0.85` |
| AI 推理 | reasoning | 超 40 字截断，hover 全文 |
| 状态 | status | 彩色标签（见 §4.5） |
| 审核备注 | review_note | 超 30 字截断，hover 全文 |
| 审核时间 | reviewed_at | Y-m-d H:i:s |
| 创建时间 | created_at | Y-m-d H:i:s |
| 操作 | — | 通过 / 拒绝（status≠review 时置灰不可点） |

### 4.3 搜索过滤

| 过滤项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| 状态 | 下拉 | review | 全部 / 待复核(review) / 监控中(monitoring) / 已拒绝(rejected) / 已过期(expired) |
| 依赖类型 | 下拉 | 全部 | 全部 / subset / implies / mutual_exclusive / equivalent |
| 问题关键词 | 文本 | — | 模糊匹配 question_a OR question_b |
| 创建时间 | 日期区间 | — | YYYY-MM-DD ~ YYYY-MM-DD |

### 4.4 审核操作

#### 单条审核

**通过**（review → monitoring）

1. 列表行点击"通过"按钮（仅 status=review 可点）
2. 弹出 layui 弹层：
   - 只读展示：市场A问题、市场B问题、依赖类型、AI置信度、AI推理
   - 输入框：审核备注（可选，最多 500 字）
   - 按钮：确认 / 取消
3. 确认后执行：
   ```sql
   UPDATE market_pair
   SET status='monitoring', review_action='approved',
       reviewed_at=NOW(), review_note=?
   WHERE id=? AND status='review'
   ```
4. 成功：Layui toast 提示"审核通过"，刷新列表
5. 失败（如已被他人处理）：提示"操作失败，记录状态已变更"

**拒绝**（review → rejected）

同上，SQL 改为：
```sql
UPDATE market_pair
SET status='rejected', review_action='rejected',
    reviewed_at=NOW(), review_note=?
WHERE id=? AND status='review'
```

#### 批量审核

1. 列表首列增加复选框，工具栏增加"批量通过"和"批量拒绝"按钮
2. 未勾选时点击批量按钮提示"请先选择记录"
3. 弹出确认弹层：
   - 显示"已选 N 条"
   - 统一审核备注输入框（可选）
   - 确认 / 取消
4. 执行：`WHERE id IN (id1,id2,...) AND status='review'`，非 review 状态自动跳过
5. 成功提示："成功处理 N 条，跳过 M 条（非 review 状态）"

### 4.5 枚举值与颜色规范

**status 标签**

| 状态 | 颜色类 | 中文说明 |
|------|--------|---------|
| review | `layui-badge` style orange | 待复核 |
| monitoring | `layui-badge` style green | 监控中 |
| rejected | `layui-badge` | 已拒绝 |
| expired | `layui-badge` style red | 已过期 |

**dependency_type 标签**

| 类型 | 颜色类 | 中文说明 |
|------|--------|---------|
| subset | blue | 子集 |
| implies | purple | 蕴含 |
| mutual_exclusive | orange | 互斥 |
| equivalent | green | 等价 |
| none | gray | 无 |

---

## 五、模块二：交易记录明细（ArbRecord）

**数据表**：`arb_record`

**核心价值**：查看每笔套利执行全过程，排查失败原因，核对利润数据。

### 5.1 arb_record 关键字段

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT | 主键 |
| market_pair_id | BIGINT | 关联市场对 ID |
| market_id_a / market_id_b | VARCHAR | 市场 ID |
| dependency_type | VARCHAR | 依赖类型 |
| side_a / side_b | VARCHAR(10) | YES / NO |
| price_a / price_b | DECIMAL(10,6) | 限价 |
| quantity | DECIMAL(18,6) | 计划下单量 |
| fill_qty_a / fill_qty_b | DECIMAL(18,6) | A/B 腿实际成交量 |
| est_profit | DECIMAL(18,6) | 预估利润 |
| actual_profit | DECIMAL(18,6) | 实际利润（结算后填写） |
| status | VARCHAR(50) | 执行状态（见下方枚举） |
| fail_reason | TEXT | 失败原因 |
| assigned_machine | VARCHAR(100) | 执行机器名 |
| executed_at | DATETIME | 执行时间 |
| created_at | DATETIME | 创建时间 |

### 5.2 列表展示

| 列名 | 字段 | 展示规则 |
|------|------|---------|
| ID | id | |
| 市场对ID | market_pair_id | 可点击，跳转至市场对审核页并自动过滤该 ID |
| 依赖类型 | dependency_type | 彩色标签（同市场对模块颜色规范） |
| A腿方向 | side_a | YES 绿色 / NO 红色 |
| B腿方向 | side_b | YES 绿色 / NO 红色 |
| A腿价格 | price_a | 保留 4 位小数 |
| B腿价格 | price_b | 保留 4 位小数 |
| 计划量 | quantity | 保留 2 位小数 |
| A腿成交量 | fill_qty_a | 保留 2 位小数 |
| B腿成交量 | fill_qty_b | 保留 2 位小数 |
| 预估利润 | est_profit | 保留 4 位小数，正数绿色 |
| 实际利润 | actual_profit | 保留 4 位小数，正数绿色 / 负数红色 |
| 状态 | status | 彩色标签（见 §5.4） |
| 失败原因 | fail_reason | 超 40 字截断，hover 显示全文 |
| 执行机器 | assigned_machine | |
| 执行时间 | executed_at | Y-m-d H:i:s |
| 创建时间 | created_at | Y-m-d H:i:s |

### 5.3 搜索过滤

| 过滤项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| 市场对ID | 数字输入 | — | 精确匹配 market_pair_id |
| 状态 | 下拉 | 全部 | 全部及各状态选项 |
| 依赖类型 | 下拉 | 全部 | subset / implies / mutual_exclusive / equivalent |
| 执行机器 | 文本输入 | — | 精确匹配 assigned_machine |
| 创建时间 | 日期区间 | — | 无条件时默认限近 30 天 |

**默认行为**：无任何过滤条件时自动追加 `created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)`，防全表扫描。

### 5.4 status 标签颜色

| 状态 | 颜色 | 中文说明 |
|------|------|---------|
| pending | 蓝灰 | 待执行 |
| executed | 蓝色 | 双腿已下单 |
| completed | 绿色 | 已结算完成 |
| cancelled | 灰色 | 已取消 |
| failed | 红色 | 执行失败 |
| single_leg_exposed | 橙色 | 单腿暴露中（风险⚠） |
| single_leg_closed | 黄色 | 单腿已平仓 |

---

## 六、模块三：PNL 报表（PnlReport）

**数据来源**：`arb_record`（实时卡片）、`pnl_daily`（趋势图）、`pnl_daily_dim`（分组统计）、`market_pair`（监控数量）、`accounts`（账户余额）

**核心价值**：一屏掌握策略整体盈亏健康状况。

### 6.1 页面布局

```
┌──────────────────────────────────────────┐
│  顶部汇总卡片（10 个指标，两行）           │
├──────────────────────────────────────────┤
│  日 PNL 趋势图（折线，含时间范围切换）    │
│  ┌─ 7天 ─┬─ 30天* ─┬─ 90天 ─┐          │
│  └───────┴─────────┴────────┘          │
│  期间小汇总行                            │
├──────────────────────────────────────────┤
│  按依赖类型分组统计表                    │
└──────────────────────────────────────────┘
```

### 6.2 汇总卡片（实时，不受趋势图时间范围影响）

**第一行：运营健康（4 个卡片）**

| 指标名 | 查询逻辑 | 单位 | 特殊样式 |
|--------|---------|------|---------|
| 监控中市场对 | `SELECT COUNT(*) FROM market_pair WHERE status='monitoring'` | 对 | — |
| 单腿暴露中 | `SELECT COUNT(*) FROM arb_record WHERE status='single_leg_exposed'` | 笔 | > 0 时卡片整体橙色警告 |
| 总交易笔数 | `SELECT COUNT(*) FROM arb_record WHERE status != 'pending'` | 笔 | — |
| 成功率 | 成功笔数 / 总交易笔数 × 100% | % | — |

> 成功笔数 = `COUNT(*) WHERE status IN ('executed','completed','single_leg_closed')`

**第二行：资金盈亏（6 个卡片）**

| 指标名 | 查询逻辑 | 单位 |
|--------|---------|------|
| 总预估利润 | `SUM(est_profit)` FROM arb_record（全部状态） | USD |
| 累计实际利润 | `SUM(actual_profit)` WHERE status IN ('completed','single_leg_closed') | USD |
| 总投入资金 | `SUM(price_a*fill_qty_a + price_b*fill_qty_b)` WHERE status IN ('executed','completed','single_leg_closed') | USD |
| 年化收益率 | `(累计实际利润 / 总投入资金) × (365 / 运行天数)` | % |
| 账户总余额 | `SUM(balance)` FROM accounts WHERE vendor='polymarket' | USD |
| 账户可用余额 | `SUM(available_balance)` FROM accounts WHERE vendor='polymarket' | USD |

> **年化收益率说明**：运行天数 = 首笔 arb_record.created_at 至今日期差；总投入资金为 0 时显示 N/A。  
> **账户余额说明**：由 fgmm-rmpd `account_balance_syncer` 每 5 分钟同步，卡片下方显示"最后更新：accounts.updated_at"。

### 6.3 日 PNL 趋势图

- **图表库**：与项目现有图表库保持一致（ECharts 或 Chart.js）
- **数据来源**：`pnl_daily` 表，按 date 字段排序
- **时间范围**：近 7 天 / 近 30 天（默认）/ 近 90 天，Tab 切换
- **Y 轴两条折线**：
  - 实际利润 `total_actual_profit` — 实线，蓝色
  - 预估利润 `total_est_profit` — 虚线，灰色
- **图表下方小汇总**（随时间范围联动）：

| 期间总实际利润 | 期间总交易数 | 期间平均成功率 | 期间 ROI |
|-------------|------------|-------------|---------|

> 若 pnl_daily 暂无数据（策略刚启动），趋势图显示空状态提示"暂无历史数据，每日凌晨 00:05 自动生成"。

### 6.4 按依赖类型分组统计

- **数据来源**：`pnl_daily_dim` WHERE dim_type='dependency_type'，时间范围与趋势图联动
- **展示形式**：普通 HTML 表格

| 依赖类型 | 交易数 | 成功数 | 成功率 | 累计实际利润(USD) | 总投入资金(USD) | ROI(%) |
|---------|-------|-------|-------|----------------|--------------|--------|

> ROI = 累计实际利润 / 总投入资金 × 100%，保留 2 位小数；总投入资金为 0 时显示 N/A。

---

## 七、模块四：风控配置（RiskConfig，只读）

**数据表**：`risk_config`

**核心价值**：运营快速查看当前策略运行参数，无需登录服务器或查 DB。

**修改方式**：仅允许直接操作数据库（防止误操作，不提供前端修改入口）。

### 7.1 页面布局

```
┌─────────────────────────────────────────────┐
│ ⚠️  [横幅] 运行模式：PROD   策略状态：运行中  │
└─────────────────────────────────────────────┘
┌──────────────┬──────────────┬──────────────┐
│ 仓位控制      │ 资金限制      │ 信号参数      │
│ ...          │ ...          │ ...          │
└──────────────┴──────────────┴──────────────┘
```

### 7.2 展示字段（分组卡片）

**顶部横幅（醒目显示，颜色随状态变化）**

| 参数 | 字段 | 样式 |
|------|------|------|
| 运行模式 | run_mode | prod=绿色大字 / test=橙色大字 |
| 策略状态 | is_paused | 0=绿色"运行中" / 1=红色"已暂停" |

**仓位控制组**

| 参数名 | 字段 | 单位 |
|--------|------|------|
| 单笔最大仓位 | max_position_per_trade | USD |
| 同时监控对数上限 | max_concurrent_pairs | 对 |
| 最小下单金额（单腿） | min_position_per_trade | USD |
| 最小下单数量（单腿） | min_order_quantity | 份 |

**资金风控组**

| 参数名 | 字段 | 单位 |
|--------|------|------|
| 单日最大亏损止损 | max_daily_loss | USD |
| 单日最大投入资金 | max_daily_capital | USD |

**信号参数组**

| 参数名 | 字段 | 说明 |
|--------|------|------|
| 最低利润门槛 | min_profit_threshold | 价格差 |
| 最小价格偏差 | min_price_deviation | 触发套利的最小偏差 |
| AI 置信度阈值 | llm_confidence_threshold | 低于此值转人工复核 |
| 单腿暴露最大时间 | max_single_leg_exposure_sec | 秒 |

**元信息**

| 参数名 | 字段 |
|--------|------|
| 配置更新时间 | updated_at |

---

## 八、模块五：AI 扫描日志（AiCallLog）

**数据表**：`ai_call_log`

**核心价值**：监控 Claude API 调用成本和效率，及时发现异常费用。

### 8.1 顶部汇总卡片（近 30 天）

| 指标 | 计算逻辑 |
|------|---------|
| 总调用次数 | COUNT(*) |
| 总估算费用 | SUM(estimated_cost_usd) USD |
| 总发现市场对数 | SUM(pairs_count) |
| 平均单次费用 | AVG(estimated_cost_usd) USD |
| 平均每对发现成本 | SUM(estimated_cost_usd) / SUM(pairs_count) USD（pairs_count=0 时 N/A） |

### 8.2 列表展示

| 列名 | 字段 | 展示规则 |
|------|------|---------|
| ID | id | |
| 模型 | model | |
| 扫描模式 | scan_mode | |
| 输入 Token | input_tokens | 千分位，如 12,345 |
| 输出 Token | output_tokens | 千分位 |
| 缓存写入 Token | cache_write_tokens | 千分位 |
| 缓存命中 Token | cache_read_tokens | 千分位 |
| 对话轮次 | rounds | |
| 耗时 | elapsed | 原样显示（如 1m32s） |
| 估算费用 | estimated_cost_usd | `$X.XXXXXX`；> $5.00 时红色标注 |
| 发现市场对数 | pairs_count | |
| 扫描时间 | created_at | Y-m-d H:i:s |

### 8.3 搜索过滤

| 过滤项 | 类型 | 默认值 |
|--------|------|--------|
| 创建时间范围 | 日期区间 | 近 30 天 |
| 扫描模式 | 下拉 | 全部（当前仅 incremental） |

---

## 九、安全规范

| 规范 | 要求 |
|------|------|
| SQL 注入防护 | 所有用户输入必须通过 `SafeQueryTrait::sanitizeLike` / `sanitizeDateRange` 过滤 |
| 状态变更防覆写 | 审核操作必须在 WHERE 中加 `AND status='review'`，防止并发覆写已处理记录 |
| 敏感字段 | URL 参数中不暴露 market_id_a/b 等长字段，仅传 id |
| 权限 | 使用 ThinkAdmin 的 `@auth true` 注解，所有接口需登录后访问 |

---

## 十、性能规范

| 规范 | 要求 |
|------|------|
| arb_record 默认范围 | 无过滤条件时自动追加 `created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)` |
| 汇总卡片 | SUM/COUNT 实时查询，接受 1-2 秒响应；数据量增大后可改读 pnl_daily 快照 |
| 趋势图数据 | 读 pnl_daily 快照表，非实时聚合，响应快 |

---

## 十一、开发顺序与工作量估算

| 阶段 | 内容 | 预计工时 |
|------|------|---------|
| P0 | 目录骨架 + 数据库连接配置 + Service 注册 + 菜单接入管理后台 | 0.5 天 |
| P1 | 市场对审核（列表 + 单条审核弹窗 + 批量审核） | 1 天 |
| P2 | 交易记录明细（列表 + 搜索过滤） | 0.5 天 |
| P3 | PNL 报表（汇总卡片 + 趋势图 + 分组统计表） | 1.5 天 |
| P4 | 风控配置只读展示 | 0.5 天 |
| P5 | AI 扫描日志（汇总卡片 + 列表） | 0.5 天 |
| — | **合计** | **4.5 天** |

---

## 十二、变更记录

| 版本 | 日期 | 说明 |
|------|------|------|
| v1.0 | 2026-05-09 | 初版，覆盖全部 5 个模块 |
