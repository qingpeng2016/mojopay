#!/usr/bin/env php
<?php
/**
 * MojoPay V1 联调测试数据（可重复执行，键前缀 seed_ / SEED_）。
 * 用法：php docs/sql/seed_v1_testdata.php
 */
declare(strict_types=1);

$host = getenv('MOJOPAY_DB_HOST') ?: '216.236.43.172';
$user = getenv('MOJOPAY_DB_USER') ?: 'mojopay';
$pass = getenv('MOJOPAY_DB_PASS') ?: 'PwrRbxXSX5sABeXS';
$db   = getenv('MOJOPAY_DB_NAME') ?: 'mojopay';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("SET NAMES utf8mb4");

function pub(string $p): string
{
    return $p . '_' . bin2hex(random_bytes(8));
}

function h32(string $s): string
{
    return hex2bin(hash('sha256', $s));
}

$counts = [];

// --- assets ---
$assets = [
    'BTC' => ['CRYPTO', 8],
    'USDT' => ['CRYPTO', 6],
    'USD' => ['FIAT', 2],
    'EUR' => ['FIAT', 2],
    'GBP' => ['FIAT', 2],
    'JPY' => ['FIAT', 0],
    'CNY' => ['FIAT', 2],
    'HKD' => ['FIAT', 2],
    'SGD' => ['FIAT', 2],
    'AUD' => ['FIAT', 2],
    'CAD' => ['FIAT', 2],
    'CHF' => ['FIAT', 2],
    'KRW' => ['FIAT', 0],
    'INR' => ['FIAT', 2],
    'BRL' => ['FIAT', 2],
    'MXN' => ['FIAT', 2],
    'THB' => ['FIAT', 2],
    'MYR' => ['FIAT', 2],
    'PHP' => ['FIAT', 2],
    'IDR' => ['FIAT', 2],
    'TWD' => ['FIAT', 2],
    'AED' => ['FIAT', 2],
    'NZD' => ['FIAT', 2],
    'VND' => ['FIAT', 0],
];
$assetId = [];
$insAsset = $pdo->prepare('INSERT IGNORE INTO asset (code, kind, decimals) VALUES (?,?,?)');
foreach ($assets as $code => [$kind, $dec]) {
    $insAsset->execute([$code, $kind, $dec]);
    $assetId[$code] = (int)$pdo->query("SELECT id FROM asset WHERE code=" . $pdo->quote($code))->fetchColumn();
}
$insNet = $pdo->prepare('INSERT IGNORE INTO asset_network (asset_id, network_code, status) VALUES (?,?,?)');
foreach (['BTC' => 'BITCOIN', 'USDT' => 'ETHEREUM'] as $ac => $net) {
    $insNet->execute([$assetId[$ac], $net, 'ACTIVE']);
}
$netBtc = (int)$pdo->query("SELECT id FROM asset_network WHERE asset_id={$assetId['BTC']} AND network_code='BITCOIN'")->fetchColumn();
$netUsdt = (int)$pdo->query("SELECT id FROM asset_network WHERE asset_id={$assetId['USDT']} AND network_code='ETHEREUM'")->fetchColumn();

// --- providers & accounts ---
$providers = [
    ['GATE_PAY', 'Gate Pay'],
    ['DEMO_PAY', 'Demo 支付'],
    ['STRIPE_BRIDGE', 'Stripe Bridge'],
];
$insProv = $pdo->prepare('INSERT IGNORE INTO provider (code,name,official_url,api_base_url_sandbox,api_base_url_production,docs_url,status) VALUES (?,?,?,?,?,?,?)');
foreach ($providers as [$code, $name]) {
    $insProv->execute([$code, $name, "https://example.com/$code", "https://sandbox.$code/api", "https://api.$code", "https://docs.$code", $code === 'GATE_PAY' ? 'INACTIVE' : 'ACTIVE']);
}
$provId = [];
foreach ($providers as [$code]) {
    $provId[$code] = (int)$pdo->query("SELECT id FROM provider WHERE code=" . $pdo->quote($code))->fetchColumn();
}
$insAcc = $pdo->prepare('INSERT IGNORE INTO provider_account (provider_id,parent_id,environment,external_id,role,credential_ref,status) VALUES (?,?,?,?,?,?,?)');
$provAccIds = [];
$masterByScope = [];
for ($i = 1; $i <= 12; $i++) {
    $pcode = $providers[($i - 1) % 3][0];
    $pid = $provId[$pcode];
    $env = $i % 3 === 0 ? 'PRODUCTION' : 'SANDBOX';
    $scope = $pid . '|' . $env;
    $ext = sprintf('seed_acc_%s_%02d', strtolower($pcode), $i);
    $role = $i % 4 === 0 ? 'SUB' : 'MASTER';
    $parentId = null;
    if ($role === 'SUB') {
        if (empty($masterByScope[$scope])) {
            $q = $pdo->prepare("SELECT id FROM provider_account WHERE provider_id=? AND environment=? AND role='MASTER' ORDER BY id LIMIT 1");
            $q->execute([$pid, $env]);
            $masterByScope[$scope] = (int)$q->fetchColumn() ?: null;
        }
        $parentId = $masterByScope[$scope];
        if (!$parentId) {
            $role = 'MASTER';
        }
    }
    $insAcc->execute([$pid, $parentId, $env, $ext, $role, "vault://seed/$ext", 'ACTIVE']);
    $newId = (int)$pdo->query("SELECT id FROM provider_account WHERE provider_id=$pid AND environment=" . $pdo->quote($env) . " AND external_id=" . $pdo->quote($ext))->fetchColumn();
    if ($role === 'MASTER' && empty($masterByScope[$scope])) {
        $masterByScope[$scope] = $newId;
    }
    $provAccIds[] = $newId;
}
$counts['provider_account'] = count($provAccIds);

// 法币能力模板：资产代码 => 默认地区（ISO 3166-1 alpha-2）
$fiatCapabilityCatalog = [
    ['USD', 'US'], ['EUR', 'DE'], ['GBP', 'GB'], ['JPY', 'JP'], ['CNY', 'CN'],
    ['HKD', 'HK'], ['SGD', 'SG'], ['AUD', 'AU'], ['CAD', 'CA'], ['CHF', 'CH'],
    ['KRW', 'KR'], ['INR', 'IN'], ['BRL', 'BR'], ['MXN', 'MX'], ['THB', 'TH'],
    ['MYR', 'MY'], ['PHP', 'PH'], ['IDR', 'ID'], ['TWD', 'TW'], ['AED', 'AE'],
    ['NZD', 'NZ'], ['VND', 'VN'],
];
$cryptoCapabilityCatalog = [
    ['BTC', 'US'], ['USDT', 'SG'],
];

$regionZh = [
    'US' => '美国', 'DE' => '德国', 'GB' => '英国', 'JP' => '日本', 'CN' => '中国', 'HK' => '中国香港',
    'SG' => '新加坡', 'AU' => '澳大利亚', 'CA' => '加拿大', 'CH' => '瑞士', 'KR' => '韩国', 'IN' => '印度',
    'BR' => '巴西', 'MX' => '墨西哥', 'TH' => '泰国', 'MY' => '马来西亚', 'PH' => '菲律宾', 'ID' => '印度尼西亚',
    'TW' => '中国台湾', 'AE' => '阿联酋', 'NZ' => '新西兰', 'VN' => '越南',
];
$insCap = $pdo->prepare('INSERT IGNORE INTO channel_capability (provider_account_id,asset_id,operation,method,region,region_name,capability_key,version,min_amount,max_amount,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$capIds = [];
$capCatalog = array_merge($fiatCapabilityCatalog, $cryptoCapabilityCatalog);
for ($i = 0; $i < 40; $i++) {
    $aid = $provAccIds[$i % count($provAccIds)];
    [$assetCode, $region] = $capCatalog[$i % count($capCatalog)];
    $asset = $assetId[$assetCode];
    $op = ['PAYIN', 'PAYOUT', 'REFUND', 'FX'][$i % 4];
    $key = sprintf('seed.cap.%d', $i + 1);
    $rzh = $regionZh[$region] ?? $region;
    $insCap->execute([$aid, $asset, $op, 'GATE_HOSTED', $region, $rzh, $key, 1, 0, '999999999', $i % 7 === 0 ? 'INACTIVE' : 'ACTIVE']);
    $capIds[] = (int)$pdo->query("SELECT id FROM channel_capability WHERE provider_account_id=$aid AND capability_key=" . $pdo->quote($key))->fetchColumn();
}

// Gate Pay 主账号：为每个法币补一条 PAYIN 能力（便于后台「通道能力」页展示）
$gatePayId = $provId['GATE_PAY'];
$gateMasters = $pdo->query("SELECT id, environment FROM provider_account WHERE provider_id=$gatePayId AND role='MASTER' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($gateMasters as $gm) {
    $gAid = (int)$gm['id'];
    foreach ($fiatCapabilityCatalog as [$assetCode, $region]) {
        $key = 'gate.fiat.' . strtolower($assetCode) . '.payin.' . strtolower((string)$gm['environment']);
        $rzh = $regionZh[$region] ?? $region;
        $insCap->execute([$gAid, $assetId[$assetCode], 'PAYIN', 'GATE_HOSTED', $region, $rzh, $key, 1, 0, '999999999', 'ACTIVE']);
        $newId = (int)$pdo->query("SELECT id FROM channel_capability WHERE provider_account_id=$gAid AND capability_key=" . $pdo->quote($key))->fetchColumn();
        if ($newId > 0) {
            $capIds[] = $newId;
        }
    }
}
$counts['channel_capability'] = count(array_unique($capIds));

// --- merchants ---
$insMer = $pdo->prepare('INSERT IGNORE INTO merchant (public_id,code,legal_name,country,category,status) VALUES (?,?,?,?,?,?)');
$merIds = [];
for ($i = 1; $i <= 8; $i++) {
    $code = sprintf('seed_merchant_%02d', $i);
    $insMer->execute([pub('mer'), $code, "Seed Merchant $i Ltd", $i % 2 ? 'US' : 'SG', 'FX_MERCHANT', $i <= 6 ? 'ACTIVE' : 'PENDING']);
    $merIds[] = (int)$pdo->query('SELECT id FROM merchant WHERE code=' . $pdo->quote($code))->fetchColumn();
}
$insMA = $pdo->prepare('INSERT IGNORE INTO merchant_account (public_id,merchant_id,code,status,brand_config) VALUES (?,?,?,?,?)');
$macctIds = [];
foreach ($merIds as $idx => $mid) {
    $code = 'main';
    $insMA->execute([pub('macc'), $mid, $code, 'ACTIVE', json_encode(['logo' => 'https://cdn.example/logo.png', 'lang' => 'en'])]);
    $macctIds[] = (int)$pdo->query("SELECT id FROM merchant_account WHERE merchant_id=$mid AND code='main'")->fetchColumn();
    if ($idx < 4) {
        $sub = 'sub_ops';
        $insMA->execute([pub('macc'), $mid, $sub, 'ACTIVE', null]);
        $macctIds[] = (int)$pdo->query("SELECT id FROM merchant_account WHERE merchant_id=$mid AND code=" . $pdo->quote($sub))->fetchColumn();
    }
}
$counts['merchant_account'] = count($macctIds);

$insMU = $pdo->prepare('INSERT IGNORE INTO merchant_user (public_id,merchant_id,external_user_id,status) VALUES (?,?,?,?)');
$userIds = [];
foreach ($merIds as $mid) {
    for ($u = 1; $u <= 4; $u++) {
        $euid = "seed_user_{$mid}_{$u}";
        $insMU->execute([pub('musr'), $mid, $euid, 'ACTIVE']);
        $userIds[] = (int)$pdo->query("SELECT id FROM merchant_user WHERE merchant_id=$mid AND external_user_id=" . $pdo->quote($euid))->fetchColumn();
    }
}
$counts['merchant_user'] = count($userIds);

$insKey = $pdo->prepare('INSERT IGNORE INTO merchant_api_key (merchant_account_id,key_id,secret_ref,scopes,environment,requests_per_minute,status) VALUES (?,?,?,?,?,?,?)');
$keyIds = [];
foreach (array_slice($macctIds, 0, 10) as $maid) {
    $kid = 'seed_key_' . $maid;
    $insKey->execute([$maid, $kid, 'vault://seed/api/' . $maid, '["payments:write","payments:read"]', 'SANDBOX', 120, 'ACTIVE']);
    $keyIds[] = (int)$pdo->query('SELECT id FROM merchant_api_key WHERE key_id=' . $pdo->quote($kid))->fetchColumn();
}

$insWh = $pdo->prepare('INSERT IGNORE INTO merchant_webhook_endpoint (public_id,merchant_account_id,endpoint_url,secret_ref,event_types,status) VALUES (?,?,?,?,?,?)');
$whIds = [];
foreach (array_slice($macctIds, 0, 8) as $maid) {
    $insWh->execute([pub('wh'), $maid, "https://hooks.example.com/merchant/$maid/events", 'vault://seed/wh/' . $maid, '["payment.paid","payout.completed"]', 'ACTIVE']);
    $whIds[] = (int)$pdo->query("SELECT id FROM merchant_webhook_endpoint WHERE merchant_account_id=$maid LIMIT 1")->fetchColumn();
}

$insBind = $pdo->prepare('INSERT IGNORE INTO channel_binding (public_id,merchant_account_id,capability_id,version,priority,weight,effective_from,status) VALUES (?,?,?,?,?,?,?,?)');
$bindIds = [];
for ($i = 0; $i < 20; $i++) {
    $maid = $macctIds[$i % count($macctIds)];
    $cap = $capIds[$i % count($capIds)];
    $pub = pub('cbind');
    $insBind->execute([$pub, $maid, $cap, 1, 100 + $i, 10, date('Y-m-d H:i:s', strtotime('-30 days')), 'ACTIVE']);
    $bindIds[] = (int)$pdo->query("SELECT id FROM channel_binding WHERE public_id=" . $pdo->quote($pub))->fetchColumn();
}

$insComp = $pdo->prepare('INSERT IGNORE INTO compliance_case (public_id,merchant_id,subject_type,purpose,country,status) VALUES (?,?,?,?,?,?)');
for ($i = 0; $i < 15; $i++) {
    $insComp->execute([pub('kyc'), $merIds[$i % count($merIds)], 'MERCHANT', 'KYB', 'US', $i % 3 === 0 ? 'PENDING' : 'APPROVED']);
}

$insBen = $pdo->prepare('INSERT IGNORE INTO beneficiary (public_id,merchant_account_id,merchant_id,asset_id,network_id,type,country,destination_ref,fingerprint,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
$benIds = [];
foreach (array_slice($macctIds, 0, 12) as $j => $maid) {
    $mid = (int)$pdo->query("SELECT merchant_id FROM merchant_account WHERE id=$maid")->fetchColumn();
    $fp = h32("seed-ben-$maid");
    if ($j % 2 === 0) {
        $insBen->execute([pub('ben'), $maid, $mid, $assetId['USD'], null, 'BANK', 'US', 'vault://seed/bank/' . $maid, $fp, 'ACTIVE']);
    } else {
        $insBen->execute([pub('ben'), $maid, $mid, $assetId['USDT'], $netUsdt, 'WALLET', 'US', 'vault://seed/wallet/' . $maid, $fp, 'ACTIVE']);
    }
}
$benIds = $pdo->query('SELECT id FROM beneficiary ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_COLUMN);

$insQuote = $pdo->prepare('INSERT IGNORE INTO quote (public_id,merchant_account_id,source_asset_id,target_asset_id,provider_account_id,rate,source_amount,target_amount,fee,fee_asset_id,pricing_mode,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$quoteIds = [];
for ($i = 1; $i <= 30; $i++) {
    $maid = $macctIds[$i % count($macctIds)];
    $pid = pub('quote');
    $insQuote->execute([$pid, $maid, $assetId['USD'], $assetId['BTC'], $provAccIds[$i % count($provAccIds)], '0.000015', 10000, 150000, 100, $assetId['USD'], 'FIXED', date('Y-m-d H:i:s', strtotime('+1 hour'))]);
    $quoteIds[] = (int)$pdo->query("SELECT id FROM quote WHERE public_id=" . $pdo->quote($pid))->fetchColumn();
}

$insPO = $pdo->prepare('INSERT IGNORE INTO payment_order (public_id,merchant_account_id,merchant_id,order_asset_id,settlement_asset_id,quote_id,merchant_order_no,order_amount,status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
$poIds = [];
for ($i = 1; $i <= 35; $i++) {
    $maid = $macctIds[$i % count($macctIds)];
    $mid = (int)$pdo->query("SELECT merchant_id FROM merchant_account WHERE id=$maid")->fetchColumn();
    $mono = 'SEED_ORD_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $pubId = pub('pay');
    $qid = $quoteIds[($i - 1) % count($quoteIds)];
    $st = ['CREATED', 'PENDING_PAYMENT', 'PAID', 'EXPIRED'][$i % 4];
    $insPO->execute([$pubId, $maid, $mid, $assetId['USD'], $assetId['USD'], $qid, $mono, 5000 + $i * 100, $st, date('Y-m-d H:i:s', strtotime('+2 days'))]);
    $poIds[] = (int)$pdo->query("SELECT id FROM payment_order WHERE merchant_order_no=" . $pdo->quote($mono))->fetchColumn();
}
$counts['payment_order'] = 35;

$bindByMaid = [];
foreach ($pdo->query('SELECT b.id AS bind_id,b.merchant_account_id,b.capability_id,c.provider_account_id FROM channel_binding b JOIN channel_capability c ON c.id=b.capability_id') as $row) {
    $bindByMaid[(int)$row['merchant_account_id']] = $row;
}
$insPA = $pdo->prepare('INSERT IGNORE INTO payment_attempt (payment_order_id,merchant_account_id,capability_id,channel_binding_id,provider_account_id,payment_asset_id,expected_amount,provider_request_no,provider_order_id,status,route_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$paIds = [];
foreach (array_filter($poIds) as $i => $poid) {
    $maid = (int)$pdo->query("SELECT merchant_account_id FROM payment_order WHERE id=$poid")->fetchColumn();
    $b    = $bindByMaid[$maid] ?? reset($bindByMaid);
    if (!$b) {
        continue;
    }
    $req = "seed_pay_req_$poid";
    $insPA->execute([$poid, $maid, $b['capability_id'], $b['bind_id'], $b['provider_account_id'], $assetId['USD'], 5000, $req, "seed_ext_po_$poid", 'SUCCEEDED', '{}']);
    $paIds[] = (int)$pdo->query("SELECT id FROM payment_attempt WHERE payment_order_id=$poid AND provider_request_no=" . $pdo->quote($req))->fetchColumn();
}

$insPR = $pdo->prepare('INSERT IGNORE INTO payment_receipt (public_id,payment_attempt_id,merchant_account_id,provider_account_id,received_asset_id,source_receipt_key,amount,fee,status,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
$prIds = [];
for ($i = 0; $i < 20; $i++) {
    $paid = $paIds[$i];
    $maid = (int)$pdo->query("SELECT merchant_account_id FROM payment_attempt WHERE id=$paid")->fetchColumn();
    $pacc = (int)$pdo->query("SELECT provider_account_id FROM payment_attempt WHERE id=$paid")->fetchColumn();
    $sk = "seed_rcpt_$i";
    $insPR->execute([pub('rcpt'), $paid, $maid, $pacc, $assetId['USD'], $sk, 5000, 50, 'CONFIRMED', date('Y-m-d H:i:s')]);
    $prIds[] = (int)$pdo->query("SELECT id FROM payment_receipt WHERE provider_account_id=$pacc AND source_receipt_key=" . $pdo->quote($sk))->fetchColumn();
}

for ($i = 0; $i < 8; $i++) {
    $poid = $poIds[$i];
    $maid = (int)$pdo->query("SELECT merchant_account_id FROM payment_order WHERE id=$poid")->fetchColumn();
    $qid = $quoteIds[$i];
    $pacc = $provAccIds[$i % count($provAccIds)];
    $req = "seed_fx_req_$i";
    $pdo->prepare('INSERT IGNORE INTO conversion (public_id,payment_order_id,merchant_account_id,quote_id,provider_account_id,sell_asset_id,buy_asset_id,provider_request_no,sold_amount,bought_amount,fee,fee_asset_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([pub('cnv'), $poid, $maid, $qid, $pacc, $assetId['USD'], $assetId['BTC'], $req, 5000, 75000, 10, $assetId['USD'], 'COMPLETED']);
}

for ($i = 0; $i < 10; $i++) {
    $rid = $prIds[$i % count($prIds)];
    $maid = (int)$pdo->query("SELECT merchant_account_id FROM payment_receipt WHERE id=$rid")->fetchColumn();
    $pacc = (int)$pdo->query("SELECT provider_account_id FROM payment_receipt WHERE id=$rid")->fetchColumn();
    $ref = "SEED_REF_$i";
    $req = "seed_ref_req_$i";
    $pdo->prepare('INSERT IGNORE INTO refund_order (public_id,payment_receipt_id,merchant_account_id,provider_account_id,asset_id,merchant_refund_no,provider_request_no,amount,status) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([pub('rfnd'), $rid, $maid, $pacc, $assetId['USD'], $ref, $req, 1000, 'COMPLETED']);
}

$insLed = $pdo->prepare('INSERT IGNORE INTO ledger_account (asset_id,scope_type,scope_id,account_code,balance) VALUES (?,?,?,?,?)');
$ledIds = [];
foreach (array_slice($macctIds, 0, 15) as $maid) {
    foreach (['AVAILABLE', 'PENDING', 'RESERVE'] as $code) {
        $insLed->execute([$assetId['USD'], 'MERCHANT', $maid, $code, random_int(10000, 999999)]);
        $ledIds[] = (int)$pdo->lastInsertId() ?: (int)$pdo->query("SELECT id FROM ledger_account WHERE scope_type='MERCHANT' AND scope_id=$maid AND account_code=" . $pdo->quote($code))->fetchColumn();
    }
}

for ($j = 1; $j <= 25; $j++) {
    $asset = $assetId['USD'];
    $ek = "seed_journal_$j";
    $pdo->prepare('INSERT IGNORE INTO journal (asset_id,event_key,business_type,business_id) VALUES (?,?,?,?)')
        ->execute([$asset, $ek, 'PAYMENT', $poIds[$j % count($poIds)]]);
    $jid = (int)$pdo->query("SELECT id FROM journal WHERE event_key=" . $pdo->quote($ek))->fetchColumn();
    $d = $ledIds[($j * 2) % count($ledIds)];
    $c = $ledIds[($j * 2 + 1) % count($ledIds)];
    $pdo->prepare('INSERT IGNORE INTO journal_entry (journal_id,ledger_account_id,asset_id,line_no,direction,amount) VALUES (?,?,?,?,?,?)')
        ->execute([$jid, $d, $asset, 1, 'D', 5000 + $j]);
    $pdo->prepare('INSERT IGNORE INTO journal_entry (journal_id,ledger_account_id,asset_id,line_no,direction,amount) VALUES (?,?,?,?,?,?)')
        ->execute([$jid, $c, $asset, 2, 'C', 5000 + $j]);
}

for ($i = 1; $i <= 15; $i++) {
    $maid = $macctIds[$i % count($macctIds)];
    $batch = sprintf('SEED_STL_%03d', $i);
    $pdo->prepare('INSERT IGNORE INTO settlement_batch (public_id,merchant_account_id,asset_id,batch_no,window_start,window_end,gross,fee,net,status) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([pub('stl'), $maid, $assetId['USD'], $batch, date('Y-m-d H:i:s', strtotime("-{$i} days")), date('Y-m-d H:i:s', strtotime("-{$i} days +1 day")), 100000, 500, 99500, 'SETTLED']);
}

$benList = $pdo->query('SELECT id,merchant_account_id,asset_id FROM beneficiary LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach ($benList as $i => $b) {
    $pno = sprintf('SEED_POUT_%03d', $i + 1);
    $pdo->prepare('INSERT IGNORE INTO payout_order (public_id,merchant_account_id,beneficiary_id,asset_id,payout_no,type,requested_amount,fee,fee_mode,status) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([pub('po'), $b['merchant_account_id'], $b['id'], $b['asset_id'], $pno, 'WITHDRAWAL', 20000, 100, 'DEDUCT', 'CREATED']);
    $poid = (int)$pdo->query("SELECT id FROM payout_order WHERE payout_no=" . $pdo->quote($pno))->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO payout_attempt (payout_id,provider_account_id,provider_request_no,amount,status) VALUES (?,?,?,?,?)')
        ->execute([$poid, $provAccIds[$i % count($provAccIds)], "seed_pout_req_$i", 20000, 'PROCESSING']);
}

for ($i = 1; $i <= 30; $i++) {
    $maid = $macctIds[$i % count($macctIds)];
    $pdo->prepare('INSERT IGNORE INTO api_idempotency (merchant_account_id,operation,idem_key,request_hash,status,expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([$maid, 'payment.create', "seed_idem_$i", h32("idem$i"), 'COMPLETED', date('Y-m-d H:i:s', strtotime('+1 day'))]);
}

$pacc = $provAccIds[0];
for ($i = 1; $i <= 40; $i++) {
    $dk = "seed_inbox_$i";
    $types = ['balance.snapshot', 'payment.paid', 'payout.completed', 'webhook.test'];
    $pdo->prepare('INSERT IGNORE INTO provider_event_inbox (provider_account_id,dedup_key,event_type,payload,status,received_at) VALUES (?,?,?,?,?,?)')
        ->execute([$provAccIds[$i % count($provAccIds)], $dk, $types[$i % 4], json_encode(['seq' => $i, 'demo' => true]), $i % 5 === 0 ? 'DONE' : 'PENDING', date('Y-m-d H:i:s', strtotime("-$i hours"))]);
}

foreach (array_slice($macctIds, 0, 6) as $i => $maid) {
    $eid = $whIds[$i % count($whIds)];
    $ev = "seed_evt_" . ($i + 1);
    $pdo->prepare('INSERT IGNORE INTO merchant_event_outbox (merchant_account_id,endpoint_id,event_id,event_type,aggregate_id,event_version,payload,status,attempt_count) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$maid, $eid, $ev, 'payment.paid', $poIds[$i], 1, '{"amount":5000}', $i % 2 ? 'PENDING' : 'DELIVERED', $i % 3]);
    $oid = (int)$pdo->query("SELECT id FROM merchant_event_outbox WHERE event_id=" . $pdo->quote($ev))->fetchColumn();
    for ($a = 1; $a <= min(3, $i + 1); $a++) {
        $pdo->prepare('INSERT IGNORE INTO merchant_webhook_delivery_attempt (outbox_id,attempt_no,http_status,status,finished_at) VALUES (?,?,?,?,?)')
            ->execute([$oid, $a, $a === 1 ? 500 : 200, $a === 1 ? 'FAILED' : 'SUCCEEDED', date('Y-m-d H:i:s')]);
    }
}

for ($i = 1; $i <= 25; $i++) {
    $pdo->prepare('INSERT IGNORE INTO async_job (job_type,job_key,payload,status,next_run_at) VALUES (?,?,?,?,?)')
        ->execute(['payment_poll', "seed_job_$i", json_encode(['order_id' => $poIds[$i % count($poIds)]]), 'PENDING', date('Y-m-d H:i:s', strtotime("+$i minutes"))]);
}

foreach ($keyIds as $i => $kid) {
    $nonce = h32("nonce_seed_$i");
    $pdo->prepare('INSERT IGNORE INTO openapi_request_nonce (api_key_id,nonce_hash,expires_at) VALUES (?,?,?)')
        ->execute([$kid, $nonce, date('Y-m-d H:i:s', strtotime('+10 minutes'))]);
}

for ($i = 1; $i <= 35; $i++) {
    $rid = 'req_seed_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT IGNORE INTO openapi_request_log (request_id,api_key_id,operation_code,http_status,duration_ms,received_at) VALUES (?,?,?,?,?,?)')
        ->execute([$rid, $keyIds[$i % count($keyIds)], 'payment.get', $i % 7 === 0 ? 400 : 200, random_int(5, 800), date('Y-m-d H:i:s', strtotime("-$i minutes"))]);
}

echo "=== seed complete ===\n";
foreach ($pdo->query("SELECT 'payment_order' t, COUNT(*) c FROM payment_order UNION ALL SELECT 'provider_event_inbox', COUNT(*) FROM provider_event_inbox UNION ALL SELECT 'openapi_request_log', COUNT(*) FROM openapi_request_log UNION ALL SELECT 'merchant', COUNT(*) FROM merchant UNION ALL SELECT 'journal_entry', COUNT(*) FROM journal_entry UNION ALL SELECT 'async_job', COUNT(*) FROM async_job") as $row) {
    echo $row['t'] . ': ' . $row['c'] . "\n";
}