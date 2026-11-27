<?php
declare (strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\service\ShardManager;
use think\admin\Controller;
use think\facade\Db;

/**
 * 做市机器人详情（固定参数清单，值读写 config_trade，页面不展示 config_name / description）
 */
class MarketMakerDetail extends Controller
{
    /**
     * 铺单机器人（config_name 以库为准；label 为产品语义，与历史库字段名解耦）
     */
    private const MAKER_SCHEMA = [
        ['kind' => 'single', 'label' => '订单最大等待时间（分钟）', 'name' => 'order_max_waiting_time'],
        ['kind' => 'single', 'label' => '初始档位', 'name' => 'hedge_remove_best_level'],
        ['kind' => 'single', 'label' => '差异阈值', 'name' => 'hedge_share_diff_threshold'],
        ['kind' => 'single', 'label' => '尾盘差异阈值', 'name' => 'hedge_share_diff_threshold_end'],
        ['kind' => 'range', 'label' => '铺单卖出比例区间', 'min' => 'hedge_min_sell_percentage', 'max' => 'hedge_max_sell_percentage'],
        ['kind' => 'range', 'label' => '铺单买入比例区间', 'min' => 'hedge_min_buy_percentage', 'max' => 'hedge_max_buy_percentage'],
        ['kind' => 'single', 'label' => '最小铺单份额', 'name' => 'fg_min_order_quantity'],
        ['kind' => 'single', 'label' => '最小铺单金额', 'name' => 'fg_min_order_value'],
    ];

    /**
     * 对冲执行机器人（poly_* + hedge_* 在库中为 trading/hedge，此处仅按 config_name 读写）
     *
     * 2026-05-19 新增 4 个 hedge_* 配置（需求 3.3.3.2）：
     *   - hedge_trigger_threshold        对冲触发阈值
     *   - hedge_tail_disable_enabled     尾盘不对冲开关（0/1，toggle UI）
     *   - hedge_tail_time_threshold_pct  尾盘时间阈值（百分比）
     *   - hedge_poly_low_price_threshold 外盘低价阈值
     */
    private const HEDGE_SCHEMA = [
        ['kind' => 'single', 'label' => '对冲最小份额', 'name' => 'poly_min_order_quantity'],
        ['kind' => 'single', 'label' => '对冲最小金额（U）', 'name' => 'poly_min_order_value'],
        ['kind' => 'single', 'label' => '对冲触发阈值', 'name' => 'hedge_trigger_threshold',
            'hint' => '未对冲份额累计超过此值时触发一次对冲'],
        ['kind' => 'toggle', 'label' => '尾盘不对冲开关', 'name' => 'hedge_tail_disable_enabled',
            'hint' => '仅作用于通用类对冲市场。开启后市场剩余时间百分比低于下方阈值时不对冲'],
        ['kind' => 'single', 'label' => '尾盘时间阈值（%）', 'name' => 'hedge_tail_time_threshold_pct',
            'hint' => '当市场剩余时间百分比（剩余/总时长×100%）低于此值时触发尾盘不对冲'],
        ['kind' => 'single', 'label' => '外盘低价阈值', 'name' => 'hedge_poly_low_price_threshold',
            'hint' => '当 Poly 上 asks 卖单最优档价格低于此值时不对冲'],
    ];

    /**
     * 手续费覆盖机器人
     */
    private const FEE_SCHEMA = [
        ['kind' => 'range', 'label' => '触发价格区间', 'min' => 'hedge_general_mid_price_top_level_price_min', 'max' => 'hedge_general_mid_price_top_level_price_max'],
        ['kind' => 'range', 'label' => '目标份额区间', 'min' => 'hedge_general_mid_price_top_level_size_min', 'max' => 'hedge_general_mid_price_top_level_size_max'],
    ];

    /**
     * 做市机器人详情
     * @auth true
     */
    public function index()
    {
        $this->title = '做市机器人详情';

        $makerNames = self::collectConfigNames(self::MAKER_SCHEMA);
        $hedgeNames = self::collectConfigNames(self::HEDGE_SCHEMA);
        $feeNames = self::collectConfigNames(self::FEE_SCHEMA);
        $allNames = array_values(array_unique(array_merge($makerNames, $hedgeNames, $feeNames)));

        $byName = [];
        if ($allNames !== []) {
            $rows = Db::name('config_trade')->whereIn('config_name', $allNames)->select()->toArray();
            foreach ($rows as $r) {
                $byName[(string)$r['config_name']] = $r;
            }
        }

        $this->assign([
            'makerRows' => self::hydrateSchema(self::MAKER_SCHEMA, $byName),
            'hedgeRows' => self::hydrateSchema(self::HEDGE_SCHEMA, $byName),
            'feeRows'   => self::hydrateSchema(self::FEE_SCHEMA, $byName),
        ]);
        return $this->fetch();
    }

    /**
     * 单条编辑（保留给后台直达链接；列表页已不使用）
     *
     * 多分片：config_trade.id 在每个分片是独立自增，但实测各分片同 id ↔ 同 config_name
     * （runtime 部署时同步种子保证）。为了写一致性，这里先按 id 查出 config_name，再按 name 跨片同步写。
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $validate = \think\facade\Validate::rule([
                'id'    => 'require|integer|gt:0',
                'value' => 'require|max:500',
            ])->message([
                'id.require'    => 'ID不能为空',
                'id.integer'    => 'ID必须是整数',
                'id.gt'         => 'ID必须大于0',
                'value.require' => '配置值不能为空',
                'value.max'     => '配置值最多500个字符',
            ]);

            if (!$validate->check($data)) {
                return $this->error($validate->getError());
            }

            $id = intval($data['id']);
            $row = Db::name('config_trade')->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('配置不存在');
            }
            $configName = (string)$row['config_name'];
            $value = (string)$data['value'];
            $now = date('Y-m-d H:i:s');

            // 2PC：phase 1 全 OK 才 commit；任一片失败 → 所有片回滚（强原子）
            $perShard = ShardManager::fanOutWriteInTx(function (string $conn) use ($configName, $value, $now) {
                return (int)Db::connect($conn)->name('config_trade')
                    ->where('config_name', $configName)
                    ->update(['value' => $value, 'updated_at' => $now]);
            });
            $failed = array_filter($perShard, static fn($r) => !($r['ok'] ?? false));
            if (!empty($failed)) {
                return $this->error('部分分片更新失败：' . json_encode($failed, JSON_UNESCAPED_UNICODE));
            }
            $totalAffected = 0;
            foreach ($perShard as $r) $totalAffected += (int)($r['result'] ?? 0);
            return json([
                'code' => 1,
                'info' => '更新成功（' . count($perShard) . ' 分片各 ' . $totalAffected . ' 行）',
                'url'  => '',
                'per_shard' => $perShard,
            ]);
        }

        $id = intval($this->request->get('id', 0));
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $vo = Db::name('config_trade')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('配置不存在');
        }

        $this->assign(['vo' => $vo]);
        return $this->fetch('form');
    }

    /**
     * 按弹窗保存：POST robot_type + fields[config_name]=value（仅允许各 SCHEMA 中的 config_name）
     * @auth true
     */
    public function saveGroup()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 0, 'info' => '请求方式错误']);
        }

        $type = (string)$this->request->post('robot_type', '');
        $schema = match ($type) {
            'maker' => self::MAKER_SCHEMA,
            'hedge' => self::HEDGE_SCHEMA,
            'fee'   => self::FEE_SCHEMA,
            default => null,
        };
        if ($schema === null) {
            return json(['code' => 0, 'info' => '参数类型无效']);
        }

        $fields = $this->request->post('fields', []);
        if (!is_array($fields) || $fields === []) {
            return json(['code' => 0, 'info' => '没有可保存的数据']);
        }

        $allowed = self::collectConfigNames($schema);
        $allowedSet = array_fill_keys($allowed, true);

        // 校验 + 收集要写的 (name, value)
        $now = date('Y-m-d H:i:s');
        $toWrite = [];
        foreach ($fields as $name => $value) {
            $name = (string)$name;
            if ($name === '' || !isset($allowedSet[$name])) {
                continue;
            }
            $value = is_scalar($value) ? trim((string)$value) : '';
            if (strlen($value) > 500) {
                return json(['code' => 0, 'info' => '配置值最多500个字符：' . $name]);
            }
            $toWrite[$name] = $value;
        }
        if (empty($toWrite)) {
            return json(['code' => 0, 'info' => '没有可保存的数据']);
        }

        // Pre-check：确认每个 config_name 在每片都存在。否则 UPDATE 0 affected 会被误以为"保存成功"
        // 触发场景：新加的 hedge_* 字段 SQL 还没在 prod 跑，但 PHP schema 先上线了 → 这里拦下
        $missing = ShardManager::missingAcrossShards('config_trade', 'config_name', array_keys($toWrite));
        if (!empty($missing)) {
            return json([
                'code' => 0,
                'info' => '部分配置在以下分片不存在，请先执行 scripts/*.sql 初始化所有分片再保存：'
                    . json_encode($missing, JSON_UNESCAPED_UNICODE),
                'missing' => $missing,
            ]);
        }

        // 跨片同步写：2PC（任一片失败 → 全部回滚，强原子）
        $perShard = ShardManager::fanOutWriteInTx(function (string $conn) use ($toWrite, $now) {
            $affected = 0;
            foreach ($toWrite as $name => $value) {
                $r = Db::connect($conn)->name('config_trade')
                    ->where('config_name', $name)
                    ->update(['value' => $value, 'updated_at' => $now]);
                $affected += (int)$r;
            }
            return ['affected' => $affected];
        });

        $failed = array_filter($perShard, static fn($r) => !($r['ok'] ?? false));
        if (!empty($failed)) {
            return json([
                'code' => 0,
                'info' => '部分分片保存失败：' . json_encode($failed, JSON_UNESCAPED_UNICODE),
                'per_shard' => $perShard,
            ]);
        }
        $totalAffected = 0;
        foreach ($perShard as $r) $totalAffected += (int)($r['result']['affected'] ?? 0);
        return json([
            'code' => 1,
            'info' => '配置已保存（' . count($perShard) . ' 个分片，合计 ' . $totalAffected . ' 行）',
            'url' => '',
            'per_shard' => $perShard,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function collectConfigNames(array $schema): array
    {
        $names = [];
        foreach ($schema as $row) {
            $kind = $row['kind'] ?? '';
            // single 和 toggle 都是单 name（toggle 在 schema/view 层区别 UI，DB 层和 single 一样）
            if ($kind === 'single' || $kind === 'toggle') {
                $names[] = (string)($row['name'] ?? '');
            } elseif ($kind === 'range') {
                $names[] = (string)($row['min'] ?? '');
                $names[] = (string)($row['max'] ?? '');
            }
        }

        return array_values(array_filter(array_unique($names)));
    }

    /**
     * @param array<string,array<string,mixed>> $byConfigName
     * @return list<array<string,mixed>>
     */
    private static function hydrateSchema(array $schema, array $byConfigName): array
    {
        $out = [];
        foreach ($schema as $def) {
            $kind = $def['kind'] ?? '';
            if ($kind === 'single' || $kind === 'toggle') {
                $n = (string)($def['name'] ?? '');
                $row = $byConfigName[$n] ?? null;
                $out[] = [
                    'kind'  => $kind,  // 'single' 或 'toggle'，view 据此分支渲染（input vs switch）
                    'label' => (string)($def['label'] ?? ''),
                    'name'  => $n,
                    'value' => $row !== null ? (string)($row['value'] ?? '') : '',
                    'hint'  => (string)($def['hint'] ?? ''),
                ];
            } elseif ($kind === 'range') {
                $mn = (string)($def['min'] ?? '');
                $mx = (string)($def['max'] ?? '');
                $r1 = $byConfigName[$mn] ?? null;
                $r2 = $byConfigName[$mx] ?? null;
                $out[] = [
                    'kind'      => 'range',
                    'label'     => (string)($def['label'] ?? ''),
                    'minName'   => $mn,
                    'maxName'   => $mx,
                    'minValue'  => $r1 !== null ? (string)($r1['value'] ?? '') : '',
                    'maxValue'  => $r2 !== null ? (string)($r2['value'] ?? '') : '',
                ];
            }
        }

        return $out;
    }
}
