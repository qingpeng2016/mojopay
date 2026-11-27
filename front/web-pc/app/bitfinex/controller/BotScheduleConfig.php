<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * bot_schedule_config（Bitfinex 调度任务）
 */
class BotScheduleConfig extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '调度任务配置';
        $get         = $this->request->get();

        $query = Db::connect('db_bitfinex')->name('bot_schedule_config');

        $module = trim((string)($get['module'] ?? Enum::BOT_SCHEDULE_MODULE));
        if ($module !== '') {
            $query->where('module', $module);
        }

        $taskName = trim((string)($get['task_name'] ?? ''));
        if ($taskName !== '') {
            $query->whereLike('task_name', '%' . $this->sanitizeLike($taskName, 100) . '%');
        }

        $isEnabled = $this->sanitizeEnum($get['is_enabled'] ?? '', ['0', '1']);
        if ($isEnabled !== null) {
            $query->where('is_enabled', (int)$isEnabled);
        }

        $result = $query->orderRaw('exe_sort IS NULL, exe_sort ASC, id ASC')->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 50,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/bot_schedule_config/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id) {
                return $this->error('参数错误');
            }

            $vo = $this->loadRow($id);
            if ($vo === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildUpdateData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $duplicate = Db::connect('db_bitfinex')
                ->name('bot_schedule_config')
                ->where('module', $data['module'])
                ->where('task_name', $data['task_name'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($duplicate)) {
                return $this->error('同 module 下任务名已存在');
            }

            $affected = Db::connect('db_bitfinex')
                ->name('bot_schedule_config')
                ->where('id', $id)
                ->update($data);

            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('bot_schedule_config')
            ->where('id', $id)
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
        $heartbeat = $row['last_live_time_by_machine'] ?? null;
        if (is_string($heartbeat) && $heartbeat !== '') {
            $decoded = json_decode($heartbeat, true);
            $heartbeat = json_last_error() === JSON_ERROR_NONE ? $decoded : $heartbeat;
        }
        $row['machine_heartbeat'] = is_array($heartbeat)
            ? json_encode($heartbeat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : (string)($heartbeat ?? '');

        $row['created_at'] = !empty($row['created_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildUpdateData(array $post): array
    {
        $module = trim((string)($post['module'] ?? ''));
        if ($module === '' || mb_strlen($module) > 50 || preg_match('/\s/u', $module)) {
            throw new \InvalidArgumentException('module 不能为空、含空白字符或超过 50 字');
        }

        $taskName = trim((string)($post['task_name'] ?? ''));
        if ($taskName === '' || mb_strlen($taskName) > 100 || preg_match('/\s/u', $taskName)) {
            throw new \InvalidArgumentException('task_name 不能为空、含空白字符或超过 100 字');
        }

        $exeSort = trim((string)($post['exe_sort'] ?? ''));
        if ($exeSort !== '' && filter_var($exeSort, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('exe_sort 须为整数或留空');
        }

        $interval = $post['interval_seconds'] ?? '';
        if (!is_numeric($interval) || !is_finite((float)$interval) || (float)$interval <= 0) {
            throw new \InvalidArgumentException('执行间隔须为大于 0 的有限秒数');
        }

        $concurrency = trim((string)($post['concurrency'] ?? ''));
        if ($concurrency !== '' && (filter_var($concurrency, FILTER_VALIDATE_INT) === false || (int)$concurrency < 1)) {
            throw new \InvalidArgumentException('并发数须为正整数或留空');
        }

        $description = trim((string)($post['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('任务描述不能超过 500 字');
        }

        $isEnabled = $this->sanitizeEnum((string)($post['is_enabled'] ?? ''), ['0', '1']);
        $isStrategyEnabled = $this->sanitizeEnum((string)($post['is_strategy_enabled'] ?? ''), ['0', '1']);
        if ($isEnabled === null || $isStrategyEnabled === null) {
            throw new \InvalidArgumentException('任务开关与策略开关须为 0 或 1');
        }

        return [
            'module'              => $module,
            'task_name'           => $taskName,
            'interval_seconds'    => (float)$interval,
            'exe_sort'            => $exeSort === '' ? null : (int)$exeSort,
            'concurrency'         => $concurrency === '' ? null : (int)$concurrency,
            'description'         => $description === '' ? null : $description,
            'is_enabled'          => (int)$isEnabled,
            'is_strategy_enabled' => (int)$isStrategyEnabled,
        ];
    }
}
