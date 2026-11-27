<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\controller\DataReport;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 一次性诊断：用反射调用 DataReport::buildStats() 并打印结果，
 * 用于验证 fan-out 改造后顶部 4 张卡的数字。
 * 本地 docker：SHARD_SKIP_UNREACHABLE=1 时 mm_1 会被静默跳过，结果只反映 mm_0。
 */
class DataStatsDebug extends Command
{
    protected function configure()
    {
        $this->setName('data:stats-debug')
             ->setDescription('Run DataReport::buildStats() and print');
    }

    protected function execute(Input $input, Output $output)
    {
        // 走 App 容器实例化 DataReport（解决依赖注入）
        $app = App::getInstance();
        /** @var DataReport $ctrl */
        $ctrl = $app->invokeClass(DataReport::class);

        $ref = new \ReflectionMethod(DataReport::class, 'buildStats');
        $ref->setAccessible(true);

        $t0 = microtime(true);
        $stats = $ref->invoke($ctrl);
        $ms = (microtime(true) - $t0) * 1000;

        $output->writeln(sprintf('[buildStats] %.1fms', $ms));
        foreach ($stats as $k => $v) {
            $output->writeln("  {$k} = " . (is_scalar($v) ? (string)$v : json_encode($v)));
        }
        return 0;
    }
}
