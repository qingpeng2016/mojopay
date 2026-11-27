<?php
declare(strict_types=1);

require __DIR__ . '/../app/so_strategy/service/FastbookPnlReportQuery.php';

use app\so_strategy\service\FastbookPnlReportQuery;

/**
 * 快盘盈亏报表 SQL 回归。
 *
 * 验证两件事：
 * 1) 索引下推后的新 SQL 与已有的 FastbookPnlQuery 全量派生表，在账号×Outcome 粒度上逐行一致；
 * 2) 用户/盘口两个维度的汇总指标（盈亏、盈/亏盘口数、最大单盘口盈/亏、交易笔数、交易用户数）正确。
 *
 * 运行：php scripts/test_fastbook_pnl_report.php
 */

const MAKER = '20292344';
/** Structs SQLite 内存库：完整字段用于新 SQL，旧 SQL 只用其中一部分。 */
function makeDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE market_config (id INTEGER PRIMARY KEY, market_type TEXT, vendor TEXT, market_id TEXT, market_name TEXT)');
    $db->exec('CREATE TABLE market_options (id INTEGER PRIMARY KEY, market_config_id INTEGER, outcome_id TEXT, trading_start_at TEXT, trading_end_at TEXT, option_id TEXT, option_code TEXT)');
    $db->exec('CREATE TABLE market_results (market_config_id INTEGER, outcome_id TEXT, window_start_time TEXT, window_end_time TEXT, result TEXT, settlement_type TEXT)');
    $db->exec('CREATE TABLE trade_records (id INTEGER PRIMARY KEY, market_config_id INTEGER, market_option_id INTEGER, trade_id TEXT, buyer_account_id TEXT, seller_account_id TEXT, trade_price REAL, trade_quantity REAL, trade_amount REAL, buy_fee_amount REAL, sell_fee_amount REAL, trade_time TEXT)');

    $db->exec("INSERT INTO market_config VALUES (1,'FASTBOOK','FOREGATE','14','BTC Up or Down 15m'),(2,'SPORTS','FOREGATE','99','其他盘类型')");
    // o1 NORMAL(UP) / o2 VOID / o3 未结算 / o4 NORMAL(DOWN) 但无成交 / o9 在查询范围之外
    $db->exec("INSERT INTO market_options VALUES
      (1,1,'o1','2026-09-01 10:00:00','2026-09-01 10:15:00','0','UP'),
      (2,1,'o1','2026-09-01 10:00:00','2026-09-01 10:15:00','1','DOWN'),
      (3,1,'o2','2026-09-02 10:00:00','2026-09-02 10:15:00','0','UP'),
      (4,1,'o2','2026-09-02 10:00:00','2026-09-02 10:15:00','1','DOWN'),
      (5,1,'o3','2026-09-03 10:00:00','2026-09-03 10:15:00','0','UP'),
      (6,1,'o3','2026-09-03 10:00:00','2026-09-03 10:15:00','1','DOWN'),
      (7,1,'o4','2026-09-04 10:00:00','2026-09-04 10:15:00','0','UP'),
      (8,1,'o4','2026-09-04 10:00:00','2026-09-04 10:15:00','1','DOWN'),
      (9,1,'o9','2026-08-01 10:00:00','2026-08-01 10:15:00','0','UP'),
      (10,1,'o9','2026-08-01 10:00:00','2026-08-01 10:15:00','1','DOWN'),
      (11,2,'s1','2026-09-05 10:00:00','2026-09-05 10:15:00','0','HOME'),
      (12,2,'s1','2026-09-05 10:00:00','2026-09-05 10:15:00','1','AWAY')");
    $db->exec("INSERT INTO market_results VALUES
      (1,'o1','2026-09-01 10:00:00','2026-09-01 10:15:00','UP','NORMAL'),
      (1,'o2','2026-09-02 10:00:00','2026-09-02 10:15:00','UP','VOID'),
      (1,'o4','2026-09-04 10:00:00','2026-09-04 10:15:00','DOWN','NORMAL'),
      (1,'o9','2026-08-01 10:00:00','2026-08-01 10:15:00','UP','NORMAL'),
      (2,'s1','2026-09-05 10:00:00','2026-09-05 10:15:00','HOME','NORMAL')");
    $db->exec("INSERT INTO trade_records VALUES
      (1,1,1,'t1','u1','" . MAKER . "',0.6,10,6,0.1,0.2,'2026-09-01 10:01:00'),
      (2,1,2,'t2','" . MAKER . "','u1',0.5,4,2,0.05,0.15,'2026-09-01 10:02:00'),
      (3,1,1,'t3','u2','u1',0.6,5,3,0.1,0.1,'2026-09-01 10:03:00'),
      (4,1,3,'t4','u1','" . MAKER . "',0.5,2,1,0.01,0.02,'2026-09-02 10:01:00'),
      (5,1,4,'t5','u2','" . MAKER . "',0.5,3,1.5,0.03,0.04,'2026-09-02 10:02:00'),
      (6,1,5,'t6','u1','" . MAKER . "',0.5,7,3.5,0.2,0.1,'2026-09-03 10:01:00'),
      (7,1,6,'t7','" . MAKER . "','u2',0.5,6,3,0.15,0.05,'2026-09-03 10:02:00'),
      (8,1,9,'t8','u1','" . MAKER . "',0.99,100,99,0,0,'2026-08-01 10:01:00'),
      (9,2,11,'t9','u1','" . MAKER . "',0.99,100,99,0,0,'2026-09-05 10:01:00')");
    return $db;
}

function run(PDO $db, array $stmt): array
{
    $handle = $db->prepare($stmt['sql']);
    foreach ($stmt['binds'] as $name => $value) {
        $handle->bindValue(':' . $name, $value);
    }
    $handle->execute();
    return $handle->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 旧口径基线：账号×Outcome 的客户 PNL（-trading_cashflow + COALESCE(settlement_cashflow,0)）。
 *
 * @return array<string,array{market_config_id:string,outcome_id:string,account_id:string,pnl:float}>
 */
function baselineRows(PDO $db, array $outcomeIds): array
{
    require_once __DIR__ . '/../app/so_strategy/service/FastbookPnlQuery.php';
    $method = new ReflectionMethod(\app\so_strategy\service\FastbookPnlQuery::class, 'sql');
    $method->setAccessible(true);
    $sql = $method->invoke(null);
    $placeholders = implode(',', array_fill(0, count($outcomeIds), '?'));
    $where = "market_type='FASTBOOK' AND vendor='FOREGATE'";
    if ($outcomeIds !== []) {
        $where .= " AND outcome_id IN ({$placeholders})";
    }
    $handle = $db->prepare("SELECT market_config_id, outcome_id, account_id,"
        . " (-trading_cashflow + COALESCE(settlement_cashflow,0)) AS pnl,"
        . " trade_fee_amount FROM ({$sql}) pnl WHERE {$where} AND account_id <> '" . MAKER . "'");
    $handle->execute(array_values($outcomeIds));
    $rows = [];
    foreach ($handle->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['market_config_id'] . '|' . $row['outcome_id'] . '|' . $row['account_id'];
        $rows[$key] = [
            'market_config_id' => (string)$row['market_config_id'],
            'outcome_id' => (string)$row['outcome_id'],
            'account_id' => (string)$row['account_id'],
            'pnl' => (float)$row['pnl'],
            'fee' => (float)$row['trade_fee_amount'],
        ];
    }
    return $rows;
}

function assertSame(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.000001) {
        throw new RuntimeException(sprintf('%s：期望 %.8f，实际 %.8f', $message, $expected, $actual));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = makeDb();
$builder = new FastbookPnlReportQuery();
$excluded = [MAKER];
$september = ['start' => '2026-09-01', 'end' => '2026-09-30'];

// 1) 用户维度：与旧口径基线逐行对齐（范围内的 o1/o2/o3/o4）
$baseline = baselineRows($db, ['o1', 'o2', 'o3', 'o4', 'o9']);
$expected = [];
foreach ($baseline as $row) {
    if (!in_array($row['outcome_id'], ['o1', 'o2', 'o3', 'o4'], true)) {
        continue;
    }
    $expected[$row['account_id']]['pnl'] = ($expected[$row['account_id']]['pnl'] ?? 0.0) + $row['pnl'];
    $expected[$row['account_id']]['fee'] = ($expected[$row['account_id']]['fee'] ?? 0.0) + $row['fee'];
    $expected[$row['account_id']]['outcomes'][$row['outcome_id']] = $row['pnl'];
}

$rows = run($db, $builder->userPnlSql($september, $excluded, 50, 0));
usort($rows, static fn(array $a, array $b): int => (float)$b['pnl'] <=> (float)$a['pnl']);
$accounts = array_column($rows, 'account_id');
sort($accounts);
assertTrue($accounts === ['u1', 'u2'], '用户盈亏报表应且仅应包含非做市账号：' . implode(',', $accounts));
assertTrue((float)$rows[0]['pnl'] >= (float)$rows[1]['pnl'], '用户盈亏报表必须按盈亏降序');

foreach ($rows as $row) {
    $accountId = (string)$row['account_id'];
    assertSame($expected[$accountId]['pnl'], (float)$row['pnl'], "账号 {$accountId} 盈亏与旧口径不符");
    assertSame($expected[$accountId]['fee'], (float)$row['fee_amount'], "账号 {$accountId} 手续费与旧口径不符");

    $wins = $losses = [];
    foreach ($expected[$accountId]['outcomes'] as $outcomeId => $pnl) {
        if ($pnl > 0) $wins[$outcomeId] = $pnl;
        elseif ($pnl < 0) $losses[$outcomeId] = $pnl;
    }
    assertSame((float)count($wins), (float)$row['win_outcomes'], "账号 {$accountId} 盈利盘口数不符");
    assertSame((float)count($losses), (float)$row['lose_outcomes'], "账号 {$accountId} 亏损盘口数不符");
    if ($wins !== []) {
        arsort($wins);
        assertSame((float)reset($wins), (float)$row['max_win_pnl'], "账号 {$accountId} 最大单盘口盈利不符");
        assertTrue((string)$row['max_win_outcome'] === (string)array_key_first($wins), "账号 {$accountId} 最大单盘口盈利对应 Outcome 不符");
    } else {
        assertTrue($row['max_win_pnl'] === null, "账号 {$accountId} 无盈利盘口时最大盈利应为空");
    }
    if ($losses !== []) {
        asort($losses);
        assertSame((float)reset($losses), (float)$row['max_loss_pnl'], "账号 {$accountId} 最大单盘口亏损不符");
        assertTrue((string)$row['max_loss_outcome'] === (string)array_key_first($losses), "账号 {$accountId} 最大单盘口亏损对应 Outcome 不符");
    } else {
        assertTrue($row['max_loss_pnl'] === null, "账号 {$accountId} 无亏损盘口时最大亏损应为空");
    }
}

// 2) 盘口维度：公司 PNL = -Σ客户 PNL；无成交的 o4 也要出现
$outcomeRows = run($db, $builder->outcomePnlSql($september, $excluded, 50, 0));
$outcomeIds = array_column($outcomeRows, 'outcome_id');
// 默认排序 = 创建时间（窗口开始时间）降序；o4 无成交被默认隐藏，o3 未结算没有创建时间排最后。
assertTrue($outcomeIds === ['o2', 'o1', 'o3'], '盘口盈亏报表默认排序不符：' . implode(',', $outcomeIds));
$byPnl = run($db, $builder->outcomePnlSql($september + ['sort_field' => 'pnl'], $excluded, 50, 0));
assertTrue((float)$byPnl[0]['pnl'] >= (float)$byPnl[1]['pnl'], '切换为盘口盈亏降序后排序失效');
$byPnlAsc = run($db, $builder->outcomePnlSql($september + ['sort_field' => 'window_start_time', 'sort_dir' => 'asc'], $excluded, 50, 0));
assertTrue(array_column($byPnlAsc, 'outcome_id') === ['o3', 'o1', 'o2'], '创建时间升序不符：' . implode(',', array_column($byPnlAsc, 'outcome_id')));

$sumCompany = 0.0;
$expectedTrades = ['o1' => 3, 'o2' => 2, 'o3' => 2, 'o4' => 0];
$expectedUsers = ['o1' => 2, 'o2' => 2, 'o3' => 2, 'o4' => 0];
foreach ($outcomeRows as $row) {
    $outcomeId = (string)$row['outcome_id'];
    $customerSum = 0.0;
    foreach ($baseline as $item) {
        if ($item['outcome_id'] === $outcomeId) {
            $customerSum += $item['pnl'];
        }
    }
    assertSame(-$customerSum, (float)$row['pnl'], "盘口 {$outcomeId} 公司盈亏不等于客户盈亏取反");
    assertSame((float)$expectedTrades[$outcomeId], (float)$row['trade_count'], "盘口 {$outcomeId} 交易笔数不符");
    assertSame((float)$expectedUsers[$outcomeId], (float)$row['user_count'], "盘口 {$outcomeId} 交易用户数不符");
    $sumCompany += (float)$row['pnl'];
}
$sumUser = 0.0;
foreach ($rows as $row) {
    $sumUser += (float)$row['pnl'];
}
assertSame(-$sumUser, $sumCompany, '两页盈亏总额应互为相反数');

// 3) VOID 盘口不产生结算现金流：o2 只有交易盈亏，且盈利可以为负
foreach ($outcomeRows as $row) {
    if ((string)$row['outcome_id'] === 'o2') {
        $customerSum = 0.0;
        foreach ($baseline as $item) {
            if ($item['outcome_id'] === 'o2') {
                $customerSum += $item['pnl'];
            }
        }
        assertSame(-$customerSum, (float)$row['pnl'], 'VOID 盘口结算项未按 0 计入');
    }
}

// 4) 时间范围下推：只落在 8 月的 o9 不应出现在 9 月的任何一页
$august = ['start' => '2026-08-01', 'end' => '2026-08-31'];
$augustOutcomes = array_column(run($db, $builder->outcomePnlSql($august, $excluded, 50, 0)), 'outcome_id');
assertTrue($augustOutcomes === ['o9'], '8 月范围应只包含 o9：' . implode(',', $augustOutcomes));
$augustUsers = run($db, $builder->userPnlSql($august, $excluded, 50, 0));
assertTrue(count($augustUsers) === 1 && $augustUsers[0]['account_id'] === 'u1', '8 月用户盈亏报表应只有 u1');

// 5) 账号筛选下推：结果必须与全量中该账号一致
$single = run($db, $builder->userPnlSql($september + ['account_id' => 'u2'], $excluded, 50, 0));
assertTrue(count($single) === 1, '账号筛选后应只剩该账号');
foreach ($rows as $row) {
    if ((string)$row['account_id'] === 'u2') {
        assertSame((float)$row['pnl'], (float)$single[0]['pnl'], '账号筛选改变了该账号的盈亏');
        assertSame((float)$row['trade_count'], (float)$single[0]['trade_count'], '账号筛选改变了该账号的成交笔数');
    }
}

// 6) 盈亏方向筛选项
$profit = run($db, $builder->userPnlSql($september + ['direction' => 'profit'], $excluded, 50, 0));
$loss = run($db, $builder->userPnlSql($september + ['direction' => 'loss'], $excluded, 50, 0));
assertTrue(count($profit) + count($loss) <= count($rows), '盈亏方向筛选结果超出全量行数');
foreach ($profit as $row) {
    assertTrue((float)$row['pnl'] > 0, '盈利筛选出现非盈利账号');
}
foreach ($loss as $row) {
    assertTrue((float)$row['pnl'] < 0, '亏损筛选出现非亏损账号');
}

// 7) 分页与总数一致
$userCount = (int)run($db, $builder->userPnlCountSql($september, $excluded))[0]['total'];
$outcomeCount = (int)run($db, $builder->outcomePnlCountSql($september, $excluded))[0]['total'];
$candidateCount = (int)run($db, $builder->candidateCountSql($september))[0]['total'];
assertTrue($userCount === count($rows), '用户报表总数与行数不符');
assertTrue($outcomeCount === count($outcomeRows), '盘口报表总数与行数不符');
assertTrue($candidateCount === 4, 'FASTBOOK 之外的盘口（SPORTS s1）不应计入候选：' . $candidateCount);
$pageOne = run($db, $builder->userPnlSql($september, $excluded, 1, 0));
$pageTwo = run($db, $builder->userPnlSql($september, $excluded, 1, 1));
assertTrue(count($pageOne) === 1 && count($pageTwo) === 1, '分页取数行数不符');
assertTrue($pageOne[0]['account_id'] !== $pageTwo[0]['account_id'], '分页第二页与第一页重复');

echo "快盘盈亏报表回归通过：用户维度逐行对齐旧口径、盘口维度盈亏取反、VOID/未结算/无成交/时间下推/账号下推/方向筛选/分页全部符合预期\n";
