-- fund_client_guaranteed_rate 增加 bonus（可在「用户利率」页修改）
ALTER TABLE `fund_client_guaranteed_rate`
    ADD COLUMN `bonus` decimal(28,10) NOT NULL DEFAULT 0.0000000000 COMMENT '客户该产品 bonus（小数，如 0.01=1%）' AFTER `guaranteed_rate`;
