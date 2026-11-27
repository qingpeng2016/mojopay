<?php
declare(strict_types=1);

require __DIR__ . '/../app/so_strategy/service/FastbookTradeFields.php';
require __DIR__ . '/../app/so_strategy/service/FastbookPnlQuery.php';

use app\so_strategy\service\FastbookPnlQuery;
use app\so_strategy\service\FastbookTradeFields;

/**
 * SO 快盘成交派生字段回归测试。
 *
 * 1) 基础用例（原有用例，保留）：成交方向、is_win 三态、账号排除、手续费归属。
 * 2) is_win 回归用例（本次补充）：覆盖 WIN/LOSE/PENDING 的全部触发条件、选项胜出唯一性、
 *    市场配置隔离，以及 is_win 与做市买卖方向/做市账号配置解耦。
 *
 * 运行：php scripts/test_fastbook_so.php
 */

/**
 * 供基础用例使用的内存库（保持与上游同结构，勿改动既有断言）。
 */
function makeLegacyDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE market_config (id INTEGER PRIMARY KEY, market_type TEXT, vendor TEXT, market_id TEXT, market_name TEXT)');
    $db->exec('CREATE TABLE market_options (id INTEGER PRIMARY KEY, market_config_id INTEGER, outcome_id TEXT, option_code TEXT)');
    $db->exec('CREATE TABLE market_results (market_config_id INTEGER, outcome_id TEXT, settlement_type TEXT, result TEXT, window_start_time TEXT, window_end_time TEXT)');
    $db->exec('CREATE TABLE trade_records (id INTEGER PRIMARY KEY, market_config_id INTEGER, market_option_id INTEGER, buyer_account_id TEXT, seller_account_id TEXT, trade_amount REAL, trade_quantity REAL, buy_fee_amount REAL, sell_fee_amount REAL)');
    $db->exec("INSERT INTO market_config VALUES (1,'FASTBOOK','FOREGATE','market-1','test')");
    $db->exec("INSERT INTO market_options VALUES (1,1,'settled','UP'),(2,1,'settled','DOWN'),(3,1,'open','UP'),(4,1,'void','UP')");
    $db->exec("INSERT INTO market_results VALUES (1,'settled','NORMAL','UP','2026-09-28','2026-09-28'),(1,'void','VOID','UP','2026-09-28','2026-09-28')");
    $db->exec("INSERT INTO trade_records VALUES
     (1,1,1,'20292344','39901',0.8,2,0.2,0.1),
     (2,1,2,'39901','20292344',0.8,2,0.3,0.4),
     (3,1,3,'20292344','39901',0.8,2,0.2,0.1),
     (4,1,4,'39901','20292344',0.8,2,0.3,0.4),
     (5,1,1,'82','39901',0.8,2,10,1.25),
     (6,1,1,'39901','82',0.8,2,1.5,10),
     (7,1,1,'20292344','20292344',0.8,2,0,0)");
    return $db;
}

/**
 * 供 is_win 回归用例使用的内存库：覆盖结算类型、结果空值、选项跨配置等边界。
 */
function makeIsWinDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE market_options (id INTEGER PRIMARY KEY, market_config_id INTEGER, outcome_id TEXT, option_code TEXT)');
    $db->exec('CREATE TABLE market_results (market_config_id INTEGER, outcome_id TEXT, settlement_type TEXT, result TEXT)');
    $db->exec('CREATE TABLE trade_records (id INTEGER PRIMARY KEY, market_config_id INTEGER, market_option_id INTEGER, buyer_account_id TEXT, seller_account_id TEXT, trade_amount REAL, trade_quantity REAL, buy_fee_amount REAL, sell_fee_amount REAL)');

    // 配置 1 覆盖 NORMAL 命中/未中、未结算、VOID、NORMAL 但 result 为空、以及同 outcome 的两个 option。
    // 配置 2 用于验证 market_config 隔离；option 999 归属配置 2，故意被配置 1 的成交引用。
    $db->exec("INSERT INTO market_options VALUES
     (101,1,'o_up','UP'),(102,1,'o_up','DOWN'),
     (103,1,'o_open','UP'),
     (104,1,'o_void','UP'),
     (105,1,'o_null','UP'),
     (106,1,'o_empty','UP'),
     (107,1,'o_keep','UP'),(108,1,'o_keep','DOWN'),
     (201,2,'x1','UP'),(202,2,'x1','DOWN'),
     (999,2,'x1','UP')");
    $db->exec("INSERT INTO market_results VALUES
     (1,'o_up','NORMAL','UP'),
     (1,'o_void','VOID','UP'),
     (1,'o_null','NORMAL',NULL),
     (1,'o_empty','NORMAL',''),
     (1,'o_keep','NORMAL','UP'),
     (2,'x1','NORMAL','DOWN')");
    // o_open 故意没有 market_results 行，模拟尚未结算。
    $db->exec("INSERT INTO trade_records VALUES
     (1,1,101,'1000','2000',0.8,2,0,0),
     (2,1,102,'1000','2000',0.8,2,0,0),
     (3,1,104,'1000','2000',0.8,2,0,0),
     (4,1,105,'1000','2000',0.8,2,0,0),
     (5,1,106,'1000','2000',0.8,2,0,0),
     (6,1,103,'1000','2000',0.8,2,0,0),
     (7,2,201,'1000','2000',0.8,2,0,0),
     (8,2,202,'1000','2000',0.8,2,0,0),
     (9,1,999,'1000','2000',0.8,2,0,0),
     (10,1,107,'20292344','2000',0.8,2,0,0),
     (11,1,107,'2000','20292344',0.8,2,0,0),
     (12,1,107,'20292344','20292344',0.8,2,0,0),
     (13,1,107,'1000','2000',0.8,2,0,0),
     (14,1,108,'20292344',NULL,0.8,2,0,0),
     (15,1,108,NULL,'20292344',0.8,2,0,0),
     (16,1,107,'82','2000',0.8,2,0,0)");
    return $db;
}

/**
 * 用生产 SQL 表达式取每笔成交的派生字段，按成交 ID 索引返回。
 */
function deriveTradeFields(PDO $db, array $makerAccountIds): array
{
    $fields = FastbookTradeFields::select($makerAccountIds);
    $sql = "SELECT id, maker_side, is_win FROM (SELECT {$fields} FROM trade_records t"
        . ' LEFT JOIN market_options mo ON mo.id = t.market_option_id AND mo.market_config_id = t.market_config_id'
        . ' LEFT JOIN market_results mr ON mr.market_config_id = t.market_config_id AND mr.outcome_id = mo.outcome_id) ORDER BY id';
    $out = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = ['maker_side' => $row['maker_side'], 'is_win' => $row['is_win']];
    }
    return $out;
}

function legacyCases(PDO $db): void
{
    $rows = array_values(deriveTradeFields($db, ['20292344']));
    $expected = [
        ['BUY', 'WIN'], ['SELL', 'LOSE'], ['BUY', 'PENDING'], ['SELL', 'PENDING'],
        ['UNKNOWN', 'WIN'], ['UNKNOWN', 'WIN'], ['UNKNOWN', 'WIN'],
    ];
    foreach ($rows as $i => $row) {
        if ([$row['maker_side'], $row['is_win']] !== $expected[$i]) {
            throw new RuntimeException('基础用例成交派生字段不符：' . json_encode($row));
        }
    }
    if (count($rows) !== count($expected)) {
        throw new RuntimeException('基础用例成交 JOIN 改变了原始成交笔数');
    }
}

function isWinRegressionCases(PDO $db): int
{
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 每个用例：成交 ID => [场景说明, 期望 maker_side, 期望 is_win]
    $cases = [
        1  => ['NORMAL 且 option_code=result，选项胜出', 'UNKNOWN', 'WIN'],
        2  => ['NORMAL 且 option_code<>result，选项落败', 'UNKNOWN', 'LOSE'],
        3  => ['VOID 结算，即使 option_code=result', 'UNKNOWN', 'PENDING'],
        4  => ['NORMAL 但 result 为 NULL', 'UNKNOWN', 'PENDING'],
        5  => ['NORMAL 但 result 为空串', 'UNKNOWN', 'PENDING'],
        6  => ['该 outcome 无 market_results 行（未结算）', 'UNKNOWN', 'PENDING'],
        7  => ['配置 2 的成交使用配置 2 的结果（未被配置 1 污染）', 'UNKNOWN', 'LOSE'],
        8  => ['配置 2 成交命中配置 2 的胜出选项', 'UNKNOWN', 'WIN'],
        9  => ['成交引用的 option 归属另一 market_config，关联落空', 'UNKNOWN', 'PENDING'],
        10 => ['做市为买方 → BUY，且与胜负无关', 'BUY', 'WIN'],
        11 => ['做市为卖方、同一选项 → SELL，is_win 不变', 'SELL', 'WIN'],
        12 => ['买卖双方均为做市账号', 'UNKNOWN', 'WIN'],
        13 => ['买卖双方均非做市账号', 'UNKNOWN', 'WIN'],
        14 => ['买方为做市、卖方为 NULL → BUY（NULL 视为非做市）', 'BUY', 'LOSE'],
        15 => ['卖方为做市、买方为 NULL → SELL', 'SELL', 'LOSE'],
        16 => ['另一做市账号（非生产配置）不参与判定', 'UNKNOWN', 'WIN'],
    ];

    $actual = deriveTradeFields($db, ['20292344']);
    if (count($actual) !== count($cases)) {
        throw new RuntimeException(sprintf('is_win 回归：成交行数 %d 与用例数 %d 不符（JOIN 可能放大了行数）', count($actual), count($cases)));
    }
    foreach ($cases as $id => [$desc, $side, $win]) {
        if (!isset($actual[$id])) {
            throw new RuntimeException("is_win 回归：缺少成交 ID {$id}（{$desc}）");
        }
        $got = $actual[$id];
        if ($got['maker_side'] !== $side || $got['is_win'] !== $win) {
            throw new RuntimeException(sprintf(
                'is_win 回归失败 [ID %d %s]：期望 %s/%s，实际 %s/%s',
                $id, $desc, $side, $win, $got['maker_side'], $got['is_win']
            ));
        }
    }

    // is_win 只由「选项 × 结算结果」决定，必须与做市账号配置解耦。
    $alt = deriveTradeFields($db, ['82']);
    if (count($alt) !== count($actual)) {
        throw new RuntimeException('is_win 回归：切换做市账号后成交行数变化');
    }
    foreach ($actual as $id => $row) {
        if ($alt[$id]['is_win'] !== $row['is_win']) {
            throw new RuntimeException(sprintf(
                'is_win 回归失败 [ID %d]：is_win 不应随做市账号变化，%s vs %s',
                $id, $row['is_win'], $alt[$id]['is_win']
            ));
        }
    }
    if ($alt[16]['maker_side'] !== 'BUY' || $alt[10]['maker_side'] !== 'UNKNOWN') {
        throw new RuntimeException(sprintf(
            'is_win 回归失败：做市账号切换后 maker_side 未按新配置重算（ID16=%s 应为 BUY，ID10=%s 应为 UNKNOWN）',
            $alt[16]['maker_side'], $alt[10]['maker_side']
        ));
    }

    return count($cases);
}

function feeAttributionCase(PDO $db): void
{
    $method = new ReflectionMethod(FastbookPnlQuery::class, 'sql');
    $method->setAccessible(true);
    $pnlSql = $method->invoke(null);
    $feeRows = $db->query('SELECT account_id, SUM(trade_fee_amount) AS fee FROM (' . $pnlSql
        . ") pnl WHERE market_type='FASTBOOK' AND vendor='FOREGATE' AND account_id <> '82'"
        . " AND outcome_id='settled' GROUP BY account_id ORDER BY account_id")->fetchAll(PDO::FETCH_ASSOC);
    $fees = array_column($feeRows, 'fee', 'account_id');
    if (isset($fees['82']) || abs((float)($fees['39901'] ?? 0) - 3.15) > 0.000001) {
        throw new RuntimeException('客户手续费归属/排除不符：' . json_encode($fees));
    }
}

legacyCases(makeLegacyDb());
$caseCount = isWinRegressionCases(makeIsWinDb());
feeAttributionCase(makeLegacyDb());

echo "SO 成交方向、is_win 三态、账号排除及手续费归属验证通过\n";
echo "is_win 回归用例通过：{$caseCount} 个场景（含 VOID / result 空值 / 未结算 / 跨配置 / 买卖方向与做市账号解耦）\n";
