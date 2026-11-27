<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 账户持仓（只读）
 *
 * accounts 表存储各平台（POLYMARKET / KALSHI / FOREGATE…）交易账户的权益快照，
 * 由 account_balance_syncer 任务每 5 分钟同步：
 *   - available_balance：可用 USDC / 现金
 *   - frozen_balance：   持仓现值（当前未平仓持仓的市值）
 *   - balance：          总权益（可用 + 持仓）
 *
 * ⚠️ 安全：accounts.info / accounts.token 含平台登录 token、Kalshi 私钥 / 登录密码等敏感信息，
 * 本页一律不查询、不展示这两列。
 *
 * 相关数据库表：accounts
 */
class Account extends Controller
{
    use SafeQueryTrait;

    /** 只读安全字段（明确排除 info / token 敏感列） */
    private const SAFE_FIELDS = 'id, vendor, account_type, market_account_id, `desc` AS account_desc, '
        . 'balance, available_balance, frozen_balance, status, created_at, updated_at';

    /**
     * 账户持仓列表（按主键 id 降序）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '账户信息';
        $get = $this->request->get();

        $conn = Db::connect('db_rmpd');

        // 动态取平台(vendor)清单：既作筛选下拉选项，也作 vendor 过滤的白名单。
        $vendors = array_values(array_filter($conn->name('accounts')->distinct(true)->column('vendor')));

        $query = $conn->name('accounts')->fieldRaw(self::SAFE_FIELDS);

        // ── 按主键 ID 精确查找 ────────────────────────────────────────────
        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        // ── 按平台(vendor)过滤（动态白名单校验） ─────────────────────────
        if (!empty($get['vendor'])) {
            $vd = $this->sanitizeEnum($get['vendor'], $vendors);
            if ($vd !== null) {
                $query->where('vendor', $vd);
            }
        }

        // ── 按账户类型模糊搜索 ────────────────────────────────────────────
        if (!empty($get['account_type'])) {
            $query->where('account_type', 'like', '%' . $this->sanitizeLike($get['account_type']) . '%');
        }

        // ── 按平台账户ID模糊搜索 ──────────────────────────────────────────
        if (!empty($get['market_account_id'])) {
            $query->where('market_account_id', 'like', '%' . $this->sanitizeLike($get['market_account_id'], 255) . '%');
        }

        // ── 按状态过滤（Normal 等，动态字符串精确匹配） ────────────────────
        if (!empty($get['status'])) {
            $query->where('status', $this->sanitizeLike($get['status'], 50));
        }

        // 排序：主键 id 降序（产品要求）
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/account/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$item) {
            foreach (['created_at', 'updated_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        $stats = $this->buildStats($conn);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'vendors'  => $vendors,
            'stats'    => $stats,
        ]);

        return $this->fetch();
    }

    /**
     * 顶部统计（全表口径，账户资金总览，一行 4 张卡片）：
     *   - account_count：  账户数
     *   - balance_total：  总权益   = Σ balance
     *   - available_total：可用现金 = Σ available_balance
     *   - frozen_total：   持仓现值 = Σ frozen_balance
     *
     * 注：平台/账户维度的持仓分布已迁到「账户持仓」页（按逐仓真实市值 market_value 统计）。
     *
     * @return array
     */
    private function buildStats($conn): array
    {
        $row = $conn->query(
            "SELECT COUNT(*) AS cnt,
                    SUM(balance) AS bal,
                    SUM(available_balance) AS avail,
                    SUM(frozen_balance) AS frozen
             FROM accounts"
        );
        $r = $row[0] ?? [];
        return [
            'account_count'   => (int)($r['cnt'] ?? 0),
            'balance_total'   => (float)($r['bal'] ?? 0),
            'available_total' => (float)($r['avail'] ?? 0),
            'frozen_total'    => (float)($r['frozen'] ?? 0),
        ];
    }
}
