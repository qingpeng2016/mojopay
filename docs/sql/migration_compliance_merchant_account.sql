ALTER TABLE `compliance_case`
  ADD COLUMN `merchant_account_id` BIGINT UNSIGNED NULL COMMENT '关联商户主/子账号' AFTER `merchant_id`,
  ADD KEY `idx_compliance_merchant_account` (`merchant_account_id`, `status`),
  ADD CONSTRAINT `fk_compliance_merchant_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`);