<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

/**
 * 策略全局键值 config_common（group_name + item_key 唯一）。
 */
class ConfigCommon extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '全局配置';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)
            ->order('group_name', 'asc')
            ->order('sort_no', 'asc')
            ->order('id', 'asc');
        $result      = $this->paginateScarb($query, $get, 'config_common/index', 50);
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

            $dup = $this->scarbDb()->name('config_common')
                ->where('group_name', $data['group_name'])
                ->where('item_key', $data['item_key'])
                ->find();
            if (!empty($dup)) {
                return $this->error('同分组下该 item_key 已存在');
            }

            $this->scarbDb()->name('config_common')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'value_type' => 'string',
            'sort_no'    => 0,
            'is_enabled' => 1,
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

            $dup = $this->scarbDb()->name('config_common')
                ->where('group_name', $data['group_name'])
                ->where('item_key', $data['item_key'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('同分组下该 item_key 已存在');
            }

            $this->scarbDb()->name('config_common')->where('id', $id)->update($data);

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

        $deleted = $this->scarbDb()->name('config_common')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('config_common');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
        if (!empty($get['group_name'])) {
            $g = trim((string)$get['group_name']);
            if ($g !== '' && strlen($g) <= 64) {
                $query->where('group_name', $g);
            }
        }
        if (!empty($get['item_key'])) {
            $k = trim((string)$get['item_key']);
            if ($k !== '') {
                $query->whereLike('item_key', '%' . $this->sanitizeLike($k, 128) . '%');
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
        $row = $this->scarbDb()->name('config_common')->where('id', $id)->find();
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
        $group = trim((string)($post['group_name'] ?? ''));
        if ($group === '' || strlen($group) > 64) {
            throw new \InvalidArgumentException('group_name 不能为空且不超过 64 字符');
        }

        $itemKey = trim((string)($post['item_key'] ?? ''));
        if ($itemKey === '' || strlen($itemKey) > 128) {
            throw new \InvalidArgumentException('item_key 不能为空且不超过 128 字符');
        }

        $itemValue = (string)($post['item_value'] ?? '');
        if ($itemValue === '') {
            throw new \InvalidArgumentException('item_value 不能为空');
        }

        $valueType = trim((string)($post['value_type'] ?? 'string'));
        if ($valueType === '' || strlen($valueType) > 32) {
            throw new \InvalidArgumentException('value_type 无效');
        }

        $desc = trim((string)($post['description'] ?? ''));
        if (strlen($desc) > 500) {
            throw new \InvalidArgumentException('description 不超过 500 字符');
        }

        $sortNo = 0;
        $sortRaw = trim((string)($post['sort_no'] ?? '0'));
        if ($sortRaw !== '') {
            if (!preg_match('/^-?\d+$/', $sortRaw)) {
                throw new \InvalidArgumentException('sort_no 须为整数');
            }
            $sortNo = (int)$sortRaw;
        }

        $enabled = $this->sanitizeEnum((string)($post['is_enabled'] ?? ''), Enum::getAccountStatuses());
        if ($enabled === null) {
            throw new \InvalidArgumentException('is_enabled 须为 0 或 1');
        }

        return [
            'group_name'  => $group,
            'item_key'    => $itemKey,
            'item_value'  => $itemValue,
            'value_type'  => $valueType,
            'description' => $desc,
            'sort_no'     => $sortNo,
            'is_enabled'  => (int)$enabled,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);
        $st                = (int)($row['is_enabled'] ?? 1);
        $row['is_enabled_text'] = $st === 1 ? '启用' : '停用';
        $val = (string)($row['item_value'] ?? '');
        if (mb_strlen($val) > 80) {
            $row['item_value_short'] = mb_substr($val, 0, 80) . '…';
        } else {
            $row['item_value_short'] = $val;
        }

        return $row;
    }
}
