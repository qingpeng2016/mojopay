<?php
// 容器内验证脚本：走框架 CLI 引导，拉真实 f_placement 数据渲染详情模板，检查新结构是否生效
require '/var/www/html/vendor/autoload.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);

use think\admin\service\RuntimeService;
use think\facade\Db;
use think\facade\View;

file_put_contents('/tmp/dbg.log', "step1 bootstrap\n", FILE_APPEND);

try {
    $app = new \think\App('/var/www/html');
    file_put_contents('/tmp/dbg.log', "step2 App ok\n", FILE_APPEND);
    RuntimeService::init($app);          // 绑定 Library::$sapp
    $app->initialize();                  // 完整加载配置与服务
    file_put_contents('/tmp/dbg.log', "step3 init ok\n", FILE_APPEND);
} catch (\Throwable $e) {
    file_put_contents('/tmp/dbg.log', "EXCEPTION: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
    exit(3);
}

// —— 拉真实数据 ——
$row = Db::connect('db_f_backtest')->name('f_placement')->order('id desc')->find();
if (!$row) {
    fwrite(STDERR, "NO_DATA\n");
    exit(1);
}
$rawIn = $row['in_s_history'] ?? '';
$rawB  = $row['in_basis_samples'] ?? '';
$row['in_s_history_full']     = $rawIn;
$row['in_basis_samples_full'] = $rawB;
foreach (['out_asks', 'out_bids', 'in_s_history', 'in_basis_samples', 'cd_perturb_asks', 'cd_perturb_bids'] as $c) {
    $d = json_decode($row[$c] ?? '', true);
    $row[$c] = is_array($d) ? $d : [];
}
$row['out_asks'] = array_slice($row['out_asks'], 0, 10);
$row['out_bids'] = array_slice($row['out_bids'], 0, 10);
foreach (['in_iv_strike_lo', 'in_iv_strike_hi', 'in_iv_lo', 'in_iv_hi'] as $ivk) {
    if (isset($row[$ivk])) $row[$ivk] = sprintf('%.4f', (float)$row[$ivk]);
}

$inputs = [
    'in_duration' => 'duration 盘口时长档位', 'in_d' => 'D 盘口总时长(秒)', 'in_tau' => 'τ 剩余时间(秒)',
    'in_s' => 'S 当前现货价',
    'in_ds_s' => 'ds_s 当前结算源报价',
    'in_pricing_source_platform' => 'pricing_source_platform 定价源平台',
    'in_pricing_source_type' => 'pricing_source_type 定价源类型',
    'in_settlement_source_platform' => 'settlement_source_platform 结算源平台',
    'in_settlement_source_type' => 'settlement_source_type 结算源类型',
    'in_k' => 'K 开盘价(锁定)', 'in_q' => 'Q 净敞口',
    'in_s_history' => 'S_history 历史现货价格序列', 'in_basis_samples' => 'basis_samples 基差快照序列',
    'in_iv_strike_lo' => 'iv_strike_lo 下档行权价', 'in_iv_strike_hi' => 'iv_strike_hi 上档行权价',
    'in_iv_lo' => 'iv_lo 下档波动率', 'in_iv_hi' => 'iv_hi 上档波动率',
];
$cdKeys = [
    'cd_trace_id', 'cd_sigma_iv', 'cd_sigma_used', 'cd_s_adj',
    'cd_z', 'cd_p_up', 'cd_skew', 'cd_p_eff_up', 'cd_p_eff_down',
    'cd_p0_ask', 'cd_p0_bid', 'cd_p0_down_ask', 'cd_p0_down_bid',
    'cd_m_eff', 'cd_m_eff_multiplier', 'cd_m_eff_raw', 'cd_phi_d2', 'cd_q_top',
    'cd_q_wait', 'cd_room', 'cd_room_sell', 'cd_room_buy', 'cd_over_cap', 'cd_q',
    'cd_rho_depth', 'cd_epsilon_samples',
    'cd_computed_at_unix_ms', 'cd_compute_duration_us', 'cd_sigma_discount',
    'cd_sigma_realized_slow', 'cd_sigma_realized_fast', 'cd_sigma_slow_floor_ratio',
    'cd_sigma_slow_floor', 'cd_sigma_combined', 'cd_sigma_self_for_quote',
    'cd_sigma_blend_low', 'cd_sigma_blend_high', 'cd_sigma_time_ratio', 'cd_sigma_blend_weight',
    'cd_sigma_blend_mode', 'cd_sigma_fallback_used',
    'cd_sigma_return', 'cd_sigma_eta', 'cd_sigma_step_sq_old', 'cd_sigma_step_sq_new',
    'cd_quote_mode', 'cd_s_eff', 'cd_v_norm',
    'cd_pm_tick_size', 'cd_pm_m_base', 'cd_pm_k', 'cd_pm_m_max', 'cd_pm_m_eff_enlarge_time',
    'cd_pm_lambda', 'cd_pm_lambda_max', 'cd_pm_p_cap', 'cd_pm_p_floor',
    'cd_pm_dense_q_min', 'cd_pm_dense_delta', 'cd_pm_epsilon', 'cd_pm_wall_weight',
    'cd_pm_tail_rho', 'cd_pm_sigma_fallback',
    'cd_pm_n_wait_factor', 'cd_pm_fill_ratio', 'cd_pm_tail_s0', 'cd_pm_tail_grow',
    'cd_pm_rho_end', 'cd_pm_p', 'cd_pm_ema_smooth_alpha', 'cd_pm_sigma_half_life',
    'cd_pm_sigma_fast_half_life', 'cd_pm_sigma_return_lag_sec', 'cd_pm_sigma_history_size',
    'cd_pm_sigma_warmup_min_samples', 'cd_pm_sigma_annual_factor',
    'cd_pm_source_type_discount_window_sec', 'cd_pm_quote_switch_sec', 'cd_pm_twap_v_norm_offset_sec',
    'cd_pm_dense_n_all',
];
$cdLabels = array_fill_keys($cdKeys, '含义占位');
$config = Db::connect('db_f_backtest')->name('f_market_config')->where('id', $row['f_config_id'] ?? 0)->find() ?: [];
$refreshMs = (int)($config['calc_interval_ms'] ?? 1000);

// —— 渲染 ——
View::config(['view_path' => '/var/www/html/app/backtest/view/']);
$checks = [];
$page = 'placement';
$html = View::fetch("{$page}/detail", [
    'row' => $row, 'inputs' => $inputs, 'cdKeys' => $cdKeys, 'cdLabels' => $cdLabels,
    'config' => $config, 'refreshMs' => $refreshMs, 'refreshSec' => $refreshMs / 1000, 'title' => '验证渲染',
]);
file_put_contents('/tmp/detail_render_placement.html', $html);
$checks['placement_kv_grid'] = strpos($html, 'kv-grid') !== false;
$checks['placement_val_cell'] = strpos($html, 'val-cell') !== false;
$checks['placement_col_33']   = strpos($html, '33.33') !== false;
$checks['placement_btn_sec']  = strpos($html, '自动刷新：' . ($refreshMs / 1000) . 's') !== false;

$page = 'market';
$html2 = View::fetch("{$page}/detail", [
    'row' => $row, 'inputs' => $inputs, 'cdKeys' => $cdKeys, 'cdLabels' => $cdLabels,
    'config' => $config, 'refreshMs' => $refreshMs, 'refreshSec' => $refreshMs / 1000, 'title' => '验证渲染',
]);
file_put_contents('/tmp/detail_render_market.html', $html2);
$checks['market_4col']      = strpos($html2, 'repeat(4,') !== false;
$checks['market_filler_x3'] = substr_count($html2, '<span class="kv-item"></span>') === 3;
$checks['market_btn_sec']   = strpos($html2, '自动刷新：' . ($refreshMs / 1000) . 's') !== false;

// —— 配置列表页：活跃盘口(配置目标) / 已有盘口(铺单中+未开盘实际数) ——
$cfgs = Db::connect('db_f_backtest')->name('f_market_config')->order('id asc')->limit(10)->select()->toArray();
$cfgIds = array_column($cfgs, 'id');
$aCnt = [];
if ($cfgIds) {
    foreach (Db::connect('db_f_backtest')->name('f_market')->field('f_config_id, COUNT(*) AS cnt')->whereIn('f_config_id', $cfgIds)->whereIn('status', ['active', 'pending'])->group('f_config_id')->select()->toArray() as $r) {
        $aCnt[$r['f_config_id']] = $r['cnt'];
    }
}
$html3 = View::fetch('config/index', [
    'list' => $cfgs, 'activeCounts' => $aCnt,
    'pagehtml' => '', 'get' => [], 'durations' => ['5m', '15m', '1h', '4h', 'daily'],
]);
$checks['config_active_col'] = strpos($html3, '活跃盘口') !== false;
$checks['config_has_rows']   = substr_count($html3, '<td>') > 10;

// —— 断言 ——
$ok = true;
foreach ($checks as $k => $v) {
    echo ($v ? '[OK] ' : '[FAIL] ') . $k . "\n";
    if (!$v) $ok = false;
}
echo '刷新间隔 ms = ' . $refreshMs . " (配置 calc_interval_ms)\n";
echo 'placement HTML = ' . strlen($html) . " / market HTML = " . strlen($html2) . " 字节\n";
echo $ok ? "ALL_CHECKS_PASS\n" : "SOME_CHECKS_FAIL\n";
exit($ok ? 0 : 2);
