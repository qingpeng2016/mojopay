<?php
declare(strict_types=1);

// 无数据库依赖，仅加载控制器以验证实际导出格式。
namespace think\admin {
    class Controller {}
}

namespace {
    require __DIR__ . '/../app/so_strategy/controller/FastbookPnlReport.php';

    $class = new ReflectionClass(\app\so_strategy\controller\FastbookPnlReport::class);
    $controller = $class->newInstanceWithoutConstructor();
    $csv = $class->getMethod('csvValue');
    $csv->setAccessible(true);
    $decorate = $class->getMethod('decorate');
    $decorate->setAccessible(true);
    $cases = [
        ['1 / 6', 'win_lose_outcomes', "\t1 / 6"],
        ['4 / 4', 'win_lose_outcomes', "\t4 / 4"],
        ['12 / 28', 'win_lose_outcomes', "\t12 / 28"],
        ['40 / 11', 'win_lose_outcomes', "\t40 / 11"],
        ['0 / 0', 'win_lose_outcomes', "\t0 / 0"],
        ['1467 / 686', 'win_lose_outcomes', "\t1467 / 686"],
        [null, 'win_lose_outcomes', ''],
        ['', 'win_lose_outcomes', ''],
        ['-123.4567890000', 'pnl', '-123.4567890000'],
        ['40', 'trade_count', '40'],
        ['2026-11-17 15:11:35.123456', 'last_trade_at', "\t2026-11-17 15:11:35"],
        ['=1+1', 'market_name', "'=1+1"],
    ];
    foreach ($cases as [$value, $field, $expected]) {
        $actual = $csv->invoke($controller, $value, $field);
        if ($actual !== $expected) {
            throw new \RuntimeException('CSV 格式不符：' . $field);
        }
        $stream = fopen('php://memory', 'w+');
        fputcsv($stream, [$actual], ',', '"', '');
        rewind($stream);
        $parsed = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        if ($parsed !== ($expected === '' ? [null] : [$expected])) {
            throw new \RuntimeException('CSV 写入后文本保护丢失：' . $field);
        }
    }
    $rows = $decorate->invoke($controller, 'user', [['win_outcomes' => 40, 'lose_outcomes' => 11]]);
    if ($rows[0]['win_lose_outcomes'] !== '40 / 11') {
        throw new \RuntimeException('页面盘口数格式变化');
    }
    echo "快盘 CSV 定向验证通过：盘口数文本保护、CSV 写入回读、页面格式及金额/时间/公式转义\n";
}
