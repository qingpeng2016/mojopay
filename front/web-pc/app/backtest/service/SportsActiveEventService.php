<?php
declare(strict_types=1);

namespace app\backtest\service;

/**
 * Reads the deduplicated active-event catalogue from ClickHouse.
 *
 * The catalogue is intentionally read with FINAL. The corresponding table
 * uses ReplacingMergeTree(updated_at), so one latest row is returned per
 * event_id after merges.
 */
final class SportsActiveEventService
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->clickhouseSelect(
            'SELECT * FROM fgmm_data.sports_active_event FINAL ORDER BY league_id, event_id'
        );
        foreach ($rows as &$row) {
            $row = $this->normalize($row);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int, array<string, mixed>> 联赛→赛事→盘口 嵌套结构 */
    public function catalogue(): array
    {
        $leagues = [];
        foreach ($this->all() as $row) {
            $leagueId = (string)($row['league_id'] ?? '');
            if ($leagueId === '') continue;
            if (!isset($leagues[$leagueId])) {
                $leagues[$leagueId] = [
                    'league_id'   => $leagueId,
                    'league_name' => (string)($row['league_name'] ?? ''),
                    'events'      => [],
                ];
            }
            $eventId = (string)($row['event_id'] ?? '');
            if ($eventId === '' || isset($leagues[$leagueId]['events'][$eventId])) continue;
            $leagues[$leagueId]['events'][$eventId] = [
                'event_id'       => $eventId,
                'event_name'     => (string)($row['event_name'] ?? ''),
                'sport'          => (string)($row['sport'] ?? ''),
                'home_team_id'   => (string)($row['home_team_id'] ?? ''),
                'home_team_name' => (string)($row['home_team_name'] ?? ''),
                'away_team_id'   => (string)($row['away_team_id'] ?? ''),
                'away_team_name' => (string)($row['away_team_name'] ?? ''),
                'start_time'     => (int)($row['start_time'] ?? 0),
                'end_time'       => (int)($row['end_time'] ?? 0),
                'event_status'   => (string)($row['event_status'] ?? ''),
                'market_keys'    => $row['market_keys'] ?? [],
                'topic'          => (string)($row['topic'] ?? ''),
            ];
        }
        $result = array_values($leagues);
        foreach ($result as &$league) {
            $league['events'] = array_values($league['events']);
        }
        unset($league);
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(string $leagueId, string $eventId, string $marketKey): ?array
    {
        $leagueId = trim($leagueId);
        $eventId = trim($eventId);
        $marketKey = trim($marketKey);
        if ($leagueId === '' || $eventId === '' || $marketKey === '') return null;
        foreach ($this->all() as $row) {
            if ((string)($row['league_id'] ?? '') !== $leagueId || (string)($row['event_id'] ?? '') !== $eventId) continue;
            if (!in_array($marketKey, $row['market_keys'] ?? [], true)) return null;
            $row['selected_market_key'] = $marketKey;
            return $row;
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function clickhouseSelect(string $sql): array
    {
        $cfg = config('database.clickhouse', []);
        [$addrHost, $addrPort] = $this->parseAddr((string)($cfg['addr'] ?? ''));
        $host = (string)($cfg['host'] ?? ($addrHost ?: '127.0.0.1'));
        $httpPort = (int)($cfg['http_port'] ?? 0);
        if ($httpPort <= 0) {
            $nativePort = (int)($cfg['port'] ?? ($addrPort ?: 9000));
            $httpPort = $nativePort === 9000 ? 8123 : $nativePort;
        }
        $headers = ['Content-Type: text/plain; charset=utf-8'];
        $user = (string)($cfg['username'] ?? $cfg['user'] ?? '');
        if ($user !== '') {
            $headers[] = 'X-ClickHouse-User: ' . $user;
            $headers[] = 'X-ClickHouse-Key: ' . (string)($cfg['password'] ?? '');
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $sql . ' FORMAT JSON',
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $url = sprintf(
            'http://%s:%d/?database=%s',
            $host,
            $httpPort,
            rawurlencode((string)($cfg['database'] ?? 'default'))
        );
        $body = @file_get_contents($url, false, $context);
        if ($body === false) throw new \RuntimeException('ClickHouse 查询失败，请检查连接配置');
        $json = json_decode($body, true);
        if (!is_array($json)) throw new \RuntimeException('ClickHouse 返回非 JSON');
        if (!empty($json['exception'])) throw new \RuntimeException((string)($json['exception']['message'] ?? 'ClickHouse 查询异常'));
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $keys = $row['market_keys'] ?? [];
        if (is_string($keys)) {
            $decoded = json_decode($keys, true);
            $keys = is_array($decoded) ? $decoded : [];
        }
        $row['market_keys'] = array_values(array_unique(array_map('strval', is_array($keys) ? $keys : [])));
        return $row;
    }

    /** @return array{0:string,1:int} */
    private function parseAddr(string $addr): array
    {
        $parts = explode(':', trim($addr), 2);
        return [$parts[0] ?? '', isset($parts[1]) ? (int)$parts[1] : 0];
    }
}
