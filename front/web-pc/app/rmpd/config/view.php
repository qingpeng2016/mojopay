<?php
// RMPD 模块视图配置（仅覆盖 tpl_cache，其余键合并自全局 config/view.php）
//
// 背景：本地用 Docker 挂载 Windows 上的 app/ 目录做实时调试时，挂载文件的 mtime
// 与容器内不一致，ThinkPHP 的"模板源更新检测"会误判模板未变、继续用旧的编译产物，
// 导致改了模板却不生效（需手动清 runtime/*/temp）。
//
// 处理：local 环境强制关闭模板缓存（每次请求重新编译，始终最新）；
//      develop/prod 沿用全局的 isOnline() 策略，不影响线上性能。
return [
    'tpl_cache' => (getenv('APP_ENV') === 'local') ? false : isOnline(),
];
