<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 3.5.1 主页面板：accounts 汇总 + 分页列表；可修改可借贷金额
 */
class Dashboard extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'Bitfinex 主页面板';
        $get = $this->request->get();

        // 仪表盘汇总（与筛选条件一致；余额字段为库内缓存快照）
        $sumFundingAvailable = (float) ($this->filteredAccountsQuery($get)->sum('funding_available_balance') ?: 0);
        $sumPositionBalance  = (float) ($this->filteredAccountsQuery($get)->sum('total_funding_position_balance') ?: 0);
        $stats = [
            'sum_funding_balance'           => (float) ($this->filteredAccountsQuery($get)->sum('funding_balance') ?: 0),
            'sum_funding_available_balance' => $sumFundingAvailable,
            'sum_position_balance'          => $sumPositionBalance,
            'sum_freeze_balance'            => (float) ($this->filteredAccountsQuery($get)->sum('total_funding_freeze_balance') ?: 0),
            'sum_remaining_lendable'        => $sumFundingAvailable - $sumPositionBalance,
        ];
        $walletYieldItems = $this->loadWalletYieldItems();
        $pageQuery = $this->filteredAccountsQuery($get)->order('id asc');
        $result    = $pageQuery->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 20,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/dashboard/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row['created_at'] = !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
            $row['updated_at'] = !empty($row['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';
        }

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'stats'    => $stats,
            'wallet_yield_items' => $walletYieldItems,
            'statuses' => array_combine(Enum::getAllAccountStatuses(), Enum::getAllAccountStatuses()),
            'account_types' => array_combine(Enum::getAllAccountTypes(), Enum::getAllAccountTypes()),
            'vendors'       => array_combine(Enum::getAllVendors(), Enum::getAllVendors()),
        ]);

        return $this->fetch();
    }

    /**
     * 修改可借贷金额（funding_available_balance）
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

            $rawValue = trim((string)$this->request->post('funding_available_balance', ''));
            $err = $this->validateAvailableBalance($rawValue, (string)$vo['funding_balance']);
            if ($err !== null) {
                return $this->error($err);
            }

            $normalized = $this->normalizeDecimal($rawValue);
            $affected = Db::connect('db_bitfinex')
                ->name('accounts')
                ->where('id', $id)
                ->update([
                    'funding_available_balance' => $normalized,
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
            ->name('accounts')
            ->where('id', $id)
            ->find();

        if (empty($row)) {
            return null;
        }

        $row['funding_balance_fmt'] = $this->formatDecimal((string)$row['funding_balance']);
        $row['funding_available_balance_fmt'] = $this->formatDecimal((string)$row['funding_available_balance']);
        $row['created_at'] = !empty($row['created_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['created_at'])) : '-';
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

        return $row;
    }

    private function validateAvailableBalance(string $value, string $fundingBalance): ?string
    {
        if ($value === '' || !is_numeric($value)) {
            return '可借贷金额必须是数字1';
        }
        if (bccomp($value, '0', 8) < 0) {
            return '可借贷金额不能为负数';
        }
        if (bccomp($value, $fundingBalance, 8) > 0) {
            return '可借贷金额不能超过借贷钱包金额（' . $this->formatDecimal($fundingBalance) . '）';
        }

        return null;
    }

    private function normalizeDecimal(string $value): string
    {
        return bcadd($value, '0', 8);
    }

    private function formatDecimal(string $value): string
    {
        return bcadd($value, '0', 8);
    }

    /**
     * @return list<array{currency: string, rate_fmt: string, profit_fmt: string}>
     */
    private function loadWalletYieldItems(): array
    {
        $rows = Db::connect('db_bitfinex')
            ->name('funding_wallet_yield')
            ->whereIn('currency', ['USD', 'UST'])
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $currency = (string)($row['currency'] ?? '');
            if ($currency === '') {
                continue;
            }
            $profit = (float)($row['total_interest'] ?? 0);
            $profitFmt = rtrim(rtrim(number_format($profit, 8, '.', ''), '0'), '.');
            $map[$currency] = [
                'rate_fmt'   => number_format((float)($row['annual_yield_rate'] ?? 0) * 100, 2, '.', '') . '%',
                'profit_fmt' => $profitFmt === '' ? '0' : $profitFmt,
            ];
        }

        $items = [];
        foreach (['UST', 'USD'] as $currency) {
            if (isset($map[$currency])) {
                $items[] = [
                    'currency'   => $currency,
                    'rate_fmt'   => $map[$currency]['rate_fmt'],
                    'profit_fmt' => $map[$currency]['profit_fmt'],
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredAccountsQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name('accounts');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['vendor'])) {
            $v = $this->sanitizeEnum($get['vendor'], Enum::getAllVendors());
            if ($v) {
                $query->where('vendor', $v);
            }
        }

        if (!empty($get['account_type'])) {
            $t = $this->sanitizeEnum($get['account_type'], Enum::getAllAccountTypes());
            if ($t) {
                $query->where('account_type', $t);
            }
        }

        if (!empty($get['status'])) {
            $s = $this->sanitizeEnum($get['status'], Enum::getAllAccountStatuses());
            if ($s) {
                $query->where('status', $s);
            }
        }

        if (!empty($get['keyword'])) {
            $kw = $this->sanitizeLike((string)$get['keyword']);
            $query->where(function ($q) use ($kw) {
                $q->where('account_name', 'like', "%{$kw}%")->whereOr('external_ref', 'like', "%{$kw}%");
            });
        }

        return $query;
    }
}
