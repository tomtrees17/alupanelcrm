<?php /** @var array $u */ /** @var array $months */ /** @var bool $enabled */ ?>
<?php
$usd = fn(float $v): string => '$' . number_format($v, $v > 0 && $v < 1 ? 4 : 2, '.', ',');
$taskLabel = function (string $k): string {
    $l = t('ai_task_' . $k);
    return $l === 'ai_task_' . $k ? $k : $l;
};
$tot = $u['total'];
$caps = $u['caps'];
$capText = fn(float $spent, float $cap): string => $usd($spent) . ' / ' . ($cap > 0 ? $usd($cap) : t('ai_no_cap'));
$over = fn(float $spent, float $cap): bool => $cap > 0 && $spent >= $cap;
?>
<div class="page-head">
    <div>
        <h1><?= t('ai_usage_title') ?></h1>
        <p class="muted"><?= t('ai_usage_sub') ?></p>
    </div>
    <form method="get" action="index.php" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="r" value="ai.usage">
        <select class="form-input" name="m" onchange="this.form.submit()">
            <?php foreach ($months as $m): ?><option value="<?= e($m) ?>"<?= $m === $u['month'] ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$enabled): ?><p class="muted small"><?= t('ai_usage_off') ?></p><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card c1"><div class="stat-label"><?= t('ai_spent_today') ?></div>
        <div class="stat-value"<?= $over($u['spent_today'], $caps['day']) ? ' style="color:var(--danger)"' : '' ?>><?= e($capText($u['spent_today'], $caps['day'])) ?></div></div>
    <div class="stat-card c3"><div class="stat-label"><?= t('ai_spent_month') ?></div>
        <div class="stat-value"<?= $over($u['spent_month'], $caps['month']) ? ' style="color:var(--danger)"' : '' ?>><?= e($capText($u['spent_month'], $caps['month'])) ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('ai_calls') ?> · <?= e($u['month']) ?></div><div class="stat-value" data-total-calls><?= num($tot['calls']) ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('ai_cost') ?> · <?= e($u['month']) ?></div><div class="stat-value" data-total-cost><?= e($usd((float) $tot['cost'])) ?></div></div>
</div>
<p class="muted small"><?= t('ai_cap_hint') ?></p>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('ai_by_task') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('ai_task') ?></th><th class="right"><?= t('ai_calls') ?></th><th class="right"><?= t('ai_st_ok') ?></th>
            <th class="right"><?= t('ai_st_invalid') ?></th><th class="right"><?= t('ai_st_api_error') ?></th>
            <th class="right"><?= t('ai_st_over_budget') ?></th><th class="right"><?= t('ai_st_repeat_skipped') ?></th>
            <th class="right"><?= t('ai_tokens_in') ?></th><th class="right"><?= t('ai_tokens_out') ?></th>
            <th class="right"><?= t('ai_cost') ?></th><th class="right"><?= t('ai_avg_ms') ?></th>
            <th class="right"><?= t('ai_adoption') ?></th><th><?= t('ai_top_fields') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$u['tasks']): ?><tr><td colspan="13" class="empty"><?= t('ai_no_calls') ?></td></tr><?php endif; ?>
        <?php foreach ($u['tasks'] as $k => $r): ?>
            <tr data-task="<?= e($k) ?>">
                <td><?= e($taskLabel($k)) ?></td>
                <td class="right num"><?= num($r['calls']) ?></td>
                <td class="right num"><?= num($r['ok']) ?></td>
                <td class="right num"><?= num($r['invalid']) ?></td>
                <td class="right num"><?= num($r['api_error']) ?></td>
                <td class="right num"><?= num($r['over_budget']) ?></td>
                <td class="right num"><?= num($r['repeat_skipped']) ?></td>
                <td class="right num"><?= num($r['in']) ?></td>
                <td class="right num"><?= num($r['out']) ?></td>
                <td class="right num"><?= e($usd((float) $r['cost'])) ?></td>
                <td class="right num"><?= $r['avg_ms'] > 0 ? num($r['avg_ms']) . ' ms' : '—' ?></td>
                <td class="right num"><?= $r['adoption'] === null ? '—' : e(round($r['adoption'] * 100) . '% (' . $r['accepted'] . '/' . $r['confirmed'] . ')') ?></td>
                <td><?= $r['fields'] ? e(implode(', ', array_map(fn($f, $n) => "{$f} ×{$n}", array_keys($r['fields']), $r['fields']))) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($u['tasks']): ?>
            <tr class="total-row" data-total>
                <td><strong><?= t('ai_total') ?></strong></td>
                <td class="right num"><strong><?= num($tot['calls']) ?></strong></td>
                <td class="right num"><?= num($tot['ok']) ?></td>
                <td class="right num"><?= num($tot['invalid']) ?></td>
                <td class="right num"><?= num($tot['api_error']) ?></td>
                <td class="right num"><?= num($tot['over_budget']) ?></td>
                <td class="right num"><?= num($tot['repeat_skipped']) ?></td>
                <td class="right num"><?= num($tot['in']) ?></td>
                <td class="right num"><?= num($tot['out']) ?></td>
                <td class="right num"><strong><?= e($usd((float) $tot['cost'])) ?></strong></td>
                <td></td><td></td><td></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('ai_inventory_ledger') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th class="right"><?= t('ai_calls') ?></th><th class="right"><?= t('ai_tokens_in') ?></th><th class="right"><?= t('ai_tokens_out') ?></th><th class="right"><?= t('ai_cost') ?></th></tr></thead>
        <tbody><tr data-inventory>
            <td class="right num"><?= num($u['inventory']['calls']) ?></td>
            <td class="right num"><?= num($u['inventory']['in']) ?></td>
            <td class="right num"><?= num($u['inventory']['out']) ?></td>
            <td class="right num"><?= e($usd((float) $u['inventory']['cost'])) ?></td>
        </tr></tbody>
    </table></div>
    <p class="muted small" style="padding:0 16px 12px"><?= t('ai_inventory_hint') ?></p>
</div>
