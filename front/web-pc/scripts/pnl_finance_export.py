#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PNL 财务对账数据导出脚本（2026-05-19 多分片版）

用途：
    给财务一份多 sheet 的 Excel，让他们用原始数据自己跑 PNL 公式，
    跑完后跟我们在 sheet "我方计算结果" 里的数对比，差异就能精确定位到
    "公式理解不一致" 还是 "数据范围不一致"。

PNL 公式（与 app/cool_pay2/trait/PnlCalcTrait.php 完全同源）：
    PNL = Part1 + Part2
    Part1 = FG_net + Poly_real_net
        FG_net        = SUM(CASE WHEN order_side='SELL' THEN trade_amount
                                  ELSE -trade_amount END)
                        ON target_trader_records WHERE status='FILLED'
        Poly_real_net = SUM(CASE WHEN order_side='SELL'
                                  THEN IF(real_trade_amount=0, trade_amount, real_trade_amount)
                                  ELSE -IF(real_trade_amount=0, trade_amount, real_trade_amount) END)
                        ON source_trader_records WHERE status='FILLED'
    Part2 = Part2_poly + Part2_fg
        Part2_poly = SUM(CASE WHEN order_side='BUY' THEN trade_quantity
                               ELSE -trade_quantity END)
                     ON source_trader_records
                     WHERE status='FILLED'
                       AND (market_config_id, source_token_id) ∈ Poly 赢家集
        Part2_fg   = 同上，但 target_trader_records / target_token_id / FG 赢家集

    Poly 赢家集 = {(mcid, source_winning_token_id) | source_winning_token_id != ''}
    FG  赢家集 = {(mcid, target_winning_token_id) | target 通过 token_id_mapping JSON 反查得到}

    !!! 重要：market_config_id 是【分片本地自增 ID】，不同分片可能有相同 mc_id 指向不同市场。
        赢家集 / Part 2 SUMIF 必须在【单个分片】内匹配；本脚本对每个分片单独算 winners
        + enrich + Part 2，再在应用层把各分片 PNL 加起来。

时间过滤：updated_at BETWEEN [start 00:00:00] AND [end 23:59:59]（与系统口径一致）

输入：
    DB_HOST/PORT/USERNAME/PASSWORD/DB_DATABASE 指向【控制库 fgmm_runtime】
    脚本读 sys_sharding_config 拿到所有 status=1 的业务分片清单，
    再用每个分片的 database_config JSON 凭证连过去拉数据。

    可选 env：
      SHARD_EXCLUDE_IDS=3,4   排除指定 sharding_id（dev 上排掉 self_mm 用）

用法：
    pip install pymysql openpyxl

    # === Prod ===
    export DB_HOST=172.16.39.211
    export DB_PORT=3306
    export DB_USERNAME=uat_mm
    export DB_PASSWORD='Wn5fkwNEfP}2pR1#v#*u'
    export DB_DATABASE=uat_fgmm_runtime
    python scripts/pnl_finance_export.py 2026-05-16 2026-05-18

    # === Dev ===
    export DB_HOST=172.16.39.253
    export DB_USERNAME=mm
    export DB_PASSWORD=2kHjniEMBGh7rRWz
    export DB_DATABASE=fgmm_runtime
    export SHARD_EXCLUDE_IDS=3   # 跳过 self_mm 自做市分片（schema 缺 trader 表）
    python scripts/pnl_finance_export.py 2026-05-13 2026-05-15
"""

from __future__ import annotations

import json
import os
import sys
from collections import defaultdict
from datetime import date, datetime
from decimal import Decimal
from typing import Any

import pymysql
from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter


# ============================================================
# 配置
# ============================================================

# DB 列（用于 SELECT）
# 注：source_trader_records 实际列名是 fee_amount（不是 trade_fee）；
#     表上没有 source_group_id（只有 target_group_id）
SOURCE_DB_COLUMNS = [
    "id", "created_at", "updated_at", "order_side", "status",
    "trade_price", "trade_quantity", "trade_amount",
    "real_trade_amount", "amount_finalized",
    "fee_amount", "trade_commission",
    "market_config_id", "source_token_id", "source_order_id",
    "source_market_id", "source_account_id",
    "source_vendor", "target_group_id", "target_token_id",
]

TARGET_DB_COLUMNS = [
    "id", "created_at", "updated_at", "order_side", "status",
    "trade_price", "trade_quantity", "trade_amount",
    "market_config_id", "target_token_id",
    "target_market_id", "target_group_id", "target_account_id",
    "target_vendor", "target_order_id",
]

# Excel 展示列 = DB 列 + 派生列（行级公式中间量），方便行级核对
# 派生列:
#   poly_amount_used   = IF(real_trade_amount = 0, trade_amount, real_trade_amount)，
#                        Part 1 Poly 端实际累加的金额
#   is_fallback_used   = 是/否，real_trade_amount=0 时为"是"（用 trade_amount 兜底）
#   is_winner_row      = 是/否，(market_config_id, source_token_id / target_token_id) 命中赢家集
#                        即该行是否计入 Part 2
SOURCE_DISPLAY_COLUMNS = [
    "__shard_name",
    "id", "created_at", "updated_at", "order_side", "status",
    "trade_price", "trade_quantity", "trade_amount",
    "real_trade_amount", "amount_finalized",
    "poly_amount_used", "is_fallback_used",
    "fee_amount", "trade_commission",
    "market_config_id", "source_token_id", "is_winner_row",
    "source_order_id", "source_market_id",
    "source_account_id", "source_vendor",
    "target_group_id", "target_token_id",
]

TARGET_DISPLAY_COLUMNS = [
    "__shard_name",
    "id", "created_at", "updated_at", "order_side", "status",
    "trade_price", "trade_quantity", "trade_amount",
    "market_config_id", "target_token_id", "is_winner_row",
    "target_market_id", "target_group_id", "target_account_id",
    "target_vendor", "target_order_id",
]

# 派生数值列，写 Excel 时给一致的小数位格式
NUMERIC_DISPLAY_COLS = {
    "trade_price", "trade_quantity", "trade_amount",
    "real_trade_amount", "poly_amount_used",
    "fee_amount", "trade_commission",
}

MAPPING_COLUMNS_BASE = [
    "id", "source_winning_token_id", "source_winning_outcome",
    "token_id_mapping",
]


# ============================================================
# 辅助
# ============================================================

def usage_and_exit(msg: str = "") -> None:
    if msg:
        print(f"错误: {msg}", file=sys.stderr)
    print(__doc__, file=sys.stderr)
    sys.exit(2)


def parse_args() -> tuple[str, str, str]:
    if len(sys.argv) < 3:
        usage_and_exit("缺少 start_date / end_date 参数")
    start_str, end_str = sys.argv[1], sys.argv[2]
    try:
        start = datetime.strptime(start_str, "%Y-%m-%d").date()
        end = datetime.strptime(end_str, "%Y-%m-%d").date()
    except ValueError:
        usage_and_exit("日期格式必须是 YYYY-MM-DD")
    if end < start:
        usage_and_exit("end_date 不能早于 start_date")
    if len(sys.argv) >= 4:
        out = sys.argv[3]
    else:
        out = f"pnl_finance_export_{start_str}_{end_str}.xlsx"
    return start.isoformat(), end.isoformat(), out


def connect_control() -> pymysql.connections.Connection:
    """连接控制库（DB_DATABASE 指向 fgmm_runtime / uat_fgmm_runtime，含 sys_sharding_config）"""
    host = os.environ.get("DB_HOST", "").strip()
    port = int(os.environ.get("DB_PORT", "3306") or 3306)
    user = os.environ.get("DB_USERNAME", "").strip()
    pwd = os.environ.get("DB_PASSWORD", "")
    db = os.environ.get("DB_DATABASE", "").strip()
    missing = [k for k, v in {
        "DB_HOST": host, "DB_USERNAME": user, "DB_DATABASE": db,
    }.items() if not v]
    if missing:
        usage_and_exit(f"缺少环境变量: {', '.join(missing)}")
    return pymysql.connect(
        host=host, port=port, user=user, password=pwd, database=db,
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor,
        autocommit=True, connect_timeout=10,
    )


def list_active_shards(control_conn: pymysql.connections.Connection) -> list[dict]:
    """
    读 sys_sharding_config status=1 的分片，解析 database_config JSON。
    SHARD_EXCLUDE_IDS env 可以排除指定分片（dev 上排掉 self_mm 用）。

    @return list of {id, name, host, port, database, username, password, weight}
    """
    exclude_raw = os.environ.get("SHARD_EXCLUDE_IDS", "").strip()
    exclude_ids: set[int] = set()
    for token in exclude_raw.split(","):
        token = token.strip()
        if token.isdigit():
            exclude_ids.add(int(token))

    with control_conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, status, accept_new_market, weight, database_config "
            "FROM sys_sharding_config WHERE status = 1 ORDER BY id"
        )
        rows = cur.fetchall()

    out: list[dict] = []
    for r in rows:
        sid = int(r["id"])
        if sid in exclude_ids:
            print(f"  [SKIP] shard id={sid} ({r['name']}) 在 SHARD_EXCLUDE_IDS 排除列表",
                  file=sys.stderr)
            continue
        try:
            cfg = json.loads(r["database_config"])
        except (TypeError, ValueError) as e:
            print(f"  [SKIP] shard id={sid} ({r['name']}) database_config 解析失败: {e}",
                  file=sys.stderr)
            continue
        if not cfg.get("host") or not cfg.get("database"):
            print(f"  [SKIP] shard id={sid} ({r['name']}) database_config 缺 host/database",
                  file=sys.stderr)
            continue
        out.append({
            "id":       sid,
            "name":     str(r["name"]),
            "host":     str(cfg["host"]),
            "port":     int(cfg.get("port") or 3306),
            "database": str(cfg["database"]),
            "username": str(cfg.get("username") or ""),
            "password": str(cfg.get("password") or ""),
            "weight":   int(r["weight"]),
        })
    return out


def connect_shard(info: dict) -> pymysql.connections.Connection:
    """用单个分片的 JSON 凭证连业务库"""
    return pymysql.connect(
        host=info["host"], port=info["port"],
        user=info["username"], password=info["password"], database=info["database"],
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor,
        autocommit=True, connect_timeout=10,
    )


def to_excel(v: Any) -> Any:
    """openpyxl 友好的值转换：Decimal -> float, datetime 保留, None -> ''."""
    if v is None:
        return ""
    if isinstance(v, Decimal):
        # decimal(28,8) 在金额场景对 float 精度（~15 位）足够
        return float(v)
    if isinstance(v, (datetime, date)):
        return v
    return v


# ============================================================
# 数据拉取
# ============================================================

def fetch_records(
    cur: pymysql.cursors.DictCursor,
    table: str,
    columns: list[str],
    start: str,
    end: str,
) -> list[dict]:
    col_sql = ", ".join(f"`{c}`" for c in columns)
    sql = (
        f"SELECT {col_sql} FROM `{table}` "
        f"WHERE `status` = 'FILLED' "
        f"AND `updated_at` >= %s AND `updated_at` <= %s "
        f"ORDER BY `id` ASC"
    )
    cur.execute(sql, (f"{start} 00:00:00", f"{end} 23:59:59"))
    return cur.fetchall()


def fetch_mapping_rows(
    cur: pymysql.cursors.DictCursor,
    mc_ids: list[int],
) -> list[dict]:
    if not mc_ids:
        return []
    # SELECT *：mapping 表不大，多带点上下文字段给财务看
    placeholders = ", ".join(["%s"] * len(mc_ids))
    sql = f"SELECT * FROM `market_mapping_config` WHERE `id` IN ({placeholders}) ORDER BY `id` ASC"
    cur.execute(sql, mc_ids)
    return cur.fetchall()


def derive_target_winner(token_id_mapping_raw: Any, source_winner: str) -> str | None:
    """从 token_id_mapping JSON 反查出 FG 赢家 token_id。失败返回 None。"""
    if not source_winner or token_id_mapping_raw is None:
        return None
    try:
        mapping = json.loads(token_id_mapping_raw) if isinstance(token_id_mapping_raw, str) else None
    except (TypeError, ValueError):
        return None
    if not isinstance(mapping, list):
        return None
    for pair in mapping:
        if not isinstance(pair, dict):
            continue
        sid = str(pair.get("sourceTokenId") or "")
        tid = str(pair.get("targetTokenId") or "")
        if sid and sid == source_winner and tid:
            return tid
    return None


# ============================================================
# 计算
# ============================================================

def enrich_source_rows(
    source_rows: list[dict],
    poly_winset: set[tuple[int, str]],
) -> None:
    """
    给 source 行打 3 个派生列（in-place）：
      poly_amount_used = IF(real_trade_amount=0, trade_amount, real_trade_amount)
      is_fallback_used = "是" / "否"
      is_winner_row    = "是" / "否"  (Part 2 命中)
    与 Part 1 Poly_real_net、Part 2 Poly 的行级口径完全同源。
    """
    for r in source_rows:
        real = Decimal(r.get("real_trade_amount") or 0)
        trade = Decimal(r.get("trade_amount") or 0)
        fallback = real == 0
        r["poly_amount_used"] = trade if fallback else real
        r["is_fallback_used"] = "是" if fallback else "否"
        key = (r["market_config_id"], str(r.get("source_token_id") or ""))
        r["is_winner_row"] = "是" if key in poly_winset else "否"


def enrich_target_rows(
    target_rows: list[dict],
    fg_winset: set[tuple[int, str]],
) -> None:
    """给 target 行打 is_winner_row（in-place）。"""
    for r in target_rows:
        key = (r["market_config_id"], str(r.get("target_token_id") or ""))
        r["is_winner_row"] = "是" if key in fg_winset else "否"


def compute_daily_summary(
    source_rows: list[dict],
    target_rows: list[dict],
) -> dict[str, dict[str, Decimal]]:
    """
    返回 { 'YYYY-MM-DD' or '合计' : { fg_net, poly_real_net, part2_poly, part2_fg, pnl } }
    依赖：source_rows 已 enrich（含 poly_amount_used、is_winner_row）；
          target_rows 已 enrich（含 is_winner_row）。
    """
    Z = Decimal("0")
    daily: dict[str, dict[str, Decimal]] = defaultdict(lambda: {
        "fg_net": Z, "poly_real_net": Z, "part2_poly": Z, "part2_fg": Z,
    })

    def day_of(row: dict) -> str:
        u = row["updated_at"]
        if isinstance(u, (datetime, date)):
            return u.strftime("%Y-%m-%d")
        return str(u)[:10]

    # FG_net: Part 1 FG
    for r in target_rows:
        d = day_of(r)
        amt = Decimal(r["trade_amount"] or 0)
        sign = Decimal("1") if r["order_side"] == "SELL" else Decimal("-1")
        daily[d]["fg_net"] += sign * amt

    # Poly_real_net: Part 1 Poly，直接用行级派生列 poly_amount_used
    for r in source_rows:
        d = day_of(r)
        used = Decimal(r["poly_amount_used"] or 0)
        sign = Decimal("1") if r["order_side"] == "SELL" else Decimal("-1")
        daily[d]["poly_real_net"] += sign * used

    # Part2_poly: source 表 is_winner_row='是' 的行
    for r in source_rows:
        if r["is_winner_row"] != "是":
            continue
        d = day_of(r)
        qty = Decimal(r.get("trade_quantity") or 0)
        sign = Decimal("1") if r["order_side"] == "BUY" else Decimal("-1")
        daily[d]["part2_poly"] += sign * qty

    # Part2_fg: target 表 is_winner_row='是' 的行
    for r in target_rows:
        if r["is_winner_row"] != "是":
            continue
        d = day_of(r)
        qty = Decimal(r.get("trade_quantity") or 0)
        sign = Decimal("1") if r["order_side"] == "BUY" else Decimal("-1")
        daily[d]["part2_fg"] += sign * qty

    # 算 pnl + 合计
    total = {"fg_net": Z, "poly_real_net": Z, "part2_poly": Z, "part2_fg": Z, "pnl": Z}
    out: dict[str, dict[str, Decimal]] = {}
    for d in sorted(daily.keys()):
        v = daily[d]
        v["pnl"] = v["fg_net"] + v["poly_real_net"] + v["part2_poly"] + v["part2_fg"]
        out[d] = v
        for k in ("fg_net", "poly_real_net", "part2_poly", "part2_fg", "pnl"):
            total[k] += v[k]
    out["合计"] = total
    return out


# ============================================================
# Excel 输出
# ============================================================

HEADER_FILL = PatternFill("solid", fgColor="D9E1F2")
TOTAL_FILL = PatternFill("solid", fgColor="FFF2CC")


def style_header(ws, row: int = 1) -> None:
    for cell in ws[row]:
        cell.font = Font(bold=True)
        cell.fill = HEADER_FILL
        cell.alignment = Alignment(horizontal="center", vertical="center")


def autosize(ws, max_width: int = 40) -> None:
    for col_cells in ws.columns:
        col = get_column_letter(col_cells[0].column)
        longest = 0
        for c in col_cells:
            if c.value is None:
                continue
            s = str(c.value)
            longest = max(longest, min(len(s), max_width))
        ws.column_dimensions[col].width = max(12, longest + 2)


def write_formula_sheet(wb: Workbook, start: str, end: str) -> None:
    ws = wb.create_sheet("公式说明", 0)
    ws["A1"] = f"PNL 计算公式说明（2026-05-15 新公式，时间范围 {start} ~ {end}，按 updated_at 过滤）"
    ws["A1"].font = Font(bold=True, size=13)
    ws.merge_cells("A1:F1")

    rows = [
        ("", ""),
        ("总公式", "PNL = Part1 + Part2"),
        ("Part1（已对冲差价）", "FG_net + Poly_real_net"),
        ("  FG_net", "SUM(CASE WHEN order_side='SELL' THEN trade_amount ELSE -trade_amount END)"),
        ("  来源", "target_trader_records WHERE status='FILLED'"),
        ("  Poly_real_net", "SUM(CASE WHEN order_side='SELL' THEN poly_amount ELSE -poly_amount END)"),
        ("  其中 poly_amount", "IF(real_trade_amount = 0, trade_amount, real_trade_amount)"),
        ("  解释", "Poly 真实成交金额扣了内扣手续费；未回填(=0)时兜底用 trade_amount，避免数字虚高"),
        ("  来源", "source_trader_records WHERE status='FILLED'"),
        ("", ""),
        ("Part2（结算差价）", "Part2_poly + Part2_fg"),
        ("  Part2_poly", "SUM(CASE WHEN order_side='BUY' THEN trade_quantity ELSE -trade_quantity END)"),
        ("  来源", "source_trader_records WHERE status='FILLED' "
                  "AND (market_config_id, source_token_id) ∈ Poly 赢家集"),
        ("  Part2_fg", "SUM(CASE WHEN order_side='BUY' THEN trade_quantity ELSE -trade_quantity END)"),
        ("  来源", "target_trader_records WHERE status='FILLED' "
                  "AND (market_config_id, target_token_id) ∈ FG 赢家集"),
        ("", ""),
        ("Poly 赢家集", "{(market_config_id, source_winning_token_id)}，取自 market_mapping_config，"
                       "source_winning_token_id 非空（即市场已结算）"),
        ("FG 赢家集", "{(market_config_id, target_winning_token_id)}，target_winning_token_id 由 "
                     "market_mapping_config.token_id_mapping JSON 反查得到 —— "
                     "找到 sourceTokenId 等于 source_winning_token_id 的那一对，取其 targetTokenId"),
        ("", ""),
        ("时间过滤", "updated_at BETWEEN [start 00:00:00] AND [end 23:59:59]"),
        ("", ""),
        ("多分片说明", "本次范围内 cool_pay2 后端是多分片（mm / mm1 / ...），脚本对每个分片各自拉数据并算 PNL，应用层求和；"
                    "明细 / 赢家表的 __shard_name 列标记每行来自哪个分片。"),
        ("  关键点", "market_config_id 是【分片本地自增 ID】，跨片不可比 —— 财务用 SUMIF 时要么按 __shard_name + market_config_id 双键，"
                  "要么直接用 target_group_id（全局唯一字符串）。"),
        ("Sheet 对照", ""),
        ("  我方计算结果", "我们按上述公式直接在 prod 数据上跑出来的结果（按日 + 合计）"),
        ("  Poly侧成交明细", "source_trader_records 全量 FILLED 行 —— 财务对 Part 1 Poly 端 / Part 2 Poly 端的原始数据"),
        ("  FG侧成交明细", "target_trader_records 全量 FILLED 行 —— 财务对 Part 1 FG 端 / Part 2 FG 端的原始数据"),
        ("  市场赢家表", "本次范围内出现过的市场列表 + 赢家 token；带 is_settled 标记 + FG 赢家反查结果"),
        ("", ""),
        ("明细 sheet 中的派生列（行级公式中间量，对账差异多半藏在这两步）", ""),
        ("  poly_amount_used", "Poly 明细列。= IF(real_trade_amount=0, trade_amount, real_trade_amount)，"
                              "即 Part 1 Poly 端实际累加的金额。Part 1 Poly_real_net 直接来自这一列。"),
        ("  is_fallback_used", "Poly 明细列。'是' 表示该行 real_trade_amount=0，用 trade_amount 兜底；"
                              "'否' 表示用 real_trade_amount。若 '是' 比例很高，说明 Dylan 的回填脚本未跑完，"
                              "数字与 Poly 真实成交金额会有 fee 几个百分点的差。"),
        ("  is_winner_row", "Poly/FG 明细列。'是' 表示该行命中赢家集，计入 Part 2；'否' 表示不计入。"
                           "Part 2 的 SUMIF 直接按这一列筛即可。"),
        ("", ""),
        ("输家 token 的处理（容易误解）", ""),
        ("", "Part 2 只统计赢家行，输家 token 不显式出现在 Part 2，但通过 Part 1 体现："),
        ("", "  我们在输家 token 上净买入 X —— Part 1 给 -X（输家结算归零，亏损落地）"),
        ("", "  我们在输家 token 上净卖出 Y —— Part 1 给 +Y（落袋为安）"),
    ]
    for i, (a, b) in enumerate(rows, start=2):
        ws[f"A{i}"] = a
        ws[f"B{i}"] = b
        ws[f"A{i}"].alignment = Alignment(vertical="top", wrap_text=True)
        ws[f"B{i}"].alignment = Alignment(vertical="top", wrap_text=True)
        if a and not a.startswith("  "):
            ws[f"A{i}"].font = Font(bold=True)
    ws.column_dimensions["A"].width = 28
    ws.column_dimensions["B"].width = 90


def write_summary_sheet(
    wb: Workbook,
    summary: dict[str, dict[str, Decimal]],
) -> None:
    ws = wb.create_sheet("我方计算结果")
    headers = ["日期", "FG_net (Part1 FG)", "Poly_real_net (Part1 Poly)",
               "Part2_poly_qty", "Part2_fg_qty", "PNL = 上述四项之和"]
    ws.append(headers)
    style_header(ws)
    keys = [k for k in summary.keys() if k != "合计"] + ["合计"]
    for k in keys:
        v = summary[k]
        ws.append([
            k,
            to_excel(v["fg_net"]),
            to_excel(v["poly_real_net"]),
            to_excel(v["part2_poly"]),
            to_excel(v["part2_fg"]),
            to_excel(v["pnl"]),
        ])
    # 给合计行加底色
    last = ws.max_row
    for col in range(1, len(headers) + 1):
        ws.cell(row=last, column=col).fill = TOTAL_FILL
        ws.cell(row=last, column=col).font = Font(bold=True)
    # 数字格式
    for r in range(2, last + 1):
        for c in range(2, len(headers) + 1):
            ws.cell(row=r, column=c).number_format = "#,##0.00000000"
    autosize(ws)


def write_records_sheet(wb: Workbook, title: str, columns: list[str], rows: list[dict]) -> None:
    ws = wb.create_sheet(title)
    ws.append(columns)
    style_header(ws)
    for r in rows:
        ws.append([to_excel(r.get(c)) for c in columns])
    ws.freeze_panes = "A2"
    # 给数值列统一小数位格式，便于财务复算
    for col_idx, name in enumerate(columns, start=1):
        if name in NUMERIC_DISPLAY_COLS:
            for r_idx in range(2, ws.max_row + 1):
                ws.cell(row=r_idx, column=col_idx).number_format = "#,##0.00000000"
    autosize(ws)


def write_mapping_sheet(wb: Workbook, mapping_rows: list[dict]) -> None:
    if not mapping_rows:
        ws = wb.create_sheet("市场赢家表")
        ws["A1"] = "本次时间范围内无任何已结算市场出现成交"
        return
    # 决定列顺序：基础几列在前 + target_winning_token_id（派生）+ is_settled（派生）+ 其它列在后
    all_keys: list[str] = []
    for r in mapping_rows:
        for k in r.keys():
            if k not in all_keys:
                all_keys.append(k)
    base_first = ["__shard_name", "id", "source_winning_token_id", "source_winning_outcome",
                  "target_winning_token_id", "is_settled", "token_id_mapping"]
    rest = [k for k in all_keys if k not in base_first]
    ordered = base_first + rest

    ws = wb.create_sheet("市场赢家表")
    ws.append(ordered)
    style_header(ws)
    for r in mapping_rows:
        ws.append([to_excel(r.get(c)) for c in ordered])
    ws.freeze_panes = "A2"
    autosize(ws)


# ============================================================
# 主流程
# ============================================================

def main() -> int:
    start, end, out_path = parse_args()
    print(f"[1/5] 连接控制库读 sys_sharding_config ...", file=sys.stderr)
    control = connect_control()
    try:
        shards = list_active_shards(control)
    finally:
        control.close()
    if not shards:
        print("  [FAIL] sys_sharding_config 没有 status=1 的分片（被全部排除？）", file=sys.stderr)
        return 3
    print(f"  发现 {len(shards)} 个分片: " +
          ", ".join(f"{s['id']}={s['name']}@{s['host']}/{s['database']}" for s in shards),
          file=sys.stderr)

    # 各分片独立拉数据 + 独立 derive winners + 独立 enrich
    # （market_config_id 是分片本地，winners 集绝对不能跨片共享）
    source_rows: list[dict] = []
    target_rows: list[dict] = []
    mapping_rows: list[dict] = []

    for s in shards:
        print(f"\n[2/5] [shard {s['id']} {s['name']}] 连接 + 拉数据 ...", file=sys.stderr)
        try:
            sc = connect_shard(s)
        except Exception as e:
            print(f"  [FAIL] shard {s['id']} 连不上: {e}", file=sys.stderr)
            return 4
        try:
            with sc.cursor() as cur:
                src = fetch_records(cur, "source_trader_records", SOURCE_DB_COLUMNS, start, end)
                tgt = fetch_records(cur, "target_trader_records", TARGET_DB_COLUMNS, start, end)
                mc_ids = sorted({r["market_config_id"] for r in src
                                 if r["market_config_id"] is not None}
                                | {r["market_config_id"] for r in tgt
                                   if r["market_config_id"] is not None})
                mapping = fetch_mapping_rows(cur, mc_ids)
        finally:
            sc.close()
        print(f"  shard {s['id']}: source={len(src)} target={len(tgt)} mapping={len(mapping)}",
              file=sys.stderr)

        # 反查 FG 赢家 + 标记结算（在本分片范围内）
        local_winners: dict[int, dict] = {}
        for r in mapping:
            poly = (r.get("source_winning_token_id") or "").strip()
            is_settled = bool(poly)
            r["is_settled"] = 1 if is_settled else 0
            r["__shard_name"] = s["name"]
            fg = derive_target_winner(r.get("token_id_mapping"), poly) if is_settled else None
            r["target_winning_token_id"] = fg or ""
            if is_settled:
                local_winners[int(r["id"])] = {"poly": poly, "fg": fg}

        # 派生行级中间量（仅在本分片范围内匹配 winners）
        poly_winset = {(mc, w["poly"]) for mc, w in local_winners.items() if w.get("poly")}
        fg_winset   = {(mc, w["fg"])   for mc, w in local_winners.items() if w.get("fg")}
        enrich_source_rows(src, poly_winset)
        enrich_target_rows(tgt, fg_winset)
        for r in src + tgt:
            r["__shard_name"] = s["name"]

        source_rows.extend(src)
        target_rows.extend(tgt)
        mapping_rows.extend(mapping)

    print(f"\n[3/5] 合并：source={len(source_rows)} target={len(target_rows)} mapping={len(mapping_rows)}",
          file=sys.stderr)

    # 汇总（compute_daily_summary 读 enrich 后的 is_winner_row + poly_amount_used，跨分片 sum 天然正确）
    print(f"[4/5] 算每日汇总 ...", file=sys.stderr)
    summary = compute_daily_summary(source_rows, target_rows)

    # 写 xlsx
    print(f"[5/5] 写 Excel -> {out_path} ...", file=sys.stderr)
    wb = Workbook()
    # 干掉默认 sheet
    default_ws = wb.active
    wb.remove(default_ws)

    write_formula_sheet(wb, start, end)
    write_summary_sheet(wb, summary)
    write_records_sheet(wb, "Poly侧成交明细", SOURCE_DISPLAY_COLUMNS, source_rows)
    write_records_sheet(wb, "FG侧成交明细", TARGET_DISPLAY_COLUMNS, target_rows)
    write_mapping_sheet(wb, mapping_rows)

    wb.save(out_path)
    print("\n========== 我方计算结果 ==========", file=sys.stderr)
    print(f"{'日期':<12}{'FG_net':>16}{'Poly_real_net':>20}{'Part2_poly':>16}{'Part2_fg':>16}{'PNL':>16}",
          file=sys.stderr)
    for k, v in summary.items():
        print(f"{k:<12}{float(v['fg_net']):>16.4f}{float(v['poly_real_net']):>20.4f}"
              f"{float(v['part2_poly']):>16.4f}{float(v['part2_fg']):>16.4f}{float(v['pnl']):>16.4f}",
              file=sys.stderr)
    print(f"\n[OK] 写入完成: {out_path}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())