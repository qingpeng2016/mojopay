<?php
declare (strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\QuickMarketSnapshots;
use think\admin\Controller;
use think\facade\Db;

/**
 * 铺单表（快盘回测历史）
 * 单次快盘 SO 调用的历史快照，每次策略计算追加一行，用于追溯铺单过程。
 * 列表展示全部已定义字段（横向滚动），并提供整行详情与 CSV 导出。
 */
class Placement extends Controller
{
    public function index()
    {
        $this->title = '铺单信息';
        $get = $this->request->get();

        $query = QuickMarketSnapshots::placementQuery();
        $this->applyFilters($query, $get);
        $columns = $this->csvColumns($this->placementColumns());
        QuickMarketSnapshots::selectFields($query, $this->queryFields($columns), true);

        $result = $query->order('m.id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/placement/index.html',
        ], false);

        $list = $result->items();
        // JSON 数组列：直接展示/复制「数据库里存储的原始 JSON 文本」，不做自定义解析
        // 列表单元格放完整原始值，靠 CSS 省略号隐藏溢出；data-copy / 悬浮取完整原始值
        $jsonCols = ['in_s_history', 'in_basis_samples', 'up_asks', 'up_bids', 'down_asks', 'down_bids', 'cd_epsilon_samples'];
        // datetime(3) 列展示截断到秒，避免毫秒尾巴干扰阅读
        $timeCols = ['open_time', 'expiry_time', 'calc_time', 'created_at'];
        // IV 列先定 4 位小数再 trimZero（0.550000000000000000 → 0.5500 → 0.55；0 → 0）
        $ivCols = ['in_iv_strike_lo', 'in_iv_strike_hi', 'in_iv_lo', 'in_iv_hi'];
        foreach ($list as &$row) {
            $row = $this->fillInputSourceSnapshot($row);
            foreach ($timeCols as $c) {
                if (!empty($row[$c])) {
                    $row[$c] = date('Y-m-d H:i:s', strtotime((string)$row[$c]));
                }
            }
            foreach ($ivCols as $ic) {
                if (isset($row[$ic]) && is_numeric($row[$ic])) {
                    $row[$ic] = sprintf('%.4f', (float)$row[$ic]);
                }
            }
            // decimal 列去尾零（8000.00000000 → 8000，0.550000000000000000 → 0.55）；非数值不受影响
            foreach ($row as $c => $v) {
                if (is_numeric($v)) {
                    $row[$c] = $this->trimZero($v);
                }
            }
            foreach ($jsonCols as $c) {
                $row[$c . '_full'] = (string)($row[$c] ?? '');
            }
            $row = $this->fillDisplayColumns($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'columns'  => $columns,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes'     => $this->sourceTypes(),
        ]);

        return $this->fetch();
    }

    /**
     * 铺单整页详情：铺单档位(up/down 可切换, 置顶) → 输入参数 → commonData。
     * 单次快盘 SO 调用的历史快照，渲染逻辑与市场详情一致。
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
        // commonData 各因子含义
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
        $query = QuickMarketSnapshots::placementQuery()->where('m.id', $id);
        $fields[] = 'f_market_id';
        QuickMarketSnapshots::selectFields($query, $fields, true);
        QuickMarketSnapshots::includePayloads($query);
        $row = $query->find();
        if (!$row) {
            return $this->error('记录不存在');
        }
        $row = QuickMarketSnapshots::presentPayloads($row);
        $row = $this->fillInputSourceSnapshot($row);

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
            'title'     => '铺单详情 · ' . $row['market_no'] . ' #' . $row['calc_seq'],
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
     * 导出 CSV（沿用仓库既有写法：window.location.href 触发真实下载，绕过 AJAX hash 路由）
     * 导出当前筛选条件下的全量历史铺单数据，便于分析。
     */
    public function export()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $get = $this->request->get();

        $cols      = $this->csvColumns($this->placementColumns());
        $keys      = array_column($cols, 'key');
        $fields = $this->queryFields($cols);
        // 表头：label 本身即"英文字段名\n中文含义"（列表页与 CSV 共用），直接落 CSV 表头
        $labels    = array_column($cols, 'label');
        $jsonCols  = ['in_s_history', 'in_basis_samples', 'up_asks', 'up_bids', 'down_asks', 'down_bids', 'cd_epsilon_samples'];
        // datetime(3) 列导出时截断到秒（去掉毫秒尾巴），保证 CSV 时间格式 yyyy-MM-dd HH:mm:ss
        $timeCols  = ['open_time', 'expiry_time', 'calc_time', 'created_at'];

        $delimiter = ',';
        $filename = 'placement_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM，保证 Excel 正确识别 UTF-8
        $this->writeCsvRow($fp, $labels, false, $delimiter);

        // 行含多个大 JSON 列（S_history/basis_samples/四簿档位），chunk 批次过大会内存爆
        // （每行可达数百 KB，128MB 限制下 chunk(500) 直接 OOM），降到 20 一批流式输出。
        $write = function ($rows) use ($fp, $keys, $jsonCols, $timeCols, $delimiter) {
            foreach ($rows as $row) {
                $row = $this->fillInputSourceSnapshot($row);
                $row = $this->fillDisplayColumns($row);
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
        };
        $currentQuery = QuickMarketSnapshots::placementQuery();
        $this->applyFilters($currentQuery, $get);
        QuickMarketSnapshots::selectFields($currentQuery, $fields, true);
        QuickMarketSnapshots::stream($currentQuery, 'm.id', 'asc', 20, $write);

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
        $fields = [];
        foreach ($cols as $col) {
            $key = $col['key'] ?? '';
            if ($key !== '') {
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
    private function applyFilters($query, array &$get): void
    {
        $prefix = 'm.';
        if (!empty($get['market_no'])) {
            $query->where($prefix . 'market_no', 'like', "%{$get['market_no']}%");
        }
        if (!empty($get['symbol'])) {
            $query->where($prefix . 'symbol', $get['symbol']);
        }
        if (!empty($get['trace_id'])) {
            $traceId = '%' . trim((string)$get['trace_id']) . '%';
            $query->whereRaw(QuickMarketSnapshots::expression('cd_trace_id', true) . ' LIKE ?', [$traceId]);
        }
        if (isset($get['f_market_id']) && trim((string)$get['f_market_id']) !== '') {
            $query->where($prefix . 'f_market_id', (int)$get['f_market_id']);
        }
        if (isset($get['calc_seq']) && trim((string)$get['calc_seq']) !== '') {
            $query->where($prefix . 'calc_seq', (int)$get['calc_seq']);
        }
        if (isset($get['f_config_id']) && trim((string)$get['f_config_id']) !== '') {
            $query->where($prefix . 'f_config_id', (int)$get['f_config_id']);
        }
        if (isset($get['calc_code']) && $get['calc_code'] !== '') {
            $query->where($prefix . 'calc_code', (int)$get['calc_code']);
        }
        foreach (QuickMarketSnapshots::SOURCES as $sourceField) {
            if (!empty($get[$sourceField])) {
                $get[$sourceField] = strtoupper((string)$get[$sourceField]);
                $query->where(QuickMarketSnapshots::expression($sourceField, true), $get[$sourceField]);
            }
        }
        // 时间筛选值经分页链接二次编解码后空格会变成「+」：还原避免 SQL 比较错位，
        // 也避免回显到输入框时「+」让 laydate 解析失败（日期格式不合法，已自动重置）。同时写回 $get 供模板回显。
        $calcStart = isset($get['calc_start']) ? trim((string)$get['calc_start']) : '';
        $calcEnd = isset($get['calc_end']) ? trim((string)$get['calc_end']) : '';
        if ($calcStart !== '') { $calcStart = str_replace('+', ' ', $calcStart); $get['calc_start'] = $calcStart; }
        if ($calcEnd !== '') { $calcEnd = str_replace('+', ' ', $calcEnd); $get['calc_end'] = $calcEnd; }
        if ($calcStart !== '' && $calcEnd !== '') {
            $query->whereBetween($prefix . 'calc_time', [$calcStart, $calcEnd]);
        } elseif ($calcStart !== '') {
            $query->where($prefix . 'calc_time', '>=', $calcStart);
        } elseif ($calcEnd !== '') {
            $query->where($prefix . 'calc_time', '<=', $calcEnd);
        } elseif (!empty($get['date_range'])) {
            $dr = trim($get['date_range']);
            // 日期范围 yyyy-MM-dd：起 00:00:00 / 止 23:59:59
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/', $dr, $m)) {
                $query->whereBetween($prefix . 'calc_time', ["{$m[1]} 00:00:00", "{$m[2]} 23:59:59"]);
            } // 日期时间范围 yyyy-MM-dd HH:mm:ss（精确到秒）
            elseif (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*-\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})$/', $dr, $m)) {
                $query->whereBetween($prefix . 'calc_time', [$m[1], $m[2]]);
            }
        }
    }

    /**
     * 铺单信息全部已定义字段（列表列 + CSV 列共用，保证一致）
     */
    private function placementColumns(): array
    {
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

        // commonData 因子中文含义（列表与 CSV 共享表头）
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

        $cols = [
            ['key' => 'id', 'label' => 'ID', 'width' => 60, 'type' => 'text'],
            ['key' => 'f_market_id', 'label' => '市场信息ID', 'width' => 95, 'type' => 'market_link'],
            ['key' => 'f_config_id', 'label' => '盘口配置ID', 'width' => 100, 'type' => 'text'],
            ['key' => 'market_no', 'label' => '盘口编号', 'width' => 200, 'type' => 'text'],
            ['key' => 'cd_trace_id', 'label' => "Trace ID\ncd_trace_id", 'width' => 220, 'type' => 'text'],
            ['key' => 'calc_seq', 'label' => '铺单序号', 'width' => 80, 'type' => 'text'],
            ['key' => 'symbol', 'label' => '标的', 'width' => 90, 'type' => 'text'],
            ['key' => 'open_time', 'label' => '开盘时间', 'width' => 170, 'type' => 'text'],
            ['key' => 'expiry_time', 'label' => '过期时间', 'width' => 170, 'type' => 'text'],
            // 注意：status / settle_result / settle_price 不展示（铺单快照落库后不随盘口结算更新，
            // 展示会误导；市场信息页有最终状态）。列表与 CSV 共用本列定义。
            // —— 输入参数（中文\n英文）——
            ['key' => 'in_symbol', 'label' => "SO 标的\nin_symbol", 'width' => 100, 'type' => 'text'],
            ['key' => 'in_duration', 'label' => "时长\nin_duration", 'width' => 100, 'type' => 'text'],
            ['key' => 'in_d', 'label' => "盘口总时长(秒)\nin_d", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_tau', 'label' => "剩余时间(秒)\nin_tau", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_s', 'label' => "当前现货价\nin_s", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_ds_s', 'label' => "结算源报价\nin_ds_s", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_pricing_source_platform', 'label' => "定价源平台\nin_pricing_source_platform", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_pricing_source_type', 'label' => "定价源类型\nin_pricing_source_type", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_settlement_source_platform', 'label' => "结算源平台\nin_settlement_source_platform", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_settlement_source_type', 'label' => "结算源类型\nin_settlement_source_type", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_k', 'label' => "开盘价(锁定)\nin_k", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_q', 'label' => "净敞口\nin_q", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_q_cap', 'label' => "单边持仓限额\nin_q_cap", 'width' => 130, 'type' => 'text'],
            ['key' => 'in_l', 'label' => "报价延迟(秒)\nin_l", 'width' => 110, 'type' => 'text'],
            ['key' => 'in_s_history', 'label' => "历史现货价格序列\nin_s_history", 'width' => 130, 'type' => 'json', 'preview_key' => 'in_s_history_preview', 'full_key' => 'in_s_history_full'],
            ['key' => 'in_basis_samples', 'label' => "基差快照序列\nin_basis_samples", 'width' => 130, 'type' => 'json', 'preview_key' => 'in_basis_samples_preview', 'full_key' => 'in_basis_samples_full'],
            ['key' => 'in_iv_strike_lo', 'label' => "下档行权价\nin_iv_strike_lo", 'width' => 150, 'type' => 'iv'],
            ['key' => 'in_iv_strike_hi', 'label' => "上档行权价\nin_iv_strike_hi", 'width' => 150, 'type' => 'iv'],
            ['key' => 'in_iv_lo', 'label' => "下档波动率\nin_iv_lo", 'width' => 130, 'type' => 'iv'],
            ['key' => 'in_iv_hi', 'label' => "上档波动率\nin_iv_hi", 'width' => 130, 'type' => 'iv'],
            // 有状态递推闭环输入：上一帧出参 state 回填（null=未初始化）
            ['key' => 'in_sigma_realized_old', 'label' => "上一帧已实现波动率\nin_sigma_realized_old", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_basis_ema_old', 'label' => "上一帧基差EMA\nin_basis_ema_old", 'width' => 150, 'type' => 'text'],
            // 快盘（fast）同口径递推输入
            ['key' => 'in_sigma_realized_fast_old', 'label' => "上一帧快盘已实现波动率\nin_sigma_realized_fast_old", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_sigma_realized_60_old', 'label' => "上一帧60秒已实现波动率\nin_sigma_realized_60_old", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_sigma_realized_900_old', 'label' => "上一帧900秒已实现波动率\nin_sigma_realized_900_old", 'width' => 150, 'type' => 'text'],
            // 新增 edge 状态字段回填（原样透传上一帧 state）
            ['key' => 'in_edge_mode_old', 'label' => "上一帧edge状态模式\nin_edge_mode_old", 'width' => 150, 'type' => 'text'],
            ['key' => 'in_edge_since_old', 'label' => "上一帧edge起始时间\nin_edge_since_old", 'width' => 170, 'type' => 'text'],
            ['key' => 'in_away_since_old', 'label' => "上一帧away起始时间\nin_away_since_old", 'width' => 170, 'type' => 'text'],
            // —— 输出：铺单档位（中文\n英文）——
            ['key' => 'up_asks', 'label' => "UP卖档\nup_asks", 'width' => 130, 'type' => 'json', 'preview_key' => 'up_asks_preview', 'full_key' => 'up_asks_full'],
            ['key' => 'up_bids', 'label' => "UP买档\nup_bids", 'width' => 130, 'type' => 'json', 'preview_key' => 'up_bids_preview', 'full_key' => 'up_bids_full'],
            ['key' => 'down_asks', 'label' => "DOWN卖档\ndown_asks", 'width' => 130, 'type' => 'json', 'preview_key' => 'down_asks_preview', 'full_key' => 'down_asks_full'],
            ['key' => 'down_bids', 'label' => "DOWN买档\ndown_bids", 'width' => 130, 'type' => 'json', 'preview_key' => 'down_bids_preview', 'full_key' => 'down_bids_full'],
        ];
        // 输出：commonData 因子（中文\n英文）
        foreach ($cdKeys as $ck) {
            if ($ck === 'cd_trace_id') {
                continue;
            }
            $label = isset($cdLabels[$ck]) ? $cdLabels[$ck] . "\n" . $ck : $ck;
            $cols[] = ['key' => $ck, 'label' => $label, 'width' => 130, 'type' => 'text'];
        }
        // 输出：state（有状态递推闭环，下帧回填输入 _old）
        $stateCols = [
            ['key' => 'st_sigma_realized', 'label' => "本帧已实现波动率\nst_sigma_realized", 'width' => 150, 'type' => 'text'],
            ['key' => 'st_basis_ema', 'label' => "本帧基差EMA\nst_basis_ema", 'width' => 150, 'type' => 'text'],
            // 快盘（fast）同口径递推输出
            ['key' => 'st_sigma_realized_fast', 'label' => "本帧快盘已实现波动率\nst_sigma_realized_fast", 'width' => 150, 'type' => 'text'],
            ['key' => 'st_sigma_realized_60', 'label' => "本帧60秒已实现波动率\nst_sigma_realized_60", 'width' => 150, 'type' => 'text'],
            ['key' => 'st_sigma_realized_900', 'label' => "本帧900秒已实现波动率\nst_sigma_realized_900", 'width' => 150, 'type' => 'text'],
            // 新增 edge 状态字段输出（下帧回填输入 _old）
            ['key' => 'st_edge_mode', 'label' => "本帧edge状态模式\nst_edge_mode", 'width' => 150, 'type' => 'text'],
            ['key' => 'st_edge_since', 'label' => "本帧edge起始时间\nst_edge_since", 'width' => 170, 'type' => 'text'],
            ['key' => 'st_away_since', 'label' => "本帧away起始时间\nst_away_since", 'width' => 170, 'type' => 'text'],
        ];
        foreach ($stateCols as $sc) {
            $cols[] = $sc;
        }
        // —— 元信息（纯中文）——
        $cols[] = ['key' => 'calc_time', 'label' => '计算时刻', 'width' => 170, 'type' => 'text'];
        $cols[] = ['key' => 'calc_code', 'label' => '返回码', 'width' => 80, 'type' => 'text'];
        $cols[] = ['key' => 'calc_msg', 'label' => '返回信息', 'width' => 160, 'type' => 'text'];
        $cols[] = ['key' => 'calc_cost_us', 'label' => '耗时(µs)', 'width' => 90, 'type' => 'text'];
        $cols[] = ['key' => 'created_at', 'label' => '入库时间', 'width' => 170, 'type' => 'text'];

        return $cols;
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

    private function fillDisplayColumns(array $row): array
    {
        foreach ($this->placementColumns() as $col) {
            $key = $col['key'] ?? '';
            if ($key !== '' && !array_key_exists($key, $row)) {
                $row[$key] = '';
            }
            $fullKey = $col['full_key'] ?? '';
            if ($fullKey !== '' && !array_key_exists($fullKey, $row)) {
                $row[$fullKey] = '';
            }
        }
        return $row;
    }

    /**
     * decimal 数值去尾零：8000.00000000 → 8000，0.550000000000000000 → 0.55，0.000000000000000000 → 0
     * ⚠ 仅处理含小数点的小数：id/calc_seq/calc_code/f_market_id 等整数列末尾的 0 是有效位
     *   （730/300），不能去尾零，否则 id=730 显示成 73、铺单次数 300 显示成 3。
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
        // 科学计数法（如 1.0E-7）先转普通小数
        if (stripos($s, 'E') !== false) {
            $s = rtrim(sprintf('%.10f', (float)$s), '0');
        }
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-' || $s === '-0') ? '0' : $s;
    }
}
