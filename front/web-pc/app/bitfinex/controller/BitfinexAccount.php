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
 * Bitfinex 子账号管理（fund_bitfinex_sub_accounts）
 */
class BitfinexAccount extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;
    use BitfinexWalletOpsTrait;

    private const ACCOUNT_TYPE = 'sub';

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'Bitfinex账号管理';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('a.id', 'asc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/bitfinex_account/index.html',
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
     * 新增子账号（1 产品 = 1 子账号，并回写 fund_products.bitfinex_sub_account_id）
     *
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $productId = $this->sanitizeId($this->request->post('product_id'));
            if (!$productId) {
                return $this->error('请选择关联产品');
            }

            [$data, $err] = $this->parseSubAccountFormData($this->request->post(), true);
            if ($err !== null) {
                return $this->error($err);
            }

            $db = Db::connect('db_bitfinex');
            $product = $db->name('fund_products')->where('id', $productId)->find();
            if (empty($product)) {
                return $this->error('产品不存在');
            }

            $bound = $db->name('fund_bitfinex_sub_accounts')->where('product_id', $productId)->find();
            if (!empty($bound)) {
                return $this->error('该产品已绑定子账号');
            }

            if ($data['accounts_id'] !== null) {
                $dup = $db->name('fund_bitfinex_sub_accounts')->where('accounts_id', $data['accounts_id'])->find();
                if (!empty($dup)) {
                    return $this->error('accounts_id 已被其他子账号占用');
                }
            }

            $now = date('Y-m-d H:i:s');
            try {
                $db->transaction(function () use ($db, $productId, $data, $now) {
                    $subId = (int)$db->name('fund_bitfinex_sub_accounts')->insertGetId([
                        'product_id'               => $productId,
                        'account_name'             => $data['account_name'],
                        'external_subaccount_id'   => $data['external_subaccount_id'],
                        'accounts_id'              => $data['accounts_id'],
                        'api_key'                  => $data['api_key'],
                        'api_secret'               => $data['api_secret'],
                        'usd_cash_total'           => 0,
                        'usd_funding_cash_total'   => 0,
                        'usd_exchange_cash_total'  => 0,
                        'usdt_cash_total'          => 0,
                        'usdt_funding_cash_total'  => 0,
                        'usdt_exchange_cash_total' => 0,
                        'status'                   => $data['status'],
                        'remark'                   => $data['remark'],
                        'created_at'               => $now,
                        'updated_at'               => $now,
                    ]);

                    $db->name('fund_products')
                        ->where('id', $productId)
                        ->update([
                            'bitfinex_sub_account_id' => $subId,
                            'updated_at'              => $now,
                        ]);
                });
            } catch (\Throwable $e) {
                return $this->error('创建失败：' . $e->getMessage());
            }

            return $this->success('子账号已创建并已绑定产品');
        }

        $this->assign([
            'products' => $this->loadUnboundProducts(),
            'statuses' => Enum::getBitfinexAccountStatusLabels(),
            'vo'       => ['status' => 'active'],
        ]);

        return $this->fetch('add');
    }

    /**
     * 编辑子账号（余额字段由策略同步，此处不可改）
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

            $existing = Db::connect('db_bitfinex')
                ->name('fund_bitfinex_sub_accounts')
                ->where('id', $id)
                ->find();
            if (empty($existing)) {
                return $this->error('子账号不存在');
            }

            [$data, $err] = $this->parseSubAccountFormData($this->request->post(), false);
            if ($err !== null) {
                return $this->error($err);
            }

            if ($data['accounts_id'] !== null) {
                $dup = Db::connect('db_bitfinex')
                    ->name('fund_bitfinex_sub_accounts')
                    ->where('accounts_id', $data['accounts_id'])
                    ->where('id', '<>', $id)
                    ->find();
                if (!empty($dup)) {
                    return $this->error('accounts_id 已被其他子账号占用');
                }
            }

            $update = [
                'account_name'           => $data['account_name'],
                'external_subaccount_id' => $data['external_subaccount_id'],
                'accounts_id'            => $data['accounts_id'],
                'api_key'                => $data['api_key'],
                'status'                 => $data['status'],
                'remark'                 => $data['remark'],
                'updated_at'             => date('Y-m-d H:i:s'),
            ];
            if ($data['api_secret'] !== '') {
                $update['api_secret'] = $data['api_secret'];
            }

            Db::connect('db_bitfinex')
                ->name('fund_bitfinex_sub_accounts')
                ->where('id', $id)
                ->update($update);

            return $this->success('子账号已更新');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $row = $this->loadAccount($id);
        if ($row === null) {
            return $this->error('子账号不存在');
        }

        $vo = $this->formatRow($row);
        $vo['api_key'] = (string)($row['api_key'] ?? '');
        $vo['api_secret_masked'] = $this->maskSecret((string)($row['api_secret'] ?? ''));
        $vo['accounts_id'] = $row['accounts_id'] ?? '';

        $this->assign([
            'vo'       => $vo,
            'statuses' => Enum::getBitfinexAccountStatusLabels(),
        ]);

        return $this->fetch('edit');
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
            return $this->error('子账号不存在');
        }

        $balances = $this->loadSubWalletBalances($id);
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
            return $this->error('子账号不存在');
        }

        $balances = $this->loadSubWalletBalances($id);
        $this->assign([
            'vo'            => $this->formatRow($vo),
            'account_type'  => self::ACCOUNT_TYPE,
            'account_id'    => $id,
            'balances'      => $balances,
            'balances_json' => json_encode($balances, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->fetch('exchange_form');
    }

    private function handleTransferPost()
    {
        $fromId = $this->sanitizeId($this->request->post('from_account_id'));
        if (!$fromId || $this->loadAccount($fromId) === null) {
            return $this->error('子账号不存在');
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

        $balances  = $this->loadSubWalletBalances($fromId);
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
            return $this->error('子账号不存在');
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

        $balances  = $this->loadSubWalletBalances($id);
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
            ->name('fund_bitfinex_sub_accounts')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->where('a.id', $id)
            ->field([
                'a.id',
                'a.product_id',
                'a.account_name',
                'a.external_subaccount_id',
                'a.accounts_id',
                'a.api_key',
                'a.api_secret',
                'a.usd_cash_total',
                'a.usd_funding_cash_total',
                'a.usd_exchange_cash_total',
                'a.usdt_cash_total',
                'a.usdt_funding_cash_total',
                'a.usdt_exchange_cash_total',
                'a.status',
                'a.remark',
                'a.updated_at',
                'p.product_name',
            ])
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
            ->name('fund_bitfinex_sub_accounts')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->field([
                'a.id',
                'a.product_id',
                'a.account_name',
                'a.external_subaccount_id',
                'a.usd_cash_total',
                'a.usd_funding_cash_total',
                'a.usd_exchange_cash_total',
                'a.usdt_cash_total',
                'a.usdt_funding_cash_total',
                'a.usdt_exchange_cash_total',
                'a.status',
                'a.remark',
                'a.updated_at',
                'p.product_name',
            ]);

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('a.id', $id);
            }
        }

        if (!empty($get['external_subaccount_id'])) {
            $external = trim((string)$get['external_subaccount_id']);
            if ($external !== '') {
                $query->whereLike(
                    'a.external_subaccount_id',
                    '%' . $this->sanitizeLike($external, 128) . '%'
                );
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllBitfinexAccountStatuses());
            if ($status) {
                $query->where('a.status', $status);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('a.product_id', $productId);
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

        $row['status_label']                 = $statusLabels[$status] ?? ($status !== '' ? $status : '-');
        $row['usd_cash_total_fmt']           = $this->trimDecimal($row['usd_cash_total'] ?? 0, 8);
        $row['usd_funding_cash_total_fmt']   = $this->trimDecimal($row['usd_funding_cash_total'] ?? 0, 8);
        $row['usd_exchange_cash_total_fmt']  = $this->trimDecimal($row['usd_exchange_cash_total'] ?? 0, 8);
        $row['usdt_cash_total_fmt']          = $this->trimDecimal($row['usdt_cash_total'] ?? 0, 8);
        $row['usdt_funding_cash_total_fmt']  = $this->trimDecimal($row['usdt_funding_cash_total'] ?? 0, 8);
        $row['usdt_exchange_cash_total_fmt'] = $this->trimDecimal($row['usdt_exchange_cash_total'] ?? 0, 8);
        $row['updated_at']                   = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUnboundProducts(): array
    {
        return Db::connect('db_bitfinex')
            ->name('fund_products')
            ->alias('p')
            ->leftJoin('fund_bitfinex_sub_accounts a', 'a.product_id = p.id')
            ->whereNull('a.id')
            ->field('p.id, p.product_code, p.product_name')
            ->orderRaw("FIELD(p.product_type, 'GUARANTEED', 'FLOATING'), p.product_code ASC")
            ->select()
            ->toArray();
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function parseSubAccountFormData(array $post, bool $requireSecret): array
    {
        $accountName = trim((string)($post['account_name'] ?? ''));
        if ($accountName === '' || mb_strlen($accountName) > 128) {
            return [[], '展示名必填且不超过 128 字符'];
        }

        $externalId = trim((string)($post['external_subaccount_id'] ?? ''));
        if ($externalId === '' || strlen($externalId) > 128) {
            return [[], '平台子账号 ID/别名必填且不超过 128 字符'];
        }

        $apiKey = trim((string)($post['api_key'] ?? ''));
        if ($apiKey === '' || strlen($apiKey) > 128) {
            return [[], 'API Key 必填且不超过 128 字符'];
        }

        $apiSecret = trim((string)($post['api_secret'] ?? ''));
        if ($requireSecret && $apiSecret === '') {
            return [[], 'API Secret 必填'];
        }
        if ($apiSecret !== '' && strlen($apiSecret) > 512) {
            return [[], 'API Secret 不超过 512 字符'];
        }

        $status = $this->sanitizeEnum($post['status'] ?? 'active', Enum::getAllBitfinexAccountStatuses());
        if (!$status) {
            return [[], '状态无效'];
        }

        $accountsId = null;
        $accountsRaw = trim((string)($post['accounts_id'] ?? ''));
        if ($accountsRaw !== '') {
            $accountsId = $this->sanitizeId($accountsRaw);
            if (!$accountsId) {
                return [[], 'accounts_id 须为正整数或留空'];
            }
        }

        $remark = trim((string)($post['remark'] ?? ''));
        if (mb_strlen($remark) > 500) {
            return [[], '备注不超过 500 字'];
        }

        return [[
            'account_name'           => $accountName,
            'external_subaccount_id' => $externalId,
            'accounts_id'            => $accountsId,
            'api_key'                => $apiKey,
            'api_secret'             => $apiSecret,
            'status'                 => $status,
            'remark'                 => $remark,
        ], null];
    }

    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '（未设置）';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($secret, 0, 4) . str_repeat('*', min(12, $len - 8)) . substr($secret, -4);
    }
}
