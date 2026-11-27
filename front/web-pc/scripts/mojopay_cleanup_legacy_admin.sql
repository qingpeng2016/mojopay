-- 从旧 fgmm 库迁到 mojopay 后执行：删掉已删除 PHP 模块的菜单与权限节点，避免侧栏/首页 iframe 请求 404。
-- 库：与 ThinkAdmin 主连接相同（当前为 mojopay 库）
-- 可重复执行

DELETE FROM system_menu
WHERE node REGEXP '^(scarb|rmpd|so_strategy|bitfinex|arbopp|backtest|data|cool_pay|wechat|api)/'
   OR url REGEXP '^(scarb|rmpd|so_strategy|bitfinex|arbopp|backtest|data|cool_pay|wechat|api)/';

DELETE FROM system_auth_node
WHERE node REGEXP '^(scarb|rmpd|so_strategy|bitfinex|arbopp|backtest|data|cool_pay|wechat|api)/';