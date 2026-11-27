<?php
declare(strict_types=1);

namespace app\cool_pay2\trait;

use think\facade\Cache;
use think\facade\Db;

/**
 * 列表 is_win 标签共用逻辑（SourceTradeRecords / TargetTradeRecords 用）。
 *
 * is_win 口径（与 PnlCalcTrait 头注释一致）：
 *   Poly 侧（source_trader_records 行）:
 *     source.source_token_id == market_mapping_config.source_winning_token_id → WIN
 *     source_winning_token_id 为空 → PENDING（未结算）
 *
 *   FG 侧（target_trader_records 行）:
 *     target.target_token_id == market_mapping_config.token_id_mapping JSON 反查后的 targetTokenId → WIN
 *     mapping JSON 反查不到 / source_winning_token_id 为空 → PENDING
 *
 * 用法：
 *   $lookup = $this->loadIsWinLookup($conn, $mcIds);     // 跨片：必须在分片闭包内调
 *   foreach ($rows as &$r) $r['is_win'] = $this->computeIsWinPoly($r, $lookup);
 *
 * mc_id 是分片本地自增 → 调用 loadIsWinLookup 时务必传入"该分片上"的 mc_id 列表，
 * 不能跨片传 lookup 或 mc_id。
 *
 * 注意：DataReport 有自己的简化版 loadWinningTokenLookup（只关心 Poly 侧），
 * 没用本 trait 是为了不动正在性能调试的代码；如果将来 DataReport 也要切到这里，
 * 把 DataReport::loadWinningTokenLookup / computeIsWin 删掉、use 本 trait + 用 computeIsWinPoly 即可。
 */
trait IsWinLookupTrait
{
    /**
     * 在一个分片上加载 mc_id → (Poly 赢家 token, FG 赢家 token) 查找表。
     *
     * @param string     $conn   ThinkPHP 连接名（必填，强制分片本地）
     * @param array|null $mcIds  null = 加载本片全表（export 大批量场景）；[] = 直接空；非空数组 = whereIn
     * @return array<int, array{poly:string, fg:?string}>
     *         key 是 market_config_id；poly = source_winning_token_id；
     *         fg = token_id_mapping JSON 反查后的 targetTokenId（反查失败时为 null）
     */
    protected function loadIsWinLookup(string $conn, ?array $mcIds): array
    {
        if ($mcIds !== null && $mcIds === []) {
            return [];
        }
        // 跨请求 file cache（5 分钟 TTL）。mmc 只在新市场上线 / 结算时变；
        // 命中后整段 lookup（dev 400-2500ms / prod 1-4s）→ ms 级。
        if ($mcIds === null) {
            $sig = '__ALL__';
        } else {
            $ids = array_map('intval', $mcIds);
            sort($ids);
            $sig = implode(',', $ids);
        }
        $cacheKey = 'iswin_lookup_' . md5($conn . '|' . $sig);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $q = Db::connect($conn)->name('market_mapping_config')
            ->field('id, source_winning_token_id, token_id_mapping');
        if ($mcIds !== null) {
            $q->whereIn('id', $mcIds);
        }
        $out = [];
        foreach ($q->select()->toArray() as $r) {
            $poly = (string)($r['source_winning_token_id'] ?? '');
            $fg = null;
            if ($poly !== '') {
                $map = json_decode((string)($r['token_id_mapping'] ?? ''), true);
                if (is_array($map)) {
                    foreach ($map as $pair) {
                        if (!is_array($pair)) {
                            continue;
                        }
                        $sid = (string)($pair['sourceTokenId'] ?? '');
                        $tid = (string)($pair['targetTokenId'] ?? '');
                        if ($sid !== '' && $sid === $poly && $tid !== '') {
                            $fg = $tid;
                            break;
                        }
                    }
                }
            }
            $out[(int)$r['id']] = ['poly' => $poly, 'fg' => $fg];
        }
        Cache::set($cacheKey, $out, 300);
        return $out;
    }

    /**
     * Poly 侧（source_trader_records 一行）→ 'WIN' / 'LOSE' / 'PENDING'
     *
     * @param array|\ArrayAccess              $row    一行 trader 记录
     * @param array<int, array{poly:string,fg:?string}> $lookup loadIsWinLookup 返回
     */
    protected function computeIsWinPoly($row, array $lookup): string
    {
        $mc = (int)($row['market_config_id'] ?? 0);
        $w = (string)($lookup[$mc]['poly'] ?? '');
        if ($w === '') {
            return 'PENDING';
        }
        return ((string)($row['source_token_id'] ?? '') === $w) ? 'WIN' : 'LOSE';
    }

    /**
     * FG 侧（target_trader_records 一行）→ 'WIN' / 'LOSE' / 'PENDING'
     *
     * @param array|\ArrayAccess              $row    一行 trader 记录
     * @param array<int, array{poly:string,fg:?string}> $lookup loadIsWinLookup 返回
     */
    protected function computeIsWinFg($row, array $lookup): string
    {
        $mc = (int)($row['market_config_id'] ?? 0);
        $w = $lookup[$mc]['fg'] ?? null;
        if ($w === null || $w === '') {
            return 'PENDING';
        }
        return ((string)($row['target_token_id'] ?? '') === (string)$w) ? 'WIN' : 'LOSE';
    }
}