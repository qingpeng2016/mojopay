<?php
declare (strict_types = 1);

namespace app\cool_pay\model;

use think\admin\Model;

/**
 * 风控规则配置模型
 */
class RiskControlRules extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'risk_control_rules';

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
        'threshold_value' => 'decimal:8',
        'enabled' => 'boolean',
        'time_window' => 'integer'
    ];
}
