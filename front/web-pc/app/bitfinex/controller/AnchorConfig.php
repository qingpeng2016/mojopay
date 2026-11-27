<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 3.5.2 利率锚定参数（funding_config_strategy.group_name = anchor）111
 */
class AnchorConfig extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '利率锚定参数';

        $rows = Db::connect('db_bitfinex')
            ->name('funding_config_strategy')
            ->where('group_name', Enum::ANCHOR_GROUP_NAME)
            ->whereNotIn('item_key', Enum::getAnchorExcludedItemKeys())
            ->order('sort_no', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['created_at'] = !empty($row['created_at'])
                ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
            $row['updated_at'] = !empty($row['updated_at'])
                ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';
        }
        unset($row);

        $this->assign('list', $rows);
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

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch();
    }

    /**
     * 修改参数值
     *
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

            $itemValue = trim((string)$this->request->post('item_value', ''));

            $err = $this->validateItemValue($itemValue, (string)$vo['value_type']);
            if ($err !== null) {
                return $this->error($err);
            }

            $affected = Db::connect('db_bitfinex')
                ->name('funding_config_strategy')
                ->where('id', $id)
                ->where('group_name', Enum::ANCHOR_GROUP_NAME)
                ->update([
                    'item_value' => $itemValue,
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
            ->name('funding_config_strategy')
            ->where('id', $id)
            ->where('group_name', Enum::ANCHOR_GROUP_NAME)
            ->whereNotIn('item_key', Enum::getAnchorExcludedItemKeys())
            ->find();

        if (empty($row)) {
            return null;
        }

        $row['created_at'] = !empty($row['created_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

        return $row;
    }

    private function validateItemValue(string $value, string $valueType): ?string
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
