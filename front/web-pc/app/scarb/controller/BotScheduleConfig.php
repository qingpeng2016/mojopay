<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class BotScheduleConfig extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '脚本调度';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)
            ->order('module', 'asc')
            ->orderRaw('IFNULL(exe_sort, 999999) ASC')
            ->order('id', 'asc');
        $result      = $this->paginateScarb($query, $get, 'bot_schedule_config/index', 50);
        $list        = $result->items();
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
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('bot_schedule_config')
                ->where('module', $data['module'])
                ->where('task_name', $data['task_name'])
                ->find();
            if (!empty($dup)) {
                return $this->error('同模块下任务名已存在');
            }

            $this->scarbDb()->name('bot_schedule_config')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'module'                => 'sc_arb',
            'interval_seconds'      => '60',
            'is_enabled'            => 1,
            'is_strategy_enabled'   => 1,
            'is_primary_machine_run'=> 0,
        ]);

        return $this->fetch('form');
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
            if ($this->loadRow($id) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('bot_schedule_config')
                ->where('module', $data['module'])
                ->where('task_name', $data['task_name'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('同模块下任务名已存在');
            }

            $this->scarbDb()->name('bot_schedule_config')->where('id', $id)->update($data);

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
     * @auth true
     */
    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }
        if ($this->loadRow($id) === null) {
            return $this->error('记录不存在');
        }

        $deleted = $this->scarbDb()->name('bot_schedule_config')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('bot_schedule_config');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
        if (!empty($get['module'])) {
            $module = trim((string)$get['module']);
            if ($module !== '' && strlen($module) <= 50) {
                $query->where('module', $module);
            }
        }
        if (!empty($get['task_name'])) {
            $name = trim((string)$get['task_name']);
            if ($name !== '') {
                $query->whereLike('task_name', '%' . $this->sanitizeLike($name, 100) . '%');
            }
        }
        $enabled = $this->sanitizeEnum((string)($get['is_enabled'] ?? ''), Enum::getAccountStatuses());
        if ($enabled !== null) {
            $query->where('is_enabled', (int)$enabled);
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->scarbDb()->name('bot_schedule_config')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $module = trim((string)($post['module'] ?? ''));
        if ($module === '' || strlen($module) > 50) {
            throw new \InvalidArgumentException('module 不能为空且不超过 50 字符');
        }

        $taskName = trim((string)($post['task_name'] ?? ''));
        if ($taskName === '' || strlen($taskName) > 100) {
            throw new \InvalidArgumentException('task_name 不能为空且不超过 100 字符');
        }

        $interval = $this->requiredDecimal((string)($post['interval_seconds'] ?? ''), 'interval_seconds');
        if ((float)$interval <= 0) {
            throw new \InvalidArgumentException('interval_seconds 须大于 0');
        }

        $isEnabled = $this->sanitizeEnum((string)($post['is_enabled'] ?? ''), Enum::getAccountStatuses());
        $isStrategy = $this->sanitizeEnum((string)($post['is_strategy_enabled'] ?? ''), Enum::getAccountStatuses());
        $isPrimary = $this->sanitizeEnum((string)($post['is_primary_machine_run'] ?? ''), Enum::getAccountStatuses());
        if ($isEnabled === null || $isStrategy === null || $isPrimary === null) {
            throw new \InvalidArgumentException('启用开关须为 0 或 1');
        }

        $desc = trim((string)($post['description'] ?? ''));
        if (strlen($desc) > 500) {
            throw new \InvalidArgumentException('description 不超过 500 字符');
        }

        return [
            'module'                 => $module,
            'task_name'              => $taskName,
            'interval_seconds'       => $interval,
            'exe_sort'               => $this->optionalInt($post['exe_sort'] ?? null),
            'concurrency'            => $this->optionalInt($post['concurrency'] ?? null),
            'description'            => $desc === '' ? null : $desc,
            'is_enabled'             => (int)$isEnabled,
            'is_strategy_enabled'    => (int)$isStrategy,
            'is_primary_machine_run' => (int)$isPrimary,
        ];
    }

    /** @return int|null */
    private function optionalInt($value): ?int
    {
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $s)) {
            throw new \InvalidArgumentException('整数格式无效');
        }

        return (int)$s;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['interval_seconds_fmt'] = $this->trimDecimal($row['interval_seconds'] ?? null, 6);
        $row['interval_seconds']     = ($row['interval_seconds'] === null || $row['interval_seconds'] === '')
            ? ''
            : $this->trimDecimal($row['interval_seconds'], 6);
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
