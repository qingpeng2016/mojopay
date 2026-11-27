<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\service\ShardManager;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 一次性：cool_pay2 各分片添加 PNL 计算热路径所需的 3 个索引。
 * 用法：php think perf:add-indexes
 *
 * 设计依据：runtime/log/pnl_perf.log（2026-05-20）显示 getWinningTokens 单查询
 * 2-4 秒、Part 2 tuple IN 0.5-2 秒；加索引后预期均降到 <100ms。
 *
 * 幂等：information_schema 检查后再 ALTER；已存在的同名索引跳过。
 */
class PerfAddIndexes extends Command
{
    private const INDEXES = [
        [
            'table' => 'market_mapping_config',
            'name'  => 'idx_perf_winning_token',
            'cols'  => '(source_winning_token_id)',
        ],
        [
            'table' => 'source_trader_records',
            'name'  => 'idx_perf_mc_tok_status',
            'cols'  => '(market_config_id, source_token_id, status, updated_at)',
        ],
        [
            'table' => 'target_trader_records',
            'name'  => 'idx_perf_mc_tok_status',
            'cols'  => '(market_config_id, target_token_id, status, updated_at)',
        ],
    ];

    protected function configure()
    {
        $this->setName('perf:add-indexes')
             ->setDescription('在 cool_pay2 各分片上添加 PNL 热路径的 3 个索引（幂等）');
    }

    protected function execute(Input $input, Output $output)
    {
        $shards = ShardManager::getActiveShards();
        $output->writeln('Active shards: ' . count($shards) . ' (' . implode(',', array_map(fn($s) => $s['name'], $shards)) . ')');

        foreach ($shards as $shardId => $info) {
            $conn = ShardManager::conn($shardId);
            $dbName = $info['database'];
            $output->writeln('');
            $output->writeln("=== Shard {$shardId} [{$info['name']}] db={$dbName} ===");

            foreach (self::INDEXES as $idx) {
                $this->applyIndex($output, $conn, $dbName, $idx);
            }
        }

        $output->writeln('');
        $output->writeln('<info>Done.</info>');
        return 0;
    }

    private function applyIndex(Output $output, string $conn, string $dbName, array $idx): void
    {
        $table = $idx['table'];
        $name  = $idx['name'];
        $cols  = $idx['cols'];

        $tableExists = (int)Db::connect($conn)->query(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$dbName, $table]
        )[0]['n'] > 0;
        if (!$tableExists) {
            $output->writeln("  [SKIP] {$table}: 表不存在");
            return;
        }

        $exists = (int)Db::connect($conn)->query(
            'SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$dbName, $table, $name]
        )[0]['n'] > 0;
        if ($exists) {
            $output->writeln("  [SKIP] {$table}.{$name}: 已存在");
            return;
        }

        $sql = "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}";
        $t0 = microtime(true);
        try {
            Db::connect($conn)->execute($sql);
            $ms = round((microtime(true) - $t0) * 1000, 1);
            $output->writeln("  <info>[OK]</info>   {$table}.{$name}  ({$ms} ms)");
        } catch (\Throwable $e) {
            $output->writeln("  <error>[FAIL]</error> {$table}.{$name}: " . $e->getMessage());
        }
    }
}