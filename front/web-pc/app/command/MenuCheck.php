<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class MenuCheck extends Command
{
    protected function configure(): void
    {
        $this->setName('menu:check')->setDescription('查 system_menu 中含 cool_pay / cool_pay2 / cool_pay3 节点');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rows = Db::table('system_menu')
            ->whereLike('node', 'cool_pay%')
            ->field('id,node,title,status')
            ->order('node', 'asc')
            ->limit(100)
            ->select()
            ->toArray();
        $output->writeln("system_menu (cool_pay*) " . count($rows) . " 行:");
        $byApp = ['cool_pay/' => [], 'cool_pay2/' => [], 'cool_pay3/' => []];
        foreach ($rows as $r) {
            foreach ($byApp as $prefix => &$bucket) {
                if (strpos($r['node'], $prefix) === 0) { $bucket[] = $r; break; }
            }
            unset($bucket);
        }
        foreach ($byApp as $prefix => $bucket) {
            $output->writeln("  --- {$prefix} (" . count($bucket) . ") ---");
            foreach ($bucket as $r) {
                $output->writeln(sprintf("    [%d] %-55s %s  status=%s", $r['id'], $r['node'], $r['title'], $r['status']));
            }
        }
        return 0;
    }
}
