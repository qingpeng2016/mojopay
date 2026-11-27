<?php
declare(strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\SportsActiveEventService;
use think\admin\Controller;
use think\Paginator;

/**
 * 体育活跃赛事：完整展示 ClickHouse fgmm_data.sports_active_event 目录。
 * 按比赛开始时间升序排列，最近的赛事排在前面。
 */
class SportsActiveEvent extends Controller
{
    /** 优先展示的列顺序；未在列表中的列会追加在末尾，保证“完整展示”。 */
    private const PREFERRED_COLUMNS = [
        'league_id', 'league_name', 'sport', 'event_id', 'event_name',
        'home_team_id', 'home_team_name', 'away_team_id', 'away_team_name',
        'start_time', 'end_time', 'event_status', 'market_keys', 'topic',
        'last_published_at', 'expires_at', 'updated_at',
    ];

    /** 需要格式化为日期时间的列（数值按 unix 秒/毫秒处理）。 */
    private const TIMESTAMP_COLUMNS = [
        'start_time', 'end_time', 'last_published_at', 'expires_at', 'updated_at',
    ];

    /** 列名 -> 中文标签映射；未列出的列回退为原始列名。 */
    private const COLUMN_LABELS = [
        'league_id' => '联赛ID', 'league_name' => '联赛', 'sport' => '运动类型',
        'event_id' => '赛事ID', 'event_name' => '赛事', 'home_team_id' => '主队ID',
        'home_team_name' => '主队', 'away_team_id' => '客队ID', 'away_team_name' => '客队',
        'start_time' => '开始时间', 'end_time' => '结束时间', 'event_status' => '赛事状态',
        'market_keys' => '盘口(玩法)', 'topic' => '主题', 'last_published_at' => '最后发布',
        'expires_at' => '过期时间', 'updated_at' => '更新时间',
    ];

    public function index()
    {
        $this->title = '体育活跃赛事';
        $get = $this->request->get();

        try {
            $service = new SportsActiveEventService();
            $rows = $service->all();
        } catch (\Throwable $e) {
            $this->assign([
                'list' => [], 'columns' => [], 'labels' => [], 'pagehtml' => '',
                'get' => $get, 'error' => $e->getMessage(),
            ]);
            return $this->fetch();
        }

        $rows = $this->applyFilters($rows, $get);

        // 按比赛开始时间升序：最近的赛事排在前面。
        usort($rows, function (array $a, array $b): int {
            return $this->sortKey($a) <=> $this->sortKey($b);
        });

        $total = count($rows);
        $listRows = (int)($get['pageSize'] ?? 20);
        $currentPage = (int)($get['page'] ?? 1);
        if ($listRows <= 0) $listRows = 20;
        if ($currentPage <= 0) $currentPage = 1;

        $columns = $this->resolveColumns($rows);
        $labels = $this->resolveLabels($columns);
        $slice = array_slice($rows, ($currentPage - 1) * $listRows, $listRows, false);

        $list = [];
        foreach ($slice as $row) {
            $display = [];
            foreach ($columns as $col) {
                $display[$col] = $this->formatCell($col, $row[$col] ?? null);
            }
            $list[] = ['_display' => $display, '_raw' => $row];
        }

        $paginator = Paginator::make($list, $listRows, $currentPage, $total, false, [
            'path' => '/admin.html#/backtest/sports_active_event/index.html',
            'query' => $get,
        ]);

        $this->assign([
            'list' => $list,
            'columns' => $columns,
            'labels' => $labels,
            'pagehtml' => $paginator->render(),
            'get' => $get,
            'error' => '',
        ]);
        return $this->fetch();
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function applyFilters(array $rows, array $get): array
    {
        foreach (['league_id', 'league_name', 'event_id', 'event_name', 'sport', 'event_status'] as $field) {
            if (($get[$field] ?? '') === '') continue;
            $needle = (string)$get[$field];
            $rows = array_filter($rows, function (array $row) use ($field, $needle): bool {
                return stripos((string)($row[$field] ?? ''), $needle) !== false;
            });
        }
        if (($get['market_key'] ?? '') !== '') {
            $needle = (string)$get['market_key'];
            $rows = array_filter($rows, function (array $row) use ($needle): bool {
                $keys = $row['market_keys'] ?? [];
                if (!is_array($keys)) return false;
                foreach ($keys as $key) {
                    if (stripos((string)$key, $needle) !== false) return true;
                }
                return false;
            });
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function sortKey(array $row): int
    {
        $value = $row['start_time'] ?? 0;
        if (is_numeric($value)) return (int)$value;
        $time = strtotime((string)$value);
        return $time === false ? 0 : $time;
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> */
    private function resolveColumns(array $rows): array
    {
        $seen = [];
        foreach (self::PREFERRED_COLUMNS as $col) $seen[$col] = true;
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!isset($seen[$key])) $seen[$key] = true;
            }
        }
        $ordered = [];
        foreach (self::PREFERRED_COLUMNS as $col) {
            if (isset($seen[$col])) {
                $ordered[] = $col;
                unset($seen[$col]);
            }
        }
        foreach (array_keys($seen) as $col) $ordered[] = $col;
        return $ordered;
    }

    /** @param list<string> $columns @return array<string, string> */
    private function resolveLabels(array $columns): array
    {
        $labels = [];
        foreach ($columns as $col) {
            $labels[$col] = self::COLUMN_LABELS[$col] ?? $col;
        }
        return $labels;
    }

    /**
     * @param mixed $value
     */
    private function formatCell(string $col, $value): string
    {
        if ($value === null) return '';
        if (is_array($value)) return implode(', ', $value);
        if (in_array($col, self::TIMESTAMP_COLUMNS, true) && is_numeric($value)) {
            $sec = (int)$value;
            if ($sec <= 0) return '';
            if ($sec > 1e11) $sec = intdiv($sec, 1000);
            return date('Y-m-d H:i:s', $sec);
        }
        return (string)$value;
    }
}
