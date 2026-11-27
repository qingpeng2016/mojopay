<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;

/**
 * ArbOpp 首页：按平台 Tab 展示已开赛且 ACTIVE 的 market
 */
class Dashboard extends Controller
{
    use ArboppListTrait;

    private const LIVE_MARKET_LIMIT = 500;

    private const STAT_GROUP_MAP_KEY_AUDIT = 'map_key_audit';

    private const STAT_ITEM_MARKET_RUNNING = 'market_running_count_strategy';

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'ArbOpp 首页';
        $get         = $this->request->get();
        $strategyRunning = $this->loadStrategyRunningMarketGroups();
        $blocks          = $this->loadLiveMarketsByPlatform();
        $selected    = strtoupper(trim((string)($get['platform'] ?? '')));
        if ($selected === '' && $blocks !== []) {
            $selected = (string)$blocks[0]['platform'];
        }
        $hasSelected = false;
        foreach ($blocks as &$block) {
            $block['active'] = ($selected !== '' && $block['platform'] === $selected);
            if ($block['active']) {
                $hasSelected = true;
            }
        }
        unset($block);
        if (!$hasSelected && $blocks !== []) {
            $blocks[0]['active'] = true;
            $selected            = (string)$blocks[0]['platform'];
        }

        $this->assign([
            'strategy_running' => $strategyRunning,
            'platform_blocks'  => $blocks,
            'selected_platform' => $selected,
            'utc_now'          => gmdate('Y-m-d H:i:s'),
            'total_live'       => array_sum(array_column($blocks, 'count')),
        ]);

        return $this->fetch();
    }

    /**
     * stat_kv map_key_audit / market_running_count_strategy：description JSON groups → 按 map_key 查 market
     *
     * @return array{
     *   ok: bool,
     *   group_count: int,
     *   pre_event_scan_minutes: int|null,
     *   end_event_scan_minutes: int|null,
     *   updated_at: string,
     *   map_key_groups: list<array{map_key: string, market_ids: array<string, int>, rows: list<array<string, mixed>>}>
     * }
     */
    private function loadStrategyRunningMarketGroups(): array
    {
        $empty = [
            'ok'                     => false,
            'group_count'            => 0,
            'pre_event_scan_minutes' => null,
            'end_event_scan_minutes' => null,
            'updated_at'             => '-',
            'map_key_groups'         => [],
        ];

        $row = $this->arboppDb()->name('stat_kv')
            ->where('group_name', self::STAT_GROUP_MAP_KEY_AUDIT)
            ->where('item_key', self::STAT_ITEM_MARKET_RUNNING)
            ->order('id', 'desc')
            ->find();
        if (empty($row)) {
            return $empty;
        }

        $jsonText = trim((string)($row['description'] ?? ''));
        if ($jsonText === '') {
            $jsonText = trim((string)($row['item_value'] ?? ''));
        }
        $payload = json_decode($jsonText, true);
        if (!is_array($payload)) {
            return $empty;
        }

        $groups = $payload['groups'] ?? [];
        if (!is_array($groups) || $groups === []) {
            return [
                'ok'                     => true,
                'group_count'            => (int)($row['item_value'] ?? 0),
                'pre_event_scan_minutes' => isset($payload['pre_event_scan_minutes']) ? (int)$payload['pre_event_scan_minutes'] : null,
                'end_event_scan_minutes' => isset($payload['end_event_scan_minutes']) ? (int)$payload['end_event_scan_minutes'] : null,
                'updated_at'             => $this->formatDatetime($row['updated_at'] ?? null),
                'map_key_groups'         => [],
            ];
        }

        $mapKeys = [];
        $metaByKey = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $mk = trim((string)($g['map_key'] ?? ''));
            if ($mk === '') {
                continue;
            }
            $mapKeys[] = $mk;
            $marketIds = [];
            if (isset($g['markets']) && is_array($g['markets'])) {
                foreach ($g['markets'] as $plat => $mid) {
                    $marketIds[strtoupper(trim((string)$plat))] = (int)$mid;
                }
            }
            $metaByKey[$mk] = ['market_ids' => $marketIds];
        }
        $mapKeys = array_values(array_unique($mapKeys));

        $byMapKey = [];
        if ($mapKeys !== []) {
            $markets = $this->arboppDb()->name('market')
                ->whereIn('map_key', $mapKeys)
                ->order('platform', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($markets as $m) {
                $mk = trim((string)($m['map_key'] ?? ''));
                if ($mk === '') {
                    continue;
                }
                $byMapKey[$mk][] = $this->formatMarketRow($m);
            }
        }

        $mapKeyGroups = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $mk = trim((string)($g['map_key'] ?? ''));
            if ($mk === '') {
                continue;
            }
            $ids = $metaByKey[$mk]['market_ids'] ?? [];
            $idParts = [];
            foreach ($ids as $plat => $mid) {
                $idParts[] = $plat . '=' . $mid;
            }
            $mapKeyGroups[] = [
                'map_key'         => $mk,
                'market_ids'      => $ids,
                'market_ids_fmt'  => implode(' · ', $idParts),
                'rows'            => $byMapKey[$mk] ?? [],
            ];
        }

        return [
            'ok'                     => true,
            'group_count'            => count($mapKeyGroups),
            'pre_event_scan_minutes' => isset($payload['pre_event_scan_minutes']) ? (int)$payload['pre_event_scan_minutes'] : null,
            'end_event_scan_minutes' => isset($payload['end_event_scan_minutes']) ? (int)$payload['end_event_scan_minutes'] : null,
            'updated_at'             => $this->formatDatetime($row['updated_at'] ?? null),
            'map_key_groups'         => $mapKeyGroups,
        ];
    }

    /**
     * @return list<array{platform: string, count: int, rows: list<array<string, mixed>>}>
     */
    private function loadLiveMarketsByPlatform(): array
    {
        $rows = $this->arboppDb()->name('market')
            ->where('status', 'ACTIVE')
            ->whereNotNull('event_start_time')
            ->where('event_start_time', '<>', '')
            ->whereRaw('STR_TO_DATE(event_start_time, "%Y-%m-%d %H:%i:%s") <= UTC_TIMESTAMP()')
            ->order('event_start_time', 'desc')
            ->order('id', 'desc')
            ->limit(self::LIVE_MARKET_LIMIT)
            ->select()
            ->toArray();

        $grouped = [];
        foreach ($rows as $row) {
            $platform = strtoupper(trim((string)($row['platform'] ?? '')));
            if ($platform === '') {
                $platform = 'UNKNOWN';
            }
            $grouped[$platform][] = $this->formatMarketRow($row);
        }
        ksort($grouped);

        $blocks = [];
        foreach ($grouped as $platform => $items) {
            $blocks[] = [
                'platform' => $platform,
                'count'    => count($items),
                'rows'     => $items,
                'active'   => false,
            ];
        }

        return $blocks;
    }

    /** @param array<string, mixed> $row */
    private function formatMarketRow(array $row): array
    {
        $row['name_fmt']    = $this->truncateText($row['name'] ?? '', 72);
        $row['map_key_fmt'] = $this->truncateText($row['map_key'] ?? '', 48);

        return $row;
    }
}