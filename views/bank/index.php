<?php /** @var array $accounts */ /** @var int $acc */ /** @var string $month */ /** @var array $months */ /** @var string $status */ /** @var string $dir */ /** @var string $q */ /** @var array $rows */ /** @var array $pg */ /** @var array $counts */ /** @var int $aiLeft */ /** @var int $bpOpen */ /** @var bool $aiOn */ ?>
<?php
$stTag = ['unmatched' => 'tag-orange', 'rule' => 'tag-blue', 'ai' => 'tag-blue', 'confirmed' => 'tag-green', 'non_ar' => 'tag-gray', 'na' => 'tag-gray'];
$money = fn(float $v): string => number_format($v, 2, ',', '.');
$filters = ['acc' => $acc, 'm' => $month, 'st' => $status, 'dir' => $dir, 'q' => $q];
?>
<div class="page-head">
    <div>
        <h1><?= t('bank_title') ?></h1>
        <p class="muted"><?= t('bank_sub') ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= url('bank.receipts') ?>"><?= t('bank_receipts') ?></a>
        <?php if ($bpOpen > 0): ?><a class="btn btn-ghost" href="<?= url('bank.bukti_potong') ?>"><?= t('bank_bp_title') ?> (<?= $bpOpen ?>)</a><?php endif; ?>
        <a class="btn btn-primary" href="<?= url('bank.import') ?>"><?= t('bank_import') ?></a>
    </div>
</div>

<?php if (!$accounts): ?>
    <div class="card"><p class="empty"><?= t('bank_no_data') ?></p></div>
<?php else: ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="bank.index">
    <select class="form-input" name="acc" onchange="this.form.submit()">
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $acc ? ' selected' : '' ?>><?= e($a['bank'] . ' …' . $a['last4']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-input" name="m" onchange="this.form.submit()">
        <?php foreach (array_unique(array_merge([$month], $months)) as $m): ?><option value="<?= e($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
    </select>
    <select class="form-input" name="dir" onchange="this.form.submit()">
        <option value=""><?= t('filter_all') ?></option>
        <option value="in"<?= $dir === 'in' ? ' selected' : '' ?>><?= t('bank_th_in') ?></option>
        <option value="out"<?= $dir === 'out' ? ' selected' : '' ?>><?= t('bank_th_out') ?></option>
    </select>
    <input type="hidden" name="st" value="<?= e($status) ?>">
    <input class="form-input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('bank_search') ?>">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>

<div class="task-filters">
    <a class="filter-btn <?= $status === '' ? 'active' : '' ?>" href="<?= url('bank.index', ['st' => ''] + $filters) ?>"><?= t('filter_all') ?> (<?= array_sum($counts) ?>)</a>
    <?php foreach (['unmatched', 'rule', 'ai', 'confirmed', 'non_ar', 'na'] as $s): ?>
        <?php if (!empty($counts[$s])): ?><a class="filter-btn <?= $status === $s ? 'active' : '' ?>" href="<?= url('bank.index', ['st' => $s] + $filters) ?>"><?= t('bank_st_' . $s) ?> (<?= (int) $counts[$s] ?>)</a><?php endif; ?>
    <?php endforeach; ?>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <?php if (!empty($counts['rule'])): ?>
        <form method="post" action="<?= url('bank.confirm_rules') ?>" onsubmit="return confirm('<?= e(sprintf(t('bank_btn_confirm_rules'), (int) $counts['rule'])) ?>?')">
            <?= Csrf::field() ?><input type="hidden" name="acc" value="<?= $acc ?>"><input type="hidden" name="m" value="<?= e($month) ?>">
            <button class="btn btn-primary" type="submit"><?= e(sprintf(t('bank_btn_confirm_rules'), (int) $counts['rule'])) ?></button>
        </form>
    <?php endif; ?>
    <?php if ($aiLeft > 0 && $aiOn): ?>
        <form method="post" action="<?= url('bank.ai_batch') ?>">
            <?= Csrf::field() ?><input type="hidden" name="acc" value="<?= $acc ?>"><input type="hidden" name="m" value="<?= e($month) ?>">
            <button class="btn btn-ghost" type="submit"><?= e(sprintf(t('bank_btn_ai'), $aiLeft)) ?></button>
        </form>
    <?php elseif ($aiLeft > 0): ?>
        <span class="muted small"><?= t('ai_usage_off') ?></span>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('bank_th_date') ?></th><th><?= t('bank_th_code') ?></th><th><?= t('bank_th_payer') ?></th><th><?= t('bank_th_desc') ?></th>
            <th class="right"><?= t('bank_th_in') ?></th><th class="right"><?= t('bank_th_out') ?></th>
            <th><?= t('bank_th_tag') ?></th><th><?= t('bank_th_status') ?></th><th><?= t('bank_th_invoices') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty"><?= t('bank_no_rows') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <?php $prop = $r['proposal'] ? json_decode((string) $r['proposal'], true) : null; ?>
            <tr>
                <td class="nowrap"><?= e(Hr::dmy($r['trx_date'])) ?></td>
                <td class="nowrap"><a href="<?= url('bank.show', ['id' => $r['id']]) ?>"><?= e($r['trx_code']) ?></a></td>
                <td><?= e((string) $r['counterparty_name']) ?: '—' ?></td>
                <td class="small" style="max-width:340px"><?= e($r['description']) ?></td>
                <td class="right num"><?= $r['direction'] === 'in' ? $money((float) $r['amount']) : '' ?></td>
                <td class="right num"><?= $r['direction'] === 'out' ? $money((float) $r['amount']) : '' ?></td>
                <td class="nowrap"><?= t('bank_tag_' . $r['tag']) ?></td>
                <td class="nowrap"><span class="tag <?= $stTag[$r['match_status']] ?? 'tag-gray' ?>"><?= t('bank_st_' . $r['match_status']) ?></span>
                    <?php if ($r['match_status'] === 'ai' && $r['ai_confidence']): ?><span class="muted small"><?= t('bank_conf_' . $r['ai_confidence']) ?></span><?php endif; ?>
                    <?php if ($r['match_status'] === 'unmatched' && $r['ai_status'] && $r['ai_status'] !== 'ok'): ?><span class="muted small"><?= t('bank_ai_' . $r['ai_status']) ?></span><?php endif; ?>
                    <?php if ($r['match_status'] === 'non_ar' && $r['non_ar_reason']): ?><span class="muted small"><?= t('bank_nonar_' . $r['non_ar_reason']) ?></span><?php endif; ?>
                </td>
                <td class="small"><?php if ($r['booked']): ?><?= e($r['booked']) ?><?php elseif ($prop && in_array($r['match_status'], ['rule', 'ai'], true)): ?><span class="muted"><?= e(sprintf(t('bank_proposal_n'), count($prop['lines']))) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?= pager('bank.index', $filters, $pg) ?>
<?php endif; ?>
