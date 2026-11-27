<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\trait\ScarbAccountTrait;
use think\admin\Controller;
use think\db\Query;

class ConfigConvert extends Controller
{
    use ScarbAccountTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '兑换配置';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('id', 'asc');
        $result      = $this->paginateScarb($query, $get, 'config_convert/index', 50);
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

            $dup = $this->scarbDb()->name('config_convert')
                ->where('exchange_account_id', $data['exchange_account_id'])
                ->where('from_asset', $data['from_asset'])
                ->where('to_asset', $data['to_asset'])
                ->find();
            if (!empty($dup)) {
                return $this->error('同账户下该币对已存在');
            }

            $this->scarbDb()->name('config_convert')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', ['from_asset' => 'USDT', 'to_asset' => 'BFUSD', 'status' => 1]);
        $this->assign('accounts', $this->loadAccountOptions());

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

            $dup = $this->scarbDb()->name('config_convert')
                ->where('exchange_account_id', $data['exchange_account_id'])
                ->where('from_asset', $data['from_asset'])
                ->where('to_asset', $data['to_asset'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('同账户下该币对已存在');
            }

            $this->scarbDb()->name('config_convert')->where('id', $id)->update($data);

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
        $this->assign('accounts', $this->loadAccountOptions());

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('config_convert');
        $this->applyCommonListFilters($query, $get, 'updated_at');

        if (!empty($get['from_asset'])) {
            $a = trim((string)$get['from_asset']);
            if ($a !== '') {
                $query->where('from_asset', $a);
            }
        }
        if (!empty($get['to_asset'])) {
            $a = trim((string)$get['to_asset']);
            if ($a !== '') {
                $query->where('to_asset', $a);
            }
        }
        if (isset($get['status']) && $get['status'] !== '') {
            $query->where('status', (int)$get['status']);
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->scarbDb()->name('config_convert')->where('id', $id)->find();
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
        $accountId = $this->sanitizeId($post['exchange_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择交易所账户');
        }
        $platform = $this->resolveAccountPlatform($accountId);
        if ($platform === null) {
            throw new \InvalidArgumentException('账户不存在');
        }

        $from = trim((string)($post['from_asset'] ?? ''));
        $to   = trim((string)($post['to_asset'] ?? ''));
        if ($from === '' || strlen($from) > 16 || $to === '' || strlen($to) > 16) {
            throw new \InvalidArgumentException('from_asset / to_asset 无效');
        }

        $minFrom = $this->optionalDecimal((string)($post['min_from_qty'] ?? ''));
        $maxFrom = $this->optionalDecimal((string)($post['max_from_qty'] ?? ''));

        $status = (int)($post['status'] ?? 1);
        if ($status !== 0 && $status !== 1) {
            throw new \InvalidArgumentException('status 须为 0 或 1');
        }

        $extra = trim((string)($post['extra_json'] ?? ''));
        $extraJson = null;
        if ($extra !== '') {
            json_decode($extra);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('extra_json 须为合法 JSON');
            }
            $extraJson = $extra;
        }

        return [
            'exchange_account_id' => $accountId,
            'platform'              => $platform,
            'from_asset'            => $from,
            'to_asset'              => $to,
            'min_from_qty'          => $minFrom,
            'max_from_qty'          => $maxFrom,
            'status'                => $status,
            'extra_json'            => $extraJson,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['min_from_qty_fmt'] = $this->trimDecimal($row['min_from_qty'] ?? null, 8);
        $row['max_from_qty_fmt'] = $this->trimDecimal($row['max_from_qty'] ?? null, 8);
        $row['updated_at']       = $this->formatDatetime($row['updated_at'] ?? null);
        $st                      = (int)($row['status'] ?? 1);
        $row['status_text']      = $st === 1 ? '启用' : '停用';

        return $row;
    }
}
