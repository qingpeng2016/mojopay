<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class AccountExchange extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '交易所账号';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('id', 'asc');
        $result      = $this->paginateScarb($query, $get, 'account_exchange/index', 50);
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
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
                $data = $this->buildFormData($this->request->post(), false);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('account_exchange')
                ->where('platform', $data['platform'])
                ->where('account_name', $data['account_name'])
                ->find();
            if (!empty($dup)) {
                return $this->error('同平台下账户名已存在');
            }

            $this->scarbDb()->name('account_exchange')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'status'              => 1,
            'asset_convert_enabled'   => 0,
            'hedge_rebalance_enabled' => 0,
            'min_portfolio_uni_mmr'   => '3',
            'account_type'        => 'um_futures_multi_asset',
        ]);
        $this->assign('platforms', Enum::getAllPlatforms());
        $this->assign('account_types', Enum::getAccountTypes());

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
            if ($this->loadRow($id, false) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post(), true);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('account_exchange')
                ->where('platform', $data['platform'])
                ->where('account_name', $data['account_name'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('同平台下账户名已存在');
            }

            $this->scarbDb()->name('account_exchange')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }
        $vo = $this->loadRow($id, true);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        $this->assign('platforms', Enum::getAllPlatforms());
        $this->assign('account_types', Enum::getAccountTypes());

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
        if ($this->loadRow($id, false) === null) {
            return $this->error('记录不存在');
        }

        $deleted = $this->scarbDb()->name('account_exchange')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('account_exchange');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
        if (!empty($get['platform'])) {
            $p = $this->sanitizeEnum($get['platform'], Enum::getAllPlatforms());
            if ($p) {
                $query->where('platform', $p);
            }
        }
        if (!empty($get['account_name'])) {
            $name = trim((string)$get['account_name']);
            if ($name !== '') {
                $query->whereLike('account_name', '%' . $this->sanitizeLike($name, 64) . '%');
            }
        }
        $status = $this->sanitizeEnum($get['status'] ?? '', Enum::getAccountStatuses());
        if ($status !== null) {
            $query->where('status', (int)$status);
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id, bool $forDisplay): ?array
    {
        $row = $this->scarbDb()->name('account_exchange')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row, $forDisplay);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post, bool $isEdit): array
    {
        $platform = $this->sanitizeEnum((string)($post['platform'] ?? ''), Enum::getAllPlatforms());
        if ($platform === null) {
            throw new \InvalidArgumentException('platform 无效');
        }

        $accountName = trim((string)($post['account_name'] ?? ''));
        if ($accountName === '' || strlen($accountName) > 64) {
            throw new \InvalidArgumentException('account_name 不能为空且不超过 64 字符');
        }

        $accountType = $this->sanitizeEnum((string)($post['account_type'] ?? ''), Enum::getAccountTypes());
        if ($accountType === null) {
            throw new \InvalidArgumentException('account_type 无效');
        }

        $status = $this->sanitizeEnum((string)($post['status'] ?? ''), Enum::getAccountStatuses());
        $convertEn = $this->sanitizeEnum((string)($post['asset_convert_enabled'] ?? ''), Enum::getAccountStatuses());
        $hedgeRebal = $this->sanitizeEnum((string)($post['hedge_rebalance_enabled'] ?? ''), Enum::getAccountStatuses());
        if ($status === null || $convertEn === null || $hedgeRebal === null) {
            throw new \InvalidArgumentException('状态开关须为 0 或 1');
        }

        $minMmr = $this->requiredDecimal((string)($post['min_portfolio_uni_mmr'] ?? ''), 'min_portfolio_uni_mmr');
        if ((float)$minMmr <= 0) {
            throw new \InvalidArgumentException('min_portfolio_uni_mmr 须大于 0');
        }

        $originAsset = $this->optionalDecimal((string)($post['origin_portfolio_total_asset_usdt'] ?? ''));
        if ($originAsset !== null && (float)$originAsset < 0) {
            throw new \InvalidArgumentException('origin_portfolio_total_asset_usdt 不能为负数');
        }

        return [
            'platform'                            => $platform,
            'account_name'                        => $accountName,
            'account_type'                        => $accountType,
            'status'                              => (int)$status,
            'asset_convert_enabled'               => (int)$convertEn,
            'hedge_rebalance_enabled'             => (int)$hedgeRebal,
            'min_portfolio_uni_mmr'               => $minMmr,
            'origin_portfolio_total_asset_usdt'   => $originAsset,
            'force_use_quote'                     => $this->forceUseQuote($post['force_use_quote'] ?? ''),
            'sub_account_email'                   => $this->nullableString($post['sub_account_email'] ?? '', 128),
            'remark'                              => $this->nullableString($post['remark'] ?? '', 255),
        ];
    }

    private function forceUseQuote($value): ?string
    {
        $s = strtoupper(trim((string)$value));
        if ($s === '') {
            return null;
        }
        if (strlen($s) > 16) {
            throw new \InvalidArgumentException('force_use_quote 不超过 16 字符');
        }

        return $s;
    }

    private function nullableString($value, int $max): ?string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        if (strlen($s) > $max) {
            throw new \InvalidArgumentException('文本字段过长');
        }

        return $s;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row, bool $maskSecret = false): array
    {
        unset($row['api_key'], $row['api_secret_enc'], $row['api_secret_enc_display']);
        $row['total_asset_usdt_fmt'] = $this->trimDecimal($row['total_asset_usdt'] ?? null, 8);
        $row['portfolio_uni_mmr_fmt'] = $this->trimDecimal($row['portfolio_uni_mmr'] ?? null, 8);
        $row['max_leverage_fmt'] = $this->parseRemarkMaxLeverage($row['remark'] ?? null);
        $origin = $row['origin_portfolio_total_asset_usdt'] ?? null;
        $row['origin_portfolio_total_asset_usdt'] = ($origin === null || $origin === '')
            ? ''
            : $this->trimDecimal($origin, 8);
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }

    /** remark JSON：{"max_leverage":150}（asset_sync 写入） */
    private function parseRemarkMaxLeverage($remark): string
    {
        $s = trim((string)$remark);
        if ($s === '') {
            return '-';
        }
        $data = json_decode($s, true);
        if (!is_array($data) || !isset($data['max_leverage'])) {
            return '-';
        }
        $lev = (int)$data['max_leverage'];
        if ($lev <= 0) {
            return '-';
        }

        return (string)$lev;
    }
}
