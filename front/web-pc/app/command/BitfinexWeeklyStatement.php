<?php

declare(strict_types=1);

namespace app\command;

use app\bitfinex\service\WeeklyStatementService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 上一 UTC 周客户周账单 PDF：已生成则跳过，缺失则补生成。
 *
 * 建议 crontab 每 N 分钟跑一次（默认 5），周一 0 点后会自动补齐上周 PDF，其余时间多为跳过。
 *
 *   php think bitfinex:weekly-statement
 *   php think bitfinex:weekly-statement --force
 *   php think bitfinex:weekly-statement --client-id=1 --statement-type=GUARANTEED
 *   crontab 示例：每 5 分钟执行 php think bitfinex:weekly-statement
 */
class BitfinexWeeklyStatement extends Command
{
    protected function configure(): void
    {
        $this->setName('bitfinex:weekly-statement')
            ->setDescription('生成上一 UTC 周客户周账单 PDF（已生成则跳过）')
            ->addOption(
                'interval',
                'i',
                Option::VALUE_OPTIONAL,
                '建议 crontab 执行间隔（分钟），仅打印提示，命令本身单次执行',
                '5'
            )
            ->addOption('force', 'f', Option::VALUE_NONE, '强制重生成上一周全部 PDF')
            ->addOption('client-id', null, Option::VALUE_OPTIONAL, '仅处理指定客户 ID')
            ->addOption(
                'statement-type',
                null,
                Option::VALUE_OPTIONAL,
                '仅处理账单类型 FLOATING|GUARANTEED'
            )
            ->addOption(
                'operator',
                null,
                Option::VALUE_OPTIONAL,
                '写入 generated_by',
                'cron:weekly-statement'
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $force    = (bool)$input->getOption('force');
        $operator = trim((string)$input->getOption('operator'));
        if ($operator === '') {
            $operator = 'cron:weekly-statement';
        }

        $options = ['force' => $force];
        $clientId = (int)($input->getOption('client-id') ?? 0);
        if ($clientId > 0) {
            $options['client_id'] = $clientId;
        }
        $statementType = strtoupper(trim((string)($input->getOption('statement-type') ?? '')));
        if ($statementType !== '') {
            if (!in_array($statementType, ['FLOATING', 'GUARANTEED'], true)) {
                $output->error('statement-type 仅支持 FLOATING 或 GUARANTEED');
                return 1;
            }
            $options['statement_type'] = $statementType;
        }

        $output->writeln(sprintf(
            '[%s] bitfinex:weekly-statement start (suggested cron every %d min, force=%s)',
            gmdate('Y-m-d H:i:s') . ' UTC',
            $interval,
            $force ? 'yes' : 'no'
        ));

        $service = new WeeklyStatementService();
        $result  = $service->generatePreviousWeekPdfs($operator, $options);

        $output->writeln($result['message']);
        foreach ($result['details'] as $line) {
            $output->writeln('  - ' . $line);
        }

        if (!empty($result['skipped_all'])) {
            $output->writeln(sprintf(
                '提醒：无需生成。建议 crontab：*/%d * * * * php think bitfinex:weekly-statement',
                $interval
            ));
            return 0;
        }

        if ((int)$result['failed'] > 0) {
            $output->error(sprintf('完成但有失败：generated=%d failed=%d', $result['generated'], $result['failed']));
            return 1;
        }

        $output->writeln(sprintf(
            '完成：week=%s~%s generated=%d skipped=%d',
            $result['week_start'],
            $result['week_end'],
            $result['generated'],
            $result['skipped']
        ));

        return 0;
    }
}
