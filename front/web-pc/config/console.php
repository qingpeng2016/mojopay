<?php


// config/console.php

return [
    // Register your custom commands here
    'commands' => [
        'resolve:market' => \app\command\ResolveMarket::class,
        'resolve:sports'=> \app\command\ResolveSports::class,
        'outcome:login' => \app\command\OutcomeLogin::class,
        'outcomes:login' => \app\command\OutcomesLogin::class,
        'fetch:outcomes' => \app\command\FetchOutcomes::class,
        'fetch:mmapi' => \app\command\FetchMMApi::class,
        'fetch:geoposition' => \app\command\FetchGeoposition::class,
        'shard:test'  => \app\command\ShardSelfTest::class,
        'data:stats-debug' => \app\command\DataStatsDebug::class,
        'data:list-debug'  => \app\command\DataListDebug::class,
        'data:insight-debug' => \app\command\DataInsightDebug::class,
        'shard:scenario-test' => \app\command\ShardScenarioTest::class,
        'shard:repair' => \app\command\ShardRepair::class,
        'hedge:schema-test' => \app\command\HedgeSchemaTest::class,
        'hedge:save-test' => \app\command\HedgeSaveTest::class,
        'menu:check' => \app\command\MenuCheck::class,
        'perf:add-indexes' => \app\command\PerfAddIndexes::class,
        'perf:rtt-deep' => \app\command\PerfRttDeep::class,
        'bitfinex:weekly-statement' => \app\command\BitfinexWeeklyStatement::class,
    ],
];
