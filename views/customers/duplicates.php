<?php
/** @var array $groups */
// CM-01: suspected duplicate customers. Nothing merges by itself — a person picks the record to keep.
$reasonLabel = ['phone' => t('cm_reason_phone'), 'name' => t('cm_reason_name'), 'company' => t('cm_reason_company')];
?>
<div class="page-head">
    <h1><?= t('cm_title') ?></h1>
    <a class="btn btn-ghost" href="<?= url('customers.index') ?>"><?= t('btn_back') ?></a>
</div>
<p class="muted small"><?= t('cm_hint') ?></p>

<?php if (!$groups): ?><div class="card"><div class="card-body"><div class="empty"><?= t('cm_none') ?></div></div></div><?php endif; ?>
<?php if ($groups): ?><p class="muted small"><?= sprintf(t('cm_count'), count($groups), array_sum(array_map(fn($g) => count($g['ids']), $groups))) ?></p><?php endif; ?>

<?php foreach ($groups as $gi => $g): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><?= e((string) $g['rows'][0]['name']) ?></span>
            <span><?php foreach ($g['reasons'] as $rs): ?><span class="tag tag-gray"><?= e($reasonLabel[$rs] ?? $rs) ?></span> <?php endforeach; ?></span>
        </div>
        <div class="card-body">
            <form method="post" action="<?= url('customers.merge') ?>" id="cm-g<?= $gi ?>">
                <?= Csrf::field() ?>
                <div class="table-wrap"><table class="cm-table">
                    <thead><tr>
                        <th><?= t('cm_th_keep') ?></th><th><?= t('cm_th_merge') ?></th><th><?= t('th_name') ?></th><th><?= t('th_company') ?></th><th><?= t('th_phone') ?></th>
                        <th><?= t('th_city') ?></th><th><?= t('owner') ?></th><th><?= t('th_tag') ?></th><th class="right"><?= t('cleanup_orders') ?></th>
                        <th><?= t('cleanup_last_order') ?></th><th class="right"><?= t('cm_th_sales') ?></th><th class="right"><?= t('cm_th_visits') ?></th><th><?= t('cm_th_created') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($g['rows'] as $i => $r): ?>
                        <tr>
                            <td><input type="radio" name="keep" value="<?= (int) $r['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> required></td>
                            <td><input type="checkbox" name="from[]" value="<?= (int) $r['id'] ?>" <?= $i > 0 ? 'checked' : '' ?>></td>
                            <td class="wrap"><a href="<?= url('customers.show', ['id' => $r['id']]) ?>"><?= e($r['name']) ?></a> <span class="muted small">#<?= (int) $r['id'] ?></span></td>
                            <td class="wrap"><?= e((string) $r['company']) ?></td>
                            <td><?= e((string) $r['phone']) ?></td>
                            <td class="small wrap"><?= e((string) $r['city']) ?></td>
                            <td><?= e((string) $r['owner']) ?></td>
                            <td><span class="tag <?= customer_tag_class((string) $r['tag']) ?>"><?= e(tr_tag((string) $r['tag'])) ?></span><?= trim((string) $r['tax_npwp']) !== '' ? ' <span class="tag tag-gray">NPWP</span>' : '' ?></td>
                            <td class="right"><?= (int) $r['orders'] ?></td>
                            <td><?= e(Hr::dmy((string) $r['last_order'])) ?></td>
                            <td class="right"><?= $r['sales'] > 0 ? idr($r['sales']) : '—' ?></td>
                            <td class="right"><?= (int) $r['visits'] ?: '—' ?></td>
                            <td><?= e(Hr::dmy((string) $r['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </form>
            <div class="pair-actions" style="margin-top:8px">
                <button class="btn btn-primary btn-sm" type="submit" form="cm-g<?= $gi ?>"><?= t('cm_btn_next') ?></button>
                <form method="post" action="<?= url('customers.dup_ignore') ?>" onsubmit="return confirm('<?= e(t('cm_ignore_confirm')) ?>')">
                    <?= Csrf::field() ?>
                    <?php foreach ($g['ids'] as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= t('cm_btn_ignore') ?></button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
