<?php
declare(strict_types=1);

namespace app\cool_pay2\controller;

/**
 * 做市仪表盘（数据洞察2 · 财务精简版）
 *
 * 只保留「维度切换器 + 核心 PNL 指标卡片」，供财务按维度/时间筛选查看 PNL。
 * 继承 {@see DataInsight} 复用其全部 PNL 计算逻辑（PNL 公式为强一致性要害，不重复实现），
 * 仅重写 index() 做精简装配 + 换用精简视图 data_insight2/index.html。
 * 卡片的 AJAX 局部刷新沿用继承来的 cards() 接口。
 */
class DataInsight2 extends DataInsight
{
    /**
     * 数据洞察2（财务）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '数据洞察2';
        $get = $this->request->get();

        $rangePreset = $get['range_preset'] ?? '30';
        [$rangeStart, $rangeEnd] = $this->resolveRange($rangePreset, $get);
        $targetGroupId = isset($get['target_group_id']) && $get['target_group_id'] !== ''
            ? trim((string)$get['target_group_id']) : '';
        $marketCategory = isset($get['market_category']) && $get['market_category'] !== ''
            ? trim((string)$get['market_category']) : '';
        $marketSubCategory = isset($get['market_sub_category']) && $get['market_sub_category'] !== ''
            ? trim((string)$get['market_sub_category']) : '';
        $allowedGroups = $this->allowedTargetGroupIds($marketCategory, $marketSubCategory);

        $cards = $this->coreMetrics($rangeStart, $rangeEnd, '', $targetGroupId, $allowedGroups);

        $categories = [
            'Sports', 'Politics', 'Crypto', 'Finance', 'Geopolitics', 'Earnings',
            'Tech', 'Culture', 'World', 'Economy', 'Climate & Science', 'Elections', 'Mentions',
        ];

        $this->assign([
            'get'               => $get,
            'range_preset'      => $rangePreset,
            'range_start'       => $rangeStart,
            'range_end'         => $rangeEnd,
            'cards'             => $cards,
            'market_categories' => $categories,
        ]);

        return $this->fetch();
    }
}
