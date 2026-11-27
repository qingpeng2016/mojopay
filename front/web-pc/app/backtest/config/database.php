<?php
// 回测中心模块默认数据库连接：指向快盘回测库 fgmm_f_backtest
// 模块内所有 Db::name('xxx') 自动走 db_f_backtest，无需每次显式 connect
return [
    'default' => 'db_f_backtest',
];
