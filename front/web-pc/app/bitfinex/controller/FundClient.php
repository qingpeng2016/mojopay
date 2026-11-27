<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\admin\service\AdminService;
use think\db\Query;
use think\facade\Db;

/**
 * 3.6.2 客户管理（fund_client / fund_client_wallet / fund_client_wallet_flow）
 */
class FundClient extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '客户管理';
        $get         = $this->request->get();

        $result = $this->filteredClientQuery($get)
            ->order('id', 'desc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/fund_client/index.html',
            ], false);

        $list = $result->items();
        if ($list !== []) {
            $clientIds = array_map(static fn ($row) => (int)$row['id'], $list);
            $aggMap    = $this->loadClientAggregates($clientIds);
            foreach ($list as &$row) {
                $row = $this->formatListRow($row, $aggMap[(int)$row['id']] ?? []);
            }
            unset($row);
        }

        $this->assign([
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'statuses'     => Enum::getFundClientStatusLabels(),
            'client_types' => Enum::getFundClientTypeLabels(),
            'kyc_statuses' => Enum::getFundClientKycLabels(),
        ]);

        return $this->fetch();
    }

    /**
     * 3.6.2.1 新建客户（同时初始化各币种零余额钱包 + 保底产品专属利率）
     *
     * @auth true
     */
    public function create()
    {
        if ($this->request->isPost()) {
            [$data, $err] = $this->parseClientFormData($this->request->post());
            if ($err !== null) {
                return $this->error($err);
            }

            $db = Db::connect('db_bitfinex');
            try {
                $db->transaction(function () use ($db, $data) {
                    $clientId = (int)$db->name('fund_client')->insertGetId($data);
                    foreach (Enum::getAllFundClientWalletCurrencies() as $currency) {
                        $db->name('fund_client_wallet')->insert([
                            'client_id'           => $clientId,
                            'currency'            => $currency,
                            'available_balance'   => 0,
                            'subscribed_balance'  => 0,
                            'frozen_balance'      => 0,
                            'status'              => 'active',
                        ]);
                    }
                    $this->initClientGuaranteedRates($db, $clientId);
                });
            } catch (\Throwable $e) {
                return $this->error('创建失败：' . $e->getMessage());
            }

            return $this->success('客户创建成功');
        }

        $this->assignFormOptions();
        $this->assign('vo', [
            'client_type' => 'individual',
            'kyc_status'  => 'pending',
            'status'      => 'active',
        ]);
        return $this->fetch('form');
    }

    /**
     * 编辑客户基本信息
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

            $vo = $this->loadClient($id);
            if ($vo === null) {
                return $this->error('客户不存在');
            }

            [$data, $err] = $this->parseClientFormData($this->request->post());
            if ($err !== null) {
                return $this->error($err);
            }

            $affected = Db::connect('db_bitfinex')
                ->name('fund_client')
                ->where('id', $id)
                ->update($data);

            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadClient($id);
        if ($vo === null) {
            return $this->error('客户不存在');
        }

        $this->assignFormOptions();
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /**
     * 3.6.2.3 客户详情
     *
     * @auth true
     */
    public function detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadClient($id);
        if ($vo === null) {
            return $this->error('客户不存在');
        }

        $db = Db::connect('db_bitfinex');
        $agg = $this->loadClientAggregates([$id])[$id] ?? [];

        $wallets = $db->name('fund_client_wallet')
            ->where('client_id', $id)
            ->order('currency', 'asc')
            ->select()
            ->toArray();
        foreach ($wallets as &$wallet) {
            $wallet = $this->formatWalletRow($wallet);
        }
        unset($wallet);

        $holdings = $db->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $id)
            ->where('s.status', 'ACTIVE')
            ->field('s.*, p.product_name, p.product_type, p.nav_per_share')
            ->order('s.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        foreach ($holdings as &$holding) {
            $holding = $this->formatHoldingRow($holding);
        }
        unset($holding);

        $flows = $db->name('fund_client_wallet_flow')
            ->where('client_id', $id)
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();
        foreach ($flows as &$flow) {
            $flow = $this->formatFlowRow($flow);
        }
        unset($flow);

        $this->assign([
            'vo'       => $this->formatClientRow($vo),
            'summary'  => $this->formatAggregateRow($agg),
            'wallets'  => $wallets,
            'holdings' => $holdings,
            'flows'    => $flows,
        ]);

        return $this->fetch();
    }

    /**
     * 3.6.2.4 入金
     *
     * @auth true
     */
    public function deposit()
    {
        if ($this->request->isPost()) {
            return $this->handleWalletMutation('deposit');
        }

        [$client, $err] = $this->loadClientForWalletForm('deposit');
        if ($err !== null) {
            return $this->error($err);
        }

        $this->assign([
            'vo'         => $client,
            'currencies' => Enum::getAllFundClientWalletCurrencies(),
            'action'     => 'deposit',
            'action_label' => '入金',
        ]);
        return $this->fetch('wallet_form');
    }

    /**
     * 3.6.2.5 出金
     *
     * @auth true
     */
    public function withdraw()
    {
        if ($this->request->isPost()) {
            return $this->handleWalletMutation('withdraw');
        }

        [$client, $err] = $this->loadClientForWalletForm('withdraw');
        if ($err !== null) {
            return $this->error($err);
        }

        $this->assign([
            'vo'           => $client,
            'currencies'   => Enum::getAllFundClientWalletCurrencies(),
            'action'       => 'withdraw',
            'action_label' => '出金',
        ]);
        return $this->fetch('wallet_form');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredClientQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name('fund_client');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['name'])) {
            $name = trim((string)$get['name']);
            if ($name !== '') {
                $query->whereLike('name', '%' . $this->sanitizeLike($name, 128) . '%');
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundClientStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        if (!empty($get['client_type'])) {
            $clientType = $this->sanitizeEnum($get['client_type'], Enum::getAllFundClientTypes());
            if ($clientType) {
                $query->where('client_type', $clientType);
            }
        }

        if (!empty($get['kyc_status'])) {
            $kycStatus = $this->sanitizeEnum($get['kyc_status'], Enum::getAllFundClientKycStatuses());
            if ($kycStatus) {
                $query->where('kyc_status', $kycStatus);
            }
        }

        return $query;
    }

    /**
     * @param int[] $clientIds
     * @return array<int, array<string, float|int>>
     */
    private function loadClientAggregates(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $db  = Db::connect('db_bitfinex');
        $map = [];
        foreach ($clientIds as $clientId) {
            $map[$clientId] = [
                'wallet_balance'       => 0.0,
                'total_invest'         => 0.0,
                'total_share_value'    => 0.0,
                'active_product_count' => 0,
            ];
        }

        $walletRows = $db->name('fund_client_wallet')
            ->whereIn('client_id', $clientIds)
            ->field('client_id, SUM(available_balance) AS wallet_balance')
            ->group('client_id')
            ->select()
            ->toArray();
        foreach ($walletRows as $row) {
            $cid = (int)$row['client_id'];
            if (isset($map[$cid])) {
                $map[$cid]['wallet_balance'] = (float)$row['wallet_balance'];
            }
        }

        $subRows = $db->name('fund_client_product_subscription')
            ->whereIn('client_id', $clientIds)
            ->where('status', 'ACTIVE')
            ->field('client_id, SUM(subscribe_amount) AS total_invest, COUNT(DISTINCT product_id) AS active_product_count')
            ->group('client_id')
            ->select()
            ->toArray();
        foreach ($subRows as $row) {
            $cid = (int)$row['client_id'];
            if (isset($map[$cid])) {
                $map[$cid]['total_invest']         = (float)$row['total_invest'];
                $map[$cid]['active_product_count'] = (int)$row['active_product_count'];
            }
        }

        $shareRows = $db->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->whereIn('s.client_id', $clientIds)
            ->where('s.status', 'ACTIVE')
            ->where('p.product_type', 'FLOATING')
            ->field('s.client_id, SUM(COALESCE(s.floating_shares, 0) * COALESCE(p.nav_per_share, 0)) AS total_share_value')
            ->group('s.client_id')
            ->select()
            ->toArray();
        foreach ($shareRows as $row) {
            $cid = (int)$row['client_id'];
            if (isset($map[$cid])) {
                $map[$cid]['total_share_value'] = (float)$row['total_share_value'];
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, float|int> $agg
     * @return array<string, mixed>
     */
    private function formatListRow(array $row, array $agg): array
    {
        $row = $this->formatClientRow($row);
        $row['wallet_balance_fmt']       = $this->trimDecimal($agg['wallet_balance'] ?? 0, 2);
        $row['total_invest_fmt']         = $this->trimDecimal($agg['total_invest'] ?? 0, 2);
        $row['total_share_value_fmt']    = $this->trimDecimal($agg['total_share_value'] ?? 0, 2);
        $row['active_product_count']     = (int)($agg['active_product_count'] ?? 0);
        return $row;
    }

    /**
     * @param array<string, float|int> $agg
     * @return array<string, mixed>
     */
    private function formatAggregateRow(array $agg): array
    {
        return [
            'wallet_balance_fmt'       => $this->trimDecimal($agg['wallet_balance'] ?? 0, 8),
            'total_invest_fmt'         => $this->trimDecimal($agg['total_invest'] ?? 0, 8),
            'total_share_value_fmt'    => $this->trimDecimal($agg['total_share_value'] ?? 0, 8),
            'active_product_count'     => (int)($agg['active_product_count'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadClient(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')->name('fund_client')->where('id', $id)->find();
        return empty($row) ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatClientRow(array $row): array
    {
        $typeLabels   = Enum::getFundClientTypeLabels();
        $kycLabels    = Enum::getFundClientKycLabels();
        $statusLabels = Enum::getFundClientStatusLabels();

        $row['client_type_label'] = $typeLabels[(string)($row['client_type'] ?? '')] ?? ($row['client_type'] ?? '-');
        $row['kyc_status_label']  = $kycLabels[(string)($row['kyc_status'] ?? '')] ?? ($row['kyc_status'] ?? '-');
        $row['status_label']      = $statusLabels[(string)($row['status'] ?? '')] ?? ($row['status'] ?? '-');
        $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']        = $this->formatDatetime($row['updated_at'] ?? null);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatWalletRow(array $row): array
    {
        $statusLabels = Enum::getFundClientStatusLabels();
        $row['status_label']              = $statusLabels[(string)($row['status'] ?? '')] ?? ($row['status'] ?? '-');
        $row['available_balance_fmt']     = $this->trimDecimal($row['available_balance'] ?? 0, 8);
        $row['subscribed_balance_fmt']    = $this->trimDecimal($row['subscribed_balance'] ?? 0, 8);
        $row['frozen_balance_fmt']        = $this->trimDecimal($row['frozen_balance'] ?? 0, 8);
        $row['updated_at']                = $this->formatDatetime($row['updated_at'] ?? null);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatHoldingRow(array $row): array
    {
        $row['subscribe_amount_fmt'] = $this->trimDecimal($row['subscribe_amount'] ?? 0, 8);
        $row['floating_shares_fmt']  = $this->trimDecimal($row['floating_shares'] ?? 0, 10);
        $row['redeemable_amount_fmt'] = $this->trimDecimal($row['redeemable_amount'] ?? 0, 8);
        $row['nav_per_share_fmt']    = $this->trimDecimal($row['nav_per_share'] ?? 0, 10);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatFlowRow(array $row): array
    {
        $row['amount_fmt']        = $this->trimDecimal(abs((float)($row['amount'] ?? 0)), 8);
        $row['balance_after_fmt'] = $this->trimDecimal($row['balance_after'] ?? 0, 8);
        $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
        return $row;
    }

    private function assignFormOptions(): void
    {
        $this->assign([
            'client_types' => Enum::getFundClientTypeLabels(),
            'kyc_statuses' => Enum::getFundClientKycLabels(),
            'statuses'     => Enum::getFundClientStatusLabels(),
        ]);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0:array<string, mixed>,1:?string}
     */
    private function parseClientFormData(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            return [[], '客户名称不能为空'];
        }
        if (strlen($name) > 128) {
            return [[], '客户名称不能超过 128 字符'];
        }

        $email = trim((string)($post['email'] ?? ''));
        if ($email !== '' && (strlen($email) > 128 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return [[], '邮箱格式无效'];
        }

        $phone = trim((string)($post['phone'] ?? ''));
        if (strlen($phone) > 32) {
            return [[], '电话不能超过 32 字符'];
        }

        $country = trim((string)($post['country'] ?? ''));
        if (strlen($country) > 64) {
            return [[], '国家/地区不能超过 64 字符'];
        }

        $externalRef = trim((string)($post['external_ref'] ?? ''));
        if (strlen($externalRef) > 128) {
            return [[], '外部引用不能超过 128 字符'];
        }

        $remark = trim((string)($post['remark'] ?? ''));
        if (strlen($remark) > 500) {
            return [[], '备注不能超过 500 字符'];
        }

        $clientType = $this->sanitizeEnum($post['client_type'] ?? '', Enum::getAllFundClientTypes()) ?? 'individual';
        $kycStatus  = $this->sanitizeEnum($post['kyc_status'] ?? '', Enum::getAllFundClientKycStatuses()) ?? 'pending';
        $status     = $this->sanitizeEnum($post['status'] ?? '', Enum::getAllFundClientStatuses()) ?? 'active';

        return [[
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'client_type'  => $clientType,
            'kyc_status'   => $kycStatus,
            'country'      => $country,
            'external_ref' => $externalRef,
            'status'       => $status,
            'remark'       => $remark,
        ], null];
    }

    /**
     * @return array{0:?array<string,mixed>,1:?string}
     */
    private function loadClientForWalletForm(string $action): array
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return [null, '参数错误'];
        }

        $client = $this->loadClient($id);
        if ($client === null) {
            return [null, '客户不存在'];
        }

        if ((string)($client['status'] ?? '') !== 'active') {
            return [null, '客户状态非正常，无法' . ($action === 'deposit' ? '入金' : '出金')];
        }

        $wallets = Db::connect('db_bitfinex')
            ->name('fund_client_wallet')
            ->where('client_id', $id)
            ->select()
            ->toArray();
        $walletBalances = [];
        foreach ($wallets as $wallet) {
            $formatted = $this->formatWalletRow($wallet);
            $walletBalances[(string)$wallet['currency']] = $formatted['available_balance_fmt'];
        }

        $client = $this->formatClientRow($client);
        $client['wallet_balances'] = $walletBalances;
        return [$client, null];
    }

    private function handleWalletMutation(string $action)
    {
        $clientId = $this->sanitizeId($this->request->post('client_id'));
        if (!$clientId) {
            return $this->error('参数错误');
        }

        $currency = $this->sanitizeEnum(
            $this->request->post('currency', ''),
            Enum::getAllFundClientWalletCurrencies()
        );
        if (!$currency) {
            return $this->error('请选择有效币种');
        }

        $amount = $this->parsePositiveAmount($this->request->post('amount'));
        if ($amount === null) {
            return $this->error('金额必须是大于 0 的数字');
        }

        $commission = 0.0;
        if ($action === 'withdraw') {
            $commissionRaw = trim((string)$this->request->post('commission', '0'));
            if ($commissionRaw === '') {
                $commissionRaw = '0';
            }
            if (!is_numeric($commissionRaw) || (float)$commissionRaw < 0) {
                return $this->error('手续费不能为负数');
            }
            $commission = round((float)$commissionRaw, 8);
        }

        $remark = trim((string)$this->request->post('remark', ''));
        if (strlen($remark) > 500) {
            return $this->error('备注不能超过 500 字符');
        }

        $client = $this->loadClient($clientId);
        if ($client === null) {
            return $this->error('客户不存在');
        }
        if ((string)($client['status'] ?? '') !== 'active') {
            return $this->error('客户状态非正常，无法操作');
        }

        $operator = $this->currentOperator();
        $db       = Db::connect('db_bitfinex');

        try {
            $db->transaction(function () use ($db, $action, $clientId, $currency, $amount, $commission, $remark, $operator) {
                $wallet = $db->name('fund_client_wallet')
                    ->where('client_id', $clientId)
                    ->where('currency', $currency)
                    ->lock(true)
                    ->find();

                if (empty($wallet)) {
                    throw new \RuntimeException('客户钱包不存在，请先创建客户');
                }
                if ((string)($wallet['status'] ?? '') !== 'active') {
                    throw new \RuntimeException('钱包状态非正常，无法操作');
                }

                $available = (float)$wallet['available_balance'];

                if ($action === 'deposit') {
                    $delta         = $amount;
                    $flowType      = 'DEPOSIT';
                    $flowAmount    = $amount;
                    $refType       = 'deposit';
                    $defaultRemark = '后台入金';
                } else {
                    $totalOut = round($amount + $commission, 8);
                    if ($available + 1e-12 < $totalOut) {
                        throw new \RuntimeException(sprintf(
                            '可用余额不足（可用 %s，需扣 %s%s）',
                            $this->trimDecimal($available, 8),
                            $this->trimDecimal($amount, 8),
                            $commission > 0 ? ' + 手续费 ' . $this->trimDecimal($commission, 8) : ''
                        ));
                    }
                    $delta         = -$totalOut;
                    $flowType      = 'WITHDRAW';
                    $flowAmount    = $totalOut;
                    $refType       = 'manual';
                    $defaultRemark = $commission > 0
                        ? sprintf('后台出金（含手续费 %s）', $this->trimDecimal($commission, 8))
                        : '后台出金';
                }

                $balanceAfter = round($available + $delta, 8);
                $affected = $db->name('fund_client_wallet')
                    ->where('id', (int)$wallet['id'])
                    ->where('client_id', $clientId)
                    ->where('currency', $currency)
                    ->update(['available_balance' => $balanceAfter]);

                if ($affected < 1) {
                    throw new \RuntimeException('钱包更新失败');
                }

                $db->name('fund_client_wallet_flow')->insert([
                    'client_id'     => $clientId,
                    'currency'      => $currency,
                    'flow_type'     => $flowType,
                    'amount'        => $flowAmount,
                    'balance_after' => $balanceAfter,
                    'ref_type'      => $refType,
                    'ref_id'        => 0,
                    'remark'        => $remark !== '' ? $remark : $defaultRemark,
                    'operator'      => $operator,
                ]);
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->success($action === 'deposit' ? '入金成功' : '出金成功');
    }

    /**
     * 新建客户时：为每个 GUARANTEED 产品写入默认利率（= fund_products.guaranteed_rate）
     *
     * @param \think\db\Connection $db
     */
    private function initClientGuaranteedRates($db, int $clientId): void
    {
        $products = $db->name('fund_products')
            ->where('product_type', 'GUARANTEED')
            ->field('id, product_code, guaranteed_rate')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($products === []) {
            return;
        }

        $rows = [];
        foreach ($products as $product) {
            $rows[] = [
                'client_id'       => $clientId,
                'product_id'      => (int)$product['id'],
                'product_code'    => (string)($product['product_code'] ?? ''),
                'guaranteed_rate' => (float)($product['guaranteed_rate'] ?? 0),
                'bonus'           => 0.0,
            ];
        }

        $db->name('fund_client_guaranteed_rate')->insertAll($rows);
    }

    private function parsePositiveAmount($value): ?float
    {
        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $amount = round((float)$raw, 8);
        if ($amount <= 0) {
            return null;
        }
        return $amount;
    }

    private function currentOperator(): string
    {
        if (class_exists(AdminService::class)) {
            if (method_exists(AdminService::class, 'getUserName')) {
                $name = AdminService::getUserName();
                if ($name !== null && $name !== '') {
                    return (string)$name;
                }
            }
            if (method_exists(AdminService::class, 'getUserId')) {
                $uid = AdminService::getUserId();
                if ($uid) {
                    return 'admin#' . $uid;
                }
            }
        }

        $user = $this->app->session->get('user');
        if (is_array($user)) {
            $username = (string)($user['username'] ?? '');
            if ($username !== '') {
                return $username;
            }
        }

        return '';
    }
}
