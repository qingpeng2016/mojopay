<?php
declare (strict_types = 1);

namespace app\cool_pay\model;

use think\admin\Model;

/**
 * 账号管理模型
 */
class Accounts extends Model
{
    /**
     * 数据表名称
     * @var string
     */
    protected $table = 'accounts';

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
        'balance' => 'decimal:8',
        'available_balance' => 'decimal:8',
        'frozen_balance' => 'decimal:8',
        'status' => 'string'
    ];
}
