<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\service\ProviderMenuSync;
use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;
use think\db\Query;

/**
 * 上游商家 provider（渠道档案与接入端点配置）。
 */
class Provider extends Controller
{
    use MojopayListTrait;
    use LookupTrait;

    public function index()
    {
        $this->title = '商家列表';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'provider/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $statuses = Enum::getProviderStatuses();
        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'statuses' => array_combine($statuses, $statuses),
        ]);

        return $this->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->mojopayDb()->name('provider')->where('code', $data['code'])->find()) {
                return $this->error('商家编码 code 已存在');
            }

            $this->mojopayDb()->name('provider')->insert($data);
            $this->syncProviderMenus();

            return $this->success('新增成功');
        }

        $this->assign('vo', ['status' => 'INACTIVE']);
        $this->assignFormLookups();

        return $this->fetch('form');
    }

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

            $dup = $this->mojopayDb()->name('provider')
                ->where('code', $data['code'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('商家编码 code 已存在');
            }

            $this->mojopayDb()->name('provider')->where('id', $id)->update($data);
            $this->syncProviderMenus();

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

    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id || $this->loadRow($id) === null) {
            return $this->error('记录不存在');
        }

        $hasAccount = $this->mojopayDb()->name('provider_account')->where('provider_id', $id)->find();
        if (!empty($hasAccount)) {
            return $this->error('请先删除该商家下的账号后再删');
        }

        $deleted = $this->mojopayDb()->name('provider')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        $this->syncProviderMenus();

        return $this->success('删除成功');
    }

    private function syncProviderMenus(): void
    {
        (new ProviderMenuSync())->sync();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('provider');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'code', 'code', 32);
        $this->applyOptionalLike($query, $get, 'name', 'name', 128);

        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getProviderStatuses());
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
        $statuses = Enum::getProviderStatuses();
        $this->assign('status_options', array_combine($statuses, $statuses));
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('provider')->where('id', $id)->find();
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
        $code = strtoupper(trim((string)($post['code'] ?? '')));
        if ($code === '' || strlen($code) > 32 || !preg_match('/^[A-Z0-9_]+$/', $code)) {
            throw new \InvalidArgumentException('code 必填，32 字符内，仅大写字母数字下划线');
        }

        $name = trim((string)($post['name'] ?? ''));
        if ($name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException('商家名称不能为空且不超过 128 字符');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getProviderStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $officialUrl = $this->parseUrlField($post['official_url'] ?? '', '官网地址', true, 512);
        $apiSandbox  = $this->parseUrlField($post['api_base_url_sandbox'] ?? '', '沙盒 API 地址', false, 512);
        $apiProd     = $this->parseUrlField($post['api_base_url_production'] ?? '', '生产 API 地址', $status === 'ACTIVE', 512);
        $docsUrl     = $this->parseUrlField($post['docs_url'] ?? '', '文档地址', false, 512);

        $remark = trim((string)($post['remark'] ?? ''));
        if (strlen($remark) > 500) {
            throw new \InvalidArgumentException('备注不超过 500 字符');
        }

        if ($status === 'ACTIVE' && $apiProd === null) {
            throw new \InvalidArgumentException('启用状态下必须填写生产 API 地址');
        }

        return [
            'code'                     => $code,
            'name'                     => $name,
            'official_url'             => $officialUrl,
            'api_base_url_sandbox'     => $apiSandbox,
            'api_base_url_production'  => $apiProd,
            'docs_url'                 => $docsUrl,
            'remark'                   => $remark === '' ? null : $remark,
            'status'                   => $status,
        ];
    }

  /** @return string|null */
    private function parseUrlField(mixed $raw, string $label, bool $required, int $maxLen): ?string
    {
        $url = trim((string)$raw);
        if ($url === '') {
            if ($required) {
                throw new \InvalidArgumentException($label . '不能为空');
            }

            return null;
        }
        if (strlen($url) > $maxLen) {
            throw new \InvalidArgumentException($label . '过长');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException($label . '须为合法 URL（https://...）');
        }

        return $url;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at']  = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']  = $this->formatDatetime($row['updated_at'] ?? null);
        $st                 = (string)($row['status'] ?? '');
        $row['status_text'] = $st === 'ACTIVE' ? '已启用' : '未启用';
        foreach (['official_url', 'api_base_url_sandbox', 'api_base_url_production', 'docs_url'] as $uKey) {
            $row[$uKey . '_short'] = $this->shortUrl((string)($row[$uKey] ?? ''));
        }

        return $row;
    }

    private function shortUrl(string $url): string
    {
        if ($url === '') {
            return '-';
        }
        if (strlen($url) <= 48) {
            return $url;
        }

        return substr($url, 0, 45) . '…';
    }
}