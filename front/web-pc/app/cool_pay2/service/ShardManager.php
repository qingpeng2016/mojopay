<?php
declare(strict_types=1);

namespace app\cool_pay2\service;

use think\facade\Config;
use think\facade\Db;

/**
 * 多分片管理（fan-out / 市场路由）
 *
 * 架构同源 fgmm-runtime 项目（release/1.0.0-beta+20260515）：
 *   - 控制库 db_fgmm_runtime 持有 sys_sharding_config + sys_market_sharding_index 两张路由表
 *   - 业务分片库 mm_0 / mm_1 / ... 各自一份完整的 uat_mm schema（同表结构、不同市场数据）
 *   - 新市场由 runtime OMS 入口加权确定性 hash 选库 + sys_market_sharding_index 永久锁定
 *   - 老市场（sharding 体系上线前）只存在于 mm_0，路由索引里没有；本类用 fan-out market_mapping_config 兜底
 *
 * 用法：
 *   // 跨片读：所有分片各自 SUM 然后相加（用于 DataReport 顶部卡）
 *   $totalVolume = array_sum(ShardManager::fanOutRead(function($conn, $info) {
 *       return (float)Db::connect($conn)->name('source_trader_records')
 *           ->where('status','FILLED')->sum('trade_amount');
 *   }));
 *
 *   // 单市场写：路由到该市场所在分片
 *   $sid = ShardManager::getShardIdForMarket($targetGroupId);
 *   if ($sid !== null) {
 *       Db::connect(ShardManager::conn($sid))
 *           ->name('market_mapping_config')->where('id', $id)->update($data);
 *   }
 *
 *   // 跨片同步写（2PC：任一片失败 → 全部回滚）：所有分片都执行（用于 config_trade 这种全局配置）
 *   ShardManager::fanOutWriteInTx(function($conn, $info) use ($name, $data) {
 *       return Db::connect($conn)->name('config_trade')->where('config_name', $name)->update($data);
 *   });
 *
 * 注意：
 *   - 缓存粒度是"单个 PHP 进程内"。sys_sharding_config 5 分钟 TTL；
 *     target_group_id → shardId 映射缓存到进程结束，因为业务上一个市场永远绑定一个分片（runtime 保证）
 *   - getShardIdForMarket 兜底分支只对老市场生效；新市场都走 sys_market_sharding_index 索引一次命中
 */
class ShardManager
{
    /** sys_sharding_config 行缓存：[shardId => 解析后的分片信息] */
    private static ?array $shardsCache = null;
    private static int $shardsCachedAt = 0;
    private const SHARDS_TTL = 300;

    /** 进程内 target_group_id → shardId 缓存（null 表示已查过但未找到） */
    private static array $marketShardCache = [];

    /** 已动态注册到 Db facade 的分片连接 [shardId => true] */
    private static array $registered = [];

    /**
     * 拿所有 status=1 的分片配置
     *
     * @return array<int, array{id:int,name:string,host:string,port:int,database:string,
     *                          username:string,password:string,weight:int,accept_new_market:int}>
     */
    public static function getActiveShards(): array
    {
        $now = time();
        if (self::$shardsCache !== null && ($now - self::$shardsCachedAt) < self::SHARDS_TTL) {
            return self::$shardsCache;
        }
        $rows = Db::connect('db_fgmm_runtime')
            ->table('sys_sharding_config')
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // env SHARD_EXCLUDE_IDS=3,4：排除指定 shard id（用于本地/dev 屏蔽非对冲策略分片，
        // 比如 dev 上 self_mm 走 fgmm_self_mm schema，缺 cool_pay2 用的 trader 表）。
        // prod 默认不设这个 env，所有 status=1 的分片都参与
        $excludeRaw = getenv('SHARD_EXCLUDE_IDS');
        $excludeSet = [];
        if ($excludeRaw !== false && $excludeRaw !== '') {
            foreach (explode(',', $excludeRaw) as $token) {
                $token = trim($token);
                if ($token !== '' && is_numeric($token)) {
                    $excludeSet[(int)$token] = true;
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $sid = (int)$r['id'];
            if (isset($excludeSet[$sid])) {
                continue;
            }
            $cfg = json_decode((string)$r['database_config'], true);
            if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['database'])) {
                throw new \RuntimeException("sys_sharding_config id={$r['id']} 的 database_config 解析失败或缺字段");
            }
            $out[$sid] = [
                'id'                => $sid,
                'name'              => (string)$r['name'],
                'host'              => (string)$cfg['host'],
                'port'              => (int)($cfg['port'] ?? 3306),
                'database'          => (string)$cfg['database'],
                'username'          => (string)($cfg['username'] ?? ''),
                'password'          => (string)($cfg['password'] ?? ''),
                'weight'            => (int)$r['weight'],
                'accept_new_market' => (int)$r['accept_new_market'],
            ];
        }

        // 自动探测：跳过没有对冲策略核心表（source_trader_records）的分片。
        // 用例：runtime 项目把自做市策略 self_mm 也放在 sys_sharding_config 里（独立 schema fgmm_self_mm
        // 没有 trader 表），如果 cool_pay2 fan-out 到 self_mm 会触发 "table doesn't exist"。
        // 探测一次性走 information_schema，失败/超时则保留分片让真实查询暴露问题（避免静默吞片）。
        $out = self::filterByCompatibility($out);

        self::$shardsCache = $out;
        self::$shardsCachedAt = $now;
        return $out;
    }

    /**
     * 对冲策略 schema 必须有这张表，没有就跳过（不是同类型分片）。
     */
    private const REQUIRED_TABLE = 'source_trader_records';

    /**
     * 探测每个分片是否含对冲策略核心表；不含的剔除并记 error_log。
     *
     * - 表不存在 → 跳过该片（permanent skip，下次缓存清空后重新探测）
     * - 连接错/超时 → **保留**该片（让真实 fan-out 暴露问题，不静默吞）
     *
     * @param array<int, array> $shards
     * @return array<int, array>
     */
    private static function filterByCompatibility(array $shards): array
    {
        $out = [];
        foreach ($shards as $sid => $info) {
            try {
                // 直接走 registerConnection（不经 conn() → 后者会调 getActiveShards 形成递归，
                // 因为此时 shardsCache 还没赋值）
                $rows = Db::connect(self::registerConnection($sid, $info))->query(
                    'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                    [$info['database'], self::REQUIRED_TABLE]
                );
                $exists = ((int)($rows[0]['n'] ?? 0)) > 0;
                if ($exists) {
                    $out[$sid] = $info;
                } else {
                    error_log(sprintf(
                        '[ShardManager] shard %d (%s @ %s/%s) 自动跳过：schema 缺 %s（不是对冲策略分片）',
                        $sid, $info['name'], $info['host'], $info['database'], self::REQUIRED_TABLE
                    ));
                }
            } catch (\Throwable $e) {
                // 探测失败（连接超时/权限错）→ 保留该分片，让真实查询时暴露具体错误
                error_log(sprintf(
                    '[ShardManager] shard %d (%s) 兼容性探测失败，保守保留：%s',
                    $sid, $info['name'], $e->getMessage()
                ));
                $out[$sid] = $info;
            }
        }
        return $out;
    }

    /**
     * 拿到分片对应的 ThinkPHP 连接名；首次调用会动态注册到 Db facade
     */
    public static function conn(int $shardId): string
    {
        if (isset(self::$registered[$shardId])) {
            return 'db_shard_' . $shardId;
        }
        $shards = self::getActiveShards();
        if (!isset($shards[$shardId])) {
            throw new \InvalidArgumentException("shard id={$shardId} 不存在或未启用");
        }
        return self::registerConnection($shardId, $shards[$shardId]);
    }

    /**
     * 注册一个分片连接到 Db facade（与 conn() 共用，但不调 getActiveShards 避免递归）。
     * 用于 filterByCompatibility 探测阶段（此时 shardsCache 还没设好，调 conn() 会陷入循环）。
     */
    private static function registerConnection(int $shardId, array $info): string
    {
        $name = 'db_shard_' . $shardId;
        if (isset(self::$registered[$shardId])) {
            return $name;
        }
        // think\Db::setConfig 接收的是 Config 对象（不是数组），所以走 Config facade
        // 把整个 connections 数组合并后塞回去 —— Config::set 在 named key 上做的是 shallow merge，
        // 直接传 ['connections' => [$name => $cfg]] 会覆盖掉其它连接
        $connections = Config::get('database.connections', []);
        $connections[$name] = [
            'type'             => 'mysql',
            'hostname'         => $info['host'],
            'hostport'         => (string)$info['port'],
            'database'         => $info['database'],
            'username'         => $info['username'],
            'password'         => $info['password'],
            'charset'          => 'utf8mb4',
            'prefix'           => '',
            'deploy'           => 0,
            'rw_separate'      => false,
            'master_num'       => 1,
            'slave_no'         => '',
            'fields_strict'    => true,
            'break_reconnect'  => false,
            'trigger_sql'      => true,
            // PDO 连接超时 5s：避免本地 docker 触达不到某分片时整个请求卡 60s+
            'params'           => [\PDO::ATTR_TIMEOUT => 5],
        ];
        Config::set(['connections' => $connections], 'database');
        self::$registered[$shardId] = true;
        return $name;
    }

    /**
     * 单市场路由：target_group_id → shardId
     *
     * 流程：
     *   1. 先查控制库 sys_market_sharding_index（新市场命中率 100%）
     *   2. 未命中再 fan-out 各分片的 market_mapping_config 兜底（老市场）
     *   3. 仍未命中返回 null（调用方决定写哪片 / 报错）
     *
     * 命中后进程内缓存，因为同 target_group_id 永远绑定同一分片（runtime 通过 condition_id 主键锁）
     */
    public static function getShardIdForMarket(string $targetGroupId): ?int
    {
        if ($targetGroupId === '') {
            return null;
        }
        if (array_key_exists($targetGroupId, self::$marketShardCache)) {
            return self::$marketShardCache[$targetGroupId];
        }

        // 1) 索引一次命中
        $row = Db::connect('db_fgmm_runtime')
            ->table('sys_market_sharding_index')
            ->where('target_group_id', $targetGroupId)
            ->field('sharding_id')
            ->limit(1)
            ->find();
        if ($row && !empty($row['sharding_id'])) {
            $sid = (int)$row['sharding_id'];
            return self::$marketShardCache[$targetGroupId] = $sid;
        }

        // 2) fan-out 兜底
        foreach (self::getActiveShards() as $shardId => $_info) {
            $hit = Db::connect(self::conn($shardId))
                ->table('market_mapping_config')
                ->where('target_group_id', $targetGroupId)
                ->count();
            if ($hit > 0) {
                return self::$marketShardCache[$targetGroupId] = $shardId;
            }
        }
        return self::$marketShardCache[$targetGroupId] = null;
    }

    /**
     * 跨片读：每个 status=1 分片各执行 $callback，返回 [shardId => result]
     *
     * 容错：环境变量 SHARD_SKIP_UNREACHABLE=1 时，单片报错时记日志 + 跳过，结果不含该片
     *       （仅给本地 docker 用 —— docker 容器走 Docker Desktop VM 网络，
     *        往往触达不了 mm_1 这种内网分片）。
     *       prod 默认 0：单片失败异常向上抛，让监控/请求层处理。
     *
     * @param callable $callback fn(string $connName, array $shardInfo): mixed
     * @return array<int, mixed>
     */
    public static function fanOutRead(callable $callback): array
    {
        $skipUnreachable = getenv('SHARD_SKIP_UNREACHABLE') === '1';
        $out = [];
        foreach (self::getActiveShards() as $shardId => $info) {
            try {
                $out[$shardId] = $callback(self::conn($shardId), $info);
            } catch (\Throwable $e) {
                if (!$skipUnreachable) {
                    throw $e;
                }
                error_log("[ShardManager] fanOutRead shard {$shardId} ({$info['name']}) 跳过: " . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * 跨片 fan-out 拿一组 [key => 数值] 映射，合并相同 key 的 sum。
     *
     * 典型场景：每片各按 DATE(created_at) 或 target_group_id 分组后返回 map，
     * app 层需要合并跨片同 key 的数值（比如 5/18 这一天同时存在 mm_0 和 mm_1 的成交）。
     *
     * @param callable $callback fn(string $connName, array $shardInfo): array<int|string, int|float>
     * @return array<string, float> 合并后的 [key => Σ value]
     */
    public static function fanOutSumByKey(callable $callback): array
    {
        $merged = [];
        foreach (self::fanOutRead($callback) as $local) {
            if (!is_array($local)) continue;
            foreach ($local as $k => $v) {
                $key = (string)$k;
                $merged[$key] = ($merged[$key] ?? 0.0) + (float)$v;
            }
        }
        return $merged;
    }

    /**
     * 跨片业务键 existence 检查（A 类全局配置表 add/edit 前的 pre-check）。
     * 在每个 status=1 分片上按 $where 查 1 行，返回**命中该业务键的分片列表**。
     *
     * edit 场景的"是否重名"：调用方判断"业务键有没有改"。
     *   - 改了：existsAcrossShards(new_key) —— 老 row 在各片用 old_key 标识，不会匹配 new_key
     *   - 没改：直接跳过（DB UNIQUE / 当前 row 自己会兜底）
     * id 跨片不对齐，按 id 排除自身没意义，所以不提供 excludeId 参数。
     *
     * 用法：
     *   $hits = ShardManager::existsAcrossShards('config_trade', ['config_name' => 'X']);
     *   if (!empty($hits)) return $this->error('config_name=X 已在分片 ' . ... . ' 存在');
     *
     * @param string $tableName ThinkPHP 表名
     * @param array<string, mixed> $where 业务键 col => val
     * @return array<int, array{shard:string, id:int}> 命中分片的 [shardId => {shard, id}]
     */
    public static function existsAcrossShards(string $tableName, array $where): array
    {
        $hits = [];
        foreach (self::getActiveShards() as $shardId => $info) {
            $q = \think\facade\Db::connect(self::conn($shardId))->name($tableName);
            foreach ($where as $col => $val) {
                $q->where($col, $val);
            }
            $row = $q->field('id')->find();
            if ($row) {
                $hits[$shardId] = ['shard' => $info['name'], 'id' => (int)$row['id']];
            }
        }
        return $hits;
    }

    /**
     * 跨片"缺失键"检查（A 类全局配置表 UPDATE 前的 pre-check）：
     * 验证一组业务键在**每个分片**都存在；返回各片缺失的键列表。
     *
     * 用法：
     *   $missing = ShardManager::missingAcrossShards('config_trade', 'config_name',
     *       ['hedge_trigger_threshold', 'hedge_tail_disable_enabled']);
     *   if (!empty($missing)) return $this->error('SQL 还没初始化某些分片: ' . json_encode($missing));
     *
     * 阻止 UPDATE WHERE business_key 在某片 0 affected 的"静默吞"——
     * 例如新字段加进 PHP schema 但 SQL 还没在 prod 跑。
     *
     * @param string $tableName ThinkPHP 表名
     * @param string $keyCol 业务键列名（典型 'config_name' / 'config_key'）
     * @param array<int|string> $expectedKeys 期望各片都有的键值列表
     * @return array<int, array{shard:string, missing:list<string>}> 仅含有缺失的分片
     */
    public static function missingAcrossShards(string $tableName, string $keyCol, array $expectedKeys): array
    {
        if (empty($expectedKeys)) return [];
        $expected = array_values(array_unique(array_map('strval', $expectedKeys)));
        $result = [];
        foreach (self::getActiveShards() as $shardId => $info) {
            $existing = \think\facade\Db::connect(self::conn($shardId))->name($tableName)
                ->whereIn($keyCol, $expected)
                ->column($keyCol);
            $existSet = array_flip(array_map('strval', $existing));
            $missing = [];
            foreach ($expected as $k) {
                if (!isset($existSet[$k])) $missing[] = $k;
            }
            if (!empty($missing)) {
                $result[$shardId] = ['shard' => $info['name'], 'missing' => $missing];
            }
        }
        return $result;
    }

    /**
     * 跨片 fan-out INSERT 一条 A 类全局配置行——**不对齐 id**，各片各自 auto-increment。
     *
     * 设计动机：早期有过 "把第一片 auto-id 显式写到其它片" 的方案，但新分片上线时其 auto-id
     * 起点可能正好撞上历史值，会触发 PK 冲突。现改成：各片独立 INSERT，跨片 id 允许不同；
     * 后续 UPDATE/DELETE 走【业务唯一键】（业务键由调用方拿）。
     *
     * 走 fanOutWriteInTx 实现 → 任一片失败自动 rollback all（强原子）。
     *
     * @param string $tableName ThinkPHP 表名（无前缀）
     * @param array<string, mixed> $data 要插入的列（不要带 id）
     * @return array{ok:bool, per_shard:array<int, array{ok:bool, shard:string, id?:int, error?:string}>}
     */
    public static function fanOutInsert(string $tableName, array $data): array
    {
        $perShard = self::fanOutWriteInTx(function (string $conn) use ($tableName, $data) {
            $newId = (int)\think\facade\Db::connect($conn)->name($tableName)->insertGetId($data);
            return ['id' => $newId];
        });

        // 把 fanOutWriteInTx 的 {ok, shard, result:{id}} 展平成 {ok, shard, id}
        $simplified = [];
        foreach ($perShard as $sid => $r) {
            $entry = ['ok' => $r['ok'] ?? false, 'shard' => $r['shard'] ?? ''];
            if ($r['ok'] ?? false) {
                $entry['id'] = (int)($r['result']['id'] ?? 0);
            } else {
                $entry['error'] = $r['error'] ?? 'unknown';
            }
            $simplified[$sid] = $entry;
        }
        $allOk = empty(array_filter($simplified, static fn($r) => !($r['ok'] ?? false)));
        return ['ok' => $allOk, 'per_shard' => $simplified];
    }

    /**
     * 瞬时错误关键字 —— 命中后 fanOutWriteInTx 会自动重试一次。
     * 永久性错误（SQL 语法 / UNIQUE 冲突 / 表不存在 / 列不存在）不会命中这些 keyword，直接报错。
     */
    private const TRANSIENT_ERROR_KEYWORDS = [
        'timed out',
        'connection refused',
        'lost connection',
        'gone away',
        'deadlock',
        'try restarting',  // MySQL "try restarting transaction" 死锁提示
    ];

    private static function isTransientError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        foreach (self::TRANSIENT_ERROR_KEYWORDS as $kw) {
            if (strpos($msg, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 跨片同步写（应用层 2PC：phase 1 全部 startTrans + exec，全成功才 commit；否则 rollback all）。
     *
     * **强原子语义**：
     *   - 全部 phase 1 + commit 成功    → 所有 per_shard.ok = true / atomic_status = 'committed'
     *   - 任一片 phase 1 失败           → **所有 per_shard.ok = false**（已成功的片也被 rollback）/ atomic_status = 'rolled_back'
     *   - phase 2 commit 中途失败（极少）→ 失败片 ok=false + atomic_status='commit_failed'；
     *                                     之前已 commit 的片标 'partial_committed'（2PC blind spot，记 error_log）
     *
     * 这样调用方按"any per_shard.ok === false → 失败"的现有 pattern 即可拿到强保证：
     *   失败 = 全部回滚（除非命中 partial_committed），无需手动补偿。
     *
     * 容错：
     *   - 单片 phase 1 瞬时错误（timed out / lost connection / deadlock 等）自动重试 1 次（per_shard.retried=true）
     *   - 永久性错误（SQL 语法 / UNIQUE 冲突）不重试，直接判定整体回滚
     *
     * @param callable $callback fn(string $connName, array $shardInfo): mixed
     * @return array<int, array{ok:bool, shard:string, result?:mixed, error?:string, retried?:bool, atomic_status:string}>
     */
    public static function fanOutWriteInTx(callable $callback): array
    {
        $shards = self::getActiveShards();
        if (empty($shards)) return [];

        $perShard = [];    // shardId => entry
        $openDbs = [];     // shardId => Db instance with open tx (待 commit/rollback)
        $allOk = true;

        // ── Phase 1: 每片 startTrans + exec callback (不 commit) ──
        foreach ($shards as $shardId => $info) {
            $entry = self::phase1TryWithRetry($callback, $shardId, $info, $openDbs);
            $perShard[$shardId] = $entry;
            if (!$entry['ok']) $allOk = false;
        }

        // ── Phase 2: 全 OK 才 commit；否则 rollback 所有还开着 tx 的片 ──
        if ($allOk) {
            $commitFailures = [];
            foreach ($openDbs as $shardId => $db) {
                try {
                    $db->commit();
                    $perShard[$shardId]['atomic_status'] = 'committed';
                } catch (\Throwable $e) {
                    $perShard[$shardId] = [
                        'ok' => false,
                        'shard' => $perShard[$shardId]['shard'],
                        'error' => 'commit failed: ' . $e->getMessage(),
                        'atomic_status' => 'commit_failed',
                    ];
                    $commitFailures[$shardId] = $e->getMessage();
                }
            }
            if (!empty($commitFailures)) {
                // 2PC blind spot：phase 2 commit drift —— 已 commit 的片无法撤销
                error_log('[ShardManager] fanOutWriteInTx phase-2 commit drift: '
                    . json_encode($commitFailures, JSON_UNESCAPED_UNICODE));
                foreach ($perShard as $sid => &$e0) {
                    if (($e0['atomic_status'] ?? '') === 'committed') {
                        $e0['atomic_status'] = 'partial_committed';
                    }
                }
                unset($e0);
            }
        } else {
            // Phase 1 有片失败 → 回滚所有还开 tx 的片；phase 1 成功的片也标 ok=false
            foreach ($openDbs as $shardId => $db) {
                try { $db->rollback(); } catch (\Throwable $_) {}
                if ($perShard[$shardId]['ok']) {
                    $perShard[$shardId]['ok'] = false;
                    $perShard[$shardId]['error'] = 'rolled_back: 其它分片失败导致整体回滚';
                }
            }
            foreach ($perShard as $sid => &$e0) {
                $e0['atomic_status'] = 'rolled_back';
            }
            unset($e0);
        }

        return $perShard;
    }

    /**
     * Phase 1 单片：startTrans + callback。瞬时错误重试一次。
     * 成功 → 把 $db 放进 $openDbs 等 phase 2 处理；失败 → rollback + 不进 openDbs
     *
     * @param array<int, mixed> $openDbs by-ref，成功路径塞入 $openDbs[$shardId]=$db
     * @return array{ok:bool, shard:string, result?:mixed, error?:string, retried?:bool}
     */
    private static function phase1TryWithRetry(callable $callback, int $shardId, array $info, array &$openDbs): array
    {
        $attempt = 0;
        $maxAttempts = 2;
        $lastError = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $conn = self::conn($shardId);
                $db = \think\facade\Db::connect($conn);
                $db->startTrans();
                $openDbs[$shardId] = $db;
                $result = $callback($conn, $info);
                $entry = ['ok' => true, 'shard' => $info['name'], 'result' => $result];
                if ($attempt > 1) $entry['retried'] = true;
                return $entry;
            } catch (\Throwable $e) {
                $lastError = $e;
                if (isset($openDbs[$shardId])) {
                    try { $openDbs[$shardId]->rollback(); } catch (\Throwable $_) {}
                    unset($openDbs[$shardId]);
                }
                if ($attempt < $maxAttempts && self::isTransientError($e)) {
                    usleep(100_000);
                    continue;
                }
                break;
            }
        }
        $entry = ['ok' => false, 'shard' => $info['name'], 'error' => $lastError->getMessage()];
        if ($attempt > 1) $entry['retried'] = true;
        return $entry;
    }

    /**
     * 测试 / 调试用：清进程内缓存
     */
    public static function resetCacheForTest(): void
    {
        self::$shardsCache = null;
        self::$shardsCachedAt = 0;
        self::$marketShardCache = [];
        self::$registered = [];
    }
}
