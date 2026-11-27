<?php
declare (strict_types = 1);

namespace app\cool_pay2\model;

use think\admin\Model;

/**
 * 市场映射配置模型
 */
class MarketMappingConfig extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'market_mapping_config';

    /**
     * 创建时间字段
     * @var string
     */
    protected $createTime = 'created_at';

    /**
     * 更新时间字段
     * @var string
     */
    protected $updateTime = 'updated_at';

    /**
     * 自动类型转换
     * @var array
     */
    protected $type = [
        'source_account_id' => 'integer',
        'target_account_id' => 'integer'
    ];
}
