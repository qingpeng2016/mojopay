<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\service\ShardManager;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 验证 saveGroup 的 pre-check：故意删某片一条 hedge_* 行后调用 missingAcrossShards
 * 期望：返回 missing 列表，而不是静默吞 0 affected。
 * 命令完成时自动恢复被删的行。
 */
class HedgeSaveTest extends Command
{
    protected function configure()
    {
        $this->setName('hedge:save-test')
             ->setDescription('Verify saveGroup pre-check catches missing config_name on a shard');
    }

    protected function execute(Input $input, Output $output)
    {
        $shards = ShardManager::getActiveShards();
        if (count($shards) < 2) {
            $output->writeln('<comment>SKIP: 单分片不需要测</comment>');
            return 0;
        }
        $shardIds = array_keys($shards);
        $secondShardId = (int)$shardIds[1];
        $testKey = 'hedge_trigger_threshold';

        // 1. 备份原行
        $orig = Db::connect(ShardManager::conn($secondShardId))->name('config_trade')
            ->where('config_name', $testKey)->find();
        if (!$orig) {
            $output->writeln("<error>{$testKey} 在第二片本来就没有，先 shard:repair 修一下</error>");
            return 1;
        }
        $output->writeln("备份 mm1 上的 {$testKey} (id={$orig['id']} value={$orig['value']})");

        try {
            // 2. 删 mm1 上的该行
            Db::connect(ShardManager::conn($secondShardId))->name('config_trade')
                ->where('config_name', $testKey)->delete();
            $output->writeln("已删 mm1 上的 {$testKey} (模拟 SQL 未在该片执行)");

            // 3. missingAcrossShards 应该返回 mm1 缺这个 key
            $missing = ShardManager::missingAcrossShards('config_trade', 'config_name', [
                $testKey, 'hedge_tail_disable_enabled',
            ]);
            $isCorrect = isset($missing[$secondShardId])
                && in_array($testKey, $missing[$secondShardId]['missing'], true);
            $output->writeln(
                ($isCorrect ? '<info>[OK]</info>' : '<error>[FAIL]</error>')
                . " missingAcrossShards 检测到 mm1 缺 {$testKey}: " . json_encode($missing, JSON_UNESCAPED_UNICODE)
            );

            // 4. 如果不修就跑 UPDATE，mm1 会 0 affected（确认静默吞的真实存在）
            $aff = Db::connect(ShardManager::conn($secondShardId))->name('config_trade')
                ->where('config_name', $testKey)
                ->update(['value' => '999', 'updated_at' => date('Y-m-d H:i:s')]);
            $output->writeln(
                ($aff === 0 ? '<info>[OK]</info>' : '<error>[FAIL]</error>')
                . " mm1 UPDATE 0 affected （证明若无 pre-check 就会静默吞）: aff={$aff}"
            );
        } finally {
            // 5. 恢复
            Db::connect(ShardManager::conn($secondShardId))->name('config_trade')->insert([
                'config_type' => $orig['config_type'],
                'config_name' => $orig['config_name'],
                'description' => $orig['description'],
                'value'       => $orig['value'],
                'created_at'  => $orig['created_at'],
                'updated_at'  => $orig['updated_at'],
            ]);
            $output->writeln("已恢复 mm1 上的 {$testKey}");
        }

        // 6. 恢复后再 missing 应该为空
        $missingAfter = ShardManager::missingAcrossShards('config_trade', 'config_name', [$testKey]);
        $output->writeln(
            (empty($missingAfter) ? '<info>[OK]</info>' : '<error>[FAIL]</error>')
            . " 恢复后 missing 应该为空: " . json_encode($missingAfter, JSON_UNESCAPED_UNICODE)
        );

        return 0;
    }
}
