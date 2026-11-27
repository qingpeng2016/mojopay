#!/usr/bin/env php
<?php
declare(strict_types=1);

$root    = dirname(__DIR__, 2);
$sqlFile = $root . '/docs/sql/migration_payment_order_payment_url.sql';

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

if (!(bool)$pdo->query("SHOW COLUMNS FROM payment_order LIKE 'payment_url'")->fetch() && is_readable($sqlFile)) {
    $pdo->exec(file_get_contents($sqlFile));
    echo "added payment_url column\n";
}

$upd = $pdo->prepare('UPDATE payment_order SET payment_url=? WHERE id=? AND (payment_url IS NULL OR payment_url=\'\')');
$rows = $pdo->query('SELECT id, public_id FROM payment_order')->fetchAll(PDO::FETCH_ASSOC);
$n = 0;
foreach ($rows as $r) {
    $url = sprintf('https://checkout.mojopay.demo/pay/%s?token=%s', $r['public_id'], bin2hex(random_bytes(8)));
    $upd->execute([$url, $r['id']]);
    $n += (int)$upd->rowCount();
}
echo "backfilled payment_url on $n rows\n";

foreach (['mojopay/merchant_payment_order/add', 'mojopay/merchant_payment_order/binding_options'] as $node) {
    $pdo->prepare('INSERT IGNORE INTO system_auth_node (auth,node) VALUES (1,?)')->execute([$node]);
}
echo "auth nodes ok\n";