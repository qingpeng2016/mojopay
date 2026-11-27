<?php
/**
 * 本地预览用：把「回测中心」整棵菜单树（含体育市场策略 + 体育活跃赛事）注入到
 * 本地 SQLite 主库。幂等、可重复执行；仅作用于运行中的容器，不修改仓库 seed 文件。
 *
 * 用法（容器内）： php /var/www/html/scripts/seed_local_backtest_menu.php [sqlite路径]
 * 默认路径：/var/www/html/local-data/sqlite.db
 */

declare(strict_types=1);

$path = $argv[1] ?? '/var/www/html/local-data/sqlite.db';
if (!file_exists($path)) {
    fwrite(STDERR, "SQLite 文件不存在: {$path}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys=OFF');

/**
 * 精确按 pid + title 取分组/根节点 id。
 */
function getIdByTitle(PDO $pdo, int $pid, string $title): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM system_menu WHERE pid = ? AND title = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$pid, $title]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * 确保某个 node 存在并归入指定父级（幂等）。
 */
function ensureNode(PDO $pdo, int $pid, string $title, string $node, int $sort): void
{
    $check = $pdo->prepare('SELECT id FROM system_menu WHERE node = ?');
    $check->execute([$node]);
    $id = $check->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status) VALUES (?,?,?,?,?,?,?,?,1)');
        $ins->execute([$pid, $title, '', $node, $node, '', '_self', $sort]);
    } else {
        $upd = $pdo->prepare('UPDATE system_menu SET pid=?,title=?,icon=?,url=?,params=?,target=?,sort=?,status=1 WHERE id=?');
        $upd->execute([$pid, $title, '', $node, '', '_self', $sort, $id]);
    }
}

// 1) 顶级：回测中心
$rootId = getIdByTitle($pdo, 0, '回测中心');
if ($rootId === null) {
    $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status) VALUES (0,?,?,?,?,?,?,?,1)')
        ->execute(['回测中心', '', '', '#', '', '_self', 10]);
    $rootId = (int)$pdo->lastInsertId();
}

// 2) 快盘策略 分组
$fastbookId = getIdByTitle($pdo, $rootId, '快盘策略');
if ($fastbookId === null) {
    $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status) VALUES (?,?,?,?,?,?,?,?,1)')
        ->execute([$rootId, '快盘策略', '', '', '#', '', '_self', 10]);
    $fastbookId = (int)$pdo->lastInsertId();
}
foreach ([
    ['盘口配置', 'backtest/config/index', 60],
    ['市场信息', 'backtest/market/index', 50],
    ['铺单信息', 'backtest/placement/index', 40],
    ['对冲信息', 'backtest/hedge/index', 30],
    ['SO文件配置', 'backtest/sofile/index', 70],
] as [$title, $node, $sort]) {
    ensureNode($pdo, $fastbookId, $title, $node, $sort);
}

// 3) 体育市场策略 分组（设计文档 §9）
$sportsId = getIdByTitle($pdo, $rootId, '体育市场策略');
if ($sportsId === null) {
    $pdo->prepare('INSERT INTO system_menu (pid,title,icon,node,url,params,target,sort,status) VALUES (?,?,?,?,?,?,?,?,1)')
        ->execute([$rootId, '体育市场策略', '', '', '#', '', '_self', 20]);
    $sportsId = (int)$pdo->lastInsertId();
}
foreach ([
    ['体育市场配置', 'backtest/sports_config/index', 60],
    ['体育市场信息', 'backtest/sports_market/index', 50],
    ['体育铺单历史', 'backtest/sports_placement/index', 40],
    ['体育活跃赛事', 'backtest/sports_active_event/index', 30],
] as [$title, $node, $sort]) {
    ensureNode($pdo, $sportsId, $title, $node, $sort);
}

echo "OK: 回测中心菜单已注入 SQLite ({$path})\n";
echo "root={$rootId} fastbook={$fastbookId} sports={$sportsId}\n";
