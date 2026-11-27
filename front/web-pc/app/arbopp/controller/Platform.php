<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class Platform extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '交易平台';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('sort', 'asc')->order('id', 'asc'),
            $get,
            'platform/index'
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'statuses' => array_combine(Enum::getPlatformStatuses(), Enum::getPlatformStatuses()),
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
            if ($this->arboppDb()->name('platform')->where('slug', $data['slug'])->find()) {
                return $this->error('slug 已存在');
            }
            $this->arboppDb()->name('platform')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'platform_type' => 'PREDICTION',
            'quote_style'   => 'PROBABILITY',
            'status'        => 'ACTIVE',
            'timeout'       => 30,
            'retry_times'   => 3,
            'retry_interval'=> 1,
            'sort'          => 0,
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
                $data = $this->buildFormData($this->request->post(), true);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }
            $dup = $this->arboppDb()->name('platform')->where('slug', $data['slug'])->where('id', '<>', $id)->find();
            if (!empty($dup)) {
                return $this->error('slug 已存在');
            }
            $this->arboppDb()->name('platform')->where('id', $id)->update($data);

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

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('platform');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'slug', 'slug', 50);
        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getPlatformStatuses());
            if ($st) {
                $query->where('status', $st);
            }
        }

        return $query;
    }

    private function assignFormLookups(): void
    {
        $this->assign('platform_types', Enum::getPlatformTypes());
        $this->assign('quote_styles', Enum::getQuoteStyles());
        $this->assign('statuses', Enum::getPlatformStatuses());
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post, bool $isEdit = false): array
    {
        $slug = strtoupper(trim((string)($post['slug'] ?? '')));
        if ($slug === '' || strlen($slug) > 50) {
            throw new \InvalidArgumentException('slug 无效');
        }
        $ptype = $this->sanitizeEnum(strtoupper((string)($post['platform_type'] ?? 'PREDICTION')), Enum::getPlatformTypes());
        $qstyle = $this->sanitizeEnum(strtoupper((string)($post['quote_style'] ?? 'PROBABILITY')), Enum::getQuoteStyles());
        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? 'ACTIVE')), Enum::getPlatformStatuses());
        if ($ptype === null || $qstyle === null || $status === null) {
            throw new \InvalidArgumentException('platform_type / quote_style / status 无效');
        }
        $display = trim((string)($post['display_name'] ?? ''));
        if (strlen($display) > 64) {
            throw new \InvalidArgumentException('display_name 过长');
        }

        foreach (['timeout', 'retry_times', 'retry_interval', 'sort'] as $intField) {
            $v = trim((string)($post[$intField] ?? '0'));
            if (!is_numeric($v)) {
                throw new \InvalidArgumentException($intField . ' 须为整数');
            }
        }

        return [
            'slug'                 => $slug,
            'platform_type'        => $ptype,
            'quote_style'          => $qstyle,
            'display_name'         => $display,
            'timeout'              => (int)($post['timeout'] ?? 30),
            'retry_times'          => (int)($post['retry_times'] ?? 3),
            'retry_interval'       => (int)($post['retry_interval'] ?? 1),
            'market_api_base_url'  => $this->nullableString($post['market_api_base_url'] ?? '', 512),
            'order_api_base_url'   => $this->nullableString($post['order_api_base_url'] ?? '', 512),
            'websocket_url'        => $this->nullableString($post['websocket_url'] ?? '', 512),
            'user_websocket_url'   => $this->nullableString($post['user_websocket_url'] ?? '', 512),
            'grpc_host'            => $this->nullableString($post['grpc_host'] ?? '', 255),
            'fg_api_host'          => $this->nullableString($post['fg_api_host'] ?? '', 255),
            'fg_kms_host'          => $this->nullableString($post['fg_kms_host'] ?? '', 255),
            'extra_config'         => $this->nullableText($post['extra_config'] ?? ''),
            'status'               => $status,
            'sort'                 => (int)($post['sort'] ?? 0),
            'remark'               => trim((string)($post['remark'] ?? '')),
        ];
    }

    private function nullableString($value, int $max): ?string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        if (strlen($s) > $max) {
            throw new \InvalidArgumentException('URL 字段过长');
        }

        return $s;
    }

    private function nullableText($value): ?string
    {
        $s = trim((string)$value);

        return $s === '' ? null : $s;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->arboppDb()->name('platform')->where('id', $id)->find();

        return empty($row) ? null : $this->formatRow($row);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}