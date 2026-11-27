<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\service\FundApiClient;
use app\bitfinex\trait\BitfinexWalletOpsTrait;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * Bitfinex 主账号管理（fund_bitfinex_master_accounts）
 */
class BitfinexMainAccount extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;
    use BitfinexWalletOpsTrait;

    private const ACCOUNT_TYPE = 'master';

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'Bitfinex主账号管理';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('id', 'asc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/bitfinex_main_account/index.html',
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
            'statuses' => Enum::getBitfinexAccountStatusLabels(),
        ]);

        return $this->fetch();
    }

    /**
     * 划转
     *
     * @auth true
     */
    public function transfer()
    {
        if ($this->request->isPost()) {
            return $this->handleTransferPost();
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadAccount($id);
        if ($vo === null) {
            return $this->error('主账号不存在');
        }

        $balances = $this->loadMasterWalletBalances($id);
        $this->assign([
            'vo'              => $this->formatRow($vo),
            'from_account_id' => $id,
            'balances'        => $balances,
            'balances_json'   => json_encode($balances, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->fetch('transfer_form');
    }

    /**
     * 兑换
     *
     * @auth true
     */
    public function exchange()
    {
        if ($this->request->isPost()) {
            return $this->handleExchangePost();
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadAccount($id);
        if ($vo === null) {
            return $this->error('主账号不存在');
        }

        $balances = $this->loadMasterWalletBalances($id);
        $this->assign([
            'vo'              => $this->formatRow($vo),
            'account_type'    => self::ACCOUNT_TYPE,
            'account_id'      => $id,
            'balances'        => $balances,
            'balances_json'   => json_encode($balances, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->fetch('exchange_form');
    }

    private function handleTransferPost()
    {
        $fromId = $this->sanitizeId($this->request->post('from_account_id'));
        if (!$fromId || $this->loadAccount($fromId) === null) {
            return $this->error('主账号不存在');
        }

        $fromWallet = $this->sanitizeEnum($this->request->post('from_wallet'), ['funding', 'exchange']);
        $toWallet   = $this->sanitizeEnum($this->request->post('to_wallet'), ['funding', 'exchange']);
        if (!$fromWallet || !$toWallet) {
            return $this->error('请选择钱包类型');
        }
        if ($fromWallet === $toWallet) {
            return $this->error('转出钱包与转入钱包不能相同');
        }

        $currency = $this->sanitizeEnum(strtoupper((string)$this->request->post('currency', '')), ['USD', 'USDT']);
        if (!$currency) {
            return $this->error('请选择币种');
        }

        $amount = $this->parsePositiveAmount($this->request->post('amount'));
        if ($amount === null) {
            return $this->error('划转金额必须大于 0');
        }

        $balances  = $this->loadMasterWalletBalances($fromId);
        $available = $this->availableWalletAmount($balances, $currency, $fromWallet);
        if ((float)$amount > $available + 1e-12) {
            return $this->error(sprintf(
                '余额不足：%s/%s 可用 %.8f，申请划转 %s',
                $currency,
                $fromWallet,
                $available,
                $amount
            ));
        }

        // 仅本账号 funding ↔ exchange
        $result = (new FundApiClient())->accountTransfer(
            self::ACCOUNT_TYPE,
            $fromId,
            self::ACCOUNT_TYPE,
            $fromId,
            $fromWallet,
            $toWallet,
            $amount,
            $currency
        );
        if (!$result['ok']) {
            return $this->error('划转失败：' . $result['message']);
        }

        return $this->success('划转提交成功：' . $result['message']);
    }

    private function handleExchangePost()
    {
        $id = $this->sanitizeId($this->request->post('account_id'));
        if (!$id || $this->loadAccount($id) === null) {
            return $this->error('主账号不存在');
        }

        $direction = $this->sanitizeEnum(
            (string)$this->request->post('direction', ''),
            ['usd_to_usdt', 'usdt_to_usd']
        );
        if (!$direction) {
            return $this->error('请选择兑换方向');
        }

        $amount = $this->parsePositiveAmount($this->request->post('amount'));
        if ($amount === null) {
            return $this->error('兑换金额必须大于 0');
        }

        $balances  = $this->loadMasterWalletBalances($id);
        $available = $this->availableExchangeAmount($balances, $direction);
        if ((float)$amount > $available + 1e-12) {
            $source = $direction === 'usdt_to_usd' ? 'USDT' : 'USD';
            return $this->error(sprintf(
                'Exchange 余额不足：%s 可用 %.8f，申请兑换 %s',
                $source,
                $available,
                $amount
            ));
        }

        $result = (new FundApiClient())->exchange(self::ACCOUNT_TYPE, $id, $direction, $amount);
        if (!$result['ok']) {
            return $this->error('兑换失败：' . $result['message']);
        }

        return $this->success('兑换提交成功：' . $result['message']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAccount(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_master_accounts')
            ->where('id', $id)
            ->find();

        return empty($row) ? null : $row;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_master_accounts')
            ->field([
                'id',
                'account_name',
                'external_account_id',
                'usd_cash_total',
                'usd_funding_cash_total',
                'usd_exchange_cash_total',
                'usdt_cash_total',
                'usdt_funding_cash_total',
                'usdt_exchange_cash_total',
                'status',
                'remark',
                'updated_at',
            ]);

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['account_name'])) {
            $name = trim((string)$get['account_name']);
            if ($name !== '') {
                $query->whereLike('account_name', '%' . $this->sanitizeLike($name, 128) . '%');
            }
        }

        if (!empty($get['external_account_id'])) {
            $external = trim((string)$get['external_account_id']);
            if ($external !== '') {
                $query->whereLike(
                    'external_account_id',
                    '%' . $this->sanitizeLike($external, 128) . '%'
                );
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllBitfinexAccountStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $statusLabels = Enum::getBitfinexAccountStatusLabels();
        $status       = (string)($row['status'] ?? '');

        $row['status_label']                  = $statusLabels[$status] ?? ($status !== '' ? $status : '-');
        $row['usd_cash_total_fmt']            = $this->trimDecimal($row['usd_cash_total'] ?? 0, 8);
        $row['usd_funding_cash_total_fmt']    = $this->trimDecimal($row['usd_funding_cash_total'] ?? 0, 8);
        $row['usd_exchange_cash_total_fmt']   = $this->trimDecimal($row['usd_exchange_cash_total'] ?? 0, 8);
        $row['usdt_cash_total_fmt']           = $this->trimDecimal($row['usdt_cash_total'] ?? 0, 8);
        $row['usdt_funding_cash_total_fmt']   = $this->trimDecimal($row['usdt_funding_cash_total'] ?? 0, 8);
        $row['usdt_exchange_cash_total_fmt']  = $this->trimDecimal($row['usdt_exchange_cash_total'] ?? 0, 8);
        $row['updated_at']                    = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
