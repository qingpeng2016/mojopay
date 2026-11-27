#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sql  = $root . '/docs/sql/migration_openapi_checkout.sql';

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

if (is_readable($sql)) {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents($sql)))) as $stmt) {
        if ($stmt !== '') {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate column')) {
                    continue;
                }
                throw $e;
            }
        }
    }
    echo "migration ok\n";
}

$pdo->exec("UPDATE merchant_api_key SET secret_plain = CONCAT('sk_test_', key_id) WHERE (secret_plain IS NULL OR secret_plain = '') AND status = 'ACTIVE'");

$pdo->exec(
    "UPDATE compliance_case c
     JOIN merchant_api_key k ON k.merchant_account_id = c.merchant_account_id
     SET c.status = 'APPROVED'
     WHERE k.status = 'ACTIVE' AND c.purpose = 'KYB' AND c.status <> 'APPROVED'
     LIMIT 50"
);

$row = $pdo->query(
    "SELECT k.key_id, k.secret_plain, k.merchant_account_id, m.code AS merchant_code, ma.id AS merchant_id
     FROM merchant_api_key k
     JOIN merchant_account ma ON ma.id = k.merchant_account_id
     JOIN merchant m ON m.id = ma.merchant_id
     WHERE k.status = 'ACTIVE' AND m.status = 'ACTIVE' AND ma.status = 'ACTIVE'
     ORDER BY k.id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fwrite(STDERR, "no active api key\n");
    exit(1);
}

$keyId  = $row['key_id'];
$secret = $row['secret_plain'];
$body   = json_encode([
    'merchant_order_no' => 'CURL_DEMO_' . date('YmdHis'),
    'amount'            => '88.00',
    'currency'          => 'USD',
    'success_url'       => 'https://merchant.example/success',
    'cancel_url'        => 'https://merchant.example/cancel',
    'customer_country'  => 'US',
], JSON_UNESCAPED_SLASHES);

$ts   = (string)time();
$path = '/api/v1/payments/checkout-sessions';
$hash = hash('sha256', $body);
$payload = $ts . "\nPOST\n" . $path . "\n" . $hash;
$sign = hash_hmac('sha256', $payload, $secret);

echo "\n--- curl (start server: cd mojopay && go run . -run_conf=local) ---\n\n";
echo "KEY_ID=$keyId\nSECRET=$secret\n\n";
echo "curl -sS -X POST 'http://127.0.0.1:8886$path' \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -H 'X-MojoPay-Key-Id: $keyId' \\\n";
echo "  -H 'X-MojoPay-Timestamp: $ts' \\\n";
echo "  -H 'X-MojoPay-Signature: $sign' \\\n";
echo "  -d '$body'\n\n";