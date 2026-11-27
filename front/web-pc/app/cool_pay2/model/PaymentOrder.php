<?php
declare (strict_types = 1);

namespace app\cool_pay2\model;

use think\admin\Model;

/**
 * 支付订单模型
 */
class PaymentOrder extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'payment_order';

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
} 
