<?php
declare (strict_types=1);

namespace app\data\controller;

use think\admin\Controller;
use think\admin\service\AdminService;
use think\exception\HttpResponseException;

class Normalized extends Controller
{
    private array $tableMetas = [
        'predict_basketball_normalized' => [
            'title' => '篮球归一化',
            'asset_type' => 'basketball',
            'time_field' => 'created_time',
            'search' => ['platform', 'competition_name', 'company_name', 'tier', 'event_id', 'source_event_id', 'market_id', 'source_market_id', 'market_type', 'line', 'period'],
            'search_options' => [
                'platform' => ['POLYMARKET', 'FB', 'THESPORTS'],
                'market_type' => ['MONEYLINE', 'SPREAD', 'TOTAL'],
                'period' => ['full', 'half', 'q1', 'q2', 'q3', 'q4'],
            ],
            'columns' => [
                'platform' => '平台',
                'competition_name' => '联赛',
                'event_id' => '内部赛事ID',
                'source_event_id' => '源赛事ID',
                'start_time' => '开赛时间',
                'market_type' => '盘口类型',
                'line' => '盘口线',
                'period' => '阶段',
                'market_id' => '内部盘口ID',
                'source_market_id' => '源盘口ID',
                'price_json' => '概率JSON',
                'margin' => '点差',
                'company_name' => '公司',
                'tier' => '梯队',
                'weight' => '权重',
                'decay' => '时间衰减系数',
                'match_clock_second' => '比赛秒',
                'home_score' => '主分',
                'away_score' => '客分',
                'timestamp_time' => '行情时间',
                'created_time' => '入库时间',
            ],
        ],
        'predict_football_normalized' => [
            'title' => '足球归一化',
            'asset_type' => 'football',
            'time_field' => 'created_time',
            'search' => ['platform', 'competition_name', 'company_name', 'tier', 'event_id', 'source_event_id', 'market_id', 'source_market_id', 'market_type', 'line', 'period'],
            'search_options' => [
                'platform' => ['POLYMARKET', 'FB', 'FEIJING', 'NANO', 'THESPORTS'],
                'market_type' => ['1X2', 'SPREAD', 'TOTAL'],
                'period' => ['full', 'half'],
            ],
            'columns' => [
                'platform' => '平台',
                'competition_name' => '联赛',
                'event_id' => '内部赛事ID',
                'source_event_id' => '源赛事ID',
                'start_time' => '开赛时间',
                'market_type' => '盘口类型',
                'line' => '盘口线',
                'period' => '阶段',
                'market_id' => '内部盘口ID',
                'source_market_id' => '源盘口ID',
                'price_json' => '概率JSON',
                'margin' => '点差',
                'company_name' => '公司',
                'tier' => '梯队',
                'weight' => '权重',
                'decay' => '时间衰减系数',
                'match_clock_minute' => '比赛分钟',
                'home_score' => '主分',
                'away_score' => '客分',
                'home_red' => '主红',
                'away_red' => '客红',
                'timestamp_time' => '行情时间',
                'created_time' => '入库时间',
            ],
        ],
        'predict_esports_normalized' => [
            'title' => '电竞归一化',
            'asset_type' => 'esports',
            'time_field' => 'created_time',
            'search' => ['platform', 'competition_name', 'company_name', 'tier', 'event_id', 'source_event_id', 'market_id', 'source_market_id', 'market_type', 'line', 'period'],
            'search_options' => [
                'platform' => ['POLYMARKET', 'FB', 'THESPORTS'],
                'market_type' => ['独赢', '胜负', '大小', '让分'],
                'period' => ['full', 'map1', 'map2', 'map3', 'map4', 'map5'],
            ],
            'columns' => [
                'platform' => '平台',
                'competition_name' => '联赛',
                'event_id' => '内部赛事ID',
                'source_event_id' => '源赛事ID',
                'start_time' => '开赛时间',
                'market_type' => '盘口类型',
                'line' => '盘口线',
                'period' => '阶段',
                'market_id' => '内部盘口ID',
                'source_market_id' => '源盘口ID',
                'price_json' => '概率JSON',
                'margin' => '点差',
                'company_name' => '公司',
                'tier' => '梯队',
                'weight' => '权重',
                'decay' => '时间衰减系数',
                'home_map_score' => '主地图分',
                'away_map_score' => '客地图分',
                'timestamp_time' => '行情时间',
                'created_time' => '入库时间',
            ],
        ],
        'crypto_data_normalized' => [
            'title' => 'Crypto归一化',
            'asset_type' => 'crypto',
            'time_field' => 'created_time',
            'search' => ['platform', 'price_type', 'symbol'],
            'search_options' => [
                'platform' => ['BINANCE', 'CHAINLINK', 'DERIBIT'],
                'price_type' => ['SPOT', 'TWAP', 'IV'],
                'symbol' => ['BTCUSDT', 'BTC/USD', 'BTC'],
            ],
            'columns' => [
                'platform' => '平台',
                'price_type' => '价格类型',
                'symbol' => '标的',
                'last_price' => '最新价',
                'bid_price' => '买一价',
                'bid_qty' => '买一量',
                'ask_price' => '卖一价',
                'ask_qty' => '卖一量',
                'event_time_fmt' => '事件时间',
                'collect_time_fmt' => '采集时间',
                'timestamp_time' => '行情时间',
                'created_time' => '入库时间',
            ],
        ],
    ];

    /** @auth true @menu true */
    public function index()
    {
        $get = $this->request->get();
        $table = $this->sanitizeTable((string)($get['table'] ?? 'predict_football_normalized'));
        $meta = $this->tableMetas[$table];
        $this->title = '数据中心 - 归一化数据';

        $page = max(1, (int)($get['page'] ?? 1));
        $pageSize = max(10, min(100, (int)($get['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        $where = [];
        foreach ($meta['search'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $where[] = sprintf(
                    "%s LIKE '%%%s%%'",
                    $this->searchExpr($field),
                    $this->escapeLike(in_array($field, ['platform', 'price_type'], true) ? strtoupper($value) : $value)
                );
            }
        }
        $startTime = trim((string)($get['start_time'] ?? ''));
        $endTime = trim((string)($get['end_time'] ?? ''));
        if ($startTime !== '' && $this->isDateTime($startTime)) {
            $where[] = $meta['time_field'] . " >= toDateTime('" . $this->escapeString($startTime) . "')";
        }
        if ($endTime !== '' && $this->isDateTime($endTime)) {
            $where[] = $meta['time_field'] . " <= toDateTime('" . $this->escapeString($endTime) . "')";
        }
        $whereSQL = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

        $select = $this->selectColumns($table, array_keys($meta['columns']));
        $sql = sprintf(
            'SELECT %s FROM %s FINAL%s ORDER BY %s DESC LIMIT %d OFFSET %d',
            implode(', ', $select),
            $table,
            $whereSQL,
            $meta['time_field'],
            $pageSize,
            $offset
        );
        $countSQL = sprintf('SELECT count() AS cnt FROM %s FINAL%s', $table, $whereSQL);

        $error = '';
        $list = [];
        $total = 0;
        try {
            $list = $this->clickhouseSelect($sql);
            $countRows = $this->clickhouseSelect($countSQL);
            $total = (int)($countRows[0]['cnt'] ?? 0);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // DateTime64 列在列表里也是原样输出，这里统一裁掉小数秒，与 CSV 导出一致。
        foreach ($list as &$row) {
            foreach ($row as $col => $val) {
                if (is_string($val)) {
                    $row[$col] = $this->secondsPrecision($val);
                }
            }
        }
        unset($row);

        $this->assign([
            'tables' => $this->tableOptions(),
            'table' => $table,
            'meta' => $meta,
            'search_labels' => $this->searchLabels($meta),
            'list' => $list,
            'get' => $get,
            'export_query' => http_build_query($get),
            'error' => $error,
            'pagehtml' => $this->pageHtml($table, $get, $page, $pageSize, $total),
        ]);
        return $this->fetch();
    }

    /**
     * 按当前筛选 + 时间范围导出整表 CSV（UTF-8 BOM，Excel 直接可读）。
     * 与列表共用同一套 WHERE 拼装，覆盖所有归一化表（含聚合信号）。
     * @auth true
     */
    public function export(): void
    {
        @set_time_limit(0);
        $get = $this->request->get();
        $table = $this->sanitizeTable((string)($get['table'] ?? 'predict_football_normalized'));
        $meta = $this->tableMetas[$table];

        $where = [];
        foreach ($meta['search'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $where[] = sprintf(
                    "%s LIKE '%%%s%%'",
                    $this->searchExpr($field),
                    $this->escapeLike(in_array($field, ['platform', 'price_type'], true) ? strtoupper($value) : $value)
                );
            }
        }
        $startTime = trim((string)($get['start_time'] ?? ''));
        $endTime = trim((string)($get['end_time'] ?? ''));
        if ($startTime !== '' && $this->isDateTime($startTime)) {
            $where[] = $meta['time_field'] . " >= toDateTime('" . $this->escapeString($startTime) . "')";
        }
        if ($endTime !== '' && $this->isDateTime($endTime)) {
            $where[] = $meta['time_field'] . " <= toDateTime('" . $this->escapeString($endTime) . "')";
        }
        $whereSQL = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

        $wanted = array_keys($meta['columns']);
        $real = $this->validColumns($table, $wanted);
        // timestamp_time / event_time_fmt / collect_time_fmt 是 selectColumns 生成的计算列，
        // 不在 system.columns 里，按 meta 原顺序补回，保证导出列序与页面一致。
        $computed = ['timestamp_time', 'event_time_fmt', 'collect_time_fmt'];
        $cols = [];
        foreach ($wanted as $w) {
            if (in_array($w, $real, true) || in_array($w, $computed, true)) {
                $cols[] = $w;
            }
        }
        $headers = [];
        foreach ($cols as $c) {
            $headers[] = $meta['columns'][$c] ?? $c;
        }

        $select = $this->selectColumns($table, $cols);
        $sql = sprintf(
            'SELECT %s FROM %s FINAL%s ORDER BY %s',
            implode(', ', $select),
            $table,
            $whereSQL,
            $meta['time_field']
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $table . '_' . date('YmdHis') . '.csv"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers, ',', '"', '');

        $offset = 0;
        $limit = 1000;
        while (true) {
            $rows = $this->clickhouseSelect($sql . sprintf(' LIMIT %d OFFSET %d', $limit, $offset));
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $this->csvCell($row[$c] ?? '');
                }
                fputcsv($fp, $line, ',', '"', '');
            }
            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }
        fclose($fp);
        exit;
    }

    // Kafka 体育推送归档检索（从现有归一化数据页内下拉切换）。所有条件与分页均下推 ClickHouse。
    /** @auth true */
    public function archive()
    {
        // 节点缓存由插件启动时自愈；这里再做显式登录兜底，禁止缓存失配时泄露归档数据。
        if (!AdminService::isLogin()) {
            throw new HttpResponseException(json([
                'code' => 0,
                'info' => '请重新登录！',
                'url' => sysuri('admin/login/index'),
            ]));
        }
        $get = $this->request->get();
        $page = max(1, (int)($get['page'] ?? 1));
        $pageSize = max(10, min(100, (int)($get['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $this->title = '数据中心 - Kafka 推送归档';

        $where = [];
        foreach (['topic', 'sport_type', 'model'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $where[] = $field . "='" . $this->escapeExact($value) . "'";
            }
        }
        foreach (['event_id', 'league_id'] as $field) {
            $value = trim((string)($get[$field] ?? ''));
            if ($value !== '') {
                $where[] = $field . " LIKE '%" . $this->escapeLike($value) . "%'";
            }
        }
        $startTime = trim((string)($get['start_time'] ?? ''));
        $endTime = trim((string)($get['end_time'] ?? ''));
        $validationError = '';
        if ($startTime === '' || !$this->isDateTime($startTime)) {
            if ($startTime !== '') {
                $validationError = '开始时间格式无效，已回退到最近 24 小时。';
            }
            $startTime = date('Y-m-d H:i:s', time() - 86400);
            $get['start_time'] = $startTime;
        }
        $safe = $this->escapeString($startTime);
        $where[] = "date>=toDate('{$safe}') AND inserted_at>=toDateTime64('{$safe}',3)";
        if ($endTime !== '') {
            if (!$this->isDateTime($endTime)) {
                $validationError .= ($validationError === '' ? '' : ' ') . '结束时间格式无效，已忽略。';
            } else {
                $safe = $this->escapeString($endTime);
                $where[] = "date<=toDate('{$safe}') AND inserted_at<=toDateTime64('{$safe}',3)";
            }
        }
        $whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        // 每条 Kafka 快照都是独立的异步归档记录；不能按 topic/event_id 折叠，
        // 否则后台只能看到赛事最新一条，无法回测历史行情。
        $sql = sprintf(
            'SELECT topic,schema_version,sport_type,league_id,event_id,snapshot_at_ms,model,inserted_at,version FROM kafka_sports_market%s ORDER BY snapshot_at_ms DESC,inserted_at DESC,version DESC,topic,event_id LIMIT %d OFFSET %d',
            $whereSQL,
            $pageSize,
            $offset
        );
        $countSQL = 'SELECT count() AS cnt FROM kafka_sports_market' . $whereSQL;
        $list = [];
        $total = 0;
        $error = $validationError;
        try {
            $list = $this->clickhouseSelect($sql);
            $rows = $this->clickhouseSelect($countSQL);
            $total = (int)($rows[0]['cnt'] ?? 0);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $this->assign([
            'tables' => $this->tableOptions(),
            'list' => $list,
            'get' => $get,
            'filters' => [
                'topic' => 'Topic', 'sport_type' => '运动', 'league_id' => '联赛ID',
                'event_id' => '赛事ID', 'model' => '模型阶段',
            ],
            'return_query' => http_build_query($get),
            'error' => $error,
            'pagehtml' => $this->archivePageHtml($get, $page, $pageSize, $total),
        ]);
        return $this->fetch();
    }

    /** sports-event.v1 详情：优先展示 Kafka 原文；历史行回退到八个归档列重组。 @auth true */
    public function detail()
    {
        $get = $this->request->get();
        $table = $this->sanitizeTable((string)($get['table'] ?? 'predict_football_normalized'));
        $meta = $this->tableMetas[$table];
        $eventId = trim((string)($get['event_id'] ?? ''));
        $sport = strtolower(trim((string)($get['sport'] ?? '')));
        if ($sport === '') {
            $sport = strtolower((string)($meta['asset_type'] ?? ''));
        }
        $this->title = '数据中心 - Kafka 体育推送归档详情';

        $payloads = [];
        $error = '';
        $topic = trim((string)($get['topic'] ?? ''));
        $version = max(0, (int)($get['version'] ?? 0));
        if ($eventId !== '') {
            $where = "event_id='" . $this->escapeExact($eventId) . "'";
            if ($topic !== '') {
                $where .= " AND topic='" . $this->escapeExact($topic) . "'";
            }
            if ($sport !== '') {
                $where .= " AND sport_type='" . $this->escapeExact($sport) . "'";
            }
            if ($version > 0) {
                $where .= ' AND version=' . $version;
            }
            try {
                // 支持 MIS 与 Bot/DDL 分步发布：旧库没有原文列时先返回空串，
                // 详情继续用八字段重组；新列上线后自动切到 Kafka 原文。
                $rawPayloadSelect = in_array(
                    'raw_payload_json',
                    $this->validColumns('kafka_sports_market', ['raw_payload_json']),
                    true
                ) ? 'raw_payload_json' : "'' AS raw_payload_json";
                $sql = sprintf(
                    "SELECT topic,schema_version,sport_type,league_id,event_id,snapshot_at_ms,model,sources,match,%s,version,inserted_at FROM kafka_sports_market WHERE %s ORDER BY snapshot_at_ms DESC,inserted_at DESC,version DESC",
                    $rawPayloadSelect,
                    $where
                );
                $rows = $this->clickhouseSelect($sql);
                foreach ($rows as $r) {
                    // 保留 JSON object 形状；关联数组模式会把空对象 {} 错重组为 []。
                    $sources = json_decode((string)($r['sources'] ?? '{}'));
                    if (json_last_error() !== JSON_ERROR_NONE || (!is_object($sources) && !is_array($sources))) {
                        $sources = new \stdClass();
                    }
                    $match = json_decode((string)($r['match'] ?? '{}'));
                    if (json_last_error() !== JSON_ERROR_NONE || (!is_object($match) && !is_array($match))) {
                        $match = new \stdClass();
                    }
                    $decoded = [
                        'schema_version' => (string)($r['schema_version'] ?? 'sports-event.v1'),
                        'sport_type' => (string)($r['sport_type'] ?? ''),
                        'league_id' => (string)($r['league_id'] ?? ''),
                        'event_id' => (string)($r['event_id'] ?? ''),
                        'snapshot_at_ms' => (int)($r['snapshot_at_ms'] ?? 0),
                        'model' => (string)($r['model'] ?? ''),
                        'sources' => $sources,
                        'match' => $match,
                    ];
                    // 判断空值时允许忽略空白，但展示/复制必须保留 Kafka 原文字节内容。
                    $raw = (string)($r['raw_payload_json'] ?? '');
                    $rawEmpty = trim($raw) === '';
                    $rawDecoded = $rawEmpty ? null : json_decode($raw);
                    if ($rawEmpty || json_last_error() !== JSON_ERROR_NONE || !is_object($rawDecoded)) {
                        // 兼容新增原文列之前的历史归档；新写入必须直接展示 Message.Value。
                        $rawDecoded = $decoded;
                        $raw = (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $payloads[] = [
                        'topic' => (string)($r['topic'] ?? ''),
                        'inserted_at' => (string)($r['inserted_at'] ?? ''),
                        'pretty' => json_encode($rawDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                        'raw' => $raw,
                    ];
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->assign([
            'table' => $table,
            'archive_table' => 'kafka_sports_market',
            'sport' => $sport,
            'event_id' => $eventId,
            'topic' => $topic,
            'payloads' => $payloads,
            'error' => $error,
            'back_url' => trim((string)($get['from'] ?? '')) === 'archive'
                ? '/data/normalized/archive.html' . $this->safeArchiveBackQuery((string)($get['back'] ?? ''))
                : '/data/normalized/index.html' . $this->safeListBackQuery((string)($get['back'] ?? ''), $table),
        ]);
        return $this->fetch();
    }

    private function validColumns(string $table, array $wanted): array
    {
        $rows = $this->clickhouseSelect(sprintf(
            "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '%s'",
            $this->escapeString($this->sanitizeTable($table))
        ));
        $exists = [];
        foreach ($rows as $r) {
            $exists[] = $r['name'];
        }
        return array_values(array_intersect($wanted, $exists));
    }

    /**
     * 时间值统一成 yyyy-MM-dd HH:mm:ss（裁掉 DateTime64 的小数秒）；非时间值返回 null。
     */
    private function asSecondPrecision(string $value): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?/', $value, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return null;
    }

    /**
     * 列表显示用：裁到秒级，与 CSV 导出的时间口径一致（列表不加 \t 前缀）。
     */
    private function secondsPrecision(string $value): string
    {
        return $this->asSecondPrecision($value) ?? $value;
    }

    /**
     * CSV 单元格取值。时间列加 \t 前缀，与 app/backtest/controller/Placement.php 的既有约定
     * 保持一致：\t 强制 Excel 按文本显示，否则 Excel 会把 2026-09-12 00:00:00 自动格式化成
     * 2026/9/12 0:00（丢秒）。
     */
    private function csvCell($value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string)$value;
        $time = $this->asSecondPrecision($value);
        return $time === null ? $value : "\t" . $time;
    }

    private function selectColumns(string $table, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            if ($column === 'platform') {
                $out[] = 'upper(platform) AS platform';
            } elseif ($column === 'price_type') {
                $out[] = 'upper(price_type) AS price_type';
            } elseif ($column === 'timestamp_time') {
                $out[] = 'formatDateTime(toDateTime(intDiv(timestamp, 1000)), \'%F %T\') AS timestamp_time';
            } elseif ($column === 'event_time_fmt') {
                $out[] = 'formatDateTime(toDateTime(intDiv(event_time, 1000)), \'%F %T\') AS event_time_fmt';
            } elseif ($column === 'collect_time_fmt') {
                $out[] = 'formatDateTime(toDateTime(intDiv(collect_time, 1000)), \'%F %T\') AS collect_time_fmt';
            } else {
                $out[] = $column;
            }
        }
        return $out;
    }

    private function clickhouseSelect(string $sql): array
    {
        $cfg = config('database.clickhouse', []);
        [$addrHost, $addrPort] = $this->parseClickHouseAddr((string)($cfg['addr'] ?? ''));
        $host = (string)($cfg['host'] ?? ($addrHost ?: '127.0.0.1'));
        $httpPort = (int)($cfg['http_port'] ?? 0);
        if ($httpPort <= 0) {
            $nativePort = (int)($cfg['port'] ?? ($addrPort ?: 9000));
            $httpPort = $nativePort === 9000 ? 8123 : $nativePort;
        }
        $query = $sql . ' FORMAT JSON';
        $url = sprintf('http://%s:%d/?database=%s', $host, $httpPort, rawurlencode((string)($cfg['database'] ?? 'default')));
        $headers = ["Content-Type: text/plain; charset=utf-8"];
        $user = (string)($cfg['username'] ?? $cfg['user'] ?? '');
        if ($user !== '') {
            $headers[] = 'X-ClickHouse-User: ' . $user;
            $headers[] = 'X-ClickHouse-Key: ' . ($cfg['password'] ?? '');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $query,
                // 远程库上大表（如 predict_football_normalized 164 万行）的 FINAL + ORDER BY
                // 实测 26s+，原先 10s 超时会让列表页永远报"ClickHouse 查询失败"。
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('ClickHouse 查询失败，请检查连接配置');
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('ClickHouse 返回非 JSON: ' . substr($body, 0, 200));
        }
        return $json['data'] ?? [];
    }

    private function parseClickHouseAddr(string $addr): array
    {
        $addr = trim($addr);
        if ($addr === '') {
            return ['', 0];
        }
        $parts = explode(':', $addr, 2);
        return [$parts[0], isset($parts[1]) ? (int)$parts[1] : 0];
    }

    private function tableOptions(): array
    {
        // 页面下拉只展示当前对运营开放的数据类型；Crypto 保留底层直链兼容。
        return [
            'predict_football_normalized' => $this->tableMetas['predict_football_normalized']['title'],
            'predict_basketball_normalized' => $this->tableMetas['predict_basketball_normalized']['title'],
            'predict_esports_normalized' => $this->tableMetas['predict_esports_normalized']['title'],
        ];
    }

    private function searchLabels(array $meta): array
    {
        $labels = [
            'pageSize' => '每页条数',
            'start_time' => '开始时间',
            'end_time' => '结束时间',
        ];
        foreach ($meta['search'] as $field) {
            $labels[$field] = $meta['columns'][$field] ?? $this->fallbackSearchLabel($field);
        }
        return $labels;
    }

    private function fallbackSearchLabel(string $field): string
    {
        return [
            'platform' => '平台',
            'price_type' => '价格类型',
            'symbol' => '标的',
            'competition_name' => '联赛',
            'event_id' => '内部赛事ID',
            'source_event_id' => '源赛事ID',
            'market_id' => '内部盘口ID',
            'source_market_id' => '源盘口ID',
            'market_type' => '盘口类型',
            'line' => '盘口线',
            'period' => '阶段',
        ][$field] ?? $field;
    }

    private function searchExpr(string $field): string
    {
        return in_array($field, ['platform', 'price_type'], true) ? 'upper(' . $field . ')' : $field;
    }

    private function sanitizeTable(string $table): string
    {
        return isset($this->tableMetas[$table]) ? $table : 'predict_football_normalized';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(["\\", "'", "%", "_"], ["\\\\", "\\'", "\\%", "\\_"], substr($value, 0, 128));
    }

    private function escapeString(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], substr($value, 0, 64));
    }

    private function escapeExact(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], substr($value, 0, 512));
    }

    private function safeArchiveBackQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str(substr($query, 0, 4096), $params);
        $allowed = array_flip([
            'topic', 'sport_type', 'league_id', 'event_id', 'model', 'start_time', 'end_time',
            'page', 'pageSize',
        ]);
        $params = array_intersect_key($params, $allowed);
        return $params ? '?' . http_build_query($params) : '';
    }

    private function safeListBackQuery(string $query, string $table): string
    {
        if ($query === '') {
            return '?table=' . urlencode($table);
        }
        parse_str(substr($query, 0, 4096), $params);
        $allowed = array_flip(array_merge([
            'table', 'start_time', 'end_time', 'page', 'pageSize',
        ], $this->tableMetas[$table]['search'] ?? []));
        $params = array_intersect_key($params, $allowed);
        $params['table'] = $this->sanitizeTable((string)($params['table'] ?? $table));
        return '?' . http_build_query($params);
    }

    private function isDateTime(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value);
    }

    /**
     * 生成稳定的页码窗口：首尾页始终可见，页码较多时中间用省略号占位。
     * @return array<int|string>
     */
    private function paginationItems(int $page, int $pages): array
    {
        if ($pages <= 7) {
            return range(1, $pages);
        }
        $start = max(2, $page - 2);
        $end = min($pages - 1, $page + 2);
        $items = [1];
        if ($start > 2) $items[] = '…';
        for ($i = $start; $i <= $end; $i++) $items[] = $i;
        if ($end < $pages - 1) $items[] = '…';
        $items[] = $pages;
        return $items;
    }

    private function pageHtml(string $table, array $get, int $page, int $pageSize, int $total): string
    {
        if ($total <= $pageSize) return '';
        $pages = (int)ceil($total / $pageSize);
        $page = min(max(1, $page), $pages);
        $html = '<ul class="pagination">';
        if ($page > 1) $html .= '<li><a href="' . $this->pageUrl($table, $get, $page - 1, $pageSize) . '">上一页</a></li>';
        foreach ($this->paginationItems($page, $pages) as $item) {
            if ($item === '…') $html .= '<li class="disabled"><span>…</span></li>';
            elseif ($item === $page) $html .= '<li class="active"><span>' . $item . '</span></li>';
            else $html .= '<li><a href="' . $this->pageUrl($table, $get, $item, $pageSize) . '">' . $item . '</a></li>';
        }
        if ($page < $pages) $html .= '<li><a href="' . $this->pageUrl($table, $get, $page + 1, $pageSize) . '">下一页</a></li>';
        return $html . '</ul>';
    }

    private function pageUrl(string $table, array $get, int $page, int $pageSize): string
    {
        $get['table'] = $table;
        $get['page'] = $page;
        $get['pageSize'] = $pageSize;
        return '/admin.html#/data/normalized/index.html?' . http_build_query($get);
    }

    private function archivePageHtml(array $get, int $page, int $pageSize, int $total): string
    {
        if ($total <= $pageSize) return '';
        $pages = (int)ceil($total / $pageSize);
        $page = min(max(1, $page), $pages);
        $link = function (int $target) use ($get, $pageSize): string {
            $get['page'] = $target;
            $get['pageSize'] = $pageSize;
            return '/data/normalized/archive.html?' . http_build_query($get);
        };
        $html = '<ul class="pagination">';
        if ($page > 1) $html .= '<li><a href="javascript:void(0)" data-open="' . htmlspecialchars($link($page - 1), ENT_QUOTES, 'UTF-8') . '">上一页</a></li>';
        foreach ($this->paginationItems($page, $pages) as $item) {
            if ($item === '…') $html .= '<li class="disabled"><span>…</span></li>';
            elseif ($item === $page) $html .= '<li class="active"><span>' . $item . '</span></li>';
            else $html .= '<li><a href="javascript:void(0)" data-open="' . htmlspecialchars($link($item), ENT_QUOTES, 'UTF-8') . '">' . $item . '</a></li>';
        }
        if ($page < $pages) $html .= '<li><a href="javascript:void(0)" data-open="' . htmlspecialchars($link($page + 1), ENT_QUOTES, 'UTF-8') . '">下一页</a></li>';
        return $html . '</ul>';
    }

}
