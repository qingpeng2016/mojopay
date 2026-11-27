<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class ConfigStrategyFee extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '手续费系数';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('platform', 'asc')->order('id', 'asc'),
            $get,
            'config_strategy_fee/index'
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => array_combine(Enum::getPlatforms(), Enum::getPlatforms()),
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
            if ($this->dup($data['platform'], $data['category'], $data['sub_category'], null)) {
                return $this->error('platform + category + sub_category 已存在');
            }
            $this->arboppDb()->name('config_strategy_fee')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', ['status' => 1, 'category' => 'Sports', 'sub_category' => '']);
        $this->assign('platforms', Enum::getPlatforms());
        $this->assign('tiny_status', Enum::getTinyStatuses());

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
            if ($this->dup($data['platform'], $data['category'], $data['sub_category'], $id)) {
                return $this->error('platform + category + sub_category 已存在');
            }
            $this->arboppDb()->name('config_strategy_fee')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $this->assign('vo', $vo);
        $this->assign('platforms', Enum::getPlatforms());
        $this->assign('tiny_status', Enum::getTinyStatuses());

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('config_strategy_fee');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        $this->applyOptionalLike($query, $get, 'category', 'category', 64);
        $this->applyOptionalLike($query, $get, 'sub_category', 'sub_category', 64);

        return $query;
    }

    private function dup(string $platform, string $category, string $sub, ?int $exclude): bool
    {
        $q = $this->arboppDb()->name('config_strategy_fee')
            ->where('platform', $platform)
            ->where('category', $category)
            ->where('sub_category', $sub);
        if ($exclude) {
            $q->where('id', '<>', $exclude);
        }

        return !empty($q->find());
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $platform = $this->sanitizeEnum(strtoupper(trim((string)($post['platform'] ?? ''))), Enum::getPlatforms());
        if ($platform === null) {
            throw new \InvalidArgumentException('platform 无效');
        }
        $category = trim((string)($post['category'] ?? ''));
        if ($category === '' || strlen($category) > 64) {
            throw new \InvalidArgumentException('category 无效');
        }
        $sub = trim((string)($post['sub_category'] ?? ''));
        if (strlen($sub) > 64) {
            throw new \InvalidArgumentException('sub_category 过长');
        }
        $coef = trim((string)($post['taker_fee_coef'] ?? ''));
        if ($coef === '' || !is_numeric($coef) || !is_finite((float)$coef)) {
            throw new \InvalidArgumentException('taker_fee_coef 须为有效数字');
        }
        $status = $this->sanitizeEnum((string)($post['status'] ?? '1'), Enum::getTinyStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 须为 0 或 1');
        }

        return [
            'platform'        => $platform,
            'category'        => $category,
            'sub_category'    => $sub,
            'taker_fee_coef'  => $coef,
            'description'     => trim((string)($post['description'] ?? '')),
            'status'          => (int)$status,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->arboppDb()->name('config_strategy_fee')->where('id', $id)->find();

        return empty($row) ? null : $this->formatRow($row);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['taker_fee_coef_fmt'] = $this->formatDecimalField($row['taker_fee_coef'] ?? null);
        $row['updated_at']         = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}