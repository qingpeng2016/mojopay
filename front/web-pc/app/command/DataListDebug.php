<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\service\ShardManager;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 验证 DataReport.index() 的 fan-out 分页逻辑：
 *   - 不构造 Request；直接复刻 controller 内"跨片拉 page*pageSize 条 → 合并 → 切页"
 *   - 默认拉 page=1 size=15，列出 id/shard/updated_at
 *   - 用法： php think data:list-debug
 */
class DataListDebug extends Command
{
    protected function configure()
    {
        $this->setName('data:list-debug')
             ->setDescription('Debug DataReport.index() fan-out list pagination');
    }

    protected function execute(Input $input, Output $output)
    {
        $page = 1;
        $pageSize = 15;
        $defaultStart = date('Y-m-d H:i:s', strtotime('-3 months'));

        $perShard = ShardManager::fanOutRead(function (string $conn) use ($page, $pageSize, $defaultStart) {
            $q = Db::connect($conn)->name('source_trader_records')->where('updated_at', '>=', $defaultStart);
            return [
                'count' => (int)(clone $q)->count(),
                'rows'  => (clone $q)->order('updated_at', 'desc')->order('id', 'desc')
                                     ->limit($page * $pageSize)->select()->toArray(),
            ];
        });

        $total = 0;
        $merged = [];
        foreach ($perShard as $sid => $r) {
            $total += $r['count'];
            foreach ($r['rows'] as $row) {
                $row['__shard'] = $sid;
                $merged[] = $row;
            }
        }
        usort($merged, function ($a, $b) {
            $cmp = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        $pageRows = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

        $output->writeln("perShard counts: " . json_encode(array_map(fn($r) => $r['count'], $perShard)));
        $output->writeln("total = {$total}");
        $output->writeln("");
        $output->writeln(sprintf("%-6s %-8s %-22s %-10s %-12s %s",
            'shard', 'id', 'updated_at', 'side', 'status', 'tgid'));
        foreach ($pageRows as $r) {
            $output->writeln(sprintf("%-6d %-8d %-22s %-10s %-12s %s",
                $r['__shard'], $r['id'],
                (string)($r['updated_at'] ?? ''),
                (string)($r['order_side'] ?? ''),
                (string)($r['status'] ?? ''),
                (string)($r['target_group_id'] ?? '')));
        }
        return 0;
    }
}
