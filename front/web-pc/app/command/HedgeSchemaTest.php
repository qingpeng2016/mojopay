<?php
declare(strict_types=1);

namespace app\command;

use app\cool_pay2\controller\MarketMakerDetail;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class HedgeSchemaTest extends Command
{
    protected function configure()
    {
        $this->setName('hedge:schema-test')
             ->setDescription('Verify MarketMakerDetail HEDGE_SCHEMA renders correctly with 4 new hedge_* fields');
    }

    protected function execute(Input $input, Output $output)
    {
        $ref = new \ReflectionClass(MarketMakerDetail::class);
        $schema = $ref->getConstant('HEDGE_SCHEMA');
        $output->writeln("HEDGE_SCHEMA 共 " . count($schema) . " 个字段:");
        foreach ($schema as $i => $row) {
            $output->writeln(sprintf("  [%d] kind=%-7s name=%-32s label=%s",
                $i, $row['kind'], $row['name'] ?? '?', $row['label']));
        }

        $collect = $ref->getMethod('collectConfigNames');
        $collect->setAccessible(true);
        $names = $collect->invoke(null, $schema);
        $output->writeln("\ncollectConfigNames: " . json_encode($names));

        $rows = Db::name('config_trade')->whereIn('config_name', $names)->select()->toArray();
        $byName = [];
        foreach ($rows as $r) $byName[$r['config_name']] = $r;

        $hydrate = $ref->getMethod('hydrateSchema');
        $hydrate->setAccessible(true);
        $rendered = $hydrate->invoke(null, $schema, $byName);
        $output->writeln("\nhydrateSchema 后准备给 view 的数据:");
        foreach ($rendered as $row) {
            if (($row['kind'] ?? '') === 'range') {
                $output->writeln(sprintf("  range   %-15s %s - %s",
                    $row['label'], $row['minValue'] ?? '?', $row['maxValue'] ?? '?'));
            } else {
                $hint = substr((string)($row['hint'] ?? ''), 0, 45);
                $output->writeln(sprintf("  %-7s %-22s = %-10s hint=%s",
                    $row['kind'], $row['label'], (string)($row['value'] ?? '?'), $hint));
            }
        }
        return 0;
    }
}
