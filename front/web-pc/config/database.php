<?php

use Symfony\Component\Yaml\Yaml;

// 获取当前Git分支
function getCurrentBranch()
{
    $gitHead = @file_get_contents(dirname(__DIR__) . '/.git/HEAD');
    if ($gitHead && preg_match('/ref: refs\/heads\/(.*)/', $gitHead, $matches)) {
        return trim($matches[1]);
    }
    // 读取不到 .git/HEAD 时（docker 构建上下文与打包后的镜像都无 .git，
    // 因为 .dockerignore 排除了 .git），不再回退到依赖 gitignored 的
    // database-local.yaml（CI 已不再往构建上下文注入该文件，会导致构建失败）。
    // 此时构建/运行的就是生产镜像，直接回退到 prod，加载仓库内已提交的
    // database-prod.yaml（生产配置），符合“生产环境连生产配置”的预期。
    return 'prod';
}

// 环境名称规范化映射
function normalizeEnvironment($envName)
{
    $envMapping = [
        'main' => 'prod',
        'master' => 'prod',
        'production' => 'prod',
        'prod' => 'prod',
        'develop' => 'develop',
        'dev' => 'develop',
        'development' => 'develop',
        'local' => 'local',
    ];
    
    return $envMapping[strtolower($envName)] ?? 'local';
}

// 获取当前环境（根据分支名映射到环境）
function getCurrentEnvironment()
{
    $branch = getCurrentBranch();
    return normalizeEnvironment($branch);
}

// 从 YAML 文件读取数据库配置
// 优先使用环境变量 APP_ENV，其次使用 Git 分支判断
$envFromVar = getenv('APP_ENV');
$currentEnv = $envFromVar ? normalizeEnvironment($envFromVar) : getCurrentEnvironment();
$yamlFile = dirname(__DIR__) . "/conf/database-{$currentEnv}.yaml";
if (!file_exists($yamlFile)) {
    throw new \RuntimeException("Database configuration file not found: {$yamlFile}");
}
$connections = Yaml::parseFile($yamlFile);
if (!is_array($connections)) {
    $connections = [];
}

$initialConnections= $connections; // 保存初始配置用于调试日志

// ===== 读取外部 API 配置 =====
$externalApiConfig = [];
if (isset($connections['external_api']) && is_array($connections['external_api'])) {
    $externalApiConfig = $connections['external_api'];
    unset($connections['external_api']);
}

// ===== 读取 Bitfinex 理财引擎 fund API 配置 =====
$fundApiConfig = [];
if (isset($connections['fund_api']) && is_array($connections['fund_api'])) {
    $fundApiConfig = $connections['fund_api'];
    unset($connections['fund_api']);
}

// ===== 读取 Jenkins 构建触发配置（SO 文件配置页「热更新」按钮用）=====
$jenkinsConfig = [];
if (isset($connections['jenkins']) && is_array($connections['jenkins'])) {
    $jenkinsConfig = $connections['jenkins'];
    unset($connections['jenkins']);
}

// ===== 读取 ClickHouse 配置（数据中心配置中心 / 归一化数据查询页使用）=====
$clickhouseConfig = [];
if (isset($connections['clickhouse']) && is_array($connections['clickhouse'])) {
    $clickhouseConfig = $connections['clickhouse'];
    unset($connections['clickhouse']);
}

// ===== 读取体育做市对冲测试回测服务地址（SO调用按钮内部 HTTP 调用用）=====
$soServiceConfig = [];
if (isset($connections['so_service']) && is_array($connections['so_service'])) {
    $soServiceConfig = $connections['so_service'];
    unset($connections['so_service']);
}

// 支持环境变量覆盖（推荐生产使用）：SO_SERVICE_HOST / SO_SERVICE_PORT
$soServiceHost = getenv('SO_SERVICE_HOST');
if ($soServiceHost !== false && $soServiceHost !== '') {
    $soServiceConfig['host'] = $soServiceHost;
}
$soServicePort = getenv('SO_SERVICE_PORT');
if ($soServicePort !== false && $soServicePort !== '') {
    $soServiceConfig['port'] = $soServicePort;
}

$defaultMysqlConnection = [
    'type'            => 'mysql',
    'hostname'        => '',
    'database'        => '',
    'username'        => '',
    'password'        => '',
    'hostport'        => '',
    'params'          => [],
    'charset'         => 'utf8mb4',
    'prefix'          => '',
    'deploy'          => 0,
    'rw_separate'     => false,
    'master_num'      => 1,
    'slave_no'        => '',
    'fields_strict'   => true,
    'break_reconnect' => false,
    'trigger_sql'     => true,
];

// 生产配置依赖环境变量注入。若外部挂载的 YAML 缺少 mysql 节点，
// 这里兜底创建默认连接，避免登录页 sysconf 读取主库时直接报配置未定义。
if ($currentEnv !== 'local') {
    foreach (['mysql', 'db_pay2', 'db_pay3'] as $connName) {
        if (!isset($connections[$connName]) || !is_array($connections[$connName])) {
            $connections[$connName] = $defaultMysqlConnection;
        }
    }
}

// ===== 非 local 环境从环境变量读取数据库配置 =====
if ($currentEnv !== 'local' && isset($connections['mysql']) && isset($connections['db_pay2'])) {
    // develop/prod 环境优先使用环境变量
    $dbHost = getenv('DB_HOST');
    if ($dbHost !== false && $dbHost !== '') {
        $connections['mysql']['hostname'] = $dbHost;
        $connections['db_pay2']['hostname'] = $dbHost; // 同时覆盖第二个数据库的主机地址
        $connections['db_pay3']['hostname'] = $dbHost; // 同时覆盖第3个数据库的主机地址
    }
    
    $dbPort = getenv('DB_PORT');
    if ($dbPort !== false && $dbPort !== '') {
        $connections['mysql']['hostport'] = $dbPort;
        $connections['db_pay2']['hostport'] = $dbPort; // 同时覆盖第二个数据库的端口
        $connections['db_pay3']['hostport'] = $dbPort; // 同时覆盖第3个数据库的端口
    }
    
    $dbDatabase = getenv('DB_DATABASE');
    if ($dbDatabase !== false && $dbDatabase !== '') {
        $connections['mysql']['database'] = $dbDatabase;
        $connections['db_pay2']['database'] = $dbDatabase; // 同时覆盖第二个数据库的名称
        //$connections['db_pay3']['database'] = 'fgmm_self_mm'; // 同时覆盖第3个数据库的名称
        $connections['db_pay3']['database'] = getenv('DB_DATABASE_UAT_SELF_MM') ?: 'fgmm_self_mm'; // 优先使用 DB_DATABASE_SELF_MM 环境变量，否则使用 DB_DATABASE
    }
    
    $dbUsername = getenv('DB_USERNAME');
    if ($dbUsername !== false && $dbUsername !== '') {
        $connections['mysql']['username'] = $dbUsername;
        $connections['db_pay2']['username'] = $dbUsername; // 同时覆盖第二个数据库的用户名
        $connections['db_pay3']['username'] = $dbUsername; // 同时覆盖第3个数据库的用户名
    }
    
    $dbPassword = getenv('DB_PASSWORD');
    if ($dbPassword !== false && $dbPassword !== '') {
        $connections['mysql']['password'] = $dbPassword;
        $connections['db_pay2']['password'] = $dbPassword; // 同时覆盖第二个数据库的密码
        $connections['db_pay3']['password'] = $dbPassword; // 同时覆盖第二个数据库的密码
    }

    if (isset($connections['db_rmpd'])) {
        $connections['db_rmpd']['hostname'] = $dbHost ?: '';
        $connections['db_rmpd']['hostport'] = $dbPort ?: '';
        $connections['db_rmpd']['database'] = getenv('DB_DATABASE_RMPD') ?: 'fgmm_strategy_rmpd';
        $connections['db_rmpd']['username'] = $dbUsername ?: '';
        $connections['db_rmpd']['password'] = $dbPassword ?: '';
    }

    if (isset($connections['db_bitfinex'])) {
        $connections['db_bitfinex']['hostname'] = $dbHost ?: '';
        $connections['db_bitfinex']['hostport'] = $dbPort ?: '';
        $connections['db_bitfinex']['database'] = getenv('DB_DATABASE_BITFINEX') ?: 'fgmm_strategy_bitfinex';
        // 可选独立账号（与 mysql 主库的 DB_USERNAME / DB_PASSWORD 不同2221）
        $dbBitfinexUser = getenv('DB_USERNAME_BITFINEX');
        $dbBitfinexPwd  = getenv('DB_PASSWORD_BITFINEX');
        $connections['db_bitfinex']['username'] = ($dbBitfinexUser !== false && $dbBitfinexUser !== '')
            ? $dbBitfinexUser
            : ($dbUsername ?: '');
        $connections['db_bitfinex']['password'] = ($dbBitfinexPwd !== false && $dbBitfinexPwd !== '')
            ? $dbBitfinexPwd
            : ($dbPassword ?: '');
    }

    if (isset($connections['db_sc_arb'])) {
        $connections['db_sc_arb']['hostname'] = $dbHost ?: '';
        $connections['db_sc_arb']['hostport'] = $dbPort ?: '';
        $connections['db_sc_arb']['database'] = getenv('DB_DATABASE_SC_ARB') ?: 'mojo_strategy_sc_arb';
        // 可选独立账号（与 mysql 主库的 DB_USERNAME / DB_PASSWORD 不同）
        $dbScArbUser = getenv('DB_USERNAME_SC_ARB');
        $dbScArbPwd  = getenv('DB_PASSWORD_SC_ARB');
        $connections['db_sc_arb']['username'] = ($dbScArbUser !== false && $dbScArbUser !== '')
            ? $dbScArbUser
            : ($dbUsername ?: '');
        $connections['db_sc_arb']['password'] = ($dbScArbPwd !== false && $dbScArbPwd !== '')
            ? $dbScArbPwd
            : ($dbPassword ?: '');
    }

    if (isset($connections['db_arbopp'])) {
        $connections['db_arbopp']['hostname'] = "54.151.158.221";
        $connections['db_arbopp']['hostport'] = $dbPort ?: '';
        $connections['db_arbopp']['database'] = getenv('DB_DATABASE_ARBOPP') ?: 'fgmm_strategy_less_one';
        $dbArboppUser = "mm";
        $dbArboppPwd  = "2kHjniEMBGh7rRWz";
        $connections['db_arbopp']['username'] = ($dbArboppUser !== false && $dbArboppUser !== '')
            ? $dbArboppUser
            : ($dbUsername ?: '');
        $connections['db_arbopp']['password'] = ($dbArboppPwd !== false && $dbArboppPwd !== '')
            ? $dbArboppPwd
            : ($dbPassword ?: '');
    }

    // 控制库（fgmm_runtime）：保存 sys_sharding_config / sys_market_sharding_index 等分片路由表1
    // 与 mysql / db_pay2 共享 host/port/凭证；database schema 名写死在 YAML，不读 env
    // （和 db_rmpd 不同——它的 DB_DATABASE_RMPD 是历史遗留，db_fgmm_runtime 这里选了更简单的 YAML 路线）
    if (isset($connections['db_fgmm_runtime'])) {
        $connections['db_fgmm_runtime']['hostname'] = $dbHost ?: '';
        $connections['db_fgmm_runtime']['hostport'] = $dbPort ?: '';
        $dbRuntimeUser = getenv('DB_USERNAME_FGMM_RUNTIME');
        $dbRuntimePwd  = getenv('DB_PASSWORD_FGMM_RUNTIME');
        $connections['db_fgmm_runtime']['username'] = ($dbRuntimeUser !== false && $dbRuntimeUser !== '')
            ? $dbRuntimeUser
            : ($dbUsername ?: '');
        $connections['db_fgmm_runtime']['password'] = ($dbRuntimePwd !== false && $dbRuntimePwd !== '')
            ? $dbRuntimePwd
            : ($dbPassword ?: '');
        // database 保持 YAML 中写死的值（prod: uat_fgmm1_runtime / develop: fgmm_runtime）
    }

    // 数据源配置中心（fgmm_data）：供 data 模块维护 fgmm-data 配置表。
    // 快盘回测库（fgmm_f_backtest）：f_market_config / f_market / f_placement 三表
    // 回测库独立部署（45.45），hostname/port/账号一律以 YAML 为准，不跟随主库 DB_HOST 覆盖
    // （否则测试环境 configmap 的 DB_HOST=172.16.39.253 会把回测库也指向 Dev-DB，页面读到旧库数据）。
    // 如需运维侧临时覆盖，可单独设置 DB_HOST_F_BACKTEST / DB_PORT_F_BACKTEST /
    // DB_USERNAME_F_BACKTEST / DB_PASSWORD_F_BACKTEST / DB_DATABASE_F_BACKTEST。
    if (isset($connections['db_f_backtest'])) {
        $dbBtHost = getenv('DB_HOST_F_BACKTEST');
        $dbBtPort = getenv('DB_PORT_F_BACKTEST');
        $dbBtUser = getenv('DB_USERNAME_F_BACKTEST');
        $dbBtPwd  = getenv('DB_PASSWORD_F_BACKTEST');
        $dbBtDb   = getenv('DB_DATABASE_F_BACKTEST');
        if ($dbBtHost !== false && $dbBtHost !== '') {
            $connections['db_f_backtest']['hostname'] = $dbBtHost;
        }
        if ($dbBtPort !== false && $dbBtPort !== '') {
            $connections['db_f_backtest']['hostport'] = $dbBtPort;
        }
        if ($dbBtUser !== false && $dbBtUser !== '') {
            $connections['db_f_backtest']['username'] = $dbBtUser;
        }
        if ($dbBtPwd !== false && $dbBtPwd !== '') {
            $connections['db_f_backtest']['password'] = $dbBtPwd;
        }
        if ($dbBtDb !== false && $dbBtDb !== '') {
            $connections['db_f_backtest']['database'] = $dbBtDb;
        }
        // 未设置独立 env 时保留 YAML 中的 hostname/port/账号（45.45 + uat_mm）
    }

    // 快盘策略打包参数库（fgmm_f_data）：placement_params 铺单参数表（SO 文件配置页用）
    // 与 db_f_backtest 同一 host/port/账号/密码，只保留独立 database schema。
    if (isset($connections['db_f_data'])) {
        if (isset($connections['db_f_backtest'])) {
            foreach (['hostname', 'hostport', 'username', 'password'] as $field) {
                if (array_key_exists($field, $connections['db_f_backtest'])) {
                    $connections['db_f_data'][$field] = $connections['db_f_backtest'][$field];
                }
            }
        }
    }

    // $dbHost_2 = getenv('DB_HOST_2'); // 环境变量#2
    // if ($dbHost_2 !== false && $dbHost_2 !== '') {
    //     $connections['db_pay2']['hostname'] = $dbHost_2;
    // }
    // $dbPort_2 = getenv('DB_PORT_2');
    // if ($dbPort_2 !== false && $dbPort_2 !== '') {
    //     $connections['db_pay2']['hostport'] = $dbPort_2;
    // }
    // $dbDatabase_2 = getenv('DB_DATABASE_2');
    // if ($dbDatabase_2 !== false && $dbDatabase_2 !== '') {
    //     $connections['db_pay2']['database'] = $dbDatabase_2;
    // }
    // $dbUsername_2 = getenv('DB_USERNAME_2');
    // if ($dbUsername_2 !== false && $dbUsername_2 !== '') {
    //     $connections['db_pay2']['username'] = $dbUsername_2;
    // }
    // $dbPassword_2 = getenv('DB_PASSWORD_2');
    // if ($dbPassword_2 !== false && $dbPassword_2 !== '') {
    //     $connections['db_pay2']['password'] = $dbPassword_2;
    // }
}

// SO 快盘 PNL 只读库默认采用 YAML；本地联调可显式覆盖到测试库。
// 密码仅从部署环境变量注入，不复用主库 DB_PASSWORD，也不在仓库中保存明文。
if (isset($connections['db_fastbook'])) {
    foreach ([
        'DB_HOST_SO_DATA' => 'hostname',
        'DB_PORT_SO_DATA' => 'hostport',
        'DB_DATABASE_SO_DATA' => 'database',
        'DB_USERNAME_SO_DATA' => 'username',
    ] as $envKey => $field) {
        $value = getenv($envKey);
        if ($value !== false && $value !== '') {
            $connections['db_fastbook'][$field] = $value;
        }
    }
    $dbSoDataPassword = getenv('DB_PASSWORD_SO_DATA');
    $connections['db_fastbook']['password'] = ($dbSoDataPassword !== false && $dbSoDataPassword !== '')
        ? $dbSoDataPassword
        : '';
}

$fundApiBaseUrl = getenv('FUND_API_BASE_URL');
if ($fundApiBaseUrl !== false && $fundApiBaseUrl !== '') {
    $fundApiConfig['base_url'] = $fundApiBaseUrl;
}
$fundApiAuthKey = getenv('FUND_API_AUTH_KEY');
if ($fundApiAuthKey !== false && $fundApiAuthKey !== '') {
    $fundApiConfig['auth_key'] = $fundApiAuthKey;
}

// Jenkins 配置支持环境变量覆盖（生产环境推荐）
$jenkinsUrl = getenv('JENKINS_URL');
if ($jenkinsUrl !== false && $jenkinsUrl !== '') {
    $jenkinsConfig['url'] = $jenkinsUrl;
}
$jenkinsUser = getenv('JENKINS_USER');
if ($jenkinsUser !== false && $jenkinsUser !== '') {
    $jenkinsConfig['user'] = $jenkinsUser;
}
$jenkinsToken = getenv('JENKINS_TOKEN');
if ($jenkinsToken !== false && $jenkinsToken !== '') {
    $jenkinsConfig['token'] = $jenkinsToken;
}

// ClickHouse 支持环境变量覆盖；PHP 后台查询走 HTTP 端口。
foreach ([
    'DATA_CLICKHOUSE_ADDR' => 'addr',
    'CLICKHOUSE_ADDR' => 'addr',
    'CLICKHOUSE_HOST' => 'host',
    'CLICKHOUSE_PORT' => 'port',
    'CLICKHOUSE_HTTP_PORT' => 'http_port',
    'DATA_CLICKHOUSE_HTTP_PORT' => 'http_port',
    'CLICKHOUSE_DATABASE' => 'database',
    'DATA_CLICKHOUSE_DATABASE' => 'database',
    'CLICKHOUSE_USERNAME' => 'username',
    'DATA_CLICKHOUSE_USER' => 'user',
    'CLICKHOUSE_PASSWORD' => 'password',
    'DATA_CLICKHOUSE_PASSWORD' => 'password',
] as $envName => $field) {
    $envValue = getenv($envName);
    if ($envValue !== false && $envValue !== '') {
        $clickhouseConfig[$field] = $envValue;
    }
}

$finailConnections = $connections; // 保存最终配置用于调试日志

// 动态设置一些 PHP 函数生成的值
if (isset($connections['mysql'])) {
    $connections['mysql']['fields_cache'] = isOnline();
}
if (isset($connections['sqlite'])) {
    $connections['sqlite']['database'] = syspath('database/sqlite.db');
}

// ===== 调试日志：打印最终配置 =====
$debugInfo = [
    'timestamp' => date('Y-m-d H:i:s'),
    'env_var_APP_ENV' => getenv('APP_ENV') ?: 'NOT_SET',
    'git_branch' => getCurrentBranch(),
    'final_environment' => $currentEnv,
    'yaml_file' => $yamlFile,
    'hostname' => $connections['mysql']['hostname'] ?? 'NOT_SET',
    'hostport' => $connections['mysql']['hostport'] ?? 'NOT_SET',
    'database' => $connections['mysql']['database'] ?? 'NOT_SET',
    'username' => $connections['mysql']['username'] ?? 'NOT_SET',
    'password' => $connections['mysql']['password'] ?? 'NOT_SET',
    'charset' => $connections['mysql']['charset'] ?? 'NOT_SET',
    'dsn' => sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=%s",
        $connections['mysql']['hostname'] ?? 'UNKNOWN1',
        $connections['mysql']['hostport'] ?? '3306',
        $connections['mysql']['database'] ?? 'UNKNOWN',
        $connections['mysql']['charset'] ?? 'utf8mb4'
    ),
    'second_db_dsn' => isset($connections['db_pay2']) ? sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=%s",
        $connections['db_pay2']['hostname'] ?? 'UNKNOWN1',
        $connections['db_pay2']['hostport'] ?? '3306',
        $connections['db_pay2']['database'] ?? 'UNKNOWN',
        $connections['db_pay2']['charset'] ?? 'utf8mb4'
    ) : 'NOT_SET',
    'initial_db_pay2_hostname' => $initialConnections['db_pay2']['hostname'] ?? 'NOT_SET',
    'final_db_pay2_hostname' => $finailConnections['db_pay2']['hostname'] ?? 'NOT_SET',
    'initial_db_pay2_database' => $initialConnections['db_pay2']['database'] ?? 'NOT_SET',
    'final_db_pay2_database' => $finailConnections['db_pay2']['database'] ?? 'NOT_SET',
    'initial_db_pay2_username' => $initialConnections['db_pay2']['username'] ?? 'NOT_SET',
    'final_db_pay2_username' => $finailConnections['db_pay2']['username'] ?? 'NOT_SET',
    'initial_db_pay2_password' => $initialConnections['db_pay2']['password'] ?? 'NOT_SET',
    'final_db_pay2_password' => $finailConnections['db_pay2']['password'] ?? 'NOT_SET', 
    'third_db_dsn' => isset($connections['db_pay3']) ? sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=%s",
        $connections['db_pay3']['hostname'] ?? 'UNKNOWN1',
        $connections['db_pay3']['hostport'] ?? '3306',
        $connections['db_pay3']['database'] ?? 'UNKNOWN',
        $connections['db_pay3']['charset'] ?? 'utf8mb4'
    ) : 'NOT_SET',
    'initial_db_pay3_hostname' => $initialConnections['db_pay3']['hostname'] ?? 'NOT_SET',
    'final_db_pay3_hostname' => $finailConnections['db_pay3']['hostname'] ?? 'NOT_SET',
    'initial_db_pay3_database' => $initialConnections['db_pay3']['database'] ?? 'NOT_SET',
    'final_db_pay3_database' => $finailConnections['db_pay3']['database'] ?? 'NOT_SET',
    'initial_db_pay3_username' => $initialConnections['db_pay3']['username'] ?? 'NOT_SET',
    'final_db_pay3_username' => $finailConnections['db_pay3']['username'] ?? 'NOT_SET',
    'initial_db_pay3_password' => $initialConnections['db_pay3']['password'] ?? 'NOT_SET',
    'final_db_pay3_password' => $finailConnections['db_pay3']['password'] ?? 'NOT_SET', 
];

// 写入日志文件
$logFile = dirname(__DIR__) . '/runtime/log/database_config_' . date('Ymd') . '.log';
@mkdir(dirname($logFile), 0777, true);
@file_put_contents($logFile, 
    "\n========== " . date('Y-m-d H:i:s') . " ==========\n" .
    json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND
);

// 同时写入 PHP error_log
error_log("[DB-Config] ENV_VAR:{$debugInfo['env_var_APP_ENV']} Git:{$debugInfo['git_branch']} FinalEnv:{$debugInfo['final_environment']} DSN:{$debugInfo['dsn']}");
// ===== 日志结束 =====

return [
    // 默认使用的数据库连接配置
    'default' => 'mysql',
    // 自定义时间查询规则
    'time_query_rule' => [],
    // 自动写入时间戳字段
    'auto_timestamp' => true,
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    // 数据库连接配置信息
    'connections' => $connections,
    // 外部登录 API 配置信息
    'external_api' => $externalApiConfig,
    // Bitfinex 理财引擎 fund API（3.6.3 申购/赎回）
    'fund_api' => $fundApiConfig,
    // Jenkins 构建触发配置（热更新按钮）
    'jenkins' => $jenkinsConfig,
    // ClickHouse 查询配置（数据中心配置中心 / normalized 只读页）
    'clickhouse' => $clickhouseConfig,
    // 体育做市对冲测试回测服务地址（SO调用按钮内部 HTTP 调用用）
    'so_service' => $soServiceConfig,
];
