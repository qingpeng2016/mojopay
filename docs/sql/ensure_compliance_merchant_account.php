#!/usr/bin/env php
<?php
/**
 * 为 compliance_case 增加 merchant_account_id 并回填测试资质。
 */
declare(strict_types=1);

$root    = dirname(__DIR__, 2);
$sqlFile = $root . '/docs/sql/migration_compliance_merchant_account.sql';

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$hasCol = (bool)$pdo->query("SHOW COLUMNS FROM compliance_case LIKE 'merchant_account_id'")->fetch();
if (!$hasCol && is_readable($sqlFile)) {
    $pdo->exec(file_get_contents($sqlFile));
    echo "applied migration_compliance_merchant_account.sql\n";
}

$pdo->exec(<<<'SQL'
UPDATE compliance_case c
INNER JOIN (
  SELECT merchant_id, MIN(id) AS aid FROM merchant_account GROUP BY merchant_id
) x ON x.merchant_id = c.merchant_id
SET c.merchant_account_id = x.aid
WHERE c.merchant_account_id IS NULL
SQL
);

function pub(string $p): string
{
    return $p . '_' . bin2hex(random_bytes(6));
}

$accounts = $pdo->query('SELECT id, merchant_id, code FROM merchant_account ORDER BY id ASC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
$usersByMerchant = [];
foreach ($pdo->query('SELECT id, merchant_id, external_user_id FROM merchant_user') as $u) {
    $usersByMerchant[(int)$u['merchant_id']][] = $u;
}

$ins = $pdo->prepare(
    'INSERT IGNORE INTO compliance_case (public_id,merchant_id,merchant_account_id,merchant_user_id,subject_type,purpose,country,status,evidence_ref) VALUES (?,?,?,?,?,?,?,?,?)'
);
$added = 0;
foreach (array_slice($accounts, 0, 12) as $i => $acc) {
    $aid = (int)$acc['id'];
    $mid = (int)$acc['merchant_id'];
    $ins->execute([pub('kyb'), $mid, $aid, null, 'MERCHANT', 'KYB', 'US', 'APPROVED', 'vault://demo/kyb/' . $aid]);
    $added += (int)$ins->rowCount();
    $users = $usersByMerchant[$mid] ?? [];
    if ($users) {
        $u = $users[$i % count($users)];
        $ins->execute([pub('kyc'), $mid, $aid, (int)$u['id'], 'MERCHANT_USER', 'KYC', 'HK', 'PENDING', 'vault://demo/kyc/' . $u['id']]);
        $added += (int)$ins->rowCount();
    }
}

$nodes = [
    'mojopay/merchant_compliance/manage',
    'mojopay/merchant_compliance/case_add',
    'mojopay/merchant_compliance/case_edit',
];
$authIns = $pdo->prepare('INSERT IGNORE INTO system_auth_node (auth,node) VALUES (1,?)');
foreach ($nodes as $node) {
    $authIns->execute([$node]);
}

echo "compliance_case with account: " . (int)$pdo->query('SELECT COUNT(*) FROM compliance_case WHERE merchant_account_id IS NOT NULL')->fetchColumn() . "\n";
echo "seed inserts: $added\n";