<?php
declare (strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\QuickMarketSnapshots;
use think\admin\Controller;
use think\facade\Db;

/**
 * 市场表（快盘回测）
 * 一个盘口一行，存最新一次铺单的完整输入 + 输出。
 * 列表实时展示铺单变化；详情按 输入 → 输出 → commonData 分模块呈现。
 */
class Market extends Controller
{
    public function index()
    {
        $this->title = '市场信息';
        $get = $this->request->get();

        $query = QuickMarketSnapshots::marketQuery();
        $this->applyFilters($query, $get);

        $fields = array_diff(array_column($this->marketColumns(), 'key'), ['in_symbol']);
        QuickMarketSnapshots::selectFields($query, $fields, false);
        $result = $query->order('m.id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/market/index.html',
        ], false);

        $list = $result->items();
        // datetime(3) 列展示截断到秒，避免毫秒尾巴干扰阅读
        $timeCols = ['open_time', 'expiry_time', 'created_at', 'updated_at'];
        foreach ($list as &$row) {
            $row = $this->fillInputSourceSnapshot($row);
            foreach ($timeCols as $c) {
                if (!empty($row[$c])) {
                    $row[$c] = date('Y-m-d H:i:s', strtotime((string)$row[$c]));
                }
            }
            // 数值列去尾零（settle_price/in_k/in_s/in_q/in_q_cap/in_l 等）
            foreach ($row as $c => $v) {
                if (is_numeric($v)) {
                    $row[$c] = $this->trimZero($v);
                }
            }
        }
        unset($row);
        $configs = Db::name('f_market_config')->column('config_name', 'id');

        $this->assign([
            'list'     => $list,
            'configs'  => $configs,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'statuses' => ['pending' => '未开盘', 'active' => '已开盘', 'close' => '已关闭'],
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes'     => $this->sourceTypes(),
        ]);

        return $this->fetch();
    }

    /**
     * 盘口整页详情：铺单档位(up/down 可切换, 置顶) → 输入参数 → commonData。
     */
    public function detail()
    {
        $id = (int)$this->request->get('id');
        // 入参（按文档顺序；含义列 = 中文（字段名））
        $inputs = [
            'in_symbol'        => 'SO 标的（symbol）',
            'in_duration'      => '盘口时长档位（duration）',
            'in_d'             => '盘口总时长（D）秒',
            'in_tau'           => '剩余时间（τ）秒',
            'in_s'             => '当前现货价（S）',
            'in_ds_s'          => '当前结算源报价（ds_s）',
            'in_pricing_source_platform'    => '定价源平台（pricing_source_platform）',
            'in_pricing_source_type'        => '定价源类型（pricing_source_type）',
            'in_settlement_source_platform' => '结算源平台（settlement_source_platform）',
            'in_settlement_source_type'     => '结算源类型（settlement_source_type）',
            'in_k'             => '开盘价（K）锁定',
            'in_q'             => '净敞口（Q）',
            'in_q_cap'         => '单边持仓限额（Q_cap）份',
            'in_l'             => '报价更新延迟（L）秒',
            'in_s_history'     => '历史现货价格序列（S_history）',
            'in_basis_samples' => '基差快照序列（basis_samples）',
            'in_iv_strike_lo'  => '下档行权价（iv_strike_lo）',
            'in_iv_strike_hi'  => '上档行权价（iv_strike_hi）',
            'in_iv_lo'         => '下档波动率（iv_lo）',
            'in_iv_hi'         => '上档波动率（iv_hi）',
            // 有状态递推闭环输入：上一帧出参 state 回填（null=未初始化走 warmup；0 是合法值）
            'in_sigma_realized_old' => '上一帧已实现波动率（sigma_realized_old）',
            'in_basis_ema_old'      => '上一帧基差 EMA（basis_ema_old）',
            // 快盘（fast）同口径递推输入
            'in_sigma_realized_fast_old' => '上一帧快盘已实现波动率（sigma_realized_fast_old）',
            'in_sigma_realized_60_old'  => '上一帧60秒已实现波动率（sigma_realized_60_old）',
            'in_sigma_realized_900_old' => '上一帧900秒已实现波动率（sigma_realized_900_old）',
            // 新增 edge 状态字段回填（原样透传上一帧 state；null/缺失=首次）
            'in_edge_mode_old'  => '上一帧 edge 状态模式（edge_mode_old）',
            'in_edge_since_old' => '上一帧 edge 起始时间（edge_since_old，Unix 毫秒）',
            'in_away_since_old' => '上一帧 away 起始时间（away_since_old，Unix 毫秒）',
        ];
        // commonData 全部因子（对齐策略库 docs/出参详解.md，2026-08-14）
        $cdKeys = [
            // 定价核心
            'cd_trace_id', 'cd_sigma_iv', 'cd_sigma_used', 'cd_s_adj',
            'cd_z', 'cd_p_up', 'cd_skew', 'cd_p_eff_up', 'cd_p_eff_down',
            'cd_p0_ask', 'cd_p0_bid', 'cd_p0_down_ask', 'cd_p0_down_bid',
            'cd_m_eff', 'cd_m_eff_multiplier', 'cd_m_eff_raw', 'cd_phi_d2', 'cd_q_top',
            // 敞口 / 容量
            'cd_q_wait', 'cd_room', 'cd_room_sell', 'cd_room_buy', 'cd_over_cap', 'cd_q',
            // edge / 5m 热度与持仓量加价
            'cd_edge_triggered', 'cd_edge_size_ratio', 'cd_edge_now_ms',
            'cd_hot_add_ratio', 'cd_hot_add_up', 'cd_hot_add_down',
            'cd_q_add_up', 'cd_q_add_down',
            // 铺单结构
            'cd_rho_depth', 'cd_epsilon_samples',
            // 出参诊断（sigma 自算/混合，对齐 feature/sigma_realized_fast）
            'cd_computed_at_unix_ms', 'cd_compute_duration_us', 'cd_sigma_discount',
            'cd_sigma_realized_60', 'cd_sigma_realized_900', 'cd_sigma_g',
            'cd_sigma_realized_slow', 'cd_sigma_realized_fast', 'cd_sigma_slow_floor_ratio',
            'cd_sigma_slow_floor', 'cd_sigma_combined', 'cd_sigma_self_for_quote',
            'cd_sigma_blend_low', 'cd_sigma_blend_high', 'cd_sigma_time_ratio', 'cd_sigma_blend_weight',
            'cd_sigma_blend_mode', 'cd_sigma_fallback_used',
            'cd_sigma_return', 'cd_sigma_eta', 'cd_sigma_step_sq_old', 'cd_sigma_step_sq_new',
            'cd_quote_mode', 'cd_s_eff', 'cd_v_norm',
            // 策略参数（pm_*）
            'cd_pm_tick_size', 'cd_pm_m_base', 'cd_pm_k', 'cd_pm_m_max',
            'cd_pm_m_eff_enlarge_time',
            'cd_pm_lambda', 'cd_pm_lambda_max', 'cd_pm_p_cap', 'cd_pm_p_floor',
            'cd_pm_dense_q_min', 'cd_pm_dense_delta', 'cd_pm_epsilon', 'cd_pm_wall_weight',
            'cd_pm_tail_rho', 'cd_pm_sigma_fallback',
            'cd_pm_n_wait_factor', 'cd_pm_fill_ratio', 'cd_pm_tail_s0', 'cd_pm_tail_grow',
            'cd_pm_rho_end', 'cd_pm_p', 'cd_pm_ema_smooth_alpha', 'cd_pm_sigma_half_life',
            'cd_pm_sigma_fast_half_life', 'cd_pm_sigma_return_lag_sec', 'cd_pm_sigma_history_size',
            'cd_pm_sigma_warmup_min_samples', 'cd_pm_sigma_annual_factor',
            'cd_pm_source_type_discount_window_sec', 'cd_pm_quote_switch_sec', 'cd_pm_twap_v_norm_offset_sec',
            'cd_pm_dense_n_all',
            'cd_pm_q_add_thresholds',
        ];
        // commonData 各因子含义（与输入区一致，增加含义列便于快速识别）
        $cdLabels = [
            'cd_trace_id'          => 'Trace ID',
            'cd_sigma_iv'          => 'IV插值波动率',
            'cd_sigma_used'        => '最终采用波动率',
            'cd_s_adj'             => '基差修正现货价',
            'cd_z'                 => '标准化距离z',
            'cd_p_up'              => '上涨概率',
            'cd_skew'              => '敞口平移量',
            'cd_p_eff_up'          => '有效上涨概率',
            'cd_p_eff_down'        => '有效下跌概率',
            'cd_p0_ask'            => '涨侧最优卖价',
            'cd_p0_bid'            => '涨侧最优买价',
            'cd_p0_down_ask'       => '跌侧最优卖价',
            'cd_p0_down_bid'       => '跌侧最优买价',
            'cd_m_eff'             => '有效点半宽',
            'cd_m_eff_multiplier'  => '本帧实际放大倍数(1或3)',
            'cd_m_eff_raw'         => '封顶前原始点差',
            'cd_phi_d2'            => '标准正态密度φ(z)',
            'cd_q_top'             => '密顶档数',
            'cd_q_wait'            => '等待区总份额',
            'cd_room'              => '本轮可用容量',
            'cd_room_sell'         => 'sell侧可用容量',
            'cd_room_buy'          => 'buy侧可用容量',
            'cd_over_cap'          => '是否超过限额',
            'cd_q'                 => '当前净敞口',
            'cd_edge_triggered'    => '是否触边',
            'cd_edge_size_ratio'   => '触边量系数',
            'cd_edge_now_ms'       => '触边计时(Unix毫秒)',
            'cd_hot_add_ratio'     => '热度加价比例',
            'cd_hot_add_up'        => '涨侧热度加价',
            'cd_hot_add_down'      => '跌侧热度加价',
            'cd_q_add_up'          => '涨侧持仓量加价',
            'cd_q_add_down'        => '跌侧持仓量加价',
            'cd_rho_depth'         => '深度衰减系数',
            'cd_epsilon_samples'   => '密顶扰动数组',
            'cd_computed_at_unix_ms' => '计算完成时刻(Unix毫秒)',
            'cd_compute_duration_us' => '计算耗时(微秒)',
            'cd_sigma_discount'    => '类型折扣系数',
            'cd_sigma_realized_60' => '60秒窗口原始年化波动率',
            'cd_sigma_realized_900' => '900秒窗口原始年化波动率',
            'cd_sigma_g'           => '有效波动率sigma_g',
            'cd_sigma_realized_slow' => '慢路原始年化波动率',
            'cd_sigma_realized_fast' => '快路原始年化波动率',
            'cd_sigma_slow_floor_ratio' => '慢路下限系数',
            'cd_sigma_slow_floor'  => '慢路下限值',
            'cd_sigma_combined'    => '取大结果max(fast,slow_floor)',
            'cd_sigma_self_for_quote' => '参与混合的自算σ',
            'cd_sigma_blend_low'   => '混合有效σ下界',
            'cd_sigma_blend_high'  => '混合有效σ上界',
            'cd_sigma_time_ratio'  => '时间混合比clamp(tau/D)',
            'cd_sigma_blend_weight' => '混合权重ratio³',
            'cd_sigma_blend_mode'  => '混合模式blend/self_only/iv_only',
            'cd_sigma_fallback_used' => '是否使用σ兜底值',
            'cd_sigma_return'      => '最新lag对数收益',
            'cd_sigma_eta'         => 'EWMA衰减系数',
            'cd_sigma_step_sq_old' => '上一帧步方差',
            'cd_sigma_step_sq_new' => '本帧步方差',
            'cd_quote_mode'        => '定价源模式',
            'cd_s_eff'             => '有效现货价',
            'cd_v_norm'            => '归一化成交量',
            'cd_pm_tick_size'      => '最小价格步进',
            'cd_pm_m_base'         => '基础点差',
            'cd_pm_k'              => '动态点差系数',
            'cd_pm_m_max'          => '点差封顶',
            'cd_pm_m_eff_enlarge_time' => '尾盘放大阈值(秒)',
            'cd_pm_lambda'         => '敞口惩罚斜率λ',
            'cd_pm_lambda_max'     => 'λ上限',
            'cd_pm_p_cap'          => '挂价上限',
            'cd_pm_p_floor'        => '挂价下限',
            'cd_pm_dense_q_min'    => '密顶最小档额',
            'cd_pm_dense_delta'    => '密顶档额递增量',
            'cd_pm_epsilon'        => '密顶扰动幅度',
            'cd_pm_wall_weight'    => '墙档权重',
            'cd_pm_tail_rho'       => '尾部衰减系数',
            'cd_pm_sigma_fallback' => 'σ兜底值',
            'cd_pm_n_wait_factor'  => '等待区档数系数',
            'cd_pm_fill_ratio'     => '填充比例',
            'cd_pm_tail_s0'        => '尾部起始份额',
            'cd_pm_tail_grow'      => '尾部增长系数',
            'cd_pm_rho_end'        => '深度衰减终点系数',
            'cd_pm_p'              => '份额幂指数',
            'cd_pm_ema_smooth_alpha' => '基差EMA平滑系数',
            'cd_pm_sigma_half_life'  => 'σ自算EMA半衰期(秒)',
            'cd_pm_sigma_fast_half_life' => '快路EWMA半衰期(秒)',
            'cd_pm_sigma_return_lag_sec' => 'lag收益窗口(秒)',
            'cd_pm_sigma_history_size' => 's_history目标长度',
            'cd_pm_sigma_warmup_min_samples' => '冷启动最少样本',
            'cd_pm_sigma_annual_factor' => '年化乘数',
            'cd_pm_source_type_discount_window_sec' => '类型不一致σ折扣窗口(秒)',
            'cd_pm_quote_switch_sec' => '同平台切换秒数',
            'cd_pm_twap_v_norm_offset_sec' => 'TWAP V_norm扣减秒数',
            'cd_pm_dense_n_all'    => '密顶档数候选',
            'cd_pm_q_add_thresholds' => '持仓量加价阈值数组',
        ];
        // 输出 state 对象（与 commonData 平级，有状态递推闭环回传；下帧回填输入 _old）
        $stateKeys = ['st_sigma_realized', 'st_basis_ema', 'st_sigma_realized_fast', 'st_sigma_realized_60', 'st_sigma_realized_900', 'st_edge_mode', 'st_edge_since', 'st_away_since'];
        $stateLabels = [
            'st_sigma_realized' => '本帧已实现波动率（st_sigma_realized）',
            'st_basis_ema'      => '本帧基差 EMA（st_basis_ema）',
            'st_sigma_realized_fast' => '本帧快盘已实现波动率（st_sigma_realized_fast）',
            'st_sigma_realized_60' => '本帧60秒已实现波动率（st_sigma_realized_60）',
            'st_sigma_realized_900' => '本帧900秒已实现波动率（st_sigma_realized_900）',
            'st_edge_mode'      => '本帧 edge 状态模式（st_edge_mode）',
            'st_edge_since'     => '本帧 edge 起始时间（st_edge_since，Unix 毫秒）',
            'st_away_since'     => '本帧 away 起始时间（st_away_since，Unix 毫秒）',
        ];

        $fields = array_merge(array_keys($inputs), $cdKeys, $stateKeys, [
            'up_asks', 'up_bids', 'down_asks', 'down_bids', 'id', 'f_config_id', 'market_no', 'symbol',
            'status', 'open_time', 'expiry_time', 'settle_result', 'settle_price', 'calc_seq', 'calc_time',
            'calc_code', 'calc_msg', 'calc_cost_us', 'created_at',
        ]);
        $query = QuickMarketSnapshots::marketQuery()->where('m.id', $id);
        $fields[] = 'updated_at';
        QuickMarketSnapshots::selectFields($query, $fields, false);
        QuickMarketSnapshots::includePayloads($query);
        $row = $query->find();
        if (!$row) {
            return $this->error('记录不存在');
        }
        $row = QuickMarketSnapshots::presentPayloads($row);
        $row = $this->fillInputSourceSnapshot($row);

        // datetime(3) 列展示截断到秒
        foreach (['open_time', 'expiry_time', 'created_at', 'updated_at'] as $tc) {
            if (!empty($row[$tc])) {
                $row[$tc] = date('Y-m-d H:i:s', strtotime((string)$row[$tc]));
            }
        }

        // 先保留「数据库里存储的原始 JSON 文本」（不解码），用于详情页原样展示/复制
        $rawInSHistory = $row['in_s_history'] ?? '';
        $rawBasis      = $row['in_basis_samples'] ?? '';
        $row['in_s_history_full']     = $rawInSHistory;
        $row['in_basis_samples_full'] = $rawBasis;

        // JSON 列解码（详情页表格渲染 / 计数用）；缺列（旧 schema）用 ?? null 兜底
        foreach (['up_asks', 'up_bids', 'down_asks', 'down_bids'] as $bk) {
            $row[$bk] = $this->decodeJson($row[$bk] ?? null);
        }
        $row['in_s_history']     = $this->decodeJson($row['in_s_history']);
        $row['in_basis_samples'] = $this->decodeJson($row['in_basis_samples']);
        $row['cd_epsilon_samples'] = $this->decodeJson($row['cd_epsilon_samples'] ?? null);

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

        // 铺单档位按价格排序（铺单有多少铺多少，不限制条数）：up/down 两个方向各自排序
        // asks 降序展示（最优卖价在底部，默认贴底、向上滚看更高档）
        // bids 降序展示（最优买价在顶部，默认贴顶、向下滚看更低档）
        foreach (['up', 'down'] as $dir) {
            $asks = $row[$dir . '_asks'] ?: [];
            $bids = $row[$dir . '_bids'] ?: [];
            usort($asks, fn($a, $b) => $a['price'] <=> $b['price']);
            usort($bids, fn($a, $b) => $b['price'] <=> $a['price']);
            $row[$dir . '_asks'] = array_reverse($asks);
            $row[$dir . '_bids'] = $bids;
        }

        foreach (array_merge(array_keys($inputs), $cdKeys, $stateKeys) as $key) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }

        $config = Db::name('f_market_config')->where('id', $row['f_config_id'])->find();
        $refreshMs = (int)($config['calc_interval_ms'] ?? 1000);
        $this->assign([
            'title'     => '盘口详情 · ' . $row['market_no'],
            'row'       => $row,
            'inputs'    => $inputs,
            'cdKeys'    => $cdKeys,
            'cdLabels'  => $cdLabels,
            'stateKeys'    => $stateKeys,
            'stateLabels'  => $stateLabels,
            'config'    => $config,
            'refreshMs' => $refreshMs,
            'refreshSec'=> $refreshMs / 1000,
        ]);

        return $this->fetch('detail');
    }

    /**
     * 导出市场信息 CSV，字段与列表主视图保持一致。
     */
    public function export()
    {
        $get = $this->request->get();

        $query = QuickMarketSnapshots::marketQuery();
        $this->applyFilters($query, $get);

        $cols = $this->marketColumns();
        $keys = array_column($cols, 'key');
        QuickMarketSnapshots::selectFields($query, $keys, false);
        $labels = array_column($cols, 'label');
        $timeCols = ['open_time', 'expiry_time', 'calc_time', 'created_at', 'updated_at'];

        $filename = 'market_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $labels);

        QuickMarketSnapshots::stream($query, 'm.id', 'desc', 200, function ($rows) use ($fp, $keys, $timeCols) {
            foreach ($rows as $row) {
                $row = $this->fillInputSourceSnapshot($row);
                $line = [];
                foreach ($keys as $k) {
                    $v = $row[$k] ?? '';
                    if (in_array($k, $timeCols, true) && $v !== '') {
                        $v = "\t" . date('Y-m-d H:i:s', strtotime((string)$v));
                    } elseif (is_numeric($v)) {
                        $v = $this->trimZero($v);
                    }
                    if (is_string($v) && preg_match('/^\d+(-\d+)+$/', $v)) {
                        $v = "\t" . $v;
                    }
                    $line[] = $v;
                }
                fputcsv($fp, $line);
            }
        });

        fclose($fp);
        exit;
    }

    private function applyFilters($query, array &$get): void
    {
        if (!empty($get['market_no'])) {
            $query->where('m.market_no', 'like', "%{$get['market_no']}%");
        }
        if (!empty($get['symbol'])) {
            $query->where('m.symbol', $get['symbol']);
        }
        if (!empty($get['trace_id'])) {
            $query->whereRaw(QuickMarketSnapshots::expression('cd_trace_id', false) . ' LIKE ?',
                ['%' . trim((string)$get['trace_id']) . '%']);
        }
        if (!empty($get['status'])) {
            $query->where('m.market_status', $get['status']);
        }
        if (!empty($get['f_config_id'])) {
            $query->where('m.f_config_id', (int)$get['f_config_id']);
        }
        foreach (QuickMarketSnapshots::SOURCES as $sourceField) {
            if (!empty($get[$sourceField])) {
                $get[$sourceField] = strtoupper((string)$get[$sourceField]);
                $query->where(QuickMarketSnapshots::expression($sourceField, false), $get[$sourceField]);
            }
        }
        // 从铺单信息页点击「市场信息ID」跳转而来：仅筛选该一条市场记录
        if (!empty($get['id'])) {
            $query->where('m.id', (int)$get['id']);
        }
    }

    private function marketColumns(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'f_config_id', 'label' => '盘口配置ID'],
            ['key' => 'market_no', 'label' => '盘口编号'],
            ['key' => 'cd_trace_id', 'label' => 'Trace ID cd_trace_id'],
            ['key' => 'symbol', 'label' => '标的'],
            ['key' => 'in_symbol', 'label' => 'in_symbol'],
            ['key' => 'status', 'label' => '状态'],
            ['key' => 'settle_result', 'label' => '结算结果'],
            ['key' => 'settle_price', 'label' => '结算价'],
            ['key' => 'open_time', 'label' => '开盘时间'],
            ['key' => 'expiry_time', 'label' => '过期时间'],
            ['key' => 'in_k', 'label' => '开盘价(锁定) in_k'],
            ['key' => 'in_s', 'label' => '当前现货价 in_s'],
            ['key' => 'in_ds_s', 'label' => '结算源报价 in_ds_s'],
            ['key' => 'in_pricing_source_platform', 'label' => '定价源平台 in_pricing_source_platform'],
            ['key' => 'in_pricing_source_type', 'label' => '定价源类型 in_pricing_source_type'],
            ['key' => 'in_settlement_source_platform', 'label' => '结算源平台 in_settlement_source_platform'],
            ['key' => 'in_settlement_source_type', 'label' => '结算源类型 in_settlement_source_type'],
            ['key' => 'in_q', 'label' => '净敞口 in_q'],
            ['key' => 'in_q_cap', 'label' => '单边持仓限额 in_q_cap'],
            ['key' => 'in_l', 'label' => '报价延迟(秒) in_l'],
            ['key' => 'calc_seq', 'label' => '铺单次数'],
            ['key' => 'calc_time', 'label' => '计算时刻'],
            ['key' => 'updated_at', 'label' => '更新时间'],
        ];
    }

    /**
     * 实时编辑盘口参数（市场详情页按钮）
     * 支持 in_q（净敞口 Q）/ in_q_cap（单边持仓限额 Q_cap）。
     * 更新盘口的下次计算参数；回测服务下一轮铺单/对冲组装入参即用新值（不再被配置表覆盖）。
     */
    public function saveParam()
    {
        $id    = (int)$this->request->post('id');
        $field = $this->request->post('field');
        $value = $this->request->post('value');
        if ($id <= 0 || !in_array($field, ['in_q', 'in_q_cap'], true)) {
            return $this->error('参数错误');
        }
        if ($value === null || !is_numeric($value)) {
            return $this->error('请输入数值');
        }
        $query = Db::name('f_market_v2')->where('id', $id);
        if (!$query->field('id')->find()) {
            return $this->error('盘口不存在');
        }
        $column = $field === 'in_q' ? 'next_q' : 'next_q_cap';
        Db::name('f_market_v2')->where('id', $id)->update([$column => (float)$value]);
        $label = $field === 'in_q' ? 'Q' : 'Q_cap';
        return $this->success($label . ' 已更新为 ' . (float)$value);
    }

    private function decodeJson($v)
    {
        if (empty($v)) {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
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

    /**
     * decimal/float 数值去尾零：8000.00000000 → 8000，0.550000000000000000 → 0.55
     * ⚠ 仅处理含小数点的小数：id/calc_seq/calc_code 等整数列末尾的 0 是有效位（730/300），
     *   不能去尾零，否则 id=730 显示成 73、铺单次数 300 显示成 3。
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
}
