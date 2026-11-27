ALTER TABLE `payment_order`
  ADD COLUMN `payment_url` VARCHAR(512) NULL COMMENT '付款页 URL（收银台链接）' AFTER `expires_at`;