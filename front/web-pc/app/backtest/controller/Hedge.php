<?php
declare (strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;

/**
 * 对冲信息（快盘回测）
 * f_hedge 一行 = 一次对冲决策（输入 + 元信息）；f_hedge_order 一行 = 一个订单 + 独立成交回执。
 * 一次决策可产出多个订单（4h 永续 1 单 / daily 期权 2 腿 / 组合 3 单）。
 * 列表展示决策主表，详情页展示输入 + 各订单明细。
 */
class Hedge extends Controller
{
    public function index()
    {
        $this->title = '对冲信息';
        $get = $this->request->get();

        $query = $this->currentHedges();
        $this->applyFilters($query, $get);
        $columns = $this->csvColumns($this->hedgeColumns());
        $query->field($this->queryFields($columns));

        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/hedge/index.html',
        ], false);

        $list = $result->items();

        // JSON 列：原始 JSON 文本展示/复制
        $jsonCols = ['in_s_history', 'in_strikes'];
        // datetime(3) 列展示截断到秒
        $timeCols = ['calc_time', 'created_at'];
        $hedgeIds = array_column($list, 'id');
        // 每行附加订单数（子表按主表 id 分组计数）
        $orderCounts = [];
        if ($hedgeIds) {
            $rows = Db::name('f_hedge_order')
                ->field('f_hedge_id, COUNT(*) AS cnt')
                ->whereIn('f_hedge_id', $hedgeIds)
                ->group('f_hedge_id')
                ->select()->toArray();
            foreach ($rows as $r) {
                $orderCounts[$r['f_hedge_id']] = (int)$r['cnt'];
            }
        }
        foreach ($list as &$row) {
            $row = $this->fillInputSourceSnapshot($row);
            foreach ($timeCols as $c) {
                if (!empty($row[$c])) {
                    $row[$c] = date('Y-m-d H:i:s', strtotime((string)$row[$c]));
                }
            }
            // IV 列先定 4 位小数再 trimZero（0.550000000000000000 → 0.55；0 → 0）
            foreach (['in_iv_strike_lo', 'in_iv_strike_hi', 'in_iv_lo', 'in_iv_hi'] as $ic) {
                if (isset($row[$ic]) && is_numeric($row[$ic])) {
                    $row[$ic] = sprintf('%.4f', (float)$row[$ic]);
                }
            }
            // 数值列去尾零（in_iv_lo/in_iv_hi 等）
            foreach ($row as $c => $v) {
                if (is_numeric($v)) {
                    $row[$c] = $this->trimZero($v);
                }
            }
            foreach ($jsonCols as $c) {
                $row[$c . '_full'] = (string)($row[$c] ?? '');
            }
            $row['order_count'] = $orderCounts[$row['id']] ?? 0;
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'columns'  => $columns,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes' => $this->sourceTypes(),
        ]);

        return $this->fetch();
    }

    /**
     * 对冲整页详情：盘口概要 → 输入（hedge.Snapshot 全量）→ 各订单明细（f_hedge_order）。
     */
    public function detail()
    {
        $id = (int)$this->request->get('id');
        $row = $this->currentHedges()->where('id', $id)->find();
        if (!$row) {
            return $this->error('记录不存在');
        }

        // 保留原始 JSON 文本（原样展示/复制）
        $row = $this->fillInputSourceSnapshot($row);

        $row['in_s_history_full'] = (string)($row['in_s_history'] ?? '');
        $row['in_strikes_full']   = (string)($row['in_strikes'] ?? '');

        // JSON 列解码
        $row['in_s_history'] = $this->decodeJson($row['in_s_history'] ?? null);
        $row['in_strikes']   = $this->decodeJson($row['in_strikes'] ?? null);
        // 递归去 JSON 数组内数值尾零（in_strikes 的 strike 等）
        $row['in_strikes'] = $this->trimZeroDeep($row['in_strikes']);

        // IV 类入参保留 4 位小数展示
        foreach (['in_iv_strike_lo', 'in_iv_strike_hi', 'in_iv_lo', 'in_iv_hi'] as $ivk) {
            if (isset($row[$ivk])) {
                $row[$ivk] = sprintf('%.4f', (float)$row[$ivk]);
            }
        }

        // 数值列去尾零（对已解码的 JSON 数组/字符串自动跳过）
        foreach ($row as $c => $v) {
            if (is_numeric($v)) {
                $row[$c] = $this->trimZero($v);
            }
        }

        // 子表订单明细
        $orders = Db::name('f_hedge_order')
            ->where('f_hedge_id', $id)
            ->order('ord_seq asc')
            ->select()->toArray();
        foreach ($orders as &$o) {
            $o['ord_legs'] = $this->decodeJson($o['ord_legs'] ?? null);
            // 递归去 legs 数组内数值尾零（strike/price 等，如 500.0000 → 500）
            $o['ord_legs'] = $this->trimZeroDeep($o['ord_legs']);
            foreach ($o as $oc => $ov) {
                if (is_numeric($ov)) {
                    $o[$oc] = $this->trimZero($ov);
                }
            }
        }
        unset($o);

        // 输入字段（hedge.Snapshot 全量，含义列 = 中文（字段名））
        $inputs = [
            'in_duration'     => '盘口时长档位（duration）',
            'in_s'            => '当前现货价（S）',
            'in_ds_s'          => '当前结算源报价（ds_s）',
            'in_pricing_source_platform'    => '定价源平台（pricing_source_platform）',
            'in_pricing_source_type'        => '定价源类型（pricing_source_type）',
            'in_settlement_source_platform' => '结算源平台（settlement_source_platform）',
            'in_settlement_source_type'     => '结算源类型（settlement_source_type）',
            'in_k'            => '开盘价（K）锁定',
            'in_s_history'    => '历史现货价格序列（S_history）',
            'in_iv_strike_lo' => '下档行权价（iv_strike_lo）',
            'in_iv_strike_hi' => '上档行权价（iv_strike_hi）',
            'in_iv_lo'        => '下档波动率（iv_lo）',
            'in_iv_hi'        => '上档波动率（iv_hi）',
            'in_tau'          => '剩余时间（τ）秒',
            'in_q'            => '净敞口（Q）',
            'in_q_cap'        => '单边持仓限额（Q_cap）份',
            'in_h_held'       => '已对冲量（H_held）BTC',
            'in_strikes'      => '期权链（strikes）',
            // 有状态递推闭环输入：上一帧出参 state 回填（null=未初始化走 warmup；0 是合法值）
            'in_sigma_realized_old' => '上一帧已实现波动率（sigma_realized_old）',
        ];

        // 输出 state 对象（有状态递推闭环回传；下帧回填输入 _old）
        $stateKeys = ['st_sigma_realized'];
        $stateLabels = [
            'st_sigma_realized' => '本帧已实现波动率（st_sigma_realized）',
        ];

        // 元信息
        $metaFields = [
            'hedge_id'     => '幂等键（hedge_id）',
            'cd_trace_id'  => 'Trace ID（cd_trace_id）',
            'placement_calc_seq' => '铺单序号（placement_calc_seq）',
            'machine_name' => '执行实例（machine_name）',
            'calc_seq'     => '对冲序号（calc_seq）',
            'calc_time'    => '决策时刻（calc_time）',
            'calc_code'    => '返回码（calc_code）',
            'calc_msg'     => '返回信息（calc_msg）',
            'calc_cost_us' => '耗时µs（calc_cost_us）',
            'created_at'   => '入库时间（created_at）',
        ];

        foreach (array_merge(array_keys($inputs), $stateKeys, array_keys($metaFields)) as $key) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }

        $config = Db::name('f_market_config')->where('id', $row['f_config_id'] ?? 0)->find();
        $refreshMs = (int)($config['calc_interval_ms'] ?? 1000);
        $this->assign([
            'title'      => '对冲详情 · ' . $row['market_no'],
            'row'        => $row,
            'orders'     => $orders,
            'inputs'     => $inputs,
            'stateKeys'  => $stateKeys,
            'stateLabels' => $stateLabels,
            'metaFields' => $metaFields,
            'refreshMs'  => $refreshMs,
        ]);

        return $this->fetch();
    }

    /**
     * 导出 CSV（表头：中文含义 + 换行 + 英文字段名，与列表页同口径）。
     * 列表导出主表（一次决策一行）；订单明细在详情页查看。
     */
    public function export()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $get = $this->request->get();

        $query = $this->currentHedges();
        $this->applyFilters($query, $get);

        $cols     = $this->csvColumns($this->hedgeColumns());
        $keys     = array_column($cols, 'key');
        $query->field($this->queryFields($cols));
        $labels   = array_map(fn($c) => $c['label'], $cols);
        $jsonCols = ['in_s_history', 'in_strikes'];
        // datetime(3) 列导出截断到秒；chunk 减小避免大 JSON 行内存爆（同铺单导出）
        $timeCols = ['calc_time', 'created_at'];

        $delimiter = ',';
        $filename = 'hedge_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM，保证 Excel 正确识别 UTF-8
        $this->writeCsvRow($fp, $labels, false, $delimiter);

        $query->order('id asc')->chunk(20, function ($rows) use ($fp, $keys, $jsonCols, $timeCols, $delimiter) {
            foreach ($rows as $row) {
                $row = $this->fillInputSourceSnapshot($row);
                $line = [];
                foreach ($keys as $k) {
                    $v = $row[$k] ?? '';
                    if (in_array($k, $timeCols, true) && $v !== '') {
                        // 加 \t 前缀强制 Excel 按文本显示，避免自动格式化成 m/d/yyyy h:mm 丢秒
                        $v = "\t" . date('Y-m-d H:i:s', strtotime((string)$v));
                    }
                    if (in_array($k, $jsonCols, true)) {
                        $v = $this->compactJsonForCsv($v);
                    } elseif (is_numeric($v)) {
                        // decimal 列去尾零（0.000000000000000000 → 0，8000.00000000 → 8000）
                        $v = $this->trimZero($v);
                    }
                    // 纯数字连字符串（如 "5-6-7"）会被 Excel 误识别为日期（2005/6/7），强制文本
                    if (is_string($v) && preg_match('/^\d+(-\d+)+$/', $v)) {
                        $v = "\t" . $v;
                    }
                    $line[] = $v;
                }
                $this->writeCsvRow($fp, $line, true, $delimiter);
            }
            flush();
        });

        fclose($fp);
        exit;
    }

    /**
     * Stream CSV fields one by one to avoid fputcsv building a huge escaped row in memory.
     */
    private function writeCsvRow($fp, array $values, bool $oneLine = false, string $delimiter = ','): void
    {
        foreach ($values as $index => $value) {
            if ($index > 0) {
                fwrite($fp, $delimiter);
            }
            if ($value === null) {
                $value = '';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (!is_scalar($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $value = (string)$value;
            if ($oneLine) {
                $value = $this->csvOneLine($value);
            }
            if (strpbrk($value, "\"\r\n" . $delimiter) === false) {
                fwrite($fp, $value);
                continue;
            }

            fwrite($fp, '"');
            $length = strlen($value);
            for ($offset = 0; $offset < $length; $offset += 8192) {
                fwrite($fp, str_replace('"', '""', substr($value, $offset, 8192)));
            }
            fwrite($fp, '"');
        }
        fwrite($fp, "\n");
    }

    private function csvColumns(array $cols): array
    {
        $ordered = [];
        foreach ($cols as $col) {
            $ordered[] = $col;
        }
        return $ordered;
    }

    private function splitHistoryForCsv($value, int $parts): array
    {
        $out = array_fill(0, $parts, '');
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $trimmed = trim($this->csvOneLine($value));
            if ($trimmed === '') {
                return $out;
            }
            if ($trimmed[0] === '[' && substr($trimmed, -1) === ']') {
                $trimmed = substr($trimmed, 1, -1);
            }
            $items = preg_split('/\s*,\s*/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            return $out;
        }

        $items = array_values($items);
        if (!$items) {
            return $out;
        }

        $chunkSize = (int)ceil(count($items) / $parts);
        $chunks = array_chunk($items, max(1, $chunkSize));
        for ($i = 0; $i < $parts; $i++) {
            $out[$i] = isset($chunks[$i]) ? implode(',', $chunks[$i]) : '';
        }
        return $out;
    }

    private function queryFields(array $cols): array
    {
        $skip = ['order_count' => true];
        $fields = [];
        foreach ($cols as $col) {
            $key = $col['key'] ?? '';
            if ($key !== '' && !isset($skip[$key])) {
                $fields[] = $key;
            }
        }
        return array_values(array_unique($fields));
    }

    private function compactJsonForCsv($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !in_array($trimmed[0], ['[', '{'], true)) {
            return $value;
        }

        // MySQL JSON values are valid but usually contain spaces after commas.
        // Keep the content intact while reducing the huge history field and keeping it one physical line.
        return preg_replace('/\s*,\s*/', ',', $trimmed);
    }

    private function csvOneLine(string $value): string
    {
        return preg_replace('/\s*[\r\n]+\s*/', ' ', $value);
    }

    /**
     * 列表 / 导出 共用的筛选条件
     */
    private function currentHedges()
    {
        return Db::name('f_hedge')->whereRaw(
            'EXISTS (SELECT 1 FROM f_market_v2 m WHERE m.id = f_hedge.f_market_id)'
        );
    }

    private function applyFilters($query, array &$get): void
    {
        if (!empty($get['market_no'])) {
            $query->where('market_no', 'like', "%{$get['market_no']}%");
        }
        if (!empty($get['symbol'])) {
            $query->where('symbol', $get['symbol']);
        }
        if (!empty($get['hedge_id'])) {
            $query->where('hedge_id', 'like', "%{$get['hedge_id']}%");
        }
        if (!empty($get['trace_id'])) {
            $query->where('cd_trace_id', 'like', '%' . trim((string)$get['trace_id']) . '%');
        }
        if (isset($get['f_market_id']) && trim((string)$get['f_market_id']) !== '') {
            $query->where('f_market_id', (int)$get['f_market_id']);
        }
        $calcStart = isset($get['calc_start']) ? trim((string)$get['calc_start']) : '';
        $calcEnd = isset($get['calc_end']) ? trim((string)$get['calc_end']) : '';
        if ($calcStart !== '' && $calcEnd !== '') {
            $query->whereBetween('calc_time', [$calcStart, $calcEnd]);
        } elseif ($calcStart !== '') {
            $query->where('calc_time', '>=', $calcStart);
        } elseif ($calcEnd !== '') {
            $query->where('calc_time', '<=', $calcEnd);
        }
        foreach (['in_pricing_source_platform', 'in_pricing_source_type', 'in_settlement_source_platform', 'in_settlement_source_type'] as $sourceField) {
            if (!empty($get[$sourceField])) {
                $get[$sourceField] = strtoupper(trim((string)$get[$sourceField]));
                $query->where($sourceField, $get[$sourceField]);
            }
        }
    }

    /**
     * 对冲决策主表全部已定义字段（列表列 + CSV 列共用，保证一致）
     * label 格式：输入输出字段 = 中文\n英文；标识/元信息 = 纯中文
     */
    private function hedgeColumns(): array
    {
        $cols = [
            // 标识与溯源
            ['key' => 'id', 'label' => 'ID', 'width' => 60, 'type' => 'text'],
            ['key' => 'hedge_id', 'label' => '幂等键', 'width' => 220, 'type' => 'text'],
            ['key' => 'cd_trace_id', 'label' => "Trace ID
cd_trace_id", 'width' => 220, 'type' => 'text'],
            ['key' => 'placement_calc_seq', 'label' => "铺单序号
placement_calc_seq", 'width' => 110, 'type' => 'placement_link'],
            ['key' => 'f_market_id', 'label' => '市场信息ID', 'width' => 95, 'type' => 'market_link'],
            ['key' => 'market_no', 'label' => '盘口编号', 'width' => 200, 'type' => 'text'],
            ['key' => 'symbol', 'label' => '标的', 'width' => 90, 'type' => 'text'],
            ['key' => 'machine_name', 'label' => '执行实例', 'width' => 150, 'type' => 'text'],
            // —— 输入参数（hedge.Snapshot 全量，中文\n英文）——
            ['key' => 'in_duration', 'label' => "时长\nin_duration", 'width' => 90, 'type' => 'text'],
            ['key' => 'in_s', 'label' => "当前现货价\nin_s", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_ds_s', 'label' => "结算源报价\nin_ds_s", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_pricing_source_platform', 'label' => "定价源平台\nin_pricing_source_platform", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_pricing_source_type', 'label' => "定价源类型\nin_pricing_source_type", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_settlement_source_platform', 'label' => "结算源平台\nin_settlement_source_platform", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_settlement_source_type', 'label' => "结算源类型\nin_settlement_source_type", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_k', 'label' => "开盘价\nin_k", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_s_history', 'label' => "历史现货价格序列\nin_s_history", 'width' => 130, 'type' => 'json', 'preview_key' => 'in_s_history_preview', 'full_key' => 'in_s_history_full'],
            ['key' => 'in_iv_strike_lo', 'label' => "下档行权价\nin_iv_strike_lo", 'width' => 140, 'type' => 'iv'],
            ['key' => 'in_iv_strike_hi', 'label' => "上档行权价\nin_iv_strike_hi", 'width' => 140, 'type' => 'iv'],
            ['key' => 'in_iv_lo', 'label' => "下档波动率\nin_iv_lo", 'width' => 120, 'type' => 'iv'],
            ['key' => 'in_iv_hi', 'label' => "上档波动率\nin_iv_hi", 'width' => 120, 'type' => 'iv'],
            ['key' => 'in_tau', 'label' => "剩余时间(秒)\nin_tau", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_q', 'label' => "净敞口\nin_q", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_q_cap', 'label' => "单边持仓限额\nin_q_cap", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_h_held', 'label' => "已对冲量\nin_h_held", 'width' => 120, 'type' => 'text'],
            ['key' => 'in_strikes', 'label' => "期权链\nin_strikes", 'width' => 140, 'type' => 'json', 'preview_key' => 'in_strikes_preview', 'full_key' => 'in_strikes_full'],
            // 有状态递推闭环输入：上一帧出参 state 回填（null=未初始化）
            ['key' => 'in_sigma_realized_old', 'label' => "上一帧已实现波动率\nin_sigma_realized_old", 'width' => 150, 'type' => 'text'],
            // 输出 state：有状态递推闭环回传值
            ['key' => 'st_sigma_realized', 'label' => "本帧已实现波动率\nst_sigma_realized", 'width' => 150, 'type' => 'text'],
            // —— 订单数（计算列，纯中文）——
            ['key' => 'order_count', 'label' => '订单数', 'width' => 80, 'type' => 'text'],
            // —— 元信息（纯中文）——
            ['key' => 'calc_seq', 'label' => '对冲序号', 'width' => 80, 'type' => 'text'],
            ['key' => 'calc_time', 'label' => '决策时刻', 'width' => 170, 'type' => 'text'],
            ['key' => 'calc_code', 'label' => '返回码', 'width' => 80, 'type' => 'text'],
            ['key' => 'calc_msg', 'label' => '返回信息', 'width' => 160, 'type' => 'text'],
            ['key' => 'calc_cost_us', 'label' => '耗时(µs)', 'width' => 90, 'type' => 'text'],
            ['key' => 'created_at', 'label' => '入库时间', 'width' => 170, 'type' => 'text'],
        ];

        return $cols;
    }

    private function sourcePlatforms(): array
    {
        return ['BINANCE', 'OKX', 'CHAINLINK'];
    }

    private function sourceTypes(): array
    {
        return ['SPOT', 'TWAP'];
    }

    private function fillInputSourceSnapshot(array $row): array
    {
        foreach ($this->defaultInputSourceSnapshot() as $key => $default) {
            if (!isset($row[$key]) || $row[$key] === '') {
                $row[$key] = $default;
            }
        }
        return $row;
    }

    private function defaultInputSourceSnapshot(): array
    {
        return [
            'in_ds_s' => 0,
            'in_pricing_source_platform'    => 'BINANCE',
            'in_pricing_source_type'        => 'SPOT',
            'in_settlement_source_platform' => 'CHAINLINK',
            'in_settlement_source_type'     => 'TWAP',
        ];
    }

    private function decodeJson($v)
    {
        if (empty($v)) {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    /**
     * decimal 数值去尾零：8000.00000000 → 8000，0.550000000000000000 → 0.55
     * ⚠ 仅处理含小数点的小数：id/calc_seq/calc_code/f_market_id 等整数列末尾的 0 是有效位
     *   （730/300），不能去尾零，否则 id=730 显示成 73、对冲序号 300 显示成 3。
     */
    private function trimZero($v)
    {
        if (!is_numeric($v)) {
            return $v;
        }
        $s = (string)$v;
        // 整数（无小数点）原样保留
        if (strpos($s, '.') === false) {
            return $s;
        }
        if (stripos($s, 'E') !== false) {
            $s = rtrim(sprintf('%.10f', (float)$s), '0');
        }
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-' || $s === '-0') ? '0' : $s;
    }

    /**
     * 递归版本：对数组内所有数值同样去尾零（JSON 列如 ord_legs 里的 strike/price）
     */
    private function trimZeroDeep($v)
    {
        if (is_array($v)) {
            foreach ($v as $k => $vv) {
                $v[$k] = $this->trimZeroDeep($vv);
            }
            return $v;
        }
        return is_numeric($v) ? $this->trimZero($v) : $v;
    }
}
