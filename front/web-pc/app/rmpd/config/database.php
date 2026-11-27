<?php
// 这是 ThinkPHP 的模块级配置文件，专门用于覆盖数据库连接配置。
// ThinkPHP 加载配置的规则：
//   1. 先读取全局配置（项目根目录的 config/database.php）
//   2. 再读取当前应用模块的配置（app/rmpd/config/database.php）
//   3. 模块配置会覆盖全局配置中同名的 key

return [
    // 'default' 指定当前模块（rmpd）默认使用哪个数据库连接。
    // 'db_rmpd' 是在全局 config/database.php 的 connections 数组中定义的连接名称，
    // 它指向策略交易系统（RMPD）专用的 MySQL 数据库。
    //
    // 这样做的好处：rmpd 模块里所有 Db::name('xxx') 调用
    // 都会自动连接到策略库，而不会错误地操作管理后台的主数据库。
    // 如果需要显式切换，可以用 Db::connect('db_rmpd')->name('xxx') 明确指定。
    'default' => 'db_rmpd',
];
