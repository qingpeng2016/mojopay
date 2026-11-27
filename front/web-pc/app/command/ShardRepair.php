<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\service\ShardManager;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 检查 A 类全局配置表跨片一致性，可选自动修复。
 *
 * 检查的表 + 业务键：
 *   accounts            (vendor, market_account_id)
 *   config_trade        (config_name)
 *   risk_control_rules  (rule_type, rule_name)
 *   rebalance_config    (config_key)
 *
 * 默认 dry-run，只报告：
 *   - missing on shard X：业务键在某些分片有，另一些片没有 → 缺失片需要 INSERT
 *   - value differs    ：业务键各片都有但字段值不同 → 需要按权威分片 UPDATE 同步
 *
 * --fix 模式：以第一个分片（默认 mm_0 / status=1 第一个）为权威，给缺失片 INSERT 并给值不同的片 UPDATE。
 *
 * 用法：
 *   php think shard:repair                  仅报告
 *   php think shard:repair --fix            报告 + 修
 *   php think shard:repair --table=config_trade  只检查指定表
 */
class ShardRepair extends Command
{
    /** A 类表 → 业务键列名（数组）映射 */
    private const TABLES = [
        'accounts'           => ['vendor', 'market_account_id'],
        'config_trade'       => ['config_name'],
        'risk_control_rules' => ['rule_type', 'rule_name'],
        'rebalance_config'   => ['config_key'],
    ];

    /** 比对时排除的字段（自增 id + 时间戳 + 浮点余额等会自然漂移的字段） */
    private const IGNORE_COLS = ['id', 'created_at', 'updated_at',
        'balance', 'available_balance', 'frozen_balance',  // accounts 余额运行时变化
    ];

    protected function configure()
    {
        $this->setName('shard:repair')
             ->addOption('fix', null, Option::VALUE_NONE, '应用修复（默认 dry-run）')
             ->addOption('table', null, Option::VALUE_REQUIRED, '只检查指定表（' . implode('/', array_keys(self::TABLES)) . '）')
             ->setDescription('Check / repair A-class config tables consistency across shards');
    }

    protected function execute(Input $input, Output $output)
    {
        $fix = (bool)$input->getOption('fix');
        $onlyTable = (string)$input->getOption('table');
        $shards = ShardManager::getActiveShards();
        if (count($shards) < 2) {
            $output->writeln('<comment>只有 1 个分片，不需要 repair</comment>');
            return 0;
        }

        $shardIds = array_keys($shards);
        $authorityShardId = (int)reset($shardIds);
        $output->writeln(sprintf('权威分片: id=%d name=%s（其它片缺数据时按这片为准 INSERT/UPDATE）',
            $authorityShardId, $shards[$authorityShardId]['name']));
        $output->writeln('模式: ' . ($fix ? '<info>FIX</info>（会写库）' : '<comment>dry-run</comment>（只报告）'));
        $output->writeln('');

        $totalMissing = 0;
        $totalDiffs = 0;
        foreach (self::TABLES as $table => $bizCols) {
            if ($onlyTable !== '' && $onlyTable !== $table) continue;
            $output->writeln("=== {$table} (业务键: " . implode('+', $bizCols) . ') ===');
            [$missing, $diffs] = $this->checkTable($output, $table, $bizCols, $shards, $authorityShardId, $fix);
            $totalMissing += $missing;
            $totalDiffs += $diffs;
            $output->writeln('');
        }

        if ($totalMissing === 0 && $totalDiffs === 0) {
            $output->writeln('<info>[PASS] 所有 A 类表跨片一致</info>');
            return 0;
        }
        if ($fix) {
            $output->writeln(sprintf('<info>[DONE] 已修复 %d 条缺失 + %d 条字段差异</info>', $totalMissing, $totalDiffs));
        } else {
            $output->writeln(sprintf('<comment>[REPORT] %d 条缺失 + %d 条字段差异，要修请加 --fix</comment>',
                $totalMissing, $totalDiffs));
        }
        return $totalMissing + $totalDiffs > 0 && !$fix ? 1 : 0;
    }

    /**
     * @return array{0:int, 1:int} [missing数, diff数]
     */
    private function checkTable(
        Output $output,
        string $table,
        array $bizCols,
        array $shards,
        int $authorityShardId,
        bool $fix
    ): array {
        // 1) 从每个分片拉所有行，按业务键 hash
        $byShard = []; // shardId -> bizKey -> row
        foreach ($shards as $sid => $info) {
            $byShard[$sid] = [];
            $rows = Db::connect(ShardManager::conn($sid))->name($table)->select()->toArray();
            foreach ($rows as $r) {
                $key = $this->makeBizKey($bizCols, $r);
                $byShard[$sid][$key] = $r;
            }
        }
        $authorityRows = $byShard[$authorityShardId];

        $missing = 0;
        $diffs = 0;

        // 2) 找 missing：权威片有 + 其它片没有
        foreach ($authorityRows as $key => $authRow) {
            foreach ($byShard as $sid => $rows) {
                if ($sid === $authorityShardId) continue;
                if (isset($rows[$key])) continue;
                $missing++;
                $output->writeln(sprintf('  <comment>[MISSING]</comment> %s 在 shard %d (%s) 缺：%s',
                    $table, $sid, $shards[$sid]['name'], $key));
                if ($fix) {
                    $insertData = $authRow;
                    unset($insertData['id']);
                    try {
                        Db::connect(ShardManager::conn($sid))->name($table)->insert($insertData);
                        $output->writeln('    → <info>已 INSERT</info>');
                    } catch (\Throwable $e) {
                        $output->writeln('    → <error>INSERT 失败: ' . $e->getMessage() . '</error>');
                    }
                }
            }
        }

        // 3) 找 diff：所有片都有但字段值不同（按权威片为准）
        foreach ($authorityRows as $key => $authRow) {
            foreach ($byShard as $sid => $rows) {
                if ($sid === $authorityShardId) continue;
                if (!isset($rows[$key])) continue; // 缺失上面处理过了
                $shardRow = $rows[$key];
                $diffCols = $this->diffCols($authRow, $shardRow);
                if (empty($diffCols)) continue;
                $diffs++;
                $output->writeln(sprintf('  <comment>[DIFF]</comment> %s key=%s shard %d (%s) 字段差异: %s',
                    $table, $key, $sid, $shards[$sid]['name'], json_encode($diffCols, JSON_UNESCAPED_UNICODE)));
                if ($fix) {
                    $updateData = [];
                    foreach ($diffCols as $col => $_) $updateData[$col] = $authRow[$col];
                    $updateData['updated_at'] = date('Y-m-d H:i:s');
                    try {
                        $q = Db::connect(ShardManager::conn($sid))->name($table);
                        foreach ($bizCols as $bc) $q->where($bc, $authRow[$bc]);
                        $q->update($updateData);
                        $output->writeln('    → <info>已 UPDATE</info>');
                    } catch (\Throwable $e) {
                        $output->writeln('    → <error>UPDATE 失败: ' . $e->getMessage() . '</error>');
                    }
                }
            }
        }

        // 4) 反向：其它片有但权威片没有（罕见情况 → 仅报告，不自动删）
        foreach ($byShard as $sid => $rows) {
            if ($sid === $authorityShardId) continue;
            foreach ($rows as $key => $row) {
                if (isset($authorityRows[$key])) continue;
                $output->writeln(sprintf('  <comment>[EXTRA on %s]</comment> %s key=%s 权威片没有但 shard %d 有（不自动删，需人工确认）',
                    $shards[$sid]['name'], $table, $key, $sid));
                // 不计入 missing/diffs，因为不自动修
            }
        }

        if ($missing === 0 && $diffs === 0) {
            $count = count($authorityRows);
            $output->writeln("  <info>[OK]</info> 跨 " . count($shards) . " 片一致（{$count} 行）");
        }
        return [$missing, $diffs];
    }

    /** 拼业务键字符串（不同列用 ::: 分隔避免歧义） */
    private function makeBizKey(array $bizCols, array $row): string
    {
        $parts = [];
        foreach ($bizCols as $col) $parts[] = (string)($row[$col] ?? '');
        return implode(':::', $parts);
    }

    /** 比对两行行：忽略 IGNORE_COLS，返回值不同的字段 [col => [authValue, shardValue]] */
    private function diffCols(array $auth, array $shard): array
    {
        $diffs = [];
        $allCols = array_unique(array_merge(array_keys($auth), array_keys($shard)));
        foreach ($allCols as $col) {
            if (in_array($col, self::IGNORE_COLS, true)) continue;
            $a = $auth[$col] ?? null;
            $s = $shard[$col] ?? null;
            // 把 DECIMAL/数值规范化避免 "1.00" vs "1" 误判
            if (is_numeric($a) && is_numeric($s)) {
                if ((string)$a !== (string)$s && abs((float)$a - (float)$s) > 1e-9) {
                    $diffs[$col] = [$a, $s];
                }
            } else {
                if ((string)$a !== (string)$s) {
                    $diffs[$col] = [(string)$a, (string)$s];
                }
            }
        }
        return $diffs;
    }
}
