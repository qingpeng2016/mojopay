-- 上游商家（provider）接入配置字段；已存在空库可直接用 all.sql
ALTER TABLE `provider`
  MODIFY COLUMN `code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '上游商家编码',
  MODIFY COLUMN `name` VARCHAR(128) NOT NULL COMMENT '上游商家名称',
  ADD COLUMN `official_url` VARCHAR(512) NULL COMMENT '官网地址' AFTER `name`,
  ADD COLUMN `api_base_url_sandbox` VARCHAR(512) NULL COMMENT '沙盒 API 根地址' AFTER `official_url`,
  ADD COLUMN `api_base_url_production` VARCHAR(512) NULL COMMENT '生产 API 根地址' AFTER `api_base_url_sandbox`,
  ADD COLUMN `docs_url` VARCHAR(512) NULL COMMENT '开发者文档地址' AFTER `api_base_url_production`,
  ADD COLUMN `remark` VARCHAR(500) NULL COMMENT '运营备注' AFTER `docs_url`,
  MODIFY COLUMN `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INACTIVE' COMMENT '上游商家启用状态';