#!/usr/bin/env php
<?php
/**
 * 同步上游/下游动态侧边栏（system_menu），不依赖 Think 引导。
 * 用法：php mojopay/docs/sql/sync_mojopay_menus.php
 */
declare(strict_types=1);

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$rootId = (int)$pdo->query("SELECT id FROM system_menu WHERE pid=0 AND title='MojoPay' LIMIT 1")->fetchColumn();
if ($rootId <= 0) {
    fwrite(STDERR, "未找到 MojoPay 根菜单，请先执行 mojopay_menu_init.sql\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');

function ensureDir(PDO $pdo, int $rootId, string $title, string $icon, int $sort): int
{
    $st = $pdo->prepare('SELECT id FROM system_menu WHERE pid=? AND title=? LIMIT 1');
    $st->execute([$rootId, $title]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare('UPDATE system_menu SET sort=? WHERE id=?')->execute([$sort, $id]);

        return $id;
    }
    $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status,create_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$rootId, $title, $icon, '', '#', '', '_self', $sort, 1, date('Y-m-d H:i:s')]);

    return (int)$pdo->lastInsertId();
}

function syncHubChildren(
    PDO $pdo,
    int $dirId,
    string $table,
    string $nameCol,
    string $hubNode,
    string $hubUrl,
    string $idParam,
    string $libTitle,
    string $libNode,
    string $libUrl
): void {
    $pdo->prepare('DELETE FROM system_menu WHERE pid=?')->execute([$dirId]);
    $rows = $pdo->query("SELECT id,{$nameCol} AS label,code FROM {$table} ORDER BY {$nameCol} ASC")->fetchAll(PDO::FETCH_ASSOC);
    $sort = 10;
    $ins  = $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status,create_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $title = trim((string)($row['label'] ?? ''));
        if ($title === '') {
            $title = (string)($row['code'] ?? ('#' . $id));
        }
        $ins->execute([$dirId, $title, 'layui-icon layui-icon-app', $hubNode, $hubUrl, $idParam . '=' . $id, '_self', $sort, 1, date('Y-m-d H:i:s')]);
        $sort += 10;
    }
    $ins->execute([$dirId, $libTitle, 'layui-icon layui-icon-add-circle', $libNode, $libUrl, '', '_self', 900, 1, date('Y-m-d H:i:s')]);
}

$upstreamId   = ensureDir($pdo, $rootId, '上游管理', 'layui-icon layui-icon-upload', 20);
$downstreamId = ensureDir($pdo, $rootId, '下游商户', 'layui-icon layui-icon-group', 10);

syncHubChildren(
    $pdo,
    $upstreamId,
    'provider',
    'name',
    'mojopay/provider_hub/index',
    'mojopay/provider_hub/index',
    'provider_id',
    '上游列表',
    'mojopay/provider/index',
    'mojopay/provider/index'
);
syncHubChildren(
    $pdo,
    $downstreamId,
    'merchant',
    'legal_name',
    'mojopay/merchant_hub/index',
    'mojopay/merchant_hub/index',
    'merchant_id',
    '商户列表',
    'mojopay/merchant/index',
    'mojopay/merchant/index'
);

$nodes = [
    'mojopay/provider_hub/index',
    'mojopay/provider/index',
    'mojopay/provider/add',
    'mojopay/provider/edit',
    'mojopay/provider/remove',
    'mojopay/merchant_hub/index',
    'mojopay/merchant/index',
    'mojopay/merchant/add',
    'mojopay/merchant/edit',
    'mojopay/merchant/remove',
    'mojopay/merchant_account/index',
    'mojopay/merchant_account/add',
    'mojopay/merchant_account/edit',
    'mojopay/merchant_account/remove',
    'mojopay/merchant_compliance/manage',
    'mojopay/merchant_compliance/case_add',
    'mojopay/merchant_compliance/case_edit',
    'mojopay/merchant_open_api/index',
    'mojopay/merchant_open_api/add',
    'mojopay/merchant_open_api/edit',
    'mojopay/merchant_open_api/remove',
    'mojopay/merchant_open_api/webhook_add',
    'mojopay/merchant_open_api/webhook_edit',
    'mojopay/merchant_open_api/webhook_remove',
    'mojopay/merchant_channel_binding/index',
    'mojopay/merchant_channel_binding/add',
    'mojopay/merchant_channel_binding/edit',
    'mojopay/merchant_channel_binding/remove',
    'mojopay/merchant_event_outbox/index',
    'mojopay/merchant_event_inbox/index',
    'mojopay/merchant_payment_order/index',
    'mojopay/merchant_payment_order/add',
    'mojopay/merchant_payment_order/binding_options',
];
$authIns = $pdo->prepare('INSERT IGNORE INTO system_auth_node (auth,node) VALUES (1,?)');
foreach ($nodes as $node) {
    $authIns->execute([$node]);
}

echo "Menu sync OK (upstream #{$upstreamId}, downstream #{$downstreamId})\n";