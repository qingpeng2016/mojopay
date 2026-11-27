<?php
/** @var array<string,mixed> $m */
/** @var callable $h */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 28px 32px; }
        body, h1, h2, td, th, div, p, span { font-family: "sun exta", sans-serif; font-size: 11px; color: #222; line-height: 1.45; }
        h1 { text-align: center; font-size: 18px; margin: 0 0 6px; }
        h2 { font-size: 13px; margin: 18px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .meta { text-align: center; color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #bbb; padding: 5px 6px; vertical-align: top; }
        th { background: #f3f3f3; font-weight: bold; }
        .right { text-align: right; }
        .note-list { font-size: 10px; color: #666; margin-top: 8px; padding-left: 20px; line-height: 1.6; }
        .note-list li { margin-bottom: 6px; }
        .section-grid td.label { width: 34%; background: #fafafa; }
        .overview-grid td.label { width: 22%; background: #fafafa; }
        .overview-grid td.amount { width: 18%; }
        .overview-grid td.desc { color: #555; }
    </style>
</head>
<body>
<h1>MojoQuant 周度对账单 · <?= $h($m['statement_type_label'] ?? '进取浮动型') ?></h1>
<div class="meta">
    账单周期：<?= $h($m['week_range_fmt'] ?? '-') ?>
</div>

<h2>一、客户信息</h2>
<table class="section-grid">
    <tr><td class="label">客户名称</td><td><?= $h($m['client_name'] ?? '-') ?></td></tr>
    <tr><td class="label">账户编号</td><td><?= $h($m['account_no'] ?? '-') ?></td></tr>
    <tr><td class="label">账单类型</td><td><?= $h($m['statement_type_label'] ?? '-') ?></td></tr>
</table>

<h2>二、账户总览</h2>
<table class="overview-grid">
    <tr>
        <th>指标</th>
        <th class="right">金额 (USD)</th>
        <th>说明</th>
    </tr>
    <tr>
        <td class="label">期初本金</td>
        <td class="amount right"><?= $h($m['opening_principal_fmt'] ?? '0.00') ?></td>
        <td class="desc">上期末持仓本金总额</td>
    </tr>
    <tr>
        <td class="label">本期新增申购</td>
        <td class="amount right"><?= $h($m['subscribe_in_week_fmt'] ?? '0.00') ?></td>
        <td class="desc">本期内新增申购本金</td>
    </tr>
    <tr>
        <td class="label">本期赎回本息</td>
        <td class="amount right"><?= $h($m['redeem_in_week_fmt'] ?? '0.00') ?></td>
        <td class="desc">本期内赎回 / 部分赎回本息</td>
    </tr>
    <tr>
        <td class="label">期末本金</td>
        <td class="amount right"><?= $h($m['closing_principal_fmt'] ?? '0.00') ?></td>
        <td class="desc">本期末持仓本金总额</td>
    </tr>
    <tr>
        <td class="label">本期净收益</td>
        <td class="amount right"><?= $h($m['period_net_profit_fmt'] ?? '0.00') ?></td>
        <td class="desc">本期扣除所有费用后客户实际所得收益（可能存在最后一位小数精度带来的差异）</td>
    </tr>
    <tr>
        <td class="label">期末持仓净收益</td>
        <td class="amount right"><?= $h($m['closing_holding_net_profit_fmt'] ?? '0.00') ?></td>
        <td class="desc">截止本期内所有持仓累计收益</td>
    </tr>
    <tr>
        <td class="label">累计净收益</td>
        <td class="amount right"><?= $h($m['cumulative_net_profit_fmt'] ?? '0.00') ?></td>
        <td class="desc">自开户以来累计实际所得收益（可能存在最后一位小数精度带来的差异）</td>
    </tr>
    <tr>
        <td class="label">期末账户总余额</td>
        <td class="amount right"><?= $h($m['closing_total_return_fmt'] ?? '0.00') ?></td>
        <td class="desc">期末本金 + 期末持仓净收益</td>
    </tr>
</table>

<h2>三、期末持仓明细</h2>
<table>
    <tr>
        <th>期限</th>
        <th class="right">本金 (USD)</th>
        <th>参考年化区间</th>
        <th class="right">本期收益 (USD)</th>
        <th class="right">累计收益 (USD)</th>
        <th>到期日</th>
    </tr>
    <?php foreach (($m['holdings'] ?? []) as $row): ?>
    <tr>
        <td><?= $h($row['term'] ?? '-') ?></td>
        <td class="right"><?= $row['principal_display'] ?? $h($row['principal_fmt'] ?? '0.00') ?></td>
        <td><?= $h($row['rate_display'] ?? '-') ?></td>
        <td class="right"><?= $row['period_profit_display'] ?? $h($row['period_profit_fmt'] ?? '0.00') ?></td>
        <td class="right"><?= $row['cumulative_profit_display'] ?? $h($row['cumulative_profit_fmt'] ?? '0.00') ?></td>
        <td><?= $row['maturity_display'] ?? $h($row['maturity'] ?? '-') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($m['holdings'])): ?>
    <tr><td colspan="6" style="text-align:center;color:#888;">暂无持仓</td></tr>
    <?php endif; ?>
</table>

<h2>四、收益与费用明细</h2>
<table class="overview-grid">
    <tr>
        <th>项目</th>
        <th class="right">金额 (USDT)</th>
        <th>备注</th>
    </tr>
    <tr>
        <td class="label">提前赎回成本</td>
        <td class="amount right">0.00</td>
        <td class="desc">提前赎回产生的全部费用</td>
    </tr>
    <tr>
        <td class="label">其他费用</td>
        <td class="amount right">0.00</td>
        <td class="desc">/</td>
    </tr>
</table>

<h2>五、本期申购记录</h2>
<table>
    <tr>
        <th>日期</th><th>业务类型</th><th>产品期限</th><th class="right">金额 (USDT)</th><th>处理状态</th><th>备注</th>
    </tr>
    <?php foreach (($m['activity_records'] ?? []) as $rec): ?>
    <tr>
        <td><?= $h($rec['date'] ?? '-') ?></td>
        <td><?= $h($rec['type'] ?? '-') ?></td>
        <td><?= $h($rec['term'] ?? '-') ?></td>
        <td class="right"><?= $h($rec['amount'] ?? '0.00') ?></td>
        <td><?= $h($rec['status'] ?? '-') ?></td>
        <td><?= $h($rec['remark'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($m['activity_records'])): ?>
    <tr><td colspan="6" style="text-align:center;color:#888;">本期无申购记录</td></tr>
    <?php endif; ?>
</table>

<h2>六、本期续购记录</h2>
<table>
    <tr>
        <th>日期</th><th>业务类型</th><th>产品期限</th><th class="right">金额 (USDT)</th><th>处理状态</th><th>备注</th>
    </tr>
    <?php foreach (($m['rollover_records'] ?? []) as $rec): ?>
    <tr>
        <td><?= $h($rec['date'] ?? '-') ?></td>
        <td><?= $h($rec['type'] ?? '-') ?></td>
        <td><?= $h($rec['term'] ?? '-') ?></td>
        <td class="right"><?= $h($rec['amount'] ?? '0.00') ?></td>
        <td><?= $h($rec['status'] ?? '-') ?></td>
        <td><?= $h($rec['remark'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($m['rollover_records'])): ?>
    <tr><td colspan="6" style="text-align:center;color:#888;">本期无续购记录</td></tr>
    <?php endif; ?>
</table>

<h2>七、本期赎回记录</h2>
<table>
    <tr>
        <th>日期</th><th>业务类型</th><th>产品期限</th><th class="right">金额 (USDT)</th><th>处理状态</th><th>备注</th>
    </tr>
    <?php foreach (($m['redemption_records'] ?? []) as $rec): ?>
    <tr>
        <td><?= $h($rec['date'] ?? '-') ?></td>
        <td><?= $h($rec['type'] ?? '-') ?></td>
        <td><?= $h($rec['term'] ?? '-') ?></td>
        <td class="right"><?= $h($rec['amount'] ?? '0.00') ?></td>
        <td><?= $h($rec['status'] ?? '-') ?></td>
        <td><?= $h($rec['remark'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($m['redemption_records'])): ?>
    <tr><td colspan="6" style="text-align:center;color:#888;">本期无赎回记录</td></tr>
    <?php endif; ?>
</table>

<h2>八、重要提示</h2>
<ol class="note-list">
    <li><strong>本账单所有收益数据均以三方平台实际成交结算数据为准。</strong></li>
    <li><strong>锁定期内产品不支持常规赎回，特殊情况需提交书面申请，审批通过后方可办理。</strong></li>
    <li><strong>如需到期复投或调整赎回安排，请至少提前 3 个工作日向您的专属对接人提交书面确认。</strong></li>
    <li><strong>如对本账单有任何异议，请于收到账单后 3 个工作日内提出，逾期未提出视为无异议。</strong></li>
</ol>
</body>
</html>
