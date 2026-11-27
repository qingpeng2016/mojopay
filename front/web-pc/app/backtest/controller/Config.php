<?php
declare (strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;
use think\facade\Validate;

/**
 * 盘口配置表（快盘回测）
 * 一行 = 一个盘口生成规则（生成什么样的盘口、可配置入参 duration/Q）。
 */
class Config extends Controller
{
    public function index()
    {
        $this->title = '盘口配置';
        $get = $this->request->get();

        $query = Db::name('f_market_config');

        if (!empty($get['config_name'])) {
            $query->where('config_name', 'like', "%{$get['config_name']}%");
        }
        if (!empty($get['symbol'])) {
            $query->where('symbol', $get['symbol']);
        }
        if (!empty($get['in_duration'])) {
            $query->where('in_duration', $get['in_duration']);
        }
        if (isset($get['status']) && $get['status'] !== '') {
            $query->where('status', (int)$get['status']);
        }
        if (!empty($get['run_mode'])) {
            $query->where('run_mode', $get['run_mode']);
        }
        foreach (['pricing_source_platform', 'pricing_source_type', 'settlement_source_platform', 'settlement_source_type'] as $sourceField) {
            if (!empty($get[$sourceField])) {
                $get[$sourceField] = strtoupper((string)$get[$sourceField]);
                $query->where($sourceField, $get[$sourceField]);
            }
        }
        // 从市场信息页点击「盘口配置ID」跳转而来：仅筛选该一条配置
        if (!empty($get['id'])) {
            $query->where('id', (int)$get['id']);
        }

        $result = $query->order('id asc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/config/index.html',
        ], false);

        $list = $result->items();
        // decimal/float 列去尾零（0.00000000 → 0，8000.00000000 → 8000）
        foreach ($list as &$row) {
            $row = $this->fillSourceConfig($row);
            foreach ($row as $c => $v) {
                if (is_numeric($v)) {
                    $row[$c] = $this->trimZero($v);
                }
            }
        }
        unset($row);
        $cfgIds = array_column($list, 'id');
        // 已有盘口数：当前 已开盘active + 未开盘pending 的市场数（不含已关闭），与「活跃盘口数」目标值对齐
        $activeCounts = [];
        if ($cfgIds) {
            $rows = Db::name('f_market_v2')
                ->field('f_config_id, COUNT(*) AS cnt')
                ->whereIn('f_config_id', $cfgIds)
                ->whereIn('market_status', ['active', 'pending'])
                ->group('f_config_id')->select()->toArray();
            foreach ($rows as $r) {
                $activeCounts[$r['f_config_id']] = $r['cnt'];
            }
        }

        $this->assign([
            'list'         => $list,
            'activeCounts' => $activeCounts,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'durations'    => ['5m', '15m', '1h', '4h', 'daily'],
            'symbolOptions' => $this->symbolOptions(),
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes'     => $this->sourceTypes(),
            'pricingSourceTypes' => $this->pricingSourceTypes(),
        ]);

        return $this->fetch();
    }

    /**
     * 新增盘口配置
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $validate = Validate::rule([
                'config_name'      => 'require|max:128',
                'symbol'           => 'require|in:BTCUSDT,ETHUSDT',
                'in_duration'      => 'require|in:5m,15m,1h,4h,daily',
                'in_d'             => 'float',
                'in_q'             => 'float',
                'in_q_cap'         => 'float',
                'active_count'     => 'integer|egt:0',
                'calc_interval_ms' => 'integer|egt:0',
                'pricing_source_platform'    => 'in:BINANCE,OKX,CHAINLINK',
                'pricing_source_type'        => 'in:SPOT,FUTURES,TWAP',
                'settlement_source_platform' => 'in:BINANCE,OKX,CHAINLINK',
                'settlement_source_type'     => 'in:SPOT,FUTURES,TWAP',
                'run_mode'         => 'require|in:live,history',
                'status'           => 'in:0,1',
                'remark'           => 'max:500',
            ])->message([
                'config_name.require' => '配置名称不能为空',
                'symbol.require'      => '标的不能为空',
                'symbol.in'           => '标的仅支持 BTCUSDT、ETHUSDT',
                'in_duration.require' => '请选择时长档位',
                'in_duration.in'      => '时长档位非法',
                'run_mode.require'    => '请选择回测模式',
                'run_mode.in'         => '回测模式非法',
            ]);
            if (!$validate->check($data)) {
                return json(['code' => 0, 'info' => $validate->getError()]);
            }
            $save = $this->buildConfigData($data);
            $save['created_at'] = date('Y-m-d H:i:s');
            $save['updated_at'] = date('Y-m-d H:i:s');
            Db::name('f_market_config')->insert($save);
            return json(['code' => 1, 'info' => '添加成功', 'url' => '#/backtest/config/index.html']);
        }
        $this->assign([
            'vo'        => $this->defaultSourceConfig(),
            'durations' => ['5m', '15m', '1h', '4h', 'daily'],
            'symbols'   => $this->symbols(),
            'symbolOptions' => $this->symbolOptions(),
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes'     => $this->sourceTypes(),
            'pricingSourceTypes' => $this->pricingSourceTypes(),
        ]);
        return $this->fetch('form');
    }

    /**
     * 编辑盘口配置
     * @auth true
     */
    public function edit()
    {
        $id = (int)$this->request->get('id');
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $id   = (int)($data['id'] ?? $id);
            $validate = Validate::rule([
                'id'               => 'require|integer|gt:0',
                'config_name'      => 'require|max:128',
                'symbol'           => 'require|in:BTCUSDT,ETHUSDT',
                'in_duration'      => 'require|in:5m,15m,1h,4h,daily',
                'run_mode'         => 'require|in:live,history',
                'status'           => 'in:0,1',
                'pricing_source_platform'    => 'in:BINANCE,OKX,CHAINLINK',
                'pricing_source_type'        => 'in:SPOT,FUTURES,TWAP',
                'settlement_source_platform' => 'in:BINANCE,OKX,CHAINLINK',
                'settlement_source_type'     => 'in:SPOT,FUTURES,TWAP',
                'remark'           => 'max:500',
            ])->message([
                'id.require'        => 'ID不能为空',
                'config_name.require' => '配置名称不能为空',
                'symbol.require'    => '标的不能为空',
                'symbol.in'         => '标的仅支持 BTCUSDT、ETHUSDT',
                'in_duration.require' => '请选择时长档位',
                'run_mode.require'  => '请选择回测模式',
            ]);
            if (!$validate->check($data)) {
                return json(['code' => 0, 'info' => $validate->getError()]);
            }
            $save = $this->buildConfigData($data);
            $save['updated_at'] = date('Y-m-d H:i:s');
            Db::name('f_market_config')->where('id', $id)->update($save);
            return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/config/index.html']);
        }
        $vo = Db::name('f_market_config')->where('id', $id)->find();
        if (!$vo) {
            return $this->error('记录不存在');
        }
        $vo = $this->fillSourceConfig($vo);
        $this->assign([
            'vo'        => $vo,
            'durations' => ['5m', '15m', '1h', '4h', 'daily'],
            'symbols'   => $this->symbols(),
            'symbolOptions' => $this->symbolOptions(),
            'sourcePlatforms' => $this->sourcePlatforms(),
            'sourceTypes'     => $this->sourceTypes(),
            'pricingSourceTypes' => $this->pricingSourceTypes(),
        ]);
        return $this->fetch('form');
    }

    /**
     * 删除盘口配置（级联）
     * 先锁配置和 V2 市场，再依次删除对冲、铺单历史与市场。
     * @auth true
     */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if (!$id) {
            return json(['code' => 0, 'info' => '缺少ID']);
        }
        Db::transaction(function () use ($id) {
            if (!Db::name('f_market_config')->where('id', $id)->lock(true)->find()) {
                return;
            }
            $currentMarkets = Db::name('f_market_v2')->field('id')->where('f_config_id', $id)
                ->order('id')->lock(true)->select()->toArray();
            $marketIds = array_column($currentMarkets, 'id');
            if ($marketIds) {
                $hedgeIds = Db::name('f_hedge')->whereIn('f_market_id', $marketIds)->column('id');
                if ($hedgeIds) {
                    Db::name('f_hedge_order')->whereIn('f_hedge_id', $hedgeIds)->delete();
                    Db::name('f_hedge')->whereIn('id', $hedgeIds)->delete();
                }
                Db::name('f_placement_v2')->whereIn('f_market_id', $marketIds)->delete();
                Db::name('f_market_v2')->whereIn('id', $marketIds)->delete();
            }
            Db::name('f_market_config')->where('id', $id)->delete();
        });
        return json(['code' => 1, 'info' => '删除成功']);
    }

    /**
     * 表单数据组装（新增/编辑共用）
     */
    private function buildConfigData(array $data): array
    {
        return [
            'config_name'      => $data['config_name'],
            'symbol'           => $this->normalizeEnum($data['symbol'] ?? 'BTCUSDT', $this->symbols(), 'BTCUSDT'),
            'in_duration'      => $data['in_duration'],
            'in_d'             => (float)($data['in_d'] ?? 0),
            'in_q'             => (float)($data['in_q'] ?? 0),
            'in_q_cap'         => (float)($data['in_q_cap'] ?? 0),
            'pricing_source_platform'    => $this->normalizeEnum($data['pricing_source_platform'] ?? 'BINANCE', $this->sourcePlatforms(), 'BINANCE'),
            'pricing_source_type'        => $this->normalizeEnum($data['pricing_source_type'] ?? 'SPOT', $this->pricingSourceTypes(), 'SPOT'),
            'settlement_source_platform' => $this->normalizeEnum($data['settlement_source_platform'] ?? 'CHAINLINK', $this->sourcePlatforms(), 'CHAINLINK'),
            'settlement_source_type'     => $this->normalizeEnum($data['settlement_source_type'] ?? 'TWAP', $this->sourceTypes(), 'TWAP'),
            'active_count'     => (int)($data['active_count'] ?? 5),
            'calc_interval_ms' => (int)($data['calc_interval_ms'] ?? 1000),
            'run_mode'         => $data['run_mode'],
            'status'           => (int)($data['status'] ?? 1),
            'remark'           => $data['remark'] ?? '',
            'history_start'    => empty($data['history_start']) ? null : $data['history_start'],
            'history_end'      => empty($data['history_end']) ? null : $data['history_end'],
        ];
    }

    private function sourcePlatforms(): array
    {
        return ['BINANCE', 'OKX', 'CHAINLINK'];
    }

    private function symbols(): array
    {
        return array_keys($this->symbolOptions());
    }

    private function symbolOptions(): array
    {
        return [
            'BTCUSDT' => '比特币（BTCUSDT）',
            'ETHUSDT' => '以太坊（ETHUSDT）',
        ];
    }

    private function sourceTypes(): array
    {
        return ['SPOT', 'FUTURES', 'TWAP'];
    }

    private function pricingSourceTypes(): array
    {
        return ['SPOT', 'FUTURES', 'TWAP'];
    }

    private function defaultSourceConfig(): array
    {
        return [
            'pricing_source_platform'    => 'BINANCE',
            'pricing_source_type'        => 'SPOT',
            'settlement_source_platform' => 'CHAINLINK',
            'settlement_source_type'     => 'TWAP',
        ];
    }

    private function fillSourceConfig(array $row): array
    {
        $row['pricing_source_platform'] = $this->normalizeEnum(
            $row['pricing_source_platform'] ?? 'BINANCE',
            $this->sourcePlatforms(),
            'BINANCE'
        );
        $row['pricing_source_type'] = $this->normalizeEnum(
            $row['pricing_source_type'] ?? 'SPOT',
            $this->pricingSourceTypes(),
            'SPOT'
        );
        $row['settlement_source_platform'] = $this->normalizeEnum(
            $row['settlement_source_platform'] ?? 'CHAINLINK',
            $this->sourcePlatforms(),
            'CHAINLINK'
        );
        $row['settlement_source_type'] = $this->normalizeEnum(
            $row['settlement_source_type'] ?? 'TWAP',
            $this->sourceTypes(),
            'TWAP'
        );
        return $row;
    }

    private function normalizeEnum($value, array $allowed, string $default): string
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * decimal/float 数值去尾零：8000.00000000 → 8000，0.50000000 → 0.5
     */
    private function trimZero($v)
    {
        if (!is_numeric($v)) {
            return $v;
        }
        $s = (string)$v;
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
