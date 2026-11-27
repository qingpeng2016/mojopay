<?php

return [
    // 发布密码从最后一次修改开始的有效期。
    'password_valid_days' => 7,

    // 同一后台账号 + 来源 IP 在窗口期内连续失败后锁定。
    'max_failed_attempts' => max(1, (int)(getenv('SO_HOTRELOAD_MAX_FAILED_ATTEMPTS') ?: 5)),
    'failure_window_minutes' => max(1, (int)(getenv('SO_HOTRELOAD_FAILURE_WINDOW_MINUTES') ?: 15)),
    'lock_minutes' => max(1, (int)(getenv('SO_HOTRELOAD_LOCK_MINUTES') ?: 30)),
    'min_password_length' => max(6, (int)(getenv('SO_HOTRELOAD_MIN_PASSWORD_LENGTH') ?: 6)),

    // 首次上线时使用密码哈希初始化数据库，不在代码或配置中保存明文。
    // 密码哈希生成方式见部署文档。
    // 将命令输出完整粘贴到下面。数据库初始化完成后，本配置值不会覆盖数据库中的密码。
    'initial_password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$RGRqMVBqcDRZbmNnWU94NQ$RoBdZCO7XDOJXt+LMvx1xwNlAmi/+em3x8/15ljarlo',
];
