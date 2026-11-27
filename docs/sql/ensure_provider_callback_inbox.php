#!/usr/bin/env php
<?php
/**
 * 确保 provider_event_inbox 表存在，并补充上游回调通知类测试数据。
 * 用法：php docs/sql/ensure_provider_callback_inbox.php
 */
declare(strict_types=1);

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET NAMES utf8mb4');

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `provider_event_inbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `dedup_key` VARCHAR(180) COLLATE utf8mb4_bin NOT NULL COMMENT '上游回调去重键',
  `event_type` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `payload` JSON NOT NULL COMMENT '上游回调事件数据',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '上游事件处理状态',
  `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '回调接收时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inbox_dedup` (`provider_account_id`, `dedup_key`),
  KEY `idx_inbox_status` (`status`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='已验签的上游回调接收记录'
SQL);

$acctIds = $pdo->query('SELECT id FROM provider_account ORDER BY id ASC LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
if (!$acctIds) {
    fwrite(STDERR, "no provider_account rows; run seed_v1_testdata.php first\n");
    exit(1);
}

$types = ['payment.paid', 'payment.failed', 'payout.completed', 'refund.completed', 'webhook.test'];
$ins   = $pdo->prepare(
    'INSERT IGNORE INTO provider_event_inbox (provider_account_id,dedup_key,event_type,payload,status,received_at) VALUES (?,?,?,?,?,?)'
);
$inserted = 0;
for ($i = 1; $i <= 24; $i++) {
    $aid = (int)$acctIds[$i % count($acctIds)];
    $dk  = "seed_cb_notify_$i";
    $typ = $types[$i % count($types)];
    $st  = $i % 4 === 0 ? 'DONE' : 'PENDING';
    $ins->execute([
        $aid,
        $dk,
        $typ,
        json_encode(['demo' => true, 'seq' => $i, 'source' => 'ensure_provider_callback_inbox'], JSON_UNESCAPED_UNICODE),
        $st,
        date('Y-m-d H:i:s', strtotime("-{$i} hours")),
    ]);
    $inserted += (int)$ins->rowCount();
}

$nonBalance = (int)$pdo->query("SELECT COUNT(*) FROM provider_event_inbox WHERE event_type NOT LIKE 'balance.%'")->fetchColumn();
echo "provider_event_inbox non-balance rows: $nonBalance (new inserts: $inserted)\n";