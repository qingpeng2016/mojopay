ALTER TABLE `merchant_api_key`
  ADD COLUMN `secret_plain` VARCHAR(128) NULL COMMENT '沙盒 HMAC 密钥明文（生产走 Vault）' AFTER `secret_ref`;

ALTER TABLE `payment_order`
  ADD COLUMN `checkout_session_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '收银台会话 ID' AFTER `payment_url`,
  ADD UNIQUE KEY `uk_payment_checkout_session` (`checkout_session_id`);