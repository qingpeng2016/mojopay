#!/usr/bin/env php
<?php
/**
 * 创建 merchant_event_inbox 表并写入余额流水测试数据。
 * 用法：php docs/sql/ensure_merchant_event_inbox.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sqlFile = $root . '/docs/sql/migration_merchant_event_inbox.sql';

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET NAMES utf8mb4');

if (is_readable($sqlFile)) {
    $pdo->exec(file_get_contents($sqlFile));
}

$acctIds = $pdo->query('SELECT id FROM merchant_account ORDER BY id ASC LIMIT 30')->fetchAll(PDO::FETCH_COLUMN);
if (!$acctIds) {
    fwrite(STDERR, "no merchant_account rows; run seed_v1_testdata.php first\n");
    exit(1);
}

$types = ['balance.snapshot', 'balance.change'];
$ins   = $pdo->prepare(
    'INSERT IGNORE INTO merchant_event_inbox (merchant_account_id,dedup_key,event_type,payload,status,received_at) VALUES (?,?,?,?,?,?)'
);
$inserted = 0;
for ($i = 1; $i <= 30; $i++) {
    $aid = (int)$acctIds[$i % count($acctIds)];
    $dk  = "seed_merchant_balance_$i";
    $typ = $types[$i % 2];
    $st  = $i % 5 === 0 ? 'DONE' : 'PENDING';
    $ins->execute([
        $aid,
        $dk,
        $typ,
        json_encode([
            'demo'    => true,
            'seq'     => $i,
            'asset'   => $i % 2 ? 'USD' : 'USDT',
            'balance' => (string)(100000 + $i * 137),
        ], JSON_UNESCAPED_UNICODE),
        $st,
        date('Y-m-d H:i:s', strtotime("-{$i} hours")),
    ]);
    $inserted += (int)$ins->rowCount();
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM merchant_event_inbox')->fetchColumn();
echo "merchant_event_inbox rows: $total (new inserts: $inserted)\n";

$node = 'mojopay/merchant_event_inbox/index';
$ex   = $pdo->prepare('SELECT id FROM system_auth_node WHERE auth=1 AND node=?');
$ex->execute([$node]);
if (!$ex->fetchColumn()) {
    $pdo->prepare('INSERT INTO system_auth_node (auth,node) VALUES (1,?)')->execute([$node]);
    echo "inserted auth node\n";
}