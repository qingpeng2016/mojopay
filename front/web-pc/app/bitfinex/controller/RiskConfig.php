<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 3.5.5 风控参数（funding_config_strategy placement/risk）
 */
class RiskConfig extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '风控参数';

        $strategyRows = Db::connect('db_bitfinex')
            ->name('funding_config_strategy')
            ->whereIn('group_name', Enum::getRiskPageFundingConfigGroups())
            ->order('group_name', 'asc')
            ->order('sort_no', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($strategyRows as &$strategyRow) {
            $strategyRow = $this->formatStrategyRow($strategyRow);
        }
        unset($strategyRow);

        $this->assign('strategyList', $strategyRows);
        return $this->fetch();
    }

    /**
     * 展示（只读）
     *
     * @auth true
     */
    public function detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        if ($this->isStrategyScope($this->request->get('scope'))) {
            $vo = $this->loadStrategyRow($id);
            if ($vo === null) {
                return $this->error('记录不存在');
            }
            $this->assign('vo', $vo);
            return $this->fetch('strategy_detail');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch();
    }

    /**
     * 修改（报警 interval 或 funding_config_strategy，见 scope）
     *
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            if ($this->isStrategyScope($this->request->post('scope'))) {
                return $this->saveStrategyEdit();
            }

            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id) {
                return $this->error('参数错误');
            }

            $vo = $this->loadRow($id);
            if ($vo === null) {
                return $this->error('记录不存在');
            }

            $hoursRaw = trim((string)$this->request->post('interval_hours', ''));
            $err = $this->validateIntervalHours($hoursRaw);
            if ($err !== null) {
                return $this->error($err);
            }

            $intervalSeconds = $this->hoursToSeconds($hoursRaw);
            $affected = Db::connect('db_bitfinex')
                ->name('bot_schedule_config')
                ->where('id', $id)
                ->where('module', Enum::BOT_SCHEDULE_MODULE)
                ->where('task_name', Enum::BOT_SCHEDULE_TASK_LAYER_LENDING_BUDGET_ALERT)
                ->update([
                    'interval_seconds' => $intervalSeconds,
                ]);

            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        if ($this->isStrategyScope($this->request->get('scope'))) {
            $vo = $this->loadStrategyRow($id);
            if ($vo === null) {
                return $this->error('记录不存在');
            }
            $this->assign('vo', $vo);
            return $this->fetch('strategy_form');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /**
     * @auth true
     * @deprecated 请用 detail?scope=strategy
     */
    public function strategyDetail()
    {
        $_GET['scope'] = 'strategy';
        return $this->detail();
    }

    /**
     * @auth true
     * @deprecated 请用 edit?scope=strategy
     */
    public function strategyEdit()
    {
        if (!$this->request->isPost()) {
            $_GET['scope'] = 'strategy';
        }
        return $this->edit();
    }

    private function saveStrategyEdit()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadStrategyRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $itemValue = trim((string)$this->request->post('item_value', ''));
        $itemKey   = (string)($vo['item_key'] ?? '');

        $err = $this->validateStrategyItemValue($itemValue, (string)$vo['value_type'], $itemKey);
        if ($err !== null) {
            return $this->error($err);
        }

        $affected = Db::connect('db_bitfinex')
            ->name('funding_config_strategy')
            ->where('id', $id)
            ->whereIn('group_name', Enum::getRiskPageFundingConfigGroups())
            ->update([
                'item_value' => $itemValue,
            ]);

        if ($affected < 1) {
            return $this->error('未更新任何记录');
        }

        return $this->success('保存成功');
    }

    private function isStrategyScope($scope): bool
    {
        return trim((string)$scope) === 'strategy';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('bot_schedule_config')
            ->where('id', $id)
            ->where('module', Enum::BOT_SCHEDULE_MODULE)
            ->where('task_name', Enum::BOT_SCHEDULE_TASK_LAYER_LENDING_BUDGET_ALERT)
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $seconds = (int)($row['interval_seconds'] ?? 0);
        $row['interval_hours_display'] = $this->secondsToHours($seconds);
        $row['created_at'] = !empty($row['created_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

        return $row;
    }

    private function secondsToHours(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0';
        }
        if ($seconds % 3600 === 0) {
            return (string)($seconds / 3600);
        }

        return rtrim(rtrim(sprintf('%.6f', $seconds / 3600), '0'), '.');
    }

    private function hoursToSeconds(string $hours): int
    {
        return (int) round((float)$hours * 3600);
    }

    private function validateIntervalHours(string $hours): ?string
    {
        if ($hours === '' || !is_numeric($hours)) {
            return '执行间隔必须是数字（单位：小时）';
        }
        if ((float)$hours <= 0) {
            return '执行间隔必须大于 0 小时';
        }
        if ($this->hoursToSeconds($hours) < 1) {
            return '执行间隔换算后至少为 1 秒';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadStrategyRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('funding_config_strategy')
            ->where('id', $id)
            ->whereIn('group_name', Enum::getRiskPageFundingConfigGroups())
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatStrategyRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatStrategyRow(array $row): array
    {
        $row['created_at'] = !empty($row['created_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';
        $itemKey = (string)($row['item_key'] ?? '');
        $row['value_hint']       = $this->strategyValueHint($itemKey);
        $row['show_value_hint']  = $row['value_hint'] !== '' ? 1 : 0;
        $row['description_text'] = htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8');

        return $row;
    }

    private function strategyValueHint(string $itemKey): string
    {
        if ($itemKey === Enum::OBLIGATION_DAY_CUTOFF_HOUR_UTC_ITEM_KEY) {
            return 'UTC 小时 0–23；时刻 hour≥该值计为下一自然日（默认 10≈北京 18:00）。改后需重跑流动性计算与铺单才生效。';
        }

        return '';
    }

    private function validateStrategyItemValue(string $value, string $valueType, string $itemKey): ?string
    {
        if ($itemKey === Enum::OBLIGATION_DAY_CUTOFF_HOUR_UTC_ITEM_KEY) {
            if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
                return '负债日界必须是整数（0–23）';
            }
            $hour = (int)$value;
            if ($hour < 0 || $hour > 23) {
                return '负债日界必须在 0–23 之间（24 无效，策略会回退默认 10）';
            }

            return null;
        }

        return $this->validateFundingConfigItemValue($value, $valueType);
    }

    private function validateFundingConfigItemValue(string $value, string $valueType): ?string
    {
        switch ($valueType) {
            case 'int':
                if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
                    return '参数值必须是整数';
                }
                break;
            case 'float':
                if ($value === '' || !is_numeric($value)) {
                    return '参数值必须是数字';
                }
                break;
            case 'bool':
                if (!in_array($value, ['0', '1', 'true', 'false'], true)) {
                    return '参数值必须是 0/1 或 true/false';
                }
                break;
            case 'string':
                if ($value === '') {
                    return '参数值不能为空';
                }
                break;
            default:
                if ($value === '') {
                    return '参数值不能为空';
                }
        }

        return null;
    }
}
