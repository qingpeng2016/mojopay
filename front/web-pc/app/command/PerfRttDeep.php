<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\service\ShardManager;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 深度排查：把 RTT 拆分成
 *   - ICMP / TCP 建连
 *   - PDO 裸连接 + 复用同连接执行 SQL
 *   - ThinkPHP Db::connect 复用 + 每次 connect
 *   - MySQL server 真实查询时长（SHOW PROFILE）
 * 用法：php think perf:rtt-deep
 */
class PerfRttDeep extends Command
{
    protected function configure()
    {
        $this->setName('perf:rtt-deep')
             ->setDescription('深度拆分 RTT/连接/服务端各层耗时');
    }

    protected function execute(Input $input, Output $output)
    {
        $shards = ShardManager::getActiveShards();
        foreach ($shards as $sid => $info) {
            $this->probeOne($output, $sid, $info);
            $output->writeln('');
            $output->writeln('========================================');
            $output->writeln('');
        }
        return 0;
    }

    private function probeOne(Output $output, int $shardId, array $info): void
    {
        $conn = ShardManager::conn($shardId);

        $output->writeln("=== Shard {$shardId} [{$info['name']}] host={$info['host']} port={$info['port']} db={$info['database']} ===");
        $output->writeln('');

        // 预先建 PDO 连接，连不上直接跳过整个 shard
        $pdo = null;
        try {
            $dsn = "mysql:host={$info['host']};port={$info['port']};dbname={$info['database']};charset=utf8mb4";
            $tConnect = microtime(true);
            $pdo = new \PDO($dsn, $info['username'], $info['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $connectMs = round((microtime(true) - $tConnect) * 1000, 1);
            $output->writeln("  [PDO 建连] {$connectMs} ms");
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('  [SKIP] PDO 建连失败: ' . $e->getMessage());
            return;
        }

        // --- 1. MySQL server 状态 ---
        $output->writeln('--- 1. MySQL server 状态 ---');
        try {
            $threads = Db::connect($conn)->query("SHOW STATUS LIKE 'Threads_running'");
            $uptime  = Db::connect($conn)->query("SHOW STATUS LIKE 'Uptime'");
            $slow    = Db::connect($conn)->query("SHOW STATUS LIKE 'Slow_queries'");
            $output->writeln('  Threads_running = ' . ($threads[0]['Value'] ?? '?'));
            $output->writeln('  Uptime          = ' . ($uptime[0]['Value'] ?? '?') . ' s');
            $output->writeln('  Slow_queries    = ' . ($slow[0]['Value'] ?? '?'));
        } catch (\Throwable $e) {
            $output->writeln('  SHOW STATUS failed: ' . $e->getMessage());
        }
        $output->writeln('');

        // --- 2. 裸 PDO：同连接重复跑 20 次 SELECT 1，看 RTT 分布 ---
        $output->writeln('--- 2. 裸 PDO 同连接 × 20 次 SELECT 1 ---');
        try {
            $samples = [];
            for ($i = 0; $i < 20; $i++) {
                $t0 = microtime(true);
                $pdo->query('SELECT 1')->fetch();
                $samples[] = round((microtime(true) - $t0) * 1000, 2);
            }
            sort($samples);
            $output->writeln('  样本(ms): ' . implode(', ', $samples));
            $output->writeln('  p50 = ' . $samples[10] . ' ms / p95 = ' . $samples[18] . ' ms / 最小 = ' . $samples[0] . ' ms');
        } catch (\Throwable $e) {
            $output->writeln('  failed: ' . $e->getMessage());
        }
        $output->writeln('');

        // --- 3. ThinkPHP Db::connect 同连接（应该 PDO 复用）× 20 次 SELECT 1 ---
        $output->writeln('--- 3. ThinkPHP Db::connect 同连接 × 20 次 SELECT 1 ---');
        try {
            $db = Db::connect($conn);
            $samples = [];
            for ($i = 0; $i < 20; $i++) {
                $t0 = microtime(true);
                $db->query('SELECT 1');
                $samples[] = round((microtime(true) - $t0) * 1000, 2);
            }
            sort($samples);
            $output->writeln('  样本(ms): ' . implode(', ', $samples));
            $output->writeln('  p50 = ' . $samples[10] . ' ms / p95 = ' . $samples[18] . ' ms / 最小 = ' . $samples[0] . ' ms');
        } catch (\Throwable $e) {
            $output->writeln('  failed: ' . $e->getMessage());
        }
        $output->writeln('');

        // --- 4. ThinkPHP 每次 Db::connect 调一次（看是否每次都建连）× 10 次 ---
        $output->writeln('--- 4. ThinkPHP 每次都 Db::connect(...)->query × 10 次 ---');
        try {
            $samples = [];
            for ($i = 0; $i < 10; $i++) {
                $t0 = microtime(true);
                Db::connect($conn)->query('SELECT 1');
                $samples[] = round((microtime(true) - $t0) * 1000, 2);
            }
            sort($samples);
            $output->writeln('  样本(ms): ' . implode(', ', $samples));
            $output->writeln('  p50 = ' . $samples[5] . ' ms / 最小 = ' . $samples[0] . ' ms');
        } catch (\Throwable $e) {
            $output->writeln('  failed: ' . $e->getMessage());
        }
        $output->writeln('');

        // --- 5. winners SQL: server-side 真实执行时长（裸 PDO） ---
        $output->writeln('--- 5. winners SQL server-side 真实执行时长 ---');
        try {
            $sql = "SELECT id, source_winning_token_id, token_id_mapping FROM market_mapping_config WHERE source_winning_token_id <> ''";

            // 用 SET profiling=1 在同连接里测
            $pdo->exec('SET profiling = 1');
            $t0 = microtime(true);
            $rows = $pdo->query($sql)->fetchAll();
            $phpMs = round((microtime(true) - $t0) * 1000, 1);
            $count = count($rows);
            $output->writeln("  PHP 端 wall time = {$phpMs} ms (返回 {$count} 行)");

            $profile = $pdo->query('SHOW PROFILES')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($profile as $p) {
                if (stripos($p['Query'], 'market_mapping_config') !== false) {
                    $output->writeln("  MySQL server 端执行 = " . round($p['Duration'] * 1000, 2) . ' ms (Query_ID=' . $p['Query_ID'] . ')');
                }
            }
            $pdo->exec('SET profiling = 0');

            // 再跑两次纯 PHP 测时间稳定性
            for ($i = 0; $i < 3; $i++) {
                $t0 = microtime(true);
                $pdo->query($sql)->fetchAll();
                $output->writeln('  第 ' . ($i + 2) . ' 次 PHP wall = ' . round((microtime(true) - $t0) * 1000, 1) . ' ms');
            }
        } catch (\Throwable $e) {
            $output->writeln('  failed: ' . $e->getMessage());
        }
        $output->writeln('');

        // --- 6. trader 表行数及最大 id ---
        $output->writeln('--- 6. 表真实大小 ---');
        foreach (['source_trader_records', 'target_trader_records', 'market_mapping_config'] as $t) {
            try {
                $r = $pdo->query("SELECT COUNT(*) AS n FROM `{$t}`")->fetch();
                $output->writeln("  {$t}: {$r['n']} 行");
            } catch (\Throwable $e) {
                $output->writeln("  {$t}: " . $e->getMessage());
            }
        }
    }
}
