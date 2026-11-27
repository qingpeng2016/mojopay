-- 周账单 PDF 已改存数据库，删除历史 pdf_relpath 字段（在 db_bitfinex 库执行）
ALTER TABLE `fund_client_weekly_statement`
    DROP COLUMN `pdf_relpath`;
