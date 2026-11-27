ALTER TABLE `channel_capability`
  ADD COLUMN `region_name` VARCHAR(64) NULL COMMENT '地区中文名称' AFTER `region`;

UPDATE `channel_capability` SET `region_name` = CASE `region`
  WHEN 'US' THEN '美国'
  WHEN 'DE' THEN '德国'
  WHEN 'GB' THEN '英国'
  WHEN 'JP' THEN '日本'
  WHEN 'CN' THEN '中国'
  WHEN 'HK' THEN '中国香港'
  WHEN 'SG' THEN '新加坡'
  WHEN 'AU' THEN '澳大利亚'
  WHEN 'CA' THEN '加拿大'
  WHEN 'CH' THEN '瑞士'
  WHEN 'KR' THEN '韩国'
  WHEN 'IN' THEN '印度'
  WHEN 'BR' THEN '巴西'
  WHEN 'MX' THEN '墨西哥'
  WHEN 'TH' THEN '泰国'
  WHEN 'MY' THEN '马来西亚'
  WHEN 'PH' THEN '菲律宾'
  WHEN 'ID' THEN '印度尼西亚'
  WHEN 'TW' THEN '中国台湾'
  WHEN 'AE' THEN '阿联酋'
  WHEN 'NZ' THEN '新西兰'
  WHEN 'VN' THEN '越南'
  ELSE `region_name`
END
WHERE `region_name` IS NULL OR `region_name` = '';