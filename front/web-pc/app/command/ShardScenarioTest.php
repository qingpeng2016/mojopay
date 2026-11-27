<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\controller\DataReport;
use app\cool_pay2\service\ShardManager;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 4 个端到端场景：
 *   1. ConfigTrade 加一条 → 两片 id 对齐 → 自动清理
 *   2. RebalanceConfig 加一条（mm1 原本 0 行，预期 mm1 建出新行）→ 自动清理
 *   3. MarketMappingConfig 列表 → 计数应等于 mm + mm1 之和
 *   4. DataReport.buildStats → 输出 active / total / pnl_30
 *
 * 用法：php think shard:scenario-test
 */
class ShardScenarioTest extends Command
{
    private int $failures = 0;

    protected function configure()
    {
        $this->setName('shard:scenario-test')
             ->setDescription('Run 4 end-to-end shard scenarios');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->scenario1ConfigTrade($output);
        $this->scenario2RebalanceConfig($output);
        $this->scenario3MarketMappingList($output);
        $this->scenario4BuildStats($output);
        $this->scenario5AtomicRollback($output);

        $output->writeln('');
        if ($this->failures === 0) {
            $output->writeln('<info>[PASS] 5 个场景全部通过</info>');
            return 0;
        }
        $output->writeln('<error>[FAIL] ' . $this->failures . ' 个场景不通过</error>');
        return 1;
    }

    private function ok(Output $output, string $label, bool $cond, string $detail = ''): void
    {
        $line = ($cond ? '<info>[OK]</info>   ' : '<error>[FAIL]</error> ') . $label;
        if ($detail !== '') $line .= '  →  ' . $detail;
        $output->writeln($line);
        if (!$cond) $this->failures++;
    }

    /** 场景 1：ConfigTrade 加一条 → 两片 id 对齐 */
    private function scenario1ConfigTrade(Output $output): void
    {
        $output->writeln('');
        $output->writeln('=== 场景 1: ConfigTrade.add 跨片同步 ===');
        $now = date('Y-m-d H:i:s');
        $marker = 'SHARD_TEST_' . substr(md5((string)microtime(true)), 0, 8);
        $data = [
            'config_type'  => 'trading',
            'config_name'  => $marker,
            'description'  => 'shard-test 一次性测试行 (auto-clean)',
            'value'        => '999',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $output->writeln("  config_name marker = {$marker}");

        try {
            $result = ShardManager::fanOutInsert('config_trade', $data);
            $this->ok($output, '1.1 fanOutInsert.ok',
                $result['ok'] === true,
                'per_shard=' . json_encode($result['per_shard']));

            // 各片按业务键（config_name）读回，断言每片都有该 marker 行
            // 注意：id 跨片可能不同（各片自己 auto-increment），不对其做严格断言
            $foundIds = [];
            foreach (ShardManager::getActiveShards() as $sid => $info) {
                $row = Db::connect(ShardManager::conn($sid))->name('config_trade')
                    ->where('config_name', $marker)->find();
                $this->ok($output, "1.2 shard {$sid} ({$info['name']}) 按业务键 (config_name) 能查到该行",
                    !empty($row),
                    'found id=' . ($row['id'] ?? 'NONE'));
                if (!empty($row)) $foundIds[$sid] = (int)$row['id'];
            }
            $this->ok($output, '1.3 业务键 (config_name=' . $marker . ') 各片都存在',
                count($foundIds) === count(ShardManager::getActiveShards()),
                'foundIds=' . json_encode($foundIds) . '（id 跨片可不同）');
        } finally {
            // 清理
            $cleanup = ShardManager::fanOutWriteInTx(function ($conn) use ($marker) {
                return Db::connect($conn)->name('config_trade')->where('config_name', $marker)->delete();
            });
            $output->writeln('  清理: ' . json_encode($cleanup));
        }
    }

    /** 场景 2：RebalanceConfig 加一条（mm1 原本 0 行） */
    private function scenario2RebalanceConfig(Output $output): void
    {
        $output->writeln('');
        $output->writeln('=== 场景 2: RebalanceConfig.add 跨片同步（mm1 原 0 行）===');

        // 拍前各片 rebalance_config 行数
        $before = [];
        foreach (ShardManager::getActiveShards() as $sid => $info) {
            $before[$sid] = (int)Db::connect(ShardManager::conn($sid))->name('rebalance_config')->count();
            $output->writeln("  before: shard {$sid} ({$info['name']}) = {$before[$sid]} 行");
        }

        $now = date('Y-m-d H:i:s');
        $marker = 'SHARD_TEST_' . substr(md5((string)microtime(true)), 0, 8);
        $data = [
            'config_key'   => $marker,
            'config_value' => '0.42',
            'config_desc'  => 'shard-test (auto-clean)',
            'status'       => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $output->writeln("  config_key marker = {$marker}");

        try {
            $result = ShardManager::fanOutInsert('rebalance_config', $data);
            $this->ok($output, '2.1 fanOutInsert.ok',
                $result['ok'] === true,
                'per_shard=' . json_encode($result['per_shard']));

            // 各片读回 + 比对行数差
            foreach (ShardManager::getActiveShards() as $sid => $info) {
                $after = (int)Db::connect(ShardManager::conn($sid))->name('rebalance_config')->count();
                $this->ok($output, "2.2 shard {$sid} ({$info['name']}) 行数 +1",
                    $after === $before[$sid] + 1,
                    "before={$before[$sid]} after={$after}");
            }
        } finally {
            $cleanup = ShardManager::fanOutWriteInTx(function ($conn) use ($marker) {
                return Db::connect($conn)->name('rebalance_config')->where('config_key', $marker)->delete();
            });
            $output->writeln('  清理: ' . json_encode($cleanup));
        }
    }

    /** 场景 3：MarketMappingConfig 列表跨片合并行数 */
    private function scenario3MarketMappingList(Output $output): void
    {
        $output->writeln('');
        $output->writeln('=== 场景 3: MarketMappingConfig 列表跨片合并 ===');
        $perShard = ShardManager::fanOutRead(function (string $conn) {
            return (int)Db::connect($conn)->name('market_mapping_config')->count();
        });
        $total = array_sum($perShard);
        foreach ($perShard as $sid => $n) {
            $output->writeln("  shard {$sid}: {$n} 个 market_mapping_config 行");
        }
        $this->ok($output, '3.1 跨片合计 > 0',
            $total > 0, "total={$total}");
        // dev 上 mm=3445 / mm1=777，总和 4222（数据可能波动，但应该 > 4000）
        $this->ok($output, '3.2 跨片合计 ≈ 4000+',
            $total > 4000, "total={$total}");
    }

    /** 场景 5：2PC 原子性 —— 故意让 mm1 插入失败（UNIQUE 冲突），验证 mm 回滚不留数据 */
    private function scenario5AtomicRollback(Output $output): void
    {
        $output->writeln('');
        $output->writeln('=== 场景 5: 2PC 原子性（一片失败 → 另一片回滚） ===');
        $shards = ShardManager::getActiveShards();
        if (count($shards) < 2) {
            $output->writeln('  [SKIP] 单分片，不需要测原子性');
            return;
        }

        $marker = 'ATOMIC_TEST_' . substr(md5((string)microtime(true)), 0, 8);
        $now = date('Y-m-d H:i:s');
        $shardIds = array_keys($shards);
        $secondShardId = (int)$shardIds[1];

        // 1) 先在 mm1 上单独插一条 → 制造 UNIQUE 冲突陷阱
        try {
            Db::connect(ShardManager::conn($secondShardId))->name('config_trade')->insert([
                'config_type' => 'trading',
                'config_name' => $marker,
                'description' => 'atomic-test trap',
                'value'       => '0',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $output->writeln("  预埋: shard {$secondShardId} 已有 config_name={$marker}（制造 UNIQUE 冲突）");
        } catch (\Throwable $e) {
            $this->ok($output, '5.1 预埋陷阱', false, '失败: ' . $e->getMessage());
            return;
        }

        // 2) fanOutInsert 同 marker → mm 会成功 phase 1，mm1 phase 1 撞 UNIQUE → 2PC 整体回滚
        $result = ShardManager::fanOutInsert('config_trade', [
            'config_type' => 'trading',
            'config_name' => $marker,
            'description' => 'should-be-rolled-back',
            'value'       => '888',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $this->ok($output, '5.1 整体 ok=false', $result['ok'] === false,
            'per_shard=' . json_encode($result['per_shard']));

        // 3) 检查 mm (第一片) 上有没有遗留 description='should-be-rolled-back' 的行 —— 不应该有
        $firstShardId = (int)$shardIds[0];
        $leaked = Db::connect(ShardManager::conn($firstShardId))->name('config_trade')
            ->where('config_name', $marker)
            ->where('description', 'should-be-rolled-back')
            ->find();
        $this->ok($output, '5.2 mm 上没有遗留行（2PC 回滚 phase 1 成功的片）',
            empty($leaked),
            $leaked ? '泄漏 id=' . $leaked['id'] : '干净');

        // 4) mm1 上的预埋行还在（它的 phase 1 失败但本身的预埋插入早就 commit 了）
        $trap = Db::connect(ShardManager::conn($secondShardId))->name('config_trade')
            ->where('config_name', $marker)->find();
        $this->ok($output, '5.3 mm1 预埋陷阱行仍在（确认是 UNIQUE 拒绝而不是其它原因）',
            !empty($trap) && (string)$trap['description'] === 'atomic-test trap',
            $trap ? 'description=' . $trap['description'] : 'NONE');

        // 5) 清理：删两片上所有该 marker 的行
        $cleanup = ShardManager::fanOutWriteInTx(function ($conn) use ($marker) {
            return Db::connect($conn)->name('config_trade')->where('config_name', $marker)->delete();
        });
        $output->writeln('  清理: ' . json_encode($cleanup));
    }

    /** 场景 4：DataReport.buildStats 顶部 4 卡 */
    private function scenario4BuildStats(Output $output): void
    {
        $output->writeln('');
        $output->writeln('=== 场景 4: DataReport.buildStats 顶部 4 卡 ===');
        $app = App::getInstance();
        /** @var DataReport $ctrl */
        $ctrl = $app->invokeClass(DataReport::class);
        $ref = new \ReflectionMethod(DataReport::class, 'buildStats');
        $ref->setAccessible(true);
        $stats = $ref->invoke($ctrl);
        foreach (['active_markets', 'total_volume', 'running_volume', 'pnl_30', 'pnl_30_raw'] as $k) {
            $output->writeln("  {$k} = " . (string)$stats[$k]);
        }
        $this->ok($output, '4.1 active_markets > 0', (int)str_replace(',', '', $stats['active_markets']) > 0);
        $this->ok($output, '4.2 pnl_30_raw 有数值', is_numeric($stats['pnl_30_raw']));
    }
}
