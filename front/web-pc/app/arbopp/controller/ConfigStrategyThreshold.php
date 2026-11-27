<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class ConfigStrategyThreshold extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '扫描阈值';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('config_key', 'asc'),
            $get,
            'config_strategy_threshold/index'
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
            if ($this->arboppDb()->name('config_strategy_threshold')->where('config_key', $data['config_key'])->find()) {
                return $this->error('config_key 已存在');
            }
            $this->arboppDb()->name('config_strategy_threshold')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', ['value_type' => 'DECIMAL', 'status' => 1]);
        $this->assign('value_types', Enum::getConfigValueTypes());
        $this->assign('tiny_status', Enum::getTinyStatuses());

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
            $dup = $this->arboppDb()->name('config_strategy_threshold')
                ->where('config_key', $data['config_key'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('config_key 已存在');
            }
            $this->arboppDb()->name('config_strategy_threshold')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $this->assign('vo', $vo);
        $this->assign('value_types', Enum::getConfigValueTypes());
        $this->assign('tiny_status', Enum::getTinyStatuses());

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('config_strategy_threshold');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'config_key', 'config_key', 64);

        return $query;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $key = trim((string)($post['config_key'] ?? ''));
        if ($key === '' || strlen($key) > 64) {
            throw new \InvalidArgumentException('config_key 无效');
        }
        $val = trim((string)($post['config_value'] ?? ''));
        if ($val === '' || !is_numeric($val)) {
            throw new \InvalidArgumentException('config_value 须为数字');
        }
        $valueType = $this->sanitizeEnum((string)($post['value_type'] ?? 'DECIMAL'), Enum::getConfigValueTypes());
        if ($valueType === null) {
            throw new \InvalidArgumentException('value_type 无效');
        }
        $status = $this->sanitizeEnum((string)($post['status'] ?? '1'), Enum::getTinyStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 须为 0 或 1');
        }
        $desc = trim((string)($post['description'] ?? ''));

        return [
            'config_key'   => $key,
            'config_value' => $val,
            'value_type'   => $valueType,
            'description'  => $desc === '' ? null : $desc,
            'status'       => (int)$status,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->arboppDb()->name('config_strategy_threshold')->where('id', $id)->find();

        return empty($row) ? null : $this->formatRow($row);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['config_value_fmt'] = $this->formatDecimalField($row['config_value'] ?? null);
        $row['updated_at']       = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}