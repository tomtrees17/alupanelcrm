<?php
/** @var array $pairs */ /** @var array $usage */ /** @var array $accounts */
/** @var array $rows */ /** @var string $ownerFilter */ /** @var array $owners */
/** @var array $suggest */ /** @var string $enrichLast */
$accountNames = array_map(fn($a) => mb_strtolower($a['name']), $accounts);
$isAccount = fn(string $n): bool => in_array(mb_strtolower(trim($n)), $accountNames, true);
?>
<div class="page-head">
    <h1><?= t('cleanup_title') ?></h1>
    <a class="btn btn-ghost" href="<?= url('customers.index') ?>"><?= t('btn_back') ?></a>
</div>

<?php if ($enrichLast !== ''): ?>
    <p class="muted small"><?= t('enrich_last') ?>: <?= e($enrichLast) ?></p>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('cleanup_pairs') ?></span></div>
    <div class="card-body">
        <p class="muted small"><?= t('cleanup_pairs_hint') ?></p>
        <?php if (!$pairs): ?><div class="empty"><?= t('cleanup_pairs_none') ?></div><?php endif; ?>
        <?php foreach ($pairs as [$a, $b]): ?>
            <div class="pair-row">
                <div class="pair-names">
                    <?php foreach ([$a, $b] as $n): ?>
                        <span class="pair-name"><strong><?= e($n) ?></strong>
                            <span class="muted small"><?= sprintf(t('cleanup_uses'), (int) ($usage[$n] ?? 0)) ?><?= $isAccount($n) ? ' · ' . t('cleanup_is_account') : '' ?></span></span>
                    <?php endforeach; ?>
                </div>
                <div class="pair-actions">
                    <?php foreach ([[$b, $a], [$a, $b]] as [$from, $to]): ?>
                        <form method="post" action="<?= url('customers.merge_owner') ?>" onsubmit="return confirm(<?= e(json_encode(sprintf(t('cleanup_merge_confirm'), $from, $to), JSON_UNESCAPED_UNICODE)) ?>)">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= sprintf(t('cleanup_merge_into'), e($to)) ?></button>
                        </form>
                    <?php endforeach; ?>
                    <form method="post" action="<?= url('customers.ignore_pair') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="a" value="<?= e($a) ?>"><input type="hidden" name="b" value="<?= e($b) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= t('cleanup_not_same') ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('cleanup_assign') ?></span></div>
    <div class="card-body">
        <form class="searchbar" method="get" action="index.php">
            <input type="hidden" name="r" value="customers.cleanup">
            <select class="form-select filter-select" name="owner" onchange="this.form.submit()" aria-label="<?= t('owner') ?>">
                <?php foreach ($owners as $o => $n): ?>
                    <option value="<?= e((string) $o) ?>" <?= $ownerFilter === (string) $o ? 'selected' : '' ?>><?= $o === '' ? t('cleanup_no_owner') : e((string) $o) ?> (<?= (int) $n ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>

        <form method="post" action="<?= url('customers.assign') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="owner" value="<?= e($ownerFilter) ?>">
            <div class="assign-bar">
                <label class="check-all"><input type="checkbox" onclick="document.querySelectorAll('.assign-pick').forEach(c => c.checked = this.checked)"> <?= t('cleanup_select_all') ?></label>
                <select class="form-select filter-select" name="owner_to" aria-label="<?= t('cleanup_assign_to') ?>">
                    <option value=""><?= t('cleanup_assign_to') ?>…</option>
                    <?php foreach ($accounts as $a): ?><option value="<?= e($a['name']) ?>"><?= e($a['name']) ?> · <?= e(role_label((string) $a['role'])) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit" name="mode" value="target"><?= t('cleanup_assign_btn') ?></button>
                <button class="btn btn-ghost" type="submit" name="mode" value="suggest"><?= t('cleanup_assign_suggest') ?></button>
            </div>
            <div class="table-wrap"><table>
                <thead><tr><th></th><th><?= t('th_name') ?></th><th><?= t('th_company') ?></th><th class="right"><?= t('cleanup_orders') ?></th><th><?= t('cleanup_last_order') ?></th><th><?= t('cleanup_suggested') ?></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= t('no_customer') ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php $sg = $suggest[(int) $r['id']] ?? ''; ?>
                    <tr>
                        <td><input class="assign-pick" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="<?= e($r['name']) ?>"></td>
                        <td><a href="<?= url('customers.show', ['id' => $r['id']]) ?>"><strong><?= e($r['name']) ?></strong></a></td>
                        <td><?= e($r['company']) ?></td>
                        <td class="right"><?= (int) $r['orders'] ?></td>
                        <td><?= e((string) $r['last_order']) ?: '—' ?></td>
                        <td><?= $sg !== '' && $sg !== $ownerFilter ? e($sg) : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </form>
        <p class="muted small"><?= t('cleanup_assign_hint') ?></p>
    </div>
</div>
