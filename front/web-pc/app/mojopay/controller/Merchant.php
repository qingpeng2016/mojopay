<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;
use think\db\Query;

/**
 * 下游签约商户 merchant。
 */
class Merchant extends Controller
{
    use MojopayListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '商户';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'merchant/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $statuses = Enum::getMerchantStatuses();
        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'statuses' => array_combine($statuses, $statuses),
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

            if ($this->mojopayDb()->name('merchant')->where('code', $data['code'])->find()) {
                return $this->error('商户编码 code 已存在');
            }

            $data['public_id'] = $this->generatePublicId();
            $this->mojopayDb()->name('merchant')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'country' => 'US',
            'status'  => 'PENDING',
        ]);
        $this->assignFormLookups();

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

            $dup = $this->mojopayDb()->name('merchant')
                ->where('code', $data['code'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('商户编码 code 已存在');
            }

            $this->mojopayDb()->name('merchant')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $this->assignFormLookups();
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    /**
     * @auth true
     */
    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id || $this->loadRow($id) === null) {
            return $this->error('记录不存在');
        }

        $deleted = $this->mojopayDb()->name('merchant')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败（可能存在关联数据）');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('merchant');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'code', 'code', 48);
        $this->applyOptionalLike($query, $get, 'legal_name', 'legal_name', 255);
        $this->applyOptionalLike($query, $get, 'public_id', 'public_id', 64);

        if (!empty($get['country'])) {
            $country = strtoupper(trim((string)$get['country']));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                $query->where('country', $country);
            }
        }

        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getMerchantStatuses());
            if ($st !== null) {
                $query->where('status', $st);
            }
        }

        if (!empty($get['date_range'])) {
            $this->applyDateRangeFilter($query, $get, 'created_at', false);
        }

        return $query;
    }

    private function assignFormLookups(): void
    {
        $statuses = Enum::getMerchantStatuses();
        $this->assign('status_options', array_combine($statuses, $statuses));
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('merchant')->where('id', $id)->find();
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
        $code = trim((string)($post['code'] ?? ''));
        if ($code === '' || strlen($code) > 48 || !preg_match('/^[A-Za-z0-9._-]+$/', $code)) {
            throw new \InvalidArgumentException('code 必填，48 字符内，仅允许字母数字 . _ -');
        }

        $legalName = trim((string)($post['legal_name'] ?? ''));
        if ($legalName === '' || strlen($legalName) > 255) {
            throw new \InvalidArgumentException('legal_name 不能为空且不超过 255 字符');
        }

        $country = strtoupper(trim((string)($post['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('country 须为 ISO 3166-1 alpha-2 两位国家码');
        }

        $category = trim((string)($post['category'] ?? ''));
        if ($category === '' || strlen($category) > 32) {
            throw new \InvalidArgumentException('category 不能为空且不超过 32 字符');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getMerchantStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        return [
            'code'       => $code,
            'legal_name' => $legalName,
            'country'    => $country,
            'category'   => $category,
            'status'     => $status,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);
        $st                = (string)($row['status'] ?? '');
        $row['status_text'] = match ($st) {
            'ACTIVE'    => '已激活',
            'SUSPENDED' => '已暂停',
            'REJECTED'  => '已拒绝',
            default     => '待准入',
        };

        return $row;
    }

    private function generatePublicId(): string
    {
        return 'mer_' . bin2hex(random_bytes(16));
    }
}