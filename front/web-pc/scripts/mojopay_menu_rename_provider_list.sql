-- 将侧栏上游底栏菜单改名为「上游列表」（ThinkAdmin 主库 system_menu）
UPDATE system_menu
SET title = '上游列表'
WHERE node = 'mojopay/provider/index' AND title IN ('商家库', '上游商家', '上游档案', '上游服务商');