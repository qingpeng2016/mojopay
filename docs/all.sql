-- MojoPay 全量初始化 · MySQL 8.0.16+
-- 依据 docs/mojopay-technical-design.md。
-- 执行前选中目标数据库。例如 mysql --default-character-set=utf8mb4 -u USER -p DB < docs/all.sql
-- 本文件用于初始化空库，可重复运行。已有表结构升级请使用独立迁移。
-- 金额 DECIMAL(36,0) 按资产最小单位存储，DATETIME(6) 按 UTC 写入。
-- 多行资金平衡、累计退款、结算分配、绑定有效期重叠及状态迁移由应用在事务内校验。
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET SESSION time_zone = '+00:00';

-- 一、资产和上游渠道

CREATE TABLE IF NOT EXISTS `asset` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `code` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '资产代码（如BTC、USD）',
  `kind` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '资产类型（法币或加密资产）',
  `decimals` TINYINT UNSIGNED NOT NULL COMMENT '资产小数位数',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asset_code` (`code`),
  CONSTRAINT `ck_asset_kind` CHECK (`kind` IN ('FIAT', 'CRYPTO')),
  CONSTRAINT `ck_asset_decimals` CHECK (`decimals` BETWEEN 0 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='资产字典';

CREATE TABLE IF NOT EXISTS `asset_network` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `network_code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '区块链网络代码',
  `contract_address` VARCHAR(160) COLLATE utf8mb4_bin NULL COMMENT '代币合约地址',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '链网络启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asset_network_code` (`asset_id`, `network_code`),
  UNIQUE KEY `uk_asset_network_scope` (`id`, `asset_id`),
  CONSTRAINT `fk_asset_network_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='资产支持的链网络';

CREATE TABLE IF NOT EXISTS `provider` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '上游服务商编码',
  `name` VARCHAR(128) NOT NULL COMMENT '上游服务商名称',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '上游服务商启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='上游服务商';

CREATE TABLE IF NOT EXISTS `provider_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_id` BIGINT UNSIGNED NOT NULL COMMENT '上游服务商主键',
  `parent_id` BIGINT UNSIGNED NULL COMMENT '上游父账号主键',
  `environment` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '上游环境（沙盒或生产）',
  `external_id` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '上游账号编号',
  `role` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '上游账号用途',
  `credential_ref` VARCHAR(255) NULL COMMENT '上游凭据存储引用',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '上游账号启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_account_ref` (`provider_id`, `environment`, `external_id`),
  UNIQUE KEY `uk_provider_account_scope` (`id`, `provider_id`, `environment`),
  KEY `idx_provider_account_parent` (`parent_id`, `provider_id`, `environment`),
  CONSTRAINT `fk_provider_account_provider` FOREIGN KEY (`provider_id`) REFERENCES `provider` (`id`),
  CONSTRAINT `fk_provider_account_parent` FOREIGN KEY (`parent_id`, `provider_id`, `environment`) REFERENCES `provider_account` (`id`, `provider_id`, `environment`),
  CONSTRAINT `ck_provider_account_env` CHECK (`environment` IN ('SANDBOX', 'PRODUCTION'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='上游主账号、机构子账号和运营账号';

CREATE TABLE IF NOT EXISTS `channel_capability` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '通道收付资产主键',
  `network_id` BIGINT UNSIGNED NULL COMMENT '资产网络主键',
  `settlement_asset_id` BIGINT UNSIGNED NULL COMMENT '商户结算资产主键',
  `operation` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通道支持的操作类型',
  `method` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '渠道支付或出款方式',
  `region` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通道适用地区代码',
  `capability_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通道能力编码',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '能力配置版本',
  `min_amount` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '单笔金额下限',
  `max_amount` DECIMAL(36,0) NOT NULL COMMENT '单笔金额上限',
  `constraints` JSON NULL COMMENT '通道限制与要求',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '通道能力启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_capability_key_ver` (`provider_account_id`, `capability_key`, `version`),
  UNIQUE KEY `uk_capability_provider_scope` (`id`, `provider_account_id`),
  KEY `idx_channel_lookup` (`operation`, `region`, `asset_id`, `method`, `status`),
  CONSTRAINT `fk_cap_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_cap_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_cap_network` FOREIGN KEY (`network_id`, `asset_id`) REFERENCES `asset_network` (`id`, `asset_id`),
  CONSTRAINT `fk_cap_settle_asset` FOREIGN KEY (`settlement_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_cap_version` CHECK (`version` > 0),
  CONSTRAINT `ck_cap_amount` CHECK (`min_amount` >= 0 AND `max_amount` >= `min_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='上游账户的收款、退款、换汇、划转及出款权限';

-- 二、商户、授权绑定、资质与受益账户

CREATE TABLE IF NOT EXISTS `merchant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `code` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户编码',
  `legal_name` VARCHAR(255) NOT NULL COMMENT '签约主体法定名称',
  `country` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户注册国家或地区代码',
  `category` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户经营类别',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '商户准入状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_public` (`public_id`),
  UNIQUE KEY `uk_merchant_code` (`code`),
  KEY `idx_merchant_country_status` (`country`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='下游签约商户';

CREATE TABLE IF NOT EXISTS `merchant_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT '所属商户主键',
  `parent_id` BIGINT UNSIGNED NULL COMMENT '商户父账号主键',
  `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户账号编码',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '商户账号启用状态',
  `brand_config` JSON NULL COMMENT '收银台品牌配置',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_account_public` (`public_id`),
  UNIQUE KEY `uk_merchant_account_code` (`merchant_id`, `code`),
  UNIQUE KEY `uk_merchant_account_scope` (`id`, `merchant_id`),
  KEY `idx_merchant_account_parent` (`parent_id`, `merchant_id`),
  CONSTRAINT `fk_merchant_account_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `merchant` (`id`),
  CONSTRAINT `fk_merchant_account_parent` FOREIGN KEY (`parent_id`, `merchant_id`) REFERENCES `merchant_account` (`id`, `merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='下游商户主账号和子账号';

CREATE TABLE IF NOT EXISTS `merchant_user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT '所属商户主键',
  `external_user_id` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '商户侧用户编号',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE' COMMENT '最终用户账号状态',
  `profile_ref` VARCHAR(255) NULL COMMENT '最终用户资料存储引用',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_user_public` (`public_id`),
  UNIQUE KEY `uk_merchant_user_ref` (`merchant_id`, `external_user_id`),
  UNIQUE KEY `uk_merchant_user_scope` (`id`, `merchant_id`),
  CONSTRAINT `fk_merchant_user_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `merchant` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户范围内识别的最终用户';

CREATE TABLE IF NOT EXISTS `merchant_api_key` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'API 密钥编号',
  `secret_ref` VARCHAR(255) NOT NULL COMMENT 'API 密钥存储引用',
  `scopes` JSON NOT NULL COMMENT '凭据授权范围',
  `environment` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '凭据环境（沙盒或生产）',
  `signature_version` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'v1' COMMENT '请求签名协议版本',
  `ip_allowlist` JSON NULL COMMENT '允许访问的来源地址段，空值为未配置',
  `requests_per_minute` INT UNSIGNED NOT NULL COMMENT '每分钟请求配额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT 'API 凭据启用状态',
  `expires_at` DATETIME(6) NULL COMMENT 'API 凭据到期时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_api_key` (`key_id`),
  KEY `idx_api_key_account` (`merchant_account_id`, `status`),
  CONSTRAINT `fk_merchant_api_key_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`),
  CONSTRAINT `ck_merchant_api_key_env` CHECK (`environment` IN ('SANDBOX', 'PRODUCTION')),
  CONSTRAINT `ck_merchant_api_key_rpm` CHECK (`requests_per_minute` > 0),
  CONSTRAINT `ck_merchant_api_key_allowlist` CHECK (`ip_allowlist` IS NULL OR (JSON_TYPE(`ip_allowlist`) = 'ARRAY' AND JSON_LENGTH(`ip_allowlist`) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户 API 凭据、授权与访问策略';

CREATE TABLE IF NOT EXISTS `merchant_webhook_endpoint` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `endpoint_url` VARCHAR(512) NOT NULL COMMENT '商户回调地址',
  `secret_ref` VARCHAR(255) NOT NULL COMMENT '回调签名密钥存储引用',
  `event_types` JSON NOT NULL COMMENT '订阅的事件类型',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '回调配置启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_webhook_endpoint_public` (`public_id`),
  UNIQUE KEY `uk_webhook_endpoint_scope` (`id`, `merchant_account_id`),
  KEY `idx_webhook_endpoint_account` (`merchant_account_id`, `status`),
  CONSTRAINT `fk_webhook_endpoint_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户回调地址与验签凭据引用';

CREATE TABLE IF NOT EXISTS `channel_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `capability_id` BIGINT UNSIGNED NOT NULL COMMENT '通道能力主键',
  `version` INT UNSIGNED NOT NULL COMMENT '路由绑定版本',
  `priority` INT NOT NULL DEFAULT 100 COMMENT '路由优先级（越小越优先）',
  `weight` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '同优先级的路由分配权重',
  `terms` JSON NULL COMMENT '通道绑定费率及授权条件',
  `effective_from` DATETIME(6) NOT NULL COMMENT '绑定生效时间',
  `effective_to` DATETIME(6) NULL COMMENT '绑定失效时间',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '路由绑定启用状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_binding_public` (`public_id`),
  UNIQUE KEY `uk_channel_binding_version` (`merchant_account_id`, `capability_id`, `version`),
  UNIQUE KEY `uk_channel_binding_scope` (`id`, `merchant_account_id`, `capability_id`),
  KEY `idx_channel_binding_routing` (`merchant_account_id`, `status`, `priority`, `effective_from`),
  CONSTRAINT `fk_binding_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`),
  CONSTRAINT `fk_binding_capability` FOREIGN KEY (`capability_id`) REFERENCES `channel_capability` (`id`),
  CONSTRAINT `ck_binding_values` CHECK (`version` > 0 AND `priority` >= 0 AND `weight` >= 0),
  CONSTRAINT `ck_binding_window` CHECK (`effective_to` IS NULL OR `effective_to` > `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户账号和上游能力的授权及路由版本';

CREATE TABLE IF NOT EXISTS `compliance_case` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT '所属商户主键',
  `merchant_user_id` BIGINT UNSIGNED NULL COMMENT '商户最终用户主键',
  `capability_id` BIGINT UNSIGNED NULL COMMENT '通道能力主键',
  `subject_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '核验对象类型（商户或最终用户）',
  `purpose` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '资质核验用途',
  `country` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '国家或地区代码',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '资质审核状态',
  `evidence_ref` VARCHAR(255) NULL COMMENT '资质审核材料存储引用',
  `expires_at` DATETIME(6) NULL COMMENT '审核结果有效期截止时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_compliance_case_public` (`public_id`),
  KEY `idx_compliance_subject` (`merchant_id`, `subject_type`, `status`, `expires_at`),
  CONSTRAINT `fk_compliance_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `merchant` (`id`),
  CONSTRAINT `fk_compliance_user` FOREIGN KEY (`merchant_user_id`, `merchant_id`) REFERENCES `merchant_user` (`id`, `merchant_id`),
  CONSTRAINT `fk_compliance_capability` FOREIGN KEY (`capability_id`) REFERENCES `channel_capability` (`id`),
  CONSTRAINT `ck_compliance_subject` CHECK ((`subject_type` = 'MERCHANT' AND `merchant_user_id` IS NULL) OR (`subject_type` = 'MERCHANT_USER' AND `merchant_user_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户 KYB、最终用户 KYC 与交易验证';

CREATE TABLE IF NOT EXISTS `beneficiary` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT '所属商户主键',
  `merchant_user_id` BIGINT UNSIGNED NULL COMMENT '商户最终用户主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `network_id` BIGINT UNSIGNED NULL COMMENT '资产网络主键',
  `type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '受益账户类型（银行或钱包）',
  `country` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '受益账户所在国家或地区代码',
  `destination_ref` VARCHAR(255) NOT NULL COMMENT '银行账户或钱包地址引用',
  `fingerprint` BINARY(32) NOT NULL COMMENT '受益账户去重指纹',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '受益账户审核状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_beneficiary_public` (`public_id`),
  UNIQUE KEY `uk_beneficiary_fingerprint` (`merchant_account_id`, `fingerprint`),
  UNIQUE KEY `uk_beneficiary_scope` (`id`, `merchant_account_id`, `asset_id`),
  CONSTRAINT `fk_beneficiary_merchant_account` FOREIGN KEY (`merchant_account_id`, `merchant_id`) REFERENCES `merchant_account` (`id`, `merchant_id`),
  CONSTRAINT `fk_beneficiary_user` FOREIGN KEY (`merchant_user_id`, `merchant_id`) REFERENCES `merchant_user` (`id`, `merchant_id`),
  CONSTRAINT `fk_beneficiary_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_beneficiary_network` FOREIGN KEY (`network_id`, `asset_id`) REFERENCES `asset_network` (`id`, `asset_id`),
  CONSTRAINT `ck_beneficiary_destination` CHECK ((`type` = 'BANK' AND `network_id` IS NULL) OR (`type` = 'WALLET' AND `network_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='审核通过的银行账户或链上钱包';

-- 三、报价、支付、兑换和退款

CREATE TABLE IF NOT EXISTS `quote` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `source_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '报价源资产主键',
  `target_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '报价目标资产主键',
  `provider_account_id` BIGINT UNSIGNED NULL COMMENT '上游账号主键',
  `rate` DECIMAL(38,18) NOT NULL COMMENT '目标资产金额与源资产金额的比率',
  `source_amount` DECIMAL(36,0) NOT NULL COMMENT '报价的源资产金额',
  `target_amount` DECIMAL(36,0) NOT NULL COMMENT '报价的目标资产金额',
  `fee` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '手续费金额',
  `fee_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '手续费资产主键',
  `pricing_mode` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '报价计价方式',
  `terms` JSON NULL COMMENT '报价保价及成交条件',
  `expires_at` DATETIME(6) NOT NULL COMMENT '报价到期时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_quote_public` (`public_id`),
  UNIQUE KEY `uk_quote_owner` (`id`, `merchant_account_id`),
  KEY `idx_quote_account_expiry` (`merchant_account_id`, `expires_at`),
  CONSTRAINT `fk_quote_merchant_account` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`),
  CONSTRAINT `fk_quote_source_asset` FOREIGN KEY (`source_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_quote_target_asset` FOREIGN KEY (`target_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_quote_fee_asset` FOREIGN KEY (`fee_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_quote_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `ck_quote_amounts` CHECK (`rate` > 0 AND `source_amount` > 0 AND `target_amount` > 0 AND `fee` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='价格、费用、金额和有效期快照';

CREATE TABLE IF NOT EXISTS `payment_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `merchant_id` BIGINT UNSIGNED NOT NULL COMMENT '所属商户主键',
  `merchant_user_id` BIGINT UNSIGNED NULL COMMENT '商户最终用户主键',
  `order_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '订单标价资产主键',
  `requested_payment_asset_id` BIGINT UNSIGNED NULL COMMENT '商户指定的付款资产主键',
  `settlement_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '商户结算资产主键',
  `quote_id` BIGINT UNSIGNED NULL COMMENT '报价记录主键',
  `merchant_order_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '商户订单号',
  `order_amount` DECIMAL(36,0) NOT NULL COMMENT '订单标价金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '支付订单状态',
  `origin` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'API' COMMENT '建单方式（接口或人工）',
  `metadata` JSON NULL COMMENT '订单扩展信息',
  `expires_at` DATETIME(6) NOT NULL COMMENT '支付订单到期时间',
  `checkout_token_hash` BINARY(32) NULL COMMENT '当前收银台令牌摘要',
  `checkout_brand_snapshot` JSON NULL COMMENT '生成支付链接时的品牌配置',
  `checkout_issued_at` DATETIME(6) NULL COMMENT '当前支付链接生成时间',
  `checkout_expires_at` DATETIME(6) NULL COMMENT '当前支付链接到期时间',
  `checkout_revoked_at` DATETIME(6) NULL COMMENT '当前支付链接吊销时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_order_public` (`public_id`),
  UNIQUE KEY `uk_payment_checkout_token` (`checkout_token_hash`),
  UNIQUE KEY `uk_payment_merchant_order` (`merchant_account_id`, `merchant_order_no`),
  UNIQUE KEY `uk_payment_owner` (`id`, `merchant_account_id`),
  KEY `idx_payment_list` (`merchant_account_id`, `status`, `created_at`),
  KEY `idx_payment_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_payment_merchant_account` FOREIGN KEY (`merchant_account_id`, `merchant_id`) REFERENCES `merchant_account` (`id`, `merchant_id`),
  CONSTRAINT `fk_payment_merchant_user` FOREIGN KEY (`merchant_user_id`, `merchant_id`) REFERENCES `merchant_user` (`id`, `merchant_id`),
  CONSTRAINT `fk_payment_quote` FOREIGN KEY (`quote_id`, `merchant_account_id`) REFERENCES `quote` (`id`, `merchant_account_id`),
  CONSTRAINT `fk_payment_order_asset` FOREIGN KEY (`order_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_payment_pay_asset` FOREIGN KEY (`requested_payment_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_payment_settlement_asset` FOREIGN KEY (`settlement_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_payment_positive` CHECK (`order_amount` > 0),
  CONSTRAINT `ck_payment_origin` CHECK (`origin` IN ('API', 'MANUAL')),
  CONSTRAINT `ck_payment_checkout_link` CHECK (
    (`checkout_token_hash` IS NULL AND `checkout_brand_snapshot` IS NULL
      AND `checkout_issued_at` IS NULL AND `checkout_expires_at` IS NULL AND `checkout_revoked_at` IS NULL)
    OR
    (`checkout_token_hash` IS NOT NULL AND `checkout_brand_snapshot` IS NOT NULL
      AND `checkout_issued_at` IS NOT NULL AND `checkout_expires_at` IS NOT NULL
      AND `checkout_issued_at` < `checkout_expires_at` AND `checkout_expires_at` <= `expires_at`
      AND (`checkout_revoked_at` IS NULL OR `checkout_revoked_at` >= `checkout_issued_at`))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户支付订单及固定结算资产';

CREATE TABLE IF NOT EXISTS `payment_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `payment_order_id` BIGINT UNSIGNED NOT NULL COMMENT '支付订单主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `capability_id` BIGINT UNSIGNED NOT NULL COMMENT '通道能力主键',
  `channel_binding_id` BIGINT UNSIGNED NOT NULL COMMENT '路由绑定主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '执行收款的上游账号主键',
  `payment_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '付款资产主键',
  `network_id` BIGINT UNSIGNED NULL COMMENT '资产网络主键',
  `expected_amount` DECIMAL(36,0) NOT NULL COMMENT '预计应付金额',
  `provider_request_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '提交上游的幂等请求号',
  `provider_order_id` VARCHAR(128) COLLATE utf8mb4_bin NULL COMMENT '上游支付订单号',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '上游支付尝试状态',
  `route_snapshot` JSON NOT NULL COMMENT '路由选择及配置快照',
  `instruction_ref` VARCHAR(255) NULL COMMENT '支付指令存储引用',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pay_attempt_request` (`provider_account_id`, `provider_request_no`),
  UNIQUE KEY `uk_pay_attempt_external` (`provider_account_id`, `provider_order_id`),
  UNIQUE KEY `uk_pay_attempt_scope` (`id`, `merchant_account_id`, `provider_account_id`),
  KEY `idx_pay_attempt_payment` (`payment_order_id`, `status`),
  CONSTRAINT `fk_pay_attempt_payment` FOREIGN KEY (`payment_order_id`, `merchant_account_id`) REFERENCES `payment_order` (`id`, `merchant_account_id`),
  CONSTRAINT `fk_pay_attempt_binding` FOREIGN KEY (`channel_binding_id`, `merchant_account_id`, `capability_id`) REFERENCES `channel_binding` (`id`, `merchant_account_id`, `capability_id`),
  CONSTRAINT `fk_pay_attempt_cap` FOREIGN KEY (`capability_id`, `provider_account_id`) REFERENCES `channel_capability` (`id`, `provider_account_id`),
  CONSTRAINT `fk_pay_attempt_asset` FOREIGN KEY (`payment_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_pay_attempt_network` FOREIGN KEY (`network_id`, `payment_asset_id`) REFERENCES `asset_network` (`id`, `asset_id`),
  CONSTRAINT `ck_pay_attempt_positive` CHECK (`expected_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='渠道选择与收款指令快照';

CREATE TABLE IF NOT EXISTS `payment_receipt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `payment_attempt_id` BIGINT UNSIGNED NOT NULL COMMENT '支付尝试主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `received_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '实际到账资产主键',
  `network_id` BIGINT UNSIGNED NULL COMMENT '资产网络主键',
  `source_receipt_key` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL COMMENT '上游到账去重标识',
  `tx_hash` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '链上交易哈希',
  `tx_output_or_log_index` BIGINT UNSIGNED NULL COMMENT '链上输出序号或事件日志索引',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '实际到账金额',
  `fee` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '收款通道手续费',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROCESSING' COMMENT '到账确认状态',
  `confirmed_at` DATETIME(6) NULL COMMENT '到账确认时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_receipt_public` (`public_id`),
  UNIQUE KEY `uk_pay_receipt_external` (`provider_account_id`, `source_receipt_key`),
  UNIQUE KEY `uk_pay_receipt_scope` (`id`, `merchant_account_id`, `provider_account_id`),
  KEY `idx_pay_receipt_tx` (`network_id`, `tx_hash`, `tx_output_or_log_index`),
  CONSTRAINT `fk_pay_receipt_attempt` FOREIGN KEY (`payment_attempt_id`, `merchant_account_id`, `provider_account_id`) REFERENCES `payment_attempt` (`id`, `merchant_account_id`, `provider_account_id`),
  CONSTRAINT `fk_pay_receipt_asset` FOREIGN KEY (`received_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_pay_receipt_network` FOREIGN KEY (`network_id`, `received_asset_id`) REFERENCES `asset_network` (`id`, `asset_id`),
  CONSTRAINT `ck_pay_receipt_amount` CHECK (`amount` > 0 AND `fee` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='一笔上游真实到账和确认记录';

CREATE TABLE IF NOT EXISTS `conversion` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `payment_order_id` BIGINT UNSIGNED NOT NULL COMMENT '支付订单主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价记录主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `sell_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '换出资产主键',
  `buy_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '换入资产主键',
  `provider_request_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '提交上游的幂等请求号',
  `provider_trade_id` VARCHAR(128) COLLATE utf8mb4_bin NULL COMMENT '上游换汇成交单号',
  `sold_amount` DECIMAL(36,0) NULL COMMENT '实际换出金额',
  `bought_amount` DECIMAL(36,0) NULL COMMENT '实际换入金额',
  `fee` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '换汇手续费',
  `fee_asset_id` BIGINT UNSIGNED NOT NULL COMMENT '手续费资产主键',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '换汇执行状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conversion_public` (`public_id`),
  UNIQUE KEY `uk_conversion_request` (`provider_account_id`, `provider_request_no`),
  UNIQUE KEY `uk_conversion_external` (`provider_account_id`, `provider_trade_id`),
  KEY `idx_conversion_payment` (`payment_order_id`, `status`),
  CONSTRAINT `fk_conversion_payment` FOREIGN KEY (`payment_order_id`, `merchant_account_id`) REFERENCES `payment_order` (`id`, `merchant_account_id`),
  CONSTRAINT `fk_conversion_quote` FOREIGN KEY (`quote_id`, `merchant_account_id`) REFERENCES `quote` (`id`, `merchant_account_id`),
  CONSTRAINT `fk_conversion_provider` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_conversion_sell` FOREIGN KEY (`sell_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_conversion_buy` FOREIGN KEY (`buy_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_conversion_fee` FOREIGN KEY (`fee_asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_conversion_amounts` CHECK (`sell_asset_id` <> `buy_asset_id` AND `sold_amount` > 0 AND `bought_amount` > 0 AND `fee` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='实际执行的换汇操作';

CREATE TABLE IF NOT EXISTS `refund_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `payment_receipt_id` BIGINT UNSIGNED NOT NULL COMMENT '原支付到账记录主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `merchant_refund_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '商户退款单号',
  `provider_request_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '提交上游的幂等请求号',
  `provider_refund_id` VARCHAR(128) COLLATE utf8mb4_bin NULL COMMENT '上游退款单号',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '申请退款金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '退款处理状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_order_public` (`public_id`),
  UNIQUE KEY `uk_refund_merchant` (`merchant_account_id`, `merchant_refund_no`),
  UNIQUE KEY `uk_refund_provider_req` (`provider_account_id`, `provider_request_no`),
  UNIQUE KEY `uk_refund_provider_ref` (`provider_account_id`, `provider_refund_id`),
  CONSTRAINT `fk_refund_receipt` FOREIGN KEY (`payment_receipt_id`, `merchant_account_id`, `provider_account_id`) REFERENCES `payment_receipt` (`id`, `merchant_account_id`, `provider_account_id`),
  CONSTRAINT `fk_refund_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_refund_positive` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='原到账下的部分或全额退款';

CREATE TABLE IF NOT EXISTS `dispute_case` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `payment_receipt_id` BIGINT UNSIGNED NOT NULL COMMENT '原支付到账记录主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `external_dispute_id` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '上游争议单号',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '争议涉及金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'OPEN' COMMENT '争议处理状态',
  `evidence_ref` VARCHAR(255) NULL COMMENT '证明材料存储引用',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dispute_case_public` (`public_id`),
  UNIQUE KEY `uk_dispute_provider` (`provider_account_id`, `external_dispute_id`),
  CONSTRAINT `fk_dispute_receipt` FOREIGN KEY (`payment_receipt_id`, `merchant_account_id`, `provider_account_id`) REFERENCES `payment_receipt` (`id`, `merchant_account_id`, `provider_account_id`),
  CONSTRAINT `fk_dispute_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_dispute_positive` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='渠道争议与拒付事实';

-- 四、复式账本、结算与出款

CREATE TABLE IF NOT EXISTS `ledger_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `scope_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '科目归属（商户、上游或平台）',
  `scope_id` BIGINT UNSIGNED NOT NULL COMMENT '科目归属对象主键',
  `account_code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '科目编码',
  `balance` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '科目账面余额',
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '余额并发控制版本',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ledger_account_scope` (`scope_type`, `scope_id`, `asset_id`, `account_code`),
  UNIQUE KEY `uk_ledger_account_asset` (`id`, `asset_id`),
  CONSTRAINT `fk_ledger_account_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_ledger_account_scope` CHECK (`scope_type` IN ('MERCHANT', 'PROVIDER', 'PLATFORM'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='按归属和资产划分的虚拟账本科目';

CREATE TABLE IF NOT EXISTS `journal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '凭证记账资产主键',
  `reversed_journal_id` BIGINT UNSIGNED NULL COMMENT '被冲正的凭证主键',
  `event_key` VARCHAR(160) COLLATE utf8mb4_bin NOT NULL COMMENT '记账事件幂等键',
  `business_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '关联业务类型',
  `business_id` BIGINT UNSIGNED NOT NULL COMMENT '业务记录主键',
  `posted_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '记账时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_journal_event` (`event_key`, `asset_id`),
  UNIQUE KEY `uk_journal_asset` (`id`, `asset_id`),
  KEY `idx_journal_business` (`business_type`, `business_id`),
  CONSTRAINT `fk_journal_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_journal_reversed` FOREIGN KEY (`reversed_journal_id`, `asset_id`) REFERENCES `journal` (`id`, `asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='单资产记账凭证及冲正引用';

CREATE TABLE IF NOT EXISTS `journal_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `journal_id` BIGINT UNSIGNED NOT NULL COMMENT '记账凭证主键',
  `ledger_account_id` BIGINT UNSIGNED NOT NULL COMMENT '账本科目主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '分录记账资产主键',
  `line_no` SMALLINT UNSIGNED NOT NULL COMMENT '凭证分录行号',
  `direction` CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '借贷方向（D借，C贷）',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '分录借方或贷方金额',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_journal_entry_line` (`journal_id`, `line_no`),
  UNIQUE KEY `uk_journal_entry_line_asset` (`journal_id`, `line_no`, `asset_id`),
  KEY `idx_journal_entry_account` (`ledger_account_id`, `id`),
  CONSTRAINT `fk_journal_entry_journal` FOREIGN KEY (`journal_id`, `asset_id`) REFERENCES `journal` (`id`, `asset_id`),
  CONSTRAINT `fk_journal_entry_account` FOREIGN KEY (`ledger_account_id`, `asset_id`) REFERENCES `ledger_account` (`id`, `asset_id`),
  CONSTRAINT `ck_journal_entry_value` CHECK (`line_no` > 0 AND `amount` > 0 AND `direction` IN ('D', 'C'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='只追加的借贷分录';

CREATE TABLE IF NOT EXISTS `settlement_batch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `batch_no` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL COMMENT '结算批次号',
  `window_start` DATETIME(6) NOT NULL COMMENT '结算周期开始时间',
  `window_end` DATETIME(6) NOT NULL COMMENT '结算周期结束时间',
  `gross` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '结算总额',
  `fee` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '手续费金额',
  `reserve` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '冻结的准备金金额',
  `net` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '净结算金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '结算批次状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settlement_batch_public` (`public_id`),
  UNIQUE KEY `uk_settlement_batch_no` (`merchant_account_id`, `batch_no`),
  UNIQUE KEY `uk_settlement_batch_scope` (`id`, `merchant_account_id`, `asset_id`),
  KEY `idx_settlement_batch_status` (`merchant_account_id`, `asset_id`, `status`),
  CONSTRAINT `fk_settlement_batch_merchant` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`),
  CONSTRAINT `fk_settlement_batch_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_settlement_batch_window` CHECK (`window_start` < `window_end`),
  CONSTRAINT `ck_settlement_batch_charges` CHECK (`fee` >= 0 AND `reserve` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户子账号按结算周期和资产生成的结算单';

CREATE TABLE IF NOT EXISTS `settlement_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `settlement_batch_id` BIGINT UNSIGNED NOT NULL COMMENT '结算批次主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `source_journal_id` BIGINT UNSIGNED NOT NULL COMMENT '结算来源凭证主键',
  `source_line_no` SMALLINT UNSIGNED NOT NULL COMMENT '结算来源分录行号',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `net` DECIMAL(36,0) NOT NULL COMMENT '净结算金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '结算明细状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settlement_item_source` (`source_journal_id`, `source_line_no`),
  UNIQUE KEY `uk_settlement_item_scope` (`id`, `merchant_account_id`, `asset_id`, `provider_account_id`),
  KEY `idx_settlement_item_batch` (`settlement_batch_id`, `status`),
  CONSTRAINT `fk_settlement_item_batch` FOREIGN KEY (`settlement_batch_id`, `merchant_account_id`, `asset_id`) REFERENCES `settlement_batch` (`id`, `merchant_account_id`, `asset_id`),
  CONSTRAINT `fk_settlement_item_source` FOREIGN KEY (`source_journal_id`, `source_line_no`, `asset_id`) REFERENCES `journal_entry` (`journal_id`, `line_no`, `asset_id`),
  CONSTRAINT `fk_settlement_item_provider` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='关联结算批次和原始分录行的结算明细';

CREATE TABLE IF NOT EXISTS `payout_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `beneficiary_id` BIGINT UNSIGNED NOT NULL COMMENT '受益账户主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `network_id` BIGINT UNSIGNED NULL COMMENT '资产网络主键',
  `freeze_journal_id` BIGINT UNSIGNED NULL COMMENT '余额冻结凭证主键',
  `payout_no` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL COMMENT '商户出款单号',
  `type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '出款类型（商户提现或代付）',
  `requested_amount` DECIMAL(36,0) NOT NULL COMMENT '申请出款金额',
  `fee` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '预计出款手续费',
  `fee_mode` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '手续费承担方式（内扣或外收）',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '出款申请状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payout_order_public` (`public_id`),
  UNIQUE KEY `uk_payout_order_no` (`merchant_account_id`, `payout_no`),
  UNIQUE KEY `uk_payout_order_scope` (`id`, `merchant_account_id`, `asset_id`),
  KEY `idx_payout_status_updated` (`status`, `updated_at`),
  CONSTRAINT `fk_payout_beneficiary` FOREIGN KEY (`beneficiary_id`, `merchant_account_id`, `asset_id`) REFERENCES `beneficiary` (`id`, `merchant_account_id`, `asset_id`),
  CONSTRAINT `fk_payout_network` FOREIGN KEY (`network_id`, `asset_id`) REFERENCES `asset_network` (`id`, `asset_id`),
  CONSTRAINT `fk_payout_freeze` FOREIGN KEY (`freeze_journal_id`, `asset_id`) REFERENCES `journal` (`id`, `asset_id`),
  CONSTRAINT `ck_payout_amounts` CHECK (`requested_amount` > 0 AND `fee` >= 0),
  CONSTRAINT `ck_payout_type` CHECK (`type` IN ('WITHDRAWAL', 'DISBURSEMENT')),
  CONSTRAINT `ck_payout_fee_mode` CHECK (`fee_mode` IN ('DEDUCT', 'ADD_ON'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户提现或获授权的代付申请';

CREATE TABLE IF NOT EXISTS `payout_funding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `payout_id` BIGINT UNSIGNED NOT NULL COMMENT '出款申请主键',
  `settlement_item_id` BIGINT UNSIGNED NOT NULL COMMENT '结算明细主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '已预留的资金来源金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'RESERVED' COMMENT '出款资金预留状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payout_funding_source` (`payout_id`, `settlement_item_id`),
  KEY `idx_payout_funding_item` (`settlement_item_id`, `status`),
  CONSTRAINT `fk_payout_funding_order` FOREIGN KEY (`payout_id`, `merchant_account_id`, `asset_id`) REFERENCES `payout_order` (`id`, `merchant_account_id`, `asset_id`),
  CONSTRAINT `fk_payout_funding_item` FOREIGN KEY (`settlement_item_id`, `merchant_account_id`, `asset_id`, `provider_account_id`) REFERENCES `settlement_item` (`id`, `merchant_account_id`, `asset_id`, `provider_account_id`),
  CONSTRAINT `ck_payout_funding_positive` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='将出款金额分配到实际商户结算明细和上游资金位置';

CREATE TABLE IF NOT EXISTS `payout_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `payout_id` BIGINT UNSIGNED NOT NULL COMMENT '出款申请主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `provider_request_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '提交上游的幂等请求号',
  `provider_payout_id` VARCHAR(128) COLLATE utf8mb4_bin NULL COMMENT '上游出款单号',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '本次上游出款金额',
  `actual_fee` DECIMAL(36,0) NULL COMMENT '实际出款手续费',
  `execution_ref` VARCHAR(160) COLLATE utf8mb4_bin NULL COMMENT '银行参考号或链上交易哈希',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '上游出款尝试状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payout_attempt_request` (`provider_account_id`, `provider_request_no`),
  UNIQUE KEY `uk_payout_attempt_external` (`provider_account_id`, `provider_payout_id`),
  KEY `idx_payout_attempt_order` (`payout_id`, `status`),
  CONSTRAINT `fk_payout_attempt_order` FOREIGN KEY (`payout_id`) REFERENCES `payout_order` (`id`),
  CONSTRAINT `fk_payout_attempt_provider` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `ck_payout_attempt_amount` CHECK (`amount` > 0 AND `actual_fee` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='真实上游出款指令及结果';

CREATE TABLE IF NOT EXISTS `transfer_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `from_provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '转出上游账号主键',
  `to_provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '转入上游账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `provider_request_no` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '提交上游的幂等请求号',
  `provider_transfer_id` VARCHAR(128) COLLATE utf8mb4_bin NULL COMMENT '上游划转单号',
  `amount` DECIMAL(36,0) NOT NULL COMMENT '申请划转金额',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED' COMMENT '划转处理状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transfer_request` (`from_provider_account_id`, `provider_request_no`),
  CONSTRAINT `fk_transfer_from` FOREIGN KEY (`from_provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_transfer_to` FOREIGN KEY (`to_provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_transfer_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `ck_transfer_value` CHECK (`amount` > 0 AND `from_provider_account_id` <> `to_provider_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='上游主账号与子账号间划拨指令';

-- 五、幂等、事件、调度任务、审批与对账

CREATE TABLE IF NOT EXISTS `api_idempotency` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `operation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'API 操作标识',
  `idem_key` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL COMMENT '请求幂等键',
  `request_hash` BINARY(32) NOT NULL COMMENT '请求内容指纹',
  `resource_id` BIGINT UNSIGNED NULL COMMENT '幂等请求关联的业务主键',
  `response_ref` VARCHAR(255) NULL COMMENT '幂等请求响应存储引用',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROCESSING' COMMENT '幂等请求处理状态',
  `expires_at` DATETIME(6) NOT NULL COMMENT '幂等记录到期时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_idempotency_scope` (`merchant_account_id`, `operation`, `idem_key`),
  KEY `idx_api_idem_expiry` (`expires_at`),
  CONSTRAINT `fk_api_idem_merchant` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户 API 写请求幂等与重复提交校验';

CREATE TABLE IF NOT EXISTS `provider_event_inbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `dedup_key` VARCHAR(180) COLLATE utf8mb4_bin NOT NULL COMMENT '上游回调去重键',
  `event_type` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `payload` JSON NOT NULL COMMENT '上游回调事件数据',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '上游事件处理状态',
  `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '回调接收时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inbox_dedup` (`provider_account_id`, `dedup_key`),
  KEY `idx_inbox_status` (`status`, `received_at`),
  CONSTRAINT `fk_inbox_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='已验签的上游回调接收记录';

CREATE TABLE IF NOT EXISTS `merchant_event_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `merchant_account_id` BIGINT UNSIGNED NOT NULL COMMENT '商户账号主键',
  `endpoint_id` BIGINT UNSIGNED NOT NULL COMMENT '商户回调地址主键',
  `event_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '商户事件编号',
  `event_type` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `aggregate_id` BIGINT UNSIGNED NOT NULL COMMENT '关联业务对象主键',
  `event_version` INT UNSIGNED NOT NULL COMMENT '事件版本号',
  `payload` JSON NOT NULL COMMENT '投递给商户的事件数据',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '商户事件投递状态',
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已尝试次数',
  `next_attempt_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '下次投递时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_outbox_delivery` (`event_id`, `endpoint_id`),
  KEY `idx_outbox_next_attempt` (`status`, `next_attempt_at`, `id`),
  KEY `idx_outbox_account_event` (`merchant_account_id`, `event_id`),
  CONSTRAINT `fk_outbox_endpoint` FOREIGN KEY (`endpoint_id`, `merchant_account_id`) REFERENCES `merchant_webhook_endpoint` (`id`, `merchant_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='按收件地址投递的商户出站事件';

CREATE TABLE IF NOT EXISTS `async_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `job_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '异步任务类型',
  `job_key` VARCHAR(180) COLLATE utf8mb4_bin NOT NULL COMMENT '任务幂等键',
  `payload` JSON NOT NULL COMMENT '异步任务执行参数',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '异步任务状态',
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已尝试次数',
  `next_run_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '下次执行时间',
  `lease_owner` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '当前任务领取者',
  `lease_until` DATETIME(6) NULL COMMENT '任务租约到期时间',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_async_job_type_key` (`job_type`, `job_key`),
  KEY `idx_async_job_next` (`status`, `next_run_at`, `lease_until`),
  CONSTRAINT `ck_async_job_lease_pair` CHECK ((`lease_owner` IS NULL) = (`lease_until` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='可恢复的查单、账单、对账及结算任务';

CREATE TABLE IF NOT EXISTS `provider_statement_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `bill_date` DATE NOT NULL COMMENT '渠道账单日期',
  `external_entry_key` VARCHAR(180) COLLATE utf8mb4_bin NOT NULL COMMENT '上游账单流水标识',
  `net_amount` DECIMAL(36,0) NOT NULL COMMENT '上游流水净额',
  `booked_at` DATETIME(6) NOT NULL COMMENT '渠道入账时间',
  `evidence_ref` VARCHAR(255) NULL COMMENT '上游账单原文存储引用',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_statement_external` (`provider_account_id`, `external_entry_key`),
  UNIQUE KEY `uk_statement_scope` (`id`, `provider_account_id`, `asset_id`),
  KEY `idx_statement_billing` (`provider_account_id`, `asset_id`, `bill_date`),
  CONSTRAINT `fk_statement_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_statement_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='归档的上游真实账单流水';

CREATE TABLE IF NOT EXISTS `recon_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `provider_account_id` BIGINT UNSIGNED NOT NULL COMMENT '上游账号主键',
  `statement_item_id` BIGINT UNSIGNED NULL COMMENT '上游账单流水主键',
  `journal_id` BIGINT UNSIGNED NULL COMMENT '记账凭证主键',
  `asset_id` BIGINT UNSIGNED NOT NULL COMMENT '资产主键',
  `bill_date` DATE NOT NULL COMMENT '渠道账单日期',
  `difference` DECIMAL(36,0) NOT NULL DEFAULT 0 COMMENT '对账差额',
  `reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对账差异原因编码',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'OPEN' COMMENT '对账差异处理状态',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_recon_worklist` (`provider_account_id`, `bill_date`, `status`),
  CONSTRAINT `fk_recon_provider` FOREIGN KEY (`provider_account_id`) REFERENCES `provider_account` (`id`),
  CONSTRAINT `fk_recon_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset` (`id`),
  CONSTRAINT `fk_recon_statement` FOREIGN KEY (`statement_item_id`, `provider_account_id`, `asset_id`) REFERENCES `provider_statement_item` (`id`, `provider_account_id`, `asset_id`),
  CONSTRAINT `fk_recon_journal` FOREIGN KEY (`journal_id`, `asset_id`) REFERENCES `journal` (`id`, `asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='账单与内部账本核对及差异处理';

CREATE TABLE IF NOT EXISTS `approval_case` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外资源编号',
  `merchant_account_id` BIGINT UNSIGNED NULL COMMENT '商户账号主键',
  `business_id` BIGINT UNSIGNED NOT NULL COMMENT '业务记录主键',
  `business_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '关联业务类型',
  `initiator_id` BIGINT UNSIGNED NOT NULL COMMENT '审批发起人主键',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING' COMMENT '人工审批状态',
  `decision_log` JSON NULL COMMENT '审批记录',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_approval_case_public` (`public_id`),
  KEY `idx_approval_business` (`business_type`, `business_id`, `status`),
  KEY `idx_approval_merchant` (`merchant_account_id`, `status`),
  CONSTRAINT `fk_approval_merchant` FOREIGN KEY (`merchant_account_id`) REFERENCES `merchant_account` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='大额订单、提现和资金调整审批';

-- 六、现有 Bot 的调度配置

CREATE TABLE IF NOT EXISTS `bot_schedule_config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '任务 ID',
  `module` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模块名',
  `task_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务名',
  `interval_seconds` float NOT NULL DEFAULT '60' COMMENT '执行间隔（秒）',
  `exe_sort` int DEFAULT NULL COMMENT '同模块内执行顺序',
  `concurrency` int DEFAULT NULL COMMENT '并发度',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '说明',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `is_strategy_enabled` tinyint(1) DEFAULT '1' COMMENT '策略是否启用',
  `last_live_time` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '上次存活时间',
  `last_live_time_by_machine` json DEFAULT NULL COMMENT '各机器上次存活',
  `is_primary_machine_run` int NOT NULL DEFAULT '0' COMMENT '是否主机器执行',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_task` (`module`,`task_name`),
  KEY `idx_module` (`module`),
  KEY `idx_bot_schedule_module_enabled` (`module`,`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bot 脚本调度';

-- 七、下游商户 OpenAPI

CREATE TABLE IF NOT EXISTS `openapi_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `api_version` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外 API 版本',
  `operation_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '接口操作编号',
  `http_method` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'HTTP 请求方法',
  `path_template` VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '对外接口路径模板',
  `required_scope` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '调用所需的权限范围',
  `idempotency_required` TINYINT UNSIGNED NOT NULL COMMENT '是否要求写请求幂等键',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DRAFT' COMMENT '接口开通状态',
  `description` VARCHAR(200) NOT NULL COMMENT '接口用途',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '创建时间',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openapi_operation_code` (`api_version`, `operation_code`),
  UNIQUE KEY `uk_openapi_operation_route` (`api_version`, `http_method`, `path_template`),
  CONSTRAINT `ck_openapi_idempotency_required` CHECK (`idempotency_required` IN (0, 1)),
  CONSTRAINT `ck_openapi_operation_status` CHECK (`status` IN ('DRAFT', 'ACTIVE', 'DISABLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='下游 OpenAPI 接口目录和授权要求';

CREATE TABLE IF NOT EXISTS `openapi_request_nonce` (
  `api_key_id` BIGINT UNSIGNED NOT NULL COMMENT '商户 API 凭据主键',
  `nonce_hash` BINARY(32) NOT NULL COMMENT '已核验随机数的摘要',
  `accepted_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '首次验签通过时间',
  `expires_at` DATETIME(6) NOT NULL COMMENT '防重放记录到期时间',
  PRIMARY KEY (`api_key_id`, `nonce_hash`),
  KEY `idx_openapi_nonce_expiry` (`expires_at`),
  CONSTRAINT `fk_openapi_nonce_key` FOREIGN KEY (`api_key_id`) REFERENCES `merchant_api_key` (`id`),
  CONSTRAINT `ck_openapi_nonce_expiry` CHECK (`expires_at` > `accepted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户 API 请求签名防重放';

CREATE TABLE IF NOT EXISTS `openapi_request_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `request_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '平台请求追踪编号',
  `api_key_id` BIGINT UNSIGNED NULL COMMENT '已验签的商户 API 凭据主键',
  `operation_id` BIGINT UNSIGNED NULL COMMENT '匹配的接口目录主键',
  `http_status` SMALLINT UNSIGNED NOT NULL COMMENT '返回给商户的 HTTP 状态码',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '返回给商户的业务错误码',
  `duration_ms` INT UNSIGNED NOT NULL COMMENT '请求处理耗时（毫秒）',
  `received_at` DATETIME(6) NOT NULL COMMENT '请求接收时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openapi_request_id` (`request_id`),
  KEY `idx_openapi_request_key_time` (`api_key_id`, `received_at`),
  KEY `idx_openapi_request_received` (`received_at`),
  CONSTRAINT `fk_openapi_request_key` FOREIGN KEY (`api_key_id`) REFERENCES `merchant_api_key` (`id`),
  CONSTRAINT `fk_openapi_request_operation` FOREIGN KEY (`operation_id`) REFERENCES `openapi_operation` (`id`),
  CONSTRAINT `ck_openapi_request_http_status` CHECK (`http_status` BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='脱敏的商户 API 请求审计';

CREATE TABLE IF NOT EXISTS `merchant_webhook_delivery_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `outbox_id` BIGINT UNSIGNED NOT NULL COMMENT '商户出站事件主键',
  `attempt_no` INT UNSIGNED NOT NULL COMMENT '该事件的投递序号',
  `attempted_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '发起投递时间',
  `finished_at` DATETIME(6) NULL COMMENT '投递结束时间',
  `http_status` SMALLINT UNSIGNED NULL COMMENT '商户响应的 HTTP 状态码',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '投递失败原因编码',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IN_PROGRESS' COMMENT '本次投递状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_webhook_delivery_attempt` (`outbox_id`, `attempt_no`),
  KEY `idx_webhook_delivery_attempted` (`attempted_at`),
  CONSTRAINT `fk_webhook_delivery_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `merchant_event_outbox` (`id`),
  CONSTRAINT `ck_webhook_delivery_attempt_no` CHECK (`attempt_no` > 0),
  CONSTRAINT `ck_webhook_delivery_http_status` CHECK (`http_status` IS NULL OR `http_status` BETWEEN 100 AND 599),
  CONSTRAINT `ck_webhook_delivery_time` CHECK (`finished_at` IS NULL OR `finished_at` >= `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='商户 Webhook 逐次投递记录';

-- 八、基础字典数据

INSERT INTO `asset` (`code`, `kind`, `decimals`) VALUES
  ('BTC', 'CRYPTO', 8),
  ('USD', 'FIAT', 2)
ON DUPLICATE KEY UPDATE `id` = `asset`.`id`;

INSERT INTO `asset_network` (`asset_id`, `network_code`, `status`)
SELECT `id`, 'BITCOIN', 'INACTIVE'
FROM `asset`
WHERE `code` = 'BTC'
ON DUPLICATE KEY UPDATE `id` = `asset_network`.`id`;

INSERT INTO `provider` (`code`, `name`, `status`)
VALUES ('GATE_PAY', 'Gate Pay', 'INACTIVE')
ON DUPLICATE KEY UPDATE `id` = `provider`.`id`;

-- 现有占位脚本默认停用，已存在的调度配置保持原状态。
INSERT INTO `bot_schedule_config`
  (`module`, `task_name`, `interval_seconds`, `description`, `is_enabled`, `is_strategy_enabled`)
VALUES
  ('ai_agent_paper', 'vip_level_sync', 60, '现有 VIP 同步脚本', 0, 1)
ON DUPLICATE KEY UPDATE `id` = `bot_schedule_config`.`id`;

-- 对外接口目录用于开发与验收，通过后按环境开通。
INSERT INTO `openapi_operation`
  (`api_version`, `operation_code`, `http_method`, `path_template`, `required_scope`, `idempotency_required`, `description`)
VALUES
  ('v1', 'merchant.onboard', 'POST', '/openapi/v1/merchants/onboardings', 'merchants:write', 1, '授权合作方提交下游商户进件'),
  ('v1', 'merchant.account.create', 'POST', '/openapi/v1/merchants/{merchant_id}/accounts', 'accounts:write', 1, '创建获授权商户的子账号'),
  ('v1', 'kyc.session.create', 'POST', '/openapi/v1/users/kyc-sessions', 'kyc:write', 1, '为商户最终用户发起实名核验'),
  ('v1', 'kyc.session.get', 'GET', '/openapi/v1/users/kyc-sessions/{id}', 'kyc:read', 0, '查询实名核验状态'),
  ('v1', 'payment_method.list', 'GET', '/openapi/v1/payment-methods', 'payments:read', 0, '查本账号可用支付方式'),
  ('v1', 'quote.create', 'POST', '/openapi/v1/quotes', 'quotes:write', 1, '获取应付款、结算资产和到期时间'),
  ('v1', 'payment.create', 'POST', '/openapi/v1/payment-orders', 'payments:write', 1, '建单并返回托管收银台链接'),
  ('v1', 'payment.get', 'GET', '/openapi/v1/payment-orders/{id}', 'payments:read', 0, '查询订单、付款及结算状态'),
  ('v1', 'refund.create', 'POST', '/openapi/v1/payment-orders/{id}/refunds', 'refunds:write', 1, '发起原单退款'),
  ('v1', 'refund.get', 'GET', '/openapi/v1/refunds/{id}', 'refunds:read', 0, '查询退款执行状态'),
  ('v1', 'balance.list', 'GET', '/openapi/v1/balances', 'balances:read', 0, '查询可用、冻结和在途余额'),
  ('v1', 'settlement.list', 'GET', '/openapi/v1/settlements', 'settlements:read', 0, '查询结算单'),
  ('v1', 'statement.list', 'GET', '/openapi/v1/statements', 'statements:read', 0, '查询商户对账单'),
  ('v1', 'payout.withdraw', 'POST', '/openapi/v1/payouts', 'payouts:write', 1, '申请向审核通过的商户受益账户提现'),
  ('v1', 'payout.get', 'GET', '/openapi/v1/payouts/{id}', 'payouts:read', 0, '查询出款执行状态')
ON DUPLICATE KEY UPDATE `id` = `openapi_operation`.`id`;
