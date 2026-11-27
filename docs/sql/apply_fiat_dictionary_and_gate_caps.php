#!/usr/bin/env php
<?php
/**
 * 写入各国法币 asset 字典，并为 Gate Pay 主账号补充法币 PAYIN 通道能力（可重复执行）。
 */
declare(strict_types=1);

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sqlFile = __DIR__ . '/migration_asset_fiat_dictionary.sql';
$sql     = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "cannot read migration\n");
    exit(1);
}
$pdo->exec($sql);
echo "fiat assets migration applied\n";

$fiatCatalog = [
    ['USD', 'US'], ['EUR', 'DE'], ['GBP', 'GB'], ['JPY', 'JP'], ['CNY', 'CN'],
    ['HKD', 'HK'], ['SGD', 'SG'], ['AUD', 'AU'], ['CAD', 'CA'], ['CHF', 'CH'],
    ['KRW', 'KR'], ['INR', 'IN'], ['BRL', 'BR'], ['MXN', 'MX'], ['THB', 'TH'],
    ['MYR', 'MY'], ['PHP', 'PH'], ['IDR', 'ID'], ['TWD', 'TW'], ['AED', 'AE'],
    ['NZD', 'NZ'], ['VND', 'VN'],
];

$assetIdByCode = [];
foreach ($pdo->query('SELECT id, code FROM asset') as $row) {
    $assetIdByCode[(string)$row['code']] = (int)$row['id'];
}

$gatePayId = (int)$pdo->query("SELECT id FROM provider WHERE code='GATE_PAY'")->fetchColumn();
if ($gatePayId <= 0) {
    echo "GATE_PAY provider not found, skip capabilities\n";
    exit(0);
}

$ins = $pdo->prepare('INSERT IGNORE INTO channel_capability (provider_account_id,asset_id,operation,method,region,capability_key,version,min_amount,max_amount,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
$inserted = 0;
foreach ($pdo->query("SELECT id, environment FROM provider_account WHERE provider_id=$gatePayId AND role='MASTER'") as $acc) {
    $accId = (int)$acc['id'];
    $env   = strtolower((string)$acc['environment']);
    foreach ($fiatCatalog as [$code, $region]) {
        if (empty($assetIdByCode[$code])) {
            continue;
        }
        $key = 'gate.fiat.' . strtolower($code) . '.payin.' . $env;
        $ins->execute([$accId, $assetIdByCode[$code], 'PAYIN', 'GATE_HOSTED', $region, $key, 1, 0, '999999999', 'ACTIVE']);
        $inserted += $ins->rowCount();
    }
}

echo "gate pay fiat PAYIN capabilities inserted: $inserted\n";
echo 'asset count: ' . $pdo->query('SELECT COUNT(*) FROM asset')->fetchColumn() . "\n";