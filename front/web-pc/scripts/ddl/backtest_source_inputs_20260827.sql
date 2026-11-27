-- Backtest source input fields.
-- Execute once after confirming these columns do not already exist.
-- Database: fgmm_f_backtest
--
-- Defaults:
--   pricing source:    BINANCE / SPOT
--   settlement source: CHAINLINK / TWAP

ALTER TABLE `fgmm_f_backtest`.`f_market_config`
    ADD COLUMN `pricing_source_platform` varchar(32) NOT NULL DEFAULT 'BINANCE' COMMENT 'pricing source platform: BINANCE/OKX/CHAINLINK' AFTER `in_q_cap`,
    ADD COLUMN `pricing_source_type` varchar(32) NOT NULL DEFAULT 'SPOT' COMMENT 'pricing source type: SPOT/TWAP' AFTER `pricing_source_platform`,
    ADD COLUMN `settlement_source_platform` varchar(32) NOT NULL DEFAULT 'CHAINLINK' COMMENT 'settlement source platform: BINANCE/OKX/CHAINLINK' AFTER `pricing_source_type`,
    ADD COLUMN `settlement_source_type` varchar(32) NOT NULL DEFAULT 'TWAP' COMMENT 'settlement source type: SPOT/TWAP' AFTER `settlement_source_platform`;

ALTER TABLE `fgmm_f_backtest`.`f_market`
    ADD COLUMN `in_ds_s` decimal(28,8) NOT NULL DEFAULT 0 COMMENT 'input ds_s: latest settlement-source quote' AFTER `in_l`,
    ADD COLUMN `in_pricing_source_platform` varchar(32) NOT NULL DEFAULT 'BINANCE' COMMENT 'input pricing_source_platform' AFTER `in_ds_s`,
    ADD COLUMN `in_pricing_source_type` varchar(32) NOT NULL DEFAULT 'SPOT' COMMENT 'input pricing_source_type' AFTER `in_pricing_source_platform`,
    ADD COLUMN `in_settlement_source_platform` varchar(32) NOT NULL DEFAULT 'CHAINLINK' COMMENT 'input settlement_source_platform' AFTER `in_pricing_source_type`,
    ADD COLUMN `in_settlement_source_type` varchar(32) NOT NULL DEFAULT 'TWAP' COMMENT 'input settlement_source_type' AFTER `in_settlement_source_platform`;

ALTER TABLE `fgmm_f_backtest`.`f_placement`
    ADD COLUMN `in_ds_s` decimal(28,8) NOT NULL DEFAULT 0 COMMENT 'input ds_s: latest settlement-source quote' AFTER `in_l`,
    ADD COLUMN `in_pricing_source_platform` varchar(32) NOT NULL DEFAULT 'BINANCE' COMMENT 'input pricing_source_platform' AFTER `in_ds_s`,
    ADD COLUMN `in_pricing_source_type` varchar(32) NOT NULL DEFAULT 'SPOT' COMMENT 'input pricing_source_type' AFTER `in_pricing_source_platform`,
    ADD COLUMN `in_settlement_source_platform` varchar(32) NOT NULL DEFAULT 'CHAINLINK' COMMENT 'input settlement_source_platform' AFTER `in_pricing_source_type`,
    ADD COLUMN `in_settlement_source_type` varchar(32) NOT NULL DEFAULT 'TWAP' COMMENT 'input settlement_source_type' AFTER `in_settlement_source_platform`;

ALTER TABLE `fgmm_f_backtest`.`f_hedge`
    ADD COLUMN `in_ds_s` decimal(28,8) NOT NULL DEFAULT 0 COMMENT 'input ds_s: latest settlement-source quote' AFTER `in_s`,
    ADD COLUMN `in_pricing_source_platform` varchar(32) NOT NULL DEFAULT 'BINANCE' COMMENT 'input pricing_source_platform' AFTER `in_ds_s`,
    ADD COLUMN `in_pricing_source_type` varchar(32) NOT NULL DEFAULT 'SPOT' COMMENT 'input pricing_source_type' AFTER `in_pricing_source_platform`,
    ADD COLUMN `in_settlement_source_platform` varchar(32) NOT NULL DEFAULT 'CHAINLINK' COMMENT 'input settlement_source_platform' AFTER `in_pricing_source_type`,
    ADD COLUMN `in_settlement_source_type` varchar(32) NOT NULL DEFAULT 'TWAP' COMMENT 'input settlement_source_type' AFTER `in_settlement_source_platform`;
