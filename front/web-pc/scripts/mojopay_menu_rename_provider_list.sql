-- 将侧栏「商家库」改名为「商家列表」（ThinkAdmin 主库 system_menu）
UPDATE system_menu
SET title = '商家列表'
WHERE node = 'mojopay/provider/index' AND title IN ('商家库', '上游商家', '上游档案', '上游服务商');