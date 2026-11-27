-- 保证 MojoPay 根下：上游管理 sort=20，下游商户 sort=10（ThinkAdmin 按 sort desc，越大越靠前）
SET @root := (SELECT id FROM system_menu WHERE pid = 0 AND title = 'MojoPay' LIMIT 1);

UPDATE system_menu SET sort = 20 WHERE pid = @root AND title = '上游管理';
UPDATE system_menu SET sort = 10 WHERE pid = @root AND title IN ('下游商户', '下游商家');