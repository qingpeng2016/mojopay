<?php

// 应用服务注册
// Docker 构建用 --no-plugins --no-scripts 时须在 app/service.php 显式列出 think services。
return [
    \think\migration\Service::class,
    \think\app\Service::class,
    \think\admin\Library::class,
    \app\admin\Service::class,
    \app\mojopay\Service::class,
];