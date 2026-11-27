<?php
declare(strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\SportsActiveEventService;
use think\admin\Controller;
use think\facade\Db;

class SportsConfig extends Controller
{
    public function index()
    {
        $this->title = '体育市场配置';
        $get = $this->request->get();
        $query = Db::name('sports_market_config');
        foreach (['sport_type', 'run_mode'] as $field) {
            if (($get[$field] ?? '') !== '') $query->where($field, $get[$field]);
        }
        foreach (['league_id', 'league_name', 'event_id', 'event_name', 'market_key'] as $field) {
            if (($get[$field] ?? '') !== '') $query->where($field, 'like', '%' . trim((string)$get[$field]) . '%');
        }
        if (($get['status'] ?? '') !== '') $query->where('status', (int)$get['status']);
        if (!empty($get['id'])) $query->where('id', (int)$get['id']);
        $result = $query->order('id desc')->paginate([
            'list_rows' => (int)($get['pageSize'] ?? 20), 'page' => (int)($get['page'] ?? 1),
            'query' => $get, 'path' => '/admin.html#/backtest/sports_config/index.html',
        ], false);
        $list = $result->items();
        $marketIds = [];
        $ids = array_column($list, 'id');
        if ($ids) {
            foreach (Db::name('sports_market')->whereIn('sports_config_id', $ids)->field('id,sports_config_id')->select()->toArray() as $market) {
                $marketIds[$market['sports_config_id']] = $market['id'];
            }
        }
        // Keep configurations from the same league together on the current page so
        // the list can present them as one collapsible group. The original order
        // (first appearance in the query result, then id desc) is preserved.
        $grouped = [];
        $groups = [];
        foreach ($list as $row) {
            $row = $this->formatRow($row);
            $leagueKey = (string)($row['league_id'] ?? '');
            if ($leagueKey === '') $leagueKey = (string)($row['league_name'] ?? '');
            if (!array_key_exists($leagueKey, $groups)) {
                $groups[$leagueKey] = count($groups);
                $grouped[$leagueKey] = [];
            }
            $grouped[$leagueKey][] = $row;
        }
        $groupedList = [];
        $groupIndex = 0;
        foreach ($grouped as $rows) {
            $group = $groupIndex++;
            $count = count($rows);
            $events = [];
            foreach ($rows as $row) {
                $eventId = (string)($row['event_id'] ?? '');
                if ($eventId === '' || isset($events[$eventId])) continue;
                $startMs = (int)($row['event_start_time_ms'] ?? 0);
                $startAt = '';
                if ($startMs > 0) {
                    $startSeconds = $startMs > 100000000000 ? intdiv($startMs, 1000) : $startMs;
                    $startAt = date('Y-m-d H:i', $startSeconds);
                }
                $homeTeam = trim((string)($row['home_team_name'] ?? ''));
                $awayTeam = trim((string)($row['away_team_name'] ?? ''));
                if ($homeTeam === '') $homeTeam = trim((string)($row['home_team_id'] ?? ''));
                if ($awayTeam === '') $awayTeam = trim((string)($row['away_team_id'] ?? ''));
                $events[$eventId] = [
                    'home_team_name' => $homeTeam !== '' ? $homeTeam : '主队未知',
                    'away_team_name' => $awayTeam !== '' ? $awayTeam : '客队未知',
                    'start_at' => $startAt,
                ];
            }
            foreach ($rows as $index => $row) {
                $row['_league_group'] = $group;
                $row['_league_first'] = $index === 0;
                $row['_league_count'] = $count;
                $row['_league_events'] = $index === 0 ? array_values($events) : [];
                $groupedList[] = $row;
            }
        }
        $list = $groupedList;
        $this->assign(['list' => $list, 'marketIds' => $marketIds, 'pagehtml' => $result->render(), 'get' => $get]);
        return $this->fetch();
    }

    /** Return the deduplicated ClickHouse catalogue used by the cascading form. */
    public function activeEvents()
    {
        try {
            return json(['code' => 1, 'data' => (new SportsActiveEventService())->catalogue()]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'info' => $e->getMessage(), 'data' => []]);
        }
    }

    /** @auth true */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $post = $this->request->post();
                $marketKeys = $this->postedMarketKeys($post);
                Db::transaction(function () use ($post, $marketKeys): void {
                    foreach ($marketKeys as $marketKey) {
                        $data = $post;
                        $data['market_key'] = $marketKey;
                        $save = $this->hydrateActiveEvent($this->buildData($data));
                        $this->validateBusiness($save);
                        $this->assertUniqueMarket($save);
                        Db::name('sports_market_config')->insert($save);
                    }
                });
                return json(['code' => 1, 'info' => '已创建 ' . count($marketKeys) . ' 个盘口配置', 'url' => '#/backtest/sports_config/index.html']);
            } catch (\Throwable $e) { return json(['code' => 0, 'info' => $e->getMessage()]); }
        }
        $this->assign('vo', $this->defaults());
        return $this->fetch('form');
    }

    /** @auth true */
    public function edit()
    {
        $id = (int)$this->request->param('id');
        if ($this->request->isPost()) {
            try {
                if ($id <= 0 || !Db::name('sports_market_config')->where('id', $id)->find()) throw new \InvalidArgumentException('配置不存在');
                $post = $this->request->post();
                $marketKeys = $this->postedMarketKeys($post);
                if (count($marketKeys) !== 1) throw new \InvalidArgumentException('编辑单条配置时只能选择一个盘口');
                $post['market_key'] = $marketKeys[0];
                $save = $this->hydrateActiveEvent($this->buildData($post));
                $this->validateBusiness($save);
                $this->assertUniqueMarket($save, $id);
                Db::name('sports_market_config')->where('id', $id)->update($save);
                return json(['code' => 1, 'info' => '保存成功；已有市场不会被配置值覆盖', 'url' => '#/backtest/sports_config/index.html']);
            } catch (\Throwable $e) { return json(['code' => 0, 'info' => $e->getMessage()]); }
        }
        $vo = Db::name('sports_market_config')->where('id', $id)->find();
        if (!$vo) return $this->error('配置不存在');
        if (is_array($vo['micro_position_distribution'] ?? null)) {
            $vo['micro_position_distribution'] = json_encode($vo['micro_position_distribution'], JSON_UNESCAPED_UNICODE);
        }
        $vo['market_exists'] = Db::name('sports_market')->where('sports_config_id', $id)->count() > 0;
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /** @auth true */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if ($id <= 0) return json(['code' => 0, 'info' => '缺少ID']);
        Db::transaction(function () use ($id) {
            $marketIds = Db::name('sports_market')->where('sports_config_id', $id)->column('id');
            if ($marketIds) {
                Db::name('sports_placement')->whereIn('sports_market_id', $marketIds)->delete();
                Db::name('sports_market')->whereIn('id', $marketIds)->delete();
            }
            Db::name('sports_market_config')->where('id', $id)->delete();
        });
        return json(['code' => 1, 'info' => '删除成功']);
    }

    private function buildData(array $data): array
    {
        $distribution = $data['micro_position_distribution'] ?? '[0.5,0.3,0.2]';
        if (is_string($distribution)) {
            $distribution = json_decode($distribution, true);
        }
        if (!is_array($distribution) || !$distribution || array_keys($distribution) !== range(0, count($distribution) - 1)) {
            throw new \InvalidArgumentException('仓位分布必须是非空 JSON 数组');
        }
        foreach ($distribution as $item) {
            if (!is_numeric($item) || !is_finite((float)$item) || (float)$item < 0) {
                throw new \InvalidArgumentException('仓位分布必须是非负有限数值数组');
            }
        }
        return [
            'league_id' => trim((string)($data['league_id'] ?? '')), 'league_name' => trim((string)($data['league_name'] ?? '')),
            'event_id' => trim((string)($data['event_id'] ?? '')), 'event_name' => trim((string)($data['event_name'] ?? '')),
            'market_key' => trim((string)($data['market_key'] ?? '')), 'sport_type' => trim((string)($data['sport_type'] ?? '')),
            'inventory_q' => $data['inventory_q'] ?? '', 'inventory_q_cap' => $data['inventory_q_cap'] ?? 10000,
            'micro_v_bid_5s_ago' => $data['micro_v_bid_5s_ago'] ?? '', 'micro_v_bid_now' => $data['micro_v_bid_now'] ?? '',
            'micro_v_ask_5s_ago' => $data['micro_v_ask_5s_ago'] ?? '', 'micro_v_ask_now' => $data['micro_v_ask_now'] ?? '',
            'micro_v_buy' => $data['micro_v_buy'] ?? '', 'micro_v_sell' => $data['micro_v_sell'] ?? '',
            'micro_v_aggressive' => $data['micro_v_aggressive'] ?? '', 'micro_v_passive' => $data['micro_v_passive'] ?? '',
            'micro_position_distribution' => json_encode(is_array($distribution) ? array_values($distribution) : [0.5, 0.3, 0.2], JSON_UNESCAPED_UNICODE),
            'slippage_estimate' => $data['slippage_estimate'] ?? '',
            'calc_interval_ms' => (int)($data['calc_interval_ms'] ?? 1000), 'run_mode' => $data['run_mode'] ?? 'live',
            'history_start' => empty($data['history_start']) ? null : $data['history_start'], 'history_end' => empty($data['history_end']) ? null : $data['history_end'],
            'status' => (int)($data['status'] ?? 1), 'remark' => trim((string)($data['remark'] ?? '')),
            'home_team_id' => '', 'home_team_name' => '', 'away_team_id' => '', 'away_team_name' => '',
            'event_start_time_ms' => 0, 'event_end_time_ms' => 0, 'event_status' => '',
            'event_market_keys' => json_encode([], JSON_UNESCAPED_UNICODE), 'event_topic' => '',
            'event_last_published_at' => null, 'event_expires_at' => null, 'event_updated_at' => null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function hydrateActiveEvent(array $data): array
    {
        $row = (new SportsActiveEventService())->find(
            (string)$data['league_id'],
            (string)$data['event_id'],
            (string)$data['market_key']
        );
        if ($row === null) throw new \InvalidArgumentException('联赛、赛事和盘口必须来自当前 ClickHouse 活跃赛事目录');
        $data['league_name'] = (string)($row['league_name'] ?? '');
        $data['sport_type'] = (string)($row['sport'] ?? '');
        $data['event_name'] = (string)($row['event_name'] ?? '');
        $data['home_team_id'] = (string)($row['home_team_id'] ?? '');
        $data['home_team_name'] = (string)($row['home_team_name'] ?? '');
        $data['away_team_id'] = (string)($row['away_team_id'] ?? '');
        $data['away_team_name'] = (string)($row['away_team_name'] ?? '');
        $data['event_start_time_ms'] = (int)($row['start_time'] ?? 0);
        $data['event_end_time_ms'] = (int)($row['end_time'] ?? 0);
        $data['event_status'] = (string)($row['event_status'] ?? '');
        $data['event_market_keys'] = json_encode($row['market_keys'] ?? [], JSON_UNESCAPED_UNICODE);
        $data['event_topic'] = (string)($row['topic'] ?? '');
        $data['event_last_published_at'] = $row['last_published_at'] ?? null;
        $data['event_expires_at'] = $row['expires_at'] ?? null;
        $data['event_updated_at'] = $row['updated_at'] ?? null;
        return $data;
    }

    /** @param array<string, mixed> $post @return list<string> */
    private function postedMarketKeys(array $post): array
    {
        $raw = $post['market_key'] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];
        $keys = [];
        foreach ($raw as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $keys, true)) $keys[] = $value;
        }
        if (!$keys) throw new \InvalidArgumentException('请至少选择一个盘口');
        return $keys;
    }

    private function validateBusiness(array &$save): void
    {
        foreach (['league_id', 'league_name', 'event_id', 'event_name', 'market_key', 'sport_type'] as $field) if ($save[$field] === '') throw new \InvalidArgumentException("{$field} 不能为空");
        if (mb_strlen($save['league_id']) > 128 || mb_strlen($save['league_name']) > 128 || mb_strlen($save['event_id']) > 255 || mb_strlen($save['event_name']) > 255 || mb_strlen($save['market_key']) > 255) throw new \InvalidArgumentException('联赛、赛事或盘口字段过长');
        if (!in_array($save['sport_type'], ['football', 'basketball', 'esports'], true)) throw new \InvalidArgumentException('体育类型非法');
        if (!in_array($save['run_mode'], ['live', 'history'], true) || $save['calc_interval_ms'] <= 0) throw new \InvalidArgumentException('运行模式或计算间隔非法');
        if ($save['run_mode'] === 'history') {
            if (!$save['history_start'] || !$save['history_end']) throw new \InvalidArgumentException('history 模式必须填写历史区间');
            $hs = strtotime((string)$save['history_start']); $he = strtotime((string)$save['history_end']);
            if ($hs === false || $he === false || $he <= $hs) throw new \InvalidArgumentException('历史结束时间必须晚于开始时间');
        }
        foreach (['inventory_q','inventory_q_cap','micro_v_bid_5s_ago','micro_v_bid_now','micro_v_ask_5s_ago','micro_v_ask_now','micro_v_buy','micro_v_sell','micro_v_aggressive','micro_v_passive','slippage_estimate'] as $field) {
            if (!is_numeric($save[$field]) || !is_finite((float)$save[$field])) throw new \InvalidArgumentException("{$field} 必须是有限数值");
            $save[$field] = (float)$save[$field];
        }
        if ($save['inventory_q_cap'] <= 0 || abs($save['inventory_q']) > $save['inventory_q_cap']) throw new \InvalidArgumentException('库存 Q 必须位于 q_cap 范围内');
        foreach (['micro_v_bid_5s_ago','micro_v_bid_now','micro_v_ask_5s_ago','micro_v_ask_now','micro_v_buy','micro_v_sell','micro_v_aggressive','micro_v_passive','slippage_estimate'] as $field) if ($save[$field] < 0) throw new \InvalidArgumentException("{$field} 不得小于 0");
        $distribution = json_decode($save['micro_position_distribution'], true);
        if (!is_array($distribution) || !$distribution) throw new \InvalidArgumentException('仓位分布必须是非空数组');
        if (!in_array($save['status'], [0, 1], true) || mb_strlen($save['remark']) > 500) throw new \InvalidArgumentException('状态或备注非法');
    }

    private function defaults(): array
    {
        return ['sport_type'=>'football','inventory_q'=>6000,'inventory_q_cap'=>10000,'micro_v_bid_5s_ago'=>1200,'micro_v_bid_now'=>1200.08,'micro_v_ask_5s_ago'=>1100,'micro_v_ask_now'=>1100.02,'micro_v_buy'=>420,'micro_v_sell'=>300,'micro_v_aggressive'=>720,'micro_v_passive'=>960,'micro_position_distribution'=>'[0.5,0.3,0.2]','slippage_estimate'=>0.0032,'calc_interval_ms'=>1000,'run_mode'=>'live','status'=>1,'league_id'=>'','league_name'=>'','event_id'=>'','event_name'=>'','market_key'=>[]];
    }

    private function assertUniqueMarket(array $save, int $excludeId = 0): void
    {
        $query = Db::name('sports_market_config')->where('league_id', $save['league_id'])->where('event_id', $save['event_id'])->where('market_key', $save['market_key']);
        if ($excludeId > 0) $query->where('id', '<>', $excludeId);
        if ($query->find()) throw new \InvalidArgumentException('该联赛赛事下盘口已存在');
    }
    private function formatRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $text = (string)$value;
            if (is_numeric($value) && str_contains($text, '.') && !in_array($key, ['id', 'status', 'calc_interval_ms'], true)) {
                $trimmed = rtrim(rtrim($text, '0'), '.');
                $row[$key] = in_array($trimmed, ['', '-', '-0'], true) ? '0' : $trimmed;
            }
        }
        return $row;
    }
}
