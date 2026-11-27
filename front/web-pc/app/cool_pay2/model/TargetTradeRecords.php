<?php
declare (strict_types = 1);

namespace app\cool_pay2\model;

use think\admin\Model;

/**
 * 账号管理模型
 */
class TargetTradeRecords extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'target_trade_records';

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
        'trade_price' => 'decimal:8',
        'trade_quantity' => 'decimal:8',
        'trade_amount' => 'decimal:8'
    ];
}
