<?php /** @var string $from */ /** @var string $to */ /** @var string $show */ /** @var array $rv */ /** @var array $rows */ /** @var array $exports */ /** @var array $recent */ /** @var callable $blockerText */ ?>
<?php
$money = fn(float $v): string => number_format($v, 2, ',', '.');
$cls = ['FAKTUR' => 'tag-green', 'GUNGGUNG' => 'tag-gray', 'TBC' => 'tag-orange'];
$c = $rv['count'];
$keep = ['from' => $from, 'to' => $to];
?>
<div class="page-head">
    <div>
        <h1><?= t('fk_title') ?></h1>
        <p class="muted"><?= t('fk_sub') ?></p>
    </div>
    <form method="get" action="index.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="r" value="tax.index">
        <input class="form-input" type="date" name="from" value="<?= e($from) ?>" style="width:160px">
        <span class="muted">–</span>
        <input class="form-input" type="date" name="to" value="<?= e($to) ?>" style="width:160px">
        <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
    </form>
</div>

<?php foreach ($rv['global'] as $g): ?><div class="alert alert-error" style="margin-bottom:12px"><?= e($blockerText($g)) ?></div><?php endforeach; ?>

<div class="stats-grid">
    <div class="stat-card c1"><div class="stat-label">FAKTUR</div><div class="stat-value" data-count-faktur><?= (int) $c['FAKTUR'] ?></div><div class="stat-sub muted"><?= t('fk_faktur_hint') ?></div></div>
    <div class="stat-card"><div class="stat-label">GUNGGUNG</div><div class="stat-value"><?= (int) $c['GUNGGUNG'] ?></div><div class="stat-sub muted"><?= t('fk_gunggung_hint') ?></div></div>
    <div class="stat-card c3"><div class="stat-label">TBC</div><div class="stat-value"><?= (int) $c['TBC'] ?></div><div class="stat-sub muted"><?= t('fk_tbc_hint') ?></div></div>
    <div class="stat-card c4"><div class="stat-label"><?= t('fk_blocked') ?></div><div class="stat-value"<?= $c['blocked'] ? ' style="color:var(--danger)"' : '' ?>><?= (int) $c['blocked'] ?></div><div class="stat-sub muted"><?= t('fk_blocked_hint') ?></div></div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <form method="post" action="<?= url('tax.generate') ?>">
        <?= Csrf::field() ?><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
        <button class="btn btn-primary" type="submit"<?= ($c['blocked'] || $rv['global'] || !$c['FAKTUR']) ? ' disabled' : '' ?>><?= t('fk_btn_generate') ?></button>
    </form>
    <form method="post" action="<?= url('tax.gunggung') ?>">
        <?= Csrf::field() ?><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
        <button class="btn btn-ghost" type="submit"<?= $c['GUNGGUNG'] ? '' : ' disabled' ?>><?= t('fk_btn_gunggung') ?></button>
    </form>
</div>

<?php if ($exports): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('fk_files') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('fk_th_kind') ?></th><th><?= t('fk_th_file') ?></th><th class="right"><?= t('fk_th_invoices') ?></th><th class="right">PPN</th><th><?= t('fk_th_by') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($exports as $x): ?>
            <tr<?= $x['superseded_at'] ? ' class="muted"' : '' ?>>
                <td><?= $x['kind'] === 'faktur' ? 'Coretax Faktur' : 'Digunggung' ?><?php if ($x['superseded_at']): ?> <span class="tag tag-gray"><?= t('fk_superseded') ?></span><?php endif; ?></td>
                <td><a href="<?= url('tax.download', ['id' => $x['id']]) ?>"><?= e($x['file_name']) ?></a></td>
                <td class="right num"><?= (int) $x['invoices'] ?><?= $x['kind'] === 'faktur' ? ' / ' . (int) $x['lines'] . ' ' . t('fk_lines') : '' ?></td>
                <td class="right num"><?= $money((float) $x['ppn_total']) ?></td>
                <td class="small nowrap"><?= e((string) $x['created_by']) ?> · <?= e((string) $x['created_at']) ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="task-filters">
    <?php foreach (['' => t('filter_all') . ' (' . count($rv['rows']) . ')', 'FAKTUR' => 'FAKTUR', 'GUNGGUNG' => 'GUNGGUNG', 'TBC' => 'TBC', 'blocked' => t('fk_blocked')] as $k => $label): ?>
        <a class="filter-btn <?= $show === $k ? 'active' : '' ?>" href="<?= url('tax.index', $keep + ['show' => $k]) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('fk_th_invoice') ?></th><th><?= t('th_date') ?></th><th><?= t('th_customer') ?></th><th>NPWP / NIK</th>
            <th class="right">DPP</th><th class="right">PPN</th><th><?= t('fk_th_class') ?></th><th><?= t('fk_th_check') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= t('fk_no_invoices') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <?php $id = Faktur::buyer_id($r); ?>
            <tr data-invoice="<?= e($r['invoice_no']) ?>" data-class="<?= e($r['class']) ?>">
                <td class="nowrap"><a href="<?= url('finance.show', ['id' => $r['id']]) ?>"><?= e($r['invoice_no']) ?></a></td>
                <td class="nowrap"><?= e(Hr::dmy($r['invoice_date'])) ?></td>
                <td><?= e(Faktur::buyer_name($r)) ?>
                    <?php if ($r['customer_id']): ?><br><a class="small" href="<?= url('tax.customer', ['id' => $r['customer_id']] + $keep) ?>"><?= t('fk_edit_tax') ?></a><?php endif; ?></td>
                <td class="small nowrap"><?= $id ? e($id['id']) : '<span class="muted">—</span>' ?></td>
                <td class="right num"><?= $money((float) $r['subtotal']) ?></td>
                <td class="right num"><?= $money((float) $r['ppn']) ?></td>
                <td class="nowrap">
                    <form method="post" action="<?= url('tax.classify') ?>" class="inline-form" style="justify-content:flex-start">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="show" value="<?= e($show) ?>">
                        <span class="tag <?= $cls[$r['class']] ?>"><?= e($r['class']) ?></span>
                        <select class="form-input" name="class" onchange="this.form.submit()" style="max-width:150px">
                            <option value=""<?= $r['faktur_class'] ? '' : ' selected' ?>><?= t('fk_auto') ?> (<?= e($r['auto_class']) ?>)</option>
                            <?php foreach (Faktur::CLASSES as $k): ?><option value="<?= $k ?>"<?= $r['faktur_class'] === $k ? ' selected' : '' ?>><?= $k ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td class="small"><?php if ($r['blockers']): ?><?php foreach ($r['blockers'] as $b): ?><div style="color:var(--danger)"><?= e($blockerText($b)) ?></div><?php endforeach; ?><?php elseif ($r['class'] === 'FAKTUR'): ?><span class="tag tag-green">OK</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<p class="muted small"><?= t('fk_not_done_hint') ?></p>
