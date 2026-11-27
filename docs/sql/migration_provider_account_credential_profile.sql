ALTER TABLE `provider_account`
  ADD COLUMN `credential_profile` JSON NULL COMMENT '账号对接配置（API 密钥、回调、出款 IP 等）' AFTER `credential_ref`;