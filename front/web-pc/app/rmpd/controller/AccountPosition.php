<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 账户持仓（逐仓明细，只读）
 *
 * account_positions 表存储各账户在各平台的逐仓持仓明细，由持仓同步任务定期拉取：
 *   - size：           持仓份数
 *   - avg_price：      建仓均价
 *   - current_price：  当前价
 *   - market_value：   当前市值（size × current_price）
 *   - unrealized_pnl： 未实现盈亏
 *   - redeemable：     是否可赎回（市场已结算可领取）
 *   - synced_at：      最近一次同步时间
 *
 * 相关数据库表：account_positions
 */
class AccountPosition extends Controller
{
    use SafeQueryTrait;

    /**
     * 账户持仓列表（按主键 id 降序）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '账户持仓';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('account_positions');

        // ── 按主键 ID 精确查找 ────────────────────────────────────────────
        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        // ── 按账户 ID 精确查找（供账户信息页下钻） ────────────────────────
        if (!empty($get['account_id'])) {
            $aid = $this->sanitizeId($get['account_id']);
            if ($aid) {
                $query->where('account_id', $aid);
            }
        }

        // ── 按平台过滤（polymarket|kalshi） ───────────────────────────────
        if (!empty($get['platform'])) {
            $pf = $this->sanitizeEnum($get['platform'], Enum::getAllPlatforms());
            if ($pf) {
                $query->where('platform', $pf);
            }
        }

        // ── 按市场问题/名称模糊搜索 ───────────────────────────────────────
        if (!empty($get['market_name'])) {
            $query->where('market_name', 'like', '%' . $this->sanitizeLike($get['market_name']) . '%');
        }

        // ── 按市场 ID 模糊搜索 ────────────────────────────────────────────
        if (!empty($get['market_id'])) {
            $query->where('market_id', 'like', '%' . $this->sanitizeLike($get['market_id'], 255) . '%');
        }

        // ── 按结果方向 outcome 模糊搜索（YES/NO/队名等） ──────────────────
        if (!empty($get['outcome'])) {
            $query->where('outcome', 'like', '%' . $this->sanitizeLike($get['outcome'], 64) . '%');
        }

        // ── 按是否可赎回过滤（redeemable 0/1） ───────────────────────────
        if (isset($get['redeemable']) && $get['redeemable'] !== '') {
            if (in_array($get['redeemable'], ['0', '1'], true)) {
                $query->where('redeemable', (int)$get['redeemable']);
            }
        }

        // ── 按同步时间范围过滤 ────────────────────────────────────────────
        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('synced_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            }
        }

        // 排序：主键 id 降序（产品要求）
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/account_position/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$item) {
            foreach (['synced_at', 'created_at', 'updated_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        $stats = $this->buildStats(Db::connect('db_rmpd'));

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => Enum::getPlatformLabels(),
            'stats'     => $stats,
        ]);

        return $this->fetch();
    }

    /**
     * 顶部持仓统计（全表口径，逐仓真实市值）：
     *   - position_count： 持仓笔数（account_positions 行数，一行=某账户某token一笔持仓）
     *   - market_value_total： 持仓总市值 = Σ market_value
     *   - unrealized_pnl_total：未实现盈亏 = Σ unrealized_pnl
     *   - platform_dist：平台持仓对比（按 platform 动态分组：仓数 + Σmarket_value），降序
     *
     * @return array
     */
    private function buildStats($conn): array
    {
        $agg = $conn->query(
            "SELECT COUNT(*) AS cnt, SUM(market_value) AS mv, SUM(unrealized_pnl) AS pnl FROM account_positions"
        );
        $a = $agg[0] ?? [];

        // 平台持仓市值：按 platform 动态分组累加。纯静态 SQL。
        $platformRows = $conn->query(
            "SELECT platform, SUM(market_value) AS mv, COUNT(*) AS cnt
             FROM account_positions GROUP BY platform ORDER BY mv DESC"
        );
        $platformDist = [];
        $platformMax  = 0.0;
        foreach ($platformRows as $r) {
            $v = (float)$r['mv'];
            $pf = (string)($r['platform'] ?? '');
            // 平台名首字母大写显示，与全站一致（polymarket→Polymarket、kalshi→Kalshi）
            $pfLabel = $pf !== '' ? ucfirst($pf) : '-';
            $platformDist[] = [
                'label' => $pfLabel,
                'title' => $pfLabel . '（' . (int)$r['cnt'] . ' 笔持仓）',
                'value' => $v,
                'cnt'   => (int)$r['cnt'],
            ];
            if ($v > $platformMax) {
                $platformMax = $v;
            }
        }

        return [
            'position_count'      => (int)($a['cnt'] ?? 0),
            'market_value_total'  => (float)($a['mv'] ?? 0),
            'unrealized_pnl_total'=> (float)($a['pnl'] ?? 0),
            'platform_dist'       => $platformDist,
            'platform_max'        => $platformMax,
        ];
    }
}
