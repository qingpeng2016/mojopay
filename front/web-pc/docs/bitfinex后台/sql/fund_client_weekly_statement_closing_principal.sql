-- 周账单增加期末本金列；生成 PDF 时写入，下一周期初本金优先取上一周该值
-- 在 db_bitfinex 执行

ALTER TABLE `fund_client_weekly_statement`
  ADD COLUMN `closing_principal` decimal(28,8) NOT NULL DEFAULT 0.00000000
    COMMENT '期末持仓本金（生成 PDF 时写入；下一周期初优先取此值）'
    AFTER `total_profit`;

-- 存量：历史 total_invest 即期末本金
UPDATE `fund_client_weekly_statement`
SET `closing_principal` = `total_invest`
WHERE `closing_principal` = 0 AND `total_invest` <> 0;
