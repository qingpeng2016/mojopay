<?php
declare (strict_types = 1);

namespace app\cool_pay;

use think\Service as BaseService;
use app\cool_pay\command\ScheduledTasks;

/**
 * CoolPay应用服务
 */
class Service extends BaseService
{
    /**
     * 启动服务
     */
    public function boot()
    {
        // 注册定时任务命令
        $this->commands([
            ScheduledTasks::class,
        ]);
    }
}
