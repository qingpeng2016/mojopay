<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MerchantScopeTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;

/**
 * 商户账号资质（KYB / KYC 统一 compliance_case）。
 */
class MerchantCompliance extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use MerchantScopeTrait;

    public function manage()
    {
        $accountId = $this->sanitizeId($this->request->get('merchant_account_id'));
        if (!$accountId) {
            return $this->error('缺少 merchant_account_id');
        }

        $account = $this->loadAccount($accountId);
        if ($account === null) {
            return $this->error('账号不存在');
        }

        try {
            $this->assertMerchantScope((int)$account['merchant_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $cases = $this->fetchCases($accountId, (int)$account['merchant_id']);
        foreach ($cases as &$row) {
            $row = $this->formatCaseRow($row);
        }
        unset($row);

        $this->title = '资质管理';
        $this->assignMerchantScopeContext((int)$account['merchant_id']);
        $suffix = $this->merchantScopeQuerySuffix();
        $qs     = 'merchant_account_id=' . $accountId . ($suffix !== '' ? '&' . $suffix : '');

        $this->assign([
            'account'     => $account,
            'cases'       => $cases,
            'account_qs'  => $qs,
            'add_url'     => url('case_add') . '?' . $qs,
        ]);

        return $this->fetch('manage');
    }

    public function caseAdd()
    {
        $accountId = $this->sanitizeId($this->request->get('merchant_account_id'));
        if (!$accountId) {
            $accountId = $this->sanitizeId($this->request->post('merchant_account_id'));
        }
        if (!$accountId) {
            return $this->error('缺少 merchant_account_id');
        }

        $account = $this->loadAccount($accountId);
        if ($account === null) {
            return $this->error('账号不存在');
        }

        try {
            $this->assertMerchantScope((int)$account['merchant_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if ($this->request->isPost()) {
            try {
                $data = $this->buildCaseData($this->request->post(), $account);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $data['public_id'] = 'ccase_' . bin2hex(random_bytes(16));
            $this->mojopayDb()->name('compliance_case')->insert($data);

            return $this->success('新增成功');
        }

        $this->title = '新增资质';
        $this->assignMerchantScopeContext((int)$account['merchant_id']);
        $this->assignCaseFormContext($account);
        $this->assign('vo', ['qual_kind' => 'KYB', 'status' => 'PENDING', 'country' => 'US']);

        return $this->fetch('case_form');
    }

    public function caseEdit()
    {
        $id = $this->sanitizeId($this->request->get('id') ?: $this->request->post('id'));
        if (!$id) {
            return $this->error('缺少 id');
        }

        $row = $this->mojopayDb()->name('compliance_case')->where('id', $id)->find();
        if (empty($row)) {
            return $this->error('记录不存在');
        }

        $accountId = (int)($row['merchant_account_id'] ?? 0);
        $account   = $accountId > 0 ? $this->loadAccount($accountId) : null;
        if ($account === null) {
            return $this->error('关联账号无效');
        }

        try {
            $this->assertMerchantScope((int)$row['merchant_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if ($this->request->isPost()) {
            try {
                $data = $this->buildCaseData($this->request->post(), $account, true);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            unset($data['merchant_id'], $data['merchant_account_id'], $data['public_id']);
            $this->mojopayDb()->name('compliance_case')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $this->title = '修改资质';
        $this->assignMerchantScopeContext((int)$account['merchant_id']);
        $this->assignCaseFormContext($account);
        $vo = $row;
        $vo['qual_kind']   = Enum::complianceQualKindLabel((string)$row['subject_type'], (string)$row['purpose']) === 'KYC' ? 'KYC' : 'KYB';
        $vo['expires_at']  = $this->formatDatetimeForInput($row['expires_at'] ?? null);
        $this->assign('vo', $vo);

        return $this->fetch('case_form');
    }

    /** @param array<string, mixed> $account */
    private function assignCaseFormContext(array $account): void
    {
        $mid = (int)$account['merchant_id'];
        $this->assign([
            'account'           => $account,
            'merchant_users'    => $this->merchantUserOptions($mid),
            'qual_kinds'        => Enum::getComplianceQualKinds(),
            'status_options'    => Enum::getComplianceStatuses(),
            'region_options'    => Enum::getRegionNameZhMap(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function loadAccount(int $accountId): ?array
    {
        $row = $this->mojopayDb()->name('merchant_account')->alias('a')
            ->join('merchant m', 'm.id = a.merchant_id')
            ->where('a.id', $accountId)
            ->field('a.*,m.legal_name as merchant_name,m.code as merchant_code')
            ->find();

        return empty($row) ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function fetchCases(int $accountId, int $merchantId): array
    {
        return $this->mojopayDb()->name('compliance_case')->alias('c')
            ->leftJoin('merchant_user u', 'u.id = c.merchant_user_id AND u.merchant_id = c.merchant_id')
            ->where('c.merchant_account_id', $accountId)
            ->where('c.merchant_id', $merchantId)
            ->field('c.*,u.external_user_id')
            ->order('c.id', 'desc')
            ->select()
            ->toArray();
    }

    /** @param array<string, mixed> $row */
    private function formatCaseRow(array $row): array
    {
        $row['qual_kind']         = Enum::complianceQualKindLabel((string)($row['subject_type'] ?? ''), (string)($row['purpose'] ?? ''));
        $row['qual_kind_text']    = Enum::getComplianceQualKinds()[$row['qual_kind']] ?? $row['qual_kind'];
        $row['status_badge_cls']  = Enum::complianceStatusBadgeClass((string)($row['status'] ?? ''));
        $row['status_text']       = Enum::complianceStatusLabel((string)($row['status'] ?? ''));
        $row['expires_at']        = $this->formatDatetime($row['expires_at'] ?? null);
        $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
        $row['subject_label']     = $row['qual_kind'] === 'KYC'
            ? (string)($row['external_user_id'] ?? '-')
            : '商户主体';

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function buildCaseData(array $post, array $account, bool $isEdit = false): array
    {
        $qualKind = strtoupper(trim((string)($post['qual_kind'] ?? '')));
        if (!in_array($qualKind, ['KYB', 'KYC'], true)) {
            throw new \InvalidArgumentException('请选择资质类型 KYB 或 KYC');
        }

        $country = strtoupper(trim((string)($post['country'] ?? '')));
        if (strlen($country) !== 2 || !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('国家/地区须为 2 位 ISO 代码');
        }

        $status = $this->sanitizeEnum(strtoupper(trim((string)($post['status'] ?? ''))), Enum::getComplianceStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('审核状态无效');
        }

        $evidence = trim((string)($post['evidence_ref'] ?? ''));
        $expires  = trim((string)($post['expires_at'] ?? ''));

        $data = [
            'merchant_id'          => (int)$account['merchant_id'],
            'merchant_account_id'  => (int)$account['id'],
            'country'              => $country,
            'status'               => $status,
            'evidence_ref'         => $evidence === '' ? null : mb_substr($evidence, 0, 255),
            'expires_at'           => $expires === '' ? null : $this->parseDatetimeInput($expires),
        ];

        if ($qualKind === 'KYB') {
            $data['subject_type']      = 'MERCHANT';
            $data['purpose']           = 'KYB';
            $data['merchant_user_id']  = null;
        } else {
            $userId = $this->sanitizeId($post['merchant_user_id'] ?? null);
            if (!$userId) {
                throw new \InvalidArgumentException('KYC 须选择最终用户');
            }
            $uidRow = $this->mojopayDb()->name('merchant_user')
                ->where('id', $userId)
                ->where('merchant_id', (int)$account['merchant_id'])
                ->find();
            if (empty($uidRow)) {
                throw new \InvalidArgumentException('最终用户不存在或不属于该商户');
            }
            $data['subject_type']     = 'MERCHANT_USER';
            $data['purpose']          = 'KYC';
            $data['merchant_user_id'] = $userId;
        }

        if ($isEdit) {
            return $data;
        }

        return $data;
    }

    private function parseDatetimeInput(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('有效期格式无效');
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function formatDatetimeForInput(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }
        $ts = strtotime($value);

        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }

    /** @return array<int, string> */
    protected function merchantUserOptions(int $merchantId): array
    {
        $rows = $this->mojopayDb()->name('merchant_user')
            ->where('merchant_id', $merchantId)
            ->order('id', 'asc')
            ->column('external_user_id', 'id');
        $out = [];
        foreach ($rows as $id => $ext) {
            $out[(int)$id] = (string)$ext;
        }

        return $out;
    }
}