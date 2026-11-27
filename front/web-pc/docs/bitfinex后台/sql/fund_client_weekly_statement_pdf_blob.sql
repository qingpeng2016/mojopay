-- 周账单 PDF 改存数据库（在 db_bitfinex 库执行，可重复执行需自行判断列是否已存在）
ALTER TABLE `fund_client_weekly_statement`
    ADD COLUMN `pdf_content` longblob DEFAULT NULL COMMENT 'PDF 二进制' AFTER `pdf_filename`,
    ADD COLUMN `pdf_size` int unsigned NOT NULL DEFAULT 0 COMMENT 'PDF 字节数' AFTER `pdf_content`;
