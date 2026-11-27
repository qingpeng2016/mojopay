# SO 快盘后台架构与进度

> 最后核对：2026-09-28。快盘基础表口径参考本地 `fgmm-f-alert` 的 `origin/develop`；本次新增成交方向、选项胜负与手续费卡片的生产数据库实数对拍尚未执行。

## 数据源与边界

- `so_strategy` 通过独立连接 `db_fastbook` 只读 `fgmm_so_data`，不修改 SO 表和业务数据。
- MIS 菜单初始化 SQL 只修改 MIS 主库的 `system_menu`、`system_auth_node`。
- 菜单统一通过 `scripts/so_strategy_menu_init.sql` 发布，不再同时维护菜单 migration。`SO策略` 直接以 `pid=0` 创建，和回测中心等一级模块同级，不读取或依赖“对冲策略”。脚本使用显式节点白名单或 `pid + title + node` 精确定位，不使用 `LIKE`、节点前缀匹配或全局同名删除；页面、异步查询和导出节点授予 `system_auth` 中全部已有角色。
- 回测中心旧脚本存在按标题全局删除“快盘策略”的逻辑，重跑时可能影响其他同名菜单；SO 脚本不复用该清理方式。
- develop/prod 的 `db_fastbook` 默认连接 `10.100.0.35:3306/fgmm_so_data`，用户名为 `uat_so`；本地联调可显式通过 `DB_HOST_SO_DATA`、`DB_PORT_SO_DATA`、`DB_DATABASE_SO_DATA`、`DB_USERNAME_SO_DATA` 覆盖 SO 连接（如 253 测试库）。密码仅从 `DB_PASSWORD_SO_DATA` 环境变量注入，不复用主库 `DB_PASSWORD`。

## 当前代码 Schema

上游当前代码定义 5 张实体表和 1 个 PNL 视图。MIS 展示 5 张实体表，PNL 明细和洞察由 `FastbookPnlQuery` 直接聚合基础表，不查询上游视图：

| 数据对象 | 页面核心筛选 |
| --- | --- |
| `market_config` | Market ID、名称、状态、创建时间 |
| `market_options` | 市场、Outcome、Option、状态、交易结束时间 |
| `trade_records` | 市场、选项、Trade ID、买方或卖方账号、成交时间 |
| `market_results` | 市场、Outcome、结果、结算类型、结算时间 |
| `sys_config` | 配置类型、配置键 |
| MIS 基础表 PNL 派生查询 | 市场、Outcome、账号、结果、结算类型、PNL 状态、结算时间 |

当前代码没有 `market_outcomes`、`market_settlement_records`、`account_ref`、`commission_amount` 或 `fully_settled_at`。若 Dylan 后续提交改变 migration，应重新逐表对齐，而不是提前采用未进入当前代码的结构。

## PNL 口径（对冲公式删除 Poly 项）

产品确认直接沿用对冲策略 PNL 公式，删除所有 Poly 相关项。只有已成交数据进入公式；`trade_records` 是 Foregate 成交接口的成交结果表，当前 schema 无订单或未成交状态行。

```text
对冲 PNL
= (FG 卖出成交额 - FG 买入成交额 - Poly 买入最终金额 + Poly 卖出最终金额)
  + (Poly 赢家买入份额 - Poly 赢家卖出份额 - FG 赢家卖出份额 + FG 赢家买入份额)

排除做市账号后，单个客户账号 PNL
= 客户卖出成交额 - 客户买入成交额
  + COALESCE(客户赢家 Option 买入成交份额 - 客户赢家 Option 卖出成交份额, 0)

公司快盘 PNL（不含手续费）
= -SUM(排除做市账号后的客户 PNL)
= SUM(客户买入成交额 - 客户卖出成交额
      - COALESCE(客户赢家买入份额 - 客户赢家卖出份额, 0))
```

此处“公司快盘 PNL”与 Dylan 已结算汇总的主体方向一致：都是公司相对非做市客户的收益。差异是 MIS 不限制结算状态，且根据产品口径不将手续费加入 PNL。账号只用于排除混入的做市数据和计算客户原子值，页面最终按 Outcome 汇总。

- 为兼容已发布 schema，数据库列名仍为 `trading_cashflow`，但从 `45a1431` 起其值和 Go model 语义均为“净交易成本（买入-卖出）”，不得再按现金流方向解读。
- `4edd0fc` 新增 `net_position_quantity`。卡片、趋势、排行和最大亏损统一使用 `SUM(trading_cashflow-COALESCE(settlement_cashflow,0))`计算公司快盘 PNL，不加手续费。
- 手续费来自 `buy_fee_amount` / `sell_fee_amount`，在 MIS 派生查询中按账号汇总为 `trade_fee_amount`，仅单独展示，不计入净交易成本或 PNL。
- 成交列表与 CSV 增加 `maker_side`（FG 买卖方向）及 `is_win`（选项是否胜出）两个派生展示列，不更改 SO 基础表。做市商账号来自必填配置 `fastbook_pnl_excluded_account_ids`（见“做市商账号唯一来源”）：仅买方匹配为 BUY，仅卖方匹配为 SELL，均不匹配或双方均匹配为 UNKNOWN。正常结算时选项代码匹配胜出结果为 WIN、否则 LOSE；未正常结算为 PENDING。`is_win` 与对冲策略目标市场口径一致，不根据 FG 买卖方向翻转。
- 洞察新增“手续费收入”卡片：与总 PNL 复用完全相同的 Foregate 快盘、账号排除和 Outcome 结束时间筛选结果，汇总客户侧 `trade_fee_amount`（买方或卖方账号对应的手续费），不加进 PNL；无成交为 0。两张卡片覆盖同一批 Outcome 和账号，不再采用独立的成交时间筛选。
- 当前代码没有额外佣金字段，后台不展示也不扣除佣金。
- 后台读取必填配置 `fastbook_pnl_excluded_account_ids`，先按账号判定每笔成交的买卖方向并排除做市商账号，再按 `market_config_id + outcome_id` 汇总；当前上游 migration 默认排除 `82`，实际以数据库动态配置为准。账号不是页面统计维度，但不能在原始成交层省略，否则同一笔成交的买卖两边会相互抵消。

## 做市商账号唯一来源

- 做市商账号只有一个来源：`sys_config.fastbook_pnl_excluded_account_ids`（`|` 分隔）。它同时用于 PNL 排除、成交列表的 `maker_side` 判定和报表页的交易用户数排除。
- `config/so_fastbook.php` 已删除：之前硬编码的 `maker_account_id` 会随环境漂移（本地、测试、生产的实际账号不同），现在全部以数据库配置为准，本地演示库把该配置设为 `20292344` 即可复用成交 BUY/SELL 的演示数据。
- `app/so_strategy/service/FastbookAccounts.php` 是唯一读取点（`excludedIds()` / `makerIds()`），缺失配置直接抛异常，避免带错口径算出结果。

## 盈亏报表（用户 / 盘口）

新增 `app/so_strategy/controller/FastbookPnlReport.php` 两个页面，菜单位于 `SO策略 → 快盘策略`：

| 页面 | 统计维度 | 盈亏口径 |
| --- | --- | --- |
| 用户盈亏报表 `userpnl` | `account_id` | 客户 PNL = 卖出成交额 - 买入成交额 + COALESCE(赢家净份额,0)，正数=用户盈利 |
| 盘口盈亏报表 `outcomepnl` | `market_config_id + outcome_id` | 公司快盘 PNL = -SUM(排除做市商账号后的客户 PNL)，正数=公司盈利，与数据洞察一致 |

- 用户页额外给出「用户盈/亏盘口数」（盈利盘口数与亏损盘口数以 `/` 拼接在同一列）、最大单盘口盈利及其 Outcome、最大单盘口亏损及其 Outcome、成交笔数、手续费、首末成交时间；盘口页给出交易笔数、交易用户数、结算结果/类型与窗口时间。
- 「详情」按钮跳转到 `FastbookData/traderecords`：用户页带 `account_id`，盘口页带 `market_config_id + outcome_id`（为此给成交记录数据集补了作用在 `mo.outcome_id` 的 Outcome ID 筛选项）。不携带成交时间：报表按盘口结束时间归属，两者口径不同。
- 两页都支持「导出 CSV」（`so_strategy/fastbook_pnl_report/export`，已在菜单脚本中授权）：复用当前全部筛选 + 同一个下推 SQL，**查询命中多少就导出多少，不再设行数上限**（20000 行上限已移除）。实现上不带 `LIMIT`，只跑一次聚合，再用 `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=false` 的非缓冲游标逐行读取并写入响应，内存恒定；不要改回 OFFSET 分批——那样每批都会重新跑一遍整条聚合 SQL。
- 金额列（盈亏、最大单盘口盈利/亏损、手续费）页面与导出**一律保留数据库原始精度** `decimal(28,10)`，与成交记录、账号 PNL 明细口径一致，页面不再二次四舍五入。时间列：页面展示原始值（带微秒）；导出先截到秒 `Y-m-d H:i:s`，再补一个制表符前缀让 Excel 按文本原样显示。两步都不能省——只截秒，Excel 仍会按系统短日期格式重排成 `2026/9/28 8:00` 吃掉秒；制表符前缀沿用 `FastbookData::csvValue` 对 ID 列的既有做法。
- SO 三个页面的「刷新」按钮原先挂的是 `data-table-refresh`，该属性只对 ThinkAdmin 的 layui 表格（`data-table`）生效，而 SO 页面都是服务端渲染的静态表，所以点了没任何反应。现已改为 `data-page-refresh`，点击调用 admin.js 的 `$.form.reload()`：Hash 路由下重新加载当前内容区并保留筛选/页码，整页直开时退化为 `location.reload()`。
- 时间筛选项名为「时间范围」，初始化参数与数据洞察逐字一致：`laydate.render({elem:'#range-trigger',type:'date',range:true,format:'yyyy-MM-dd',value:start+' - '+end,done:...})`，页面提交两个独立的 `range_start` / `range_end`（UTC 日期）。之前用普通输入框（空值）或 `range:' - '` 时，laydate 校验不通过会弹「日期格式不合法 / 已自动重置」。
- 时间归属沿用洞察口径 `COALESCE(window_end_time, MAX(option.trading_end_at))`，UTC；默认近 30 天，单次跨度上限 90 天（超出自动收敛并在页面顶部显示实际范围）。

### 为什么不用 `FastbookPnlQuery` 直接套一层 WHERE

`FastbookPnlQuery` 的 `account_trades` 是 `trade_records` 两次全表 UNION ALL + GROUP BY，MySQL 8 无法把外层谓词推进这种派生表，恒定全表聚合。报表改用 `FastbookPnlReportQuery` 的索引下推：

1. 候选 Outcome：`market_results` 走 `idx_result_window_end`、`market_options`（未结算回退）走 `idx_option_trading_end`，两者 `UNION ALL`；`NOT EXISTS` 保证每个 Outcome 只来自一个分支，等价于洞察的 `COALESCE(window_end_time, MAX(trading_end_at))`。
2. option 桥：`market_options.uk(market_config_id,outcome_id,option_id)` 取候选 Outcome 的全部 option，此时才 join `market_config` 锁定 FASTBOOK + FOREGATE。**候选分支里不能 join market_config**：实测会把优化器改成从 market_config 驱动、按 `(market_config_id)` 回查结果表，丢掉上面的时间范围扫描。
3. 成交：`trade_records` 用 `idx_trade_option` 点查；账号筛选下推进买卖双方两个 UNION 分支，命中 `idx_trade_buyer_time` / `idx_trade_seller_time`。
4. 聚合：用户页先得 account×outcome 的客户 PNL，再用窗口函数 `ROW_NUMBER()` 取每账号最大单盘口盈/亏及其 Outcome，最后 `GROUP BY account_id` rollup；盘口页单次 `GROUP BY outcome` 同时产出公司 PNL、`COUNT(DISTINCT 成交)` 与去重交易用户数。

保护：时间范围强制 + 90 天上限；查询前先跑候选 Outcome 的 COUNT（只扫两张小表），超过 100000 直接提示缩小范围而不落聚合；每页固定 20 条并忽略外部 `pageSize`。EXPLAIN 验收标准见上：候选分支必须出现 `range` + 上述两个时间索引，成交层出现 `idx_trade_option`。

回归：`php scripts/test_fastbook_pnl_report.php` 在 SQLite 内存库验证新 SQL 与 `FastbookPnlQuery` 在 account×outcome 粒度逐行一致，并覆盖 公司 PNL = -Σ客户 PNL、VOID 不结算、未结算/无成交 Outcome、非 FASTBOOK 市场不入库、时间/账号/方向下推、分页。
- 洞察页固定 `market_type=FASTBOOK` 且 `vendor=FOREGATE`，查询区只保留 UTC 开始、结束日期。
- 洞察不限制 `settlement_type`、`pnl_status` 或 Outcome 状态。未结盘时结算现金流为空，按 0 计算；已产生 NORMAL 赢家时自然增加赢家净份额。CLOSING 最终成交补采期间数值可随新成交实时变化。

## 已实现与验证

- 2026-11-17 用户盈亏 CSV 格式修复：已实现按 `win_lose_outcomes` 字段添加制表符文本前缀，避免 Excel/WPS 将 `1 / 6` 等盈/亏盘口数推断为日期，显示格式保持 `40 / 11`。已在本地 PHP 容器通过 `scripts/test_fastbook_pnl_csv.php`，覆盖日期样式/大计数/零值、CSV 写入回读、页面格式及金额/时间/公式转义；控制器语法和 `git diff --check` 通过。已部署到本地 `fg-marketmaker-mis-local`（8081），后台入口 HTTP 200；桌面 Excel/WPS 实际打开新导出文件的显示效果待用户确认，尚未发布。
- 同日补齐本地预览菜单：确认容器主库为本地 `fgmm_mis_local`，执行现有 `scripts/so_strategy_menu_init.sql` 后 SO 顶级菜单为 1 个，用户/盘口报表菜单均存在，并已执行 `php think clear`。253 TCP 可达，但已检查的本地配置凭据认证失败；按用户最新要求停止测试库连接，保留本地主库。本地 MySQL 尚无 `fgmm_so_data`，报表实际数据与下载链路尚未验证，不能将菜单初始化视为报表验收通过。
- 同日后续修复本地 SO 页面超时（以上“尚无 SO 库”状态已更新）：创建本地 `fgmm_so_data`，采用上游 5 张基础表结构（去掉全部 DROP），插入带 `csv-local-demo` / `local-demo-*` 标识的演示记录，提供 `40 / 11`、`1 / 6`、`4 / 4` 三组用户盘口数。仅在本地容器中将 SO 连接覆盖为本地 MySQL，未修改仓库默认连接及远端数据库。正常验证码登录、后台 SO 菜单、数据洞察、用户盈亏页面及 CSV 下载均经 HTTP 验证通过（各请求约 0.02～0.06 秒）；CSV 三组盘口数均验证含制表符文本保护。仍待用户以 Excel/WPS 打开检查显示效果，尚未发布。
- 已实现 SO 菜单、5 张实体表列表、PNL 明细、核心筛选与 CSV。
- 已实现与对冲策略数据洞察一致的仪表盘布局：日/周/月双趋势图、按 Outcome 的盈利/亏损 Top 10、日期范围局部查询及原有 4 张核心指标卡。本次新增手续费收入卡片及成交方向/选项胜负派生列，验证状态见下。
- 本次功能定向验证：PHP 8.2 容器内完成两个控制器、派生字段服务与配置文件语法检查；`scripts/test_fastbook_so.php` 在 SQLite 内存库用上游同结构的基础表执行成交方向、`is_win` 三态、异常账号以及与客户 PNL 相同的手续费排除 SQL，用例通过；洞察模板脚本完成 JavaScript 语法检查，`git diff --check` 通过。本次尚未验证 SO 页面登录态与 CSV 的实际数据库查询，也未进行生产/测试数据库实数对拍。
- 本地 8081 已启动 `fg-mis-so-review` 预览容器，挂载当前 SO 工作区及账号配置。旧版 ThinkPHP 在 PHP 8.2 下的弃用提示导致 Cookie 响应头报错，已通过仅用于本地预览的 PHP 配置关闭弃用提示的页面输出；当前预览主库 SQLite 尚未初始化 `system_config`，因此登录页仍为 500，不能标为页面验收通过。已核对本地 Docker 没有 `fg-mis-so-mysql` 容器、主机名无法解析，留存的 `fg-mis-local-data` 数据卷中也没有主库 SQLite；本地 SO YAML 凭据用于连接 253 时被拒绝。`172.16.39.253:3306` TCP 可达，IDE 已保存的 `mm` 凭据位于 `master_key` 加密存储、尚未注入容器；SO 默认地址 `10.100.0.35:3306` 从本机连接超时。需待测试库可供容器使用的凭据确定后验证页面与实数。
- 洞察统计统一按 Outcome（一次独立开盘和结算的快盘窗口）聚合账号 PNL：总 PNL、平均每 Outcome PNL、盈利/亏损 Outcome 占比、最大单 Outcome 亏损及排行榜均不使用账号作为分组维度。
- `FastbookPnlQuery` 使用 `trade_records + market_options + market_results + market_config` 生成账号 + Outcome 原子粒度；由于未结盘时结算现金流为 NULL，洞察层统一使用 `SUM(trading_cashflow - COALESCE(settlement_cashflow,0)) GROUP BY market_config_id,outcome_id`计算公司快盘 PNL。
- 当前 MySQL 表结构虽可承载其他 `market_type`，但最新代码只有 `internal/fastbook/collection` 写入链路，且固定写入 `market_type=FASTBOOK`、`vendor=FOREGATE`；后台继续固定 FASTBOOK 条件作为防御边界。
- 已与当前对冲策略代码 `PnlCalcTrait` 交叉核对：对冲策略 Part 1 是 `FG SELL-BUY + Poly SELL-BUY`，Part 2 仅计赢家份额；删除 Poly 后正好得到上述快盘公式。
- SO 没有 FG/Poly 双边对冲数据，因此仅移除对冲页顶部“未对冲份额”模块，以及 Market 分类等对冲专属筛选；快盘查询仍只保留 UTC 开始、结束日期。
- 控制器、派生查询、页面和菜单节点已按当前 `origin/develop` schema 重新对齐；MIS 运行时不再需要 `v_market_account_pnl` 的 SELECT 权限。
- 本地 Docker 演示库使用当前项目 migration 与 mock 数据重建，不再使用其他 schema 快照。
- 本地 mock 逐笔反算已验证公司方向的两个赢家分支：DOWN 获胜时客户 PNL 为 `-216.51933`，公司快盘 PNL 为 `+216.51933`；临时切换为 UP 获胜时客户 PNL 为 `+211.70067`，公司快盘 PNL 为 `-211.70067`。验证后数据已恢复 DOWN。
- 本地 mock 删除结果并临时切换为 OPEN 后，上游 `final_pnl=NULL`，洞察仍以客户净交易成本得到公司快盘 `PNL=+216.51933`；核心卡片、周趋势、Outcome 排行、明细和按 Outcome 结束日期筛选全部包含该未结盘记录，验证后已恢复 CLOSED/DOWN。
- 手续费隔离已用极端值验证：将手续费从 `4.41876184` 临时改为 `1998` 后，公司快盘 PNL 仍为 `216.51933`；验证后手续费已恢复原值。
- PHP 8.2 lint、`git diff --check`、登录态洞察页、PNL 明细、Outcome 聚合、月趋势/排行异步接口、CSV 和做市账号排除已对本次影响范围执行验证。新基础表查询在已结盘、未结盘、切换赢家三个场景与旧视图逐字段一致，测试修改均已回滚。
- 本地 Docker 预览使用 `uat_so` 测试账号，该账号仅有 5 张基础表的 SELECT 权限，显式无权读取 `v_market_account_pnl`；洞察页和卡片接口仍正常返回公司 PNL `216.51933`，证明运行时不依赖该视图。
- 已修复洞察模板中压缩对象字面量 `$.ajax({url:...})` 被 ThinkPHP 误识别为 `{url}` 标签、导致页面脚本截断的问题；周/月现在分别切换为 7/30 个 UTC 日期点，并带加载状态。
- “查看 PNL 明细”已改为 ThinkAdmin `data-open` 内容区导航，避免局部模板被浏览器直接打开而缺少后台全局样式；明细表增加固定表头、横向滚动边界和行悬停样式。
- SO 各数据列表固定每页 20 条，不展示“每页数量”筛选，并忽略外部传入的 `pageSize` 参数。
- SO 六个数据列表（含数据洞察入口的 PNL 明细）分页和筛选统一使用 `/admin.html#/so_strategy/fastbook_data/...` 后台 Hash 路由，不再把局部模板作为整页打开；分页样式已限定在 SO 列表范围，补齐 hover、当前页、禁用态和窄屏横向滚动。
- 本地 8081 预览已挂载当前 `mm3` 工作区并清理框架缓存；本地 `fgmm_so_data` 已增加 45 组带 `pagination-demo-*` 标识的分页演示数据，市场配置、市场选项、成交记录、结算结果和派生 PNL 明细均可直接验收多页效果。控制器 PHP 语法、Hash 分页链接的筛选参数保留及 `git diff --check` 已验证通过。
- 页面业务维度统一描述为“市场”，底层分组键仍是 `market_config_id + outcome_id`。市场盈利/亏损排行榜默认只显示 Outcome ID 值，点击该值复制完整 ID；“最大单市场亏损”卡片固定显示 `Outcome ID：值`，无亏损时显示 `Outcome ID：-`，有值时同样可点击复制。
- SO 菜单统一由 `scripts/so_strategy_menu_init.sql` 初始化，不再保留功能重复的菜单 migration。脚本在临时 MySQL 8 库连续执行两次验证通过：始终生成 1 个 SO 顶级菜单、1 个快盘分组和 7 个页面；3 个模拟角色各获得 10 个节点，共 30 条授权；既有回测中心、对冲策略菜单未被修改。

### 上游 `3eaffe9` 增量影响核对

- 相对上一基线 `4edd0fc`，新增提交 `72d9274`、`8edcd2d` 主要调整告警包与 Fx module 组织，并引入进程内通用缓存、未关闭 Outcome 缓存和中间价 100 点样本缓存。
- `8edcd2d..3eaffe9` 没有改变 PNL 视图的基础表聚合公式、result/option 匹配或 buyer/seller 落库映射；`3eaffe9` 新增无成交 Outcome 不发送 PNL 通知。migration 中 `fastbook_pnl_excluded_account_ids` 默认值由 `82|83|84` 改为 `82`，MIS 未硬编码该值，始终读取 `sys_config`。
- 上游现在启动时加载未关闭 Outcome，并定期从 MySQL 校准；业务正常写市场、结果、状态时会即时回写缓存，CLOSED 会即时移出工作集。该缓存只改变上游任务候选集的读取路径，不替代数据库；MIS 仅读取 MySQL 基础表。
- MIS 始终直接查询 MySQL，不读取上游进程缓存，所以已落库数据会按原口径立即可见。若数据库被上游进程之外的程序直接修改，上游采集任务最多等待下一次 30 秒校准才能感知；这是采集调度时效变化，不是后台查询或 PNL 公式变化。
- 中间价样本仍仅用于价差告警，保存在进程内缓存且重启后重建，没有新增公允价表或可供 MIS 实时 PNL 使用的持久化字段；本次后台不应接入该缓存。
- 已在 Go 1.25 Docker 环境对本次影响范围执行定向测试：`common/cache`、`internal/fastbook/cache`、`application`、`comparison/midprice`、`collection/trade`、`persistence/mysql`、`reporting` 全部通过。

## 外部条件

- MIS develop/prod 的 SO 默认连接为 `10.100.0.35:3306/fgmm_so_data`，用户名 `uat_so`，仓库不保存密码；本地 253 测试环境需单独覆盖 SO 的 host/user 并提供 `DB_PASSWORD_SO_DATA`。目前 Docker 未取得测试库凭据，尚未完成新库实数对拍。
- `db_fastbook` 只保留单库连接必需项，不显式配置主从读写分离、从库编号、严格写字段、断线重连或 SQL 触发参数；SO 模块通过自己的 `app/so_strategy/config/database.php` 选择该连接，不改变 MIS 默认连接及其他模块连接。
- 测试 MIS 主库 `172.16.39.253:3306/mm` 从当前开发机及本地 Docker 均连接超时，因此菜单 SQL 尚未在远端执行；发布时需在能访问该内网地址的部署环境执行 `scripts/so_strategy_menu_init.sql`，随后运行 `php think clear`。
- Dylan 后续代码更新后，需要再次比较当前 migration、model、采集时序和 PNL 公式，再同步后台与本文档的“已实现/已验证”状态。
