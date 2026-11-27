<?php

// 应用服务注册1
// Docker 构建用 --no-plugins --no-scripts，think-install 不执行，vendor/services.php 不会生成。
// 因此必须在这里显式列出所有 composer 包通过 extra.think.services 声明的服务。
// 其中最关键的是 think\app\Service：它是 topthink/think-multi-app 的服务，缺失后多应用模式
// 失效，请求会全部落进默认的 index 模块（URL 首段被当成控制器名），
// 表现为「控制器不存在:app\index\controller\Login」。
// 新增 composer 依赖后需同步检查这里，构建期由 scripts/verify_composer_build.php 校验完整性。
return [
    \think\migration\Service::class,
    \think\app\Service::class,
    \think\admin\Library::class,
    \app\admin\Service::class,
    \app\wechat\Service::class,
    \app\cool_pay\Service::class,
    \app\cool_pay2\Service::class,
    \app\rmpd\Service::class,
    \app\bitfinex\Service::class,
    \app\scarb\Service::class,
    \app\data\Service::class,
];
