<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class ConfigStrategy extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '策略键值';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('group_name', 'asc')->order('sort_no', 'asc')->order('id', 'asc'),
            $get,
            'config_strategy/index'
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign(['list' => $list, 'pagehtml' => $result->render(), 'get' => $get]);

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
            if ($this->dup($data['group_name'], $data['item_key'], null)) {
                return $this->error('同 group + item_key 已存在');
            }
            $this->arboppDb()->name('config_strategy')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'value_type' => 'string',
            'sort_no'    => 0,
            'is_enabled' => 1,
        ]);
        $this->assignFormLookups();

        return $this->fetch('form');
    }

    /**
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id || $this->loadRow($id) === null) {
                return $this->error('记录不存在');
            }
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }
            if ($this->dup($data['group_name'], $data['item_key'], $id)) {
                return $this->error('同 group + item_key 已存在');
            }
            $this->arboppDb()->name('config_strategy')->where('id', $id)->update($data);

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
        $this->assignFormLookups();
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('config_strategy');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'group_name', 'group_name', 64);
        $this->applyOptionalLike($query, $get, 'item_key', 'item_key', 128);

        return $query;
    }

    private function dup(string $group, string $key, ?int $exclude): bool
    {
        $q = $this->arboppDb()->name('config_strategy')->where('group_name', $group)->where('item_key', $key);
        if ($exclude) {
            $q->where('id', '<>', $exclude);
        }

        return !empty($q->find());
    }

    private function assignFormLookups(): void
    {
        $this->assign('value_types', Enum::getConfigValueTypes());
        $this->assign('yes_no', Enum::getYesNo());
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $group = trim((string)($post['group_name'] ?? ''));
        $key   = trim((string)($post['item_key'] ?? ''));
        if ($group === '' || strlen($group) > 64) {
            throw new \InvalidArgumentException('group_name 无效');
        }
        if ($key === '' || strlen($key) > 128) {
            throw new \InvalidArgumentException('item_key 无效');
        }
        $value = (string)($post['item_value'] ?? '');
        if ($value === '') {
            throw new \InvalidArgumentException('item_value 不能为空');
        }
        $valueType = $this->sanitizeEnum((string)($post['value_type'] ?? 'string'), Enum::getConfigValueTypes());
        if ($valueType === null) {
            throw new \InvalidArgumentException('value_type 无效');
        }
        $enabled = $this->sanitizeEnum((string)($post['is_enabled'] ?? '1'), Enum::getYesNo());
        if ($enabled === null) {
            throw new \InvalidArgumentException('is_enabled 须为 0 或 1');
        }
        $sortNo = trim((string)($post['sort_no'] ?? '0'));
        if (!is_numeric($sortNo)) {
            throw new \InvalidArgumentException('sort_no 须为整数');
        }

        return [
            'group_name'  => $group,
            'item_key'    => $key,
            'item_value'  => $value,
            'value_type'  => $valueType,
            'description' => trim((string)($post['description'] ?? '')),
            'sort_no'     => (int)$sortNo,
            'is_enabled'  => (int)$enabled,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->arboppDb()->name('config_strategy')->where('id', $id)->find();

        return empty($row) ? null : $this->formatRow($row);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['item_value_fmt'] = $this->truncateText($row['item_value'] ?? '', 60);
        $row['updated_at']     = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}