<?php
declare (strict_types = 1);

namespace app\cool_pay\model;

use think\admin\Model;

/**
 * 调仓配置模型
 */
class RebalanceConfig extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'rebalance_config';

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
        'status' => 'boolean'
    ];
}

