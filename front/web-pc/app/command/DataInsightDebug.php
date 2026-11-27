<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\controller\DataInsight;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 反射调用 DataInsight 的关键私有方法并打印输出，验证多分片改造后的结果。
 * 用法： php think data:insight-debug
 */
class DataInsightDebug extends Command
{
    protected function configure()
    {
        $this->setName('data:insight-debug')
             ->setDescription('Run DataInsight key methods and print');
    }

    protected function execute(Input $input, Output $output)
    {
        $app = App::getInstance();
        /** @var DataInsight $ctrl */
        $ctrl = $app->invokeClass(DataInsight::class);
        $ref = new \ReflectionClass(DataInsight::class);

        $invoke = function (string $method, array $args) use ($ref, $ctrl) {
            $m = $ref->getMethod($method);
            $m->setAccessible(true);
            return $m->invokeArgs($ctrl, $args);
        };

        $start = date('Y-m-d', strtotime('-29 days'));
        $end = date('Y-m-d');

        $t0 = microtime(true);
        $u = $invoke('unhedgedPositionStats', []);
        $output->writeln(sprintf('[unhedgedPositionStats] %.0fms', (microtime(true) - $t0) * 1000));
        $output->writeln('  market_count=' . $u['market_count']);
        $output->writeln('  total_unhedged_sell=' . $u['total_unhedged_sell']);
        $output->writeln('  total_unhedged_buy=' . $u['total_unhedged_buy']);
        $output->writeln('  top_sell[0]=' . json_encode($u['top_sell_outcomes'][0] ?? null, JSON_UNESCAPED_UNICODE));

        $t0 = microtime(true);
        $cm = $invoke('coreMetrics', [$start, $end, '', '', null]);
        $output->writeln(sprintf('[coreMetrics %s..%s] %.0fms', $start, $end, (microtime(true) - $t0) * 1000));
        foreach (['total_pnl_fmt', 'market_count', 'win_count', 'lose_count', 'max_loss_fmt', 'max_loss_id_short'] as $k) {
            $output->writeln("  {$k} = " . (string)$cm[$k]);
        }

        $t0 = microtime(true);
        $series = $invoke('dailyPnlSeries', [date('Y-m-d', strtotime('-6 days')), $end, '', '', null]);
        $output->writeln(sprintf('[dailyPnlSeries last 7d] %.0fms', (microtime(true) - $t0) * 1000));
        foreach ($series as $row) {
            $output->writeln("  {$row['d']}  pnl={$row['pnl']}  cum={$row['cum']}");
        }
        return 0;
    }
}
