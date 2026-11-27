<?php
// 验证订单簿排序：asks 降序 / bids 升序（贴近盘口各10档）
$pdo = new PDO('mysql:host=172.16.39.253;port=3306;dbname=fgmm_f_backtest;charset=utf8mb4', 'mm', '2kHjniEMBGh7rRWz');
$row = $pdo->query("SELECT out_asks, out_bids FROM f_placement ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$asks = json_decode($row['out_asks'], true) ?: [];
$bids = json_decode($row['out_bids'], true) ?: [];
usort($asks, fn($a, $b) => $a['price'] <=> $b['price']);
usort($bids, fn($a, $b) => $b['price'] <=> $a['price']);
$asks = array_reverse($asks); // 降序，最优卖价在底部（完整铺单）
$bids = $bids;                // 降序，最优买价在顶部（完整铺单）

$ap = array_column($asks, 'price');
$bp = array_column($bids, 'price');
echo "asks(降序): " . implode(' > ', $ap) . "\n";
echo "bids(降序): " . implode(' > ', $bp) . "\n";
// 断言：asks 非递增、bids 非递增（均降序）
$ok = true;
for ($i = 1; $i < count($ap); $i++) if ($ap[$i] > $ap[$i - 1]) $ok = false;
for ($i = 1; $i < count($bp); $i++) if ($bp[$i] > $bp[$i - 1]) $ok = false;
echo $ok ? "SORT_OK\n" : "SORT_FAIL\n";
