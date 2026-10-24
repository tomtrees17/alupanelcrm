<?php /** @var array $rows */ /** @var array $f */ /** @var array $sales */ /** @var array $regions */ /** @var array $categories */ /** @var int $total */ /** @var bool $canRecord */
include __DIR__ . '/_helpers.php';
$self = url('visits.prospects', array_filter($f, fn($x) => $x !== ''));
?>
<div class="page-head"><h1><?= t('vis_prospects') ?> <span class="muted small"><?= $total ?></span></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="visits.prospects">
    <input class="form-input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= t('vis_prospect_search') ?>">
    <select class="form-select filter-select" name="region" onchange="this.form.submit()"><option value=""><?= t('hr_all_locations') ?></option>
        <?php foreach ($regions as $x): ?><option <?= $f['region'] === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
    <select class="form-select filter-select" name="category" onchange="this.form.submit()"><option value=""><?= t('vis_all_categories') ?></option>
        <?php foreach ($categories as $x): ?><option <?= $f['category'] === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
    <select class="form-select filter-select" name="assigned" onchange="this.form.submit()">
        <option value=""><?= t('vis_any_assignee') ?></option><option value="none" <?= $f['assigned'] === 'none' ? 'selected' : '' ?>><?= t('vis_unassigned') ?></option>
        <?php foreach ($sales as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $f['assigned'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>
<form method="post" action="<?= url('visits.assign') ?>">
    <?= Csrf::field() ?><input type="hidden" name="back" value="<?= e($self) ?>">
    <div class="inline-form" style="justify-content:flex-start;margin-bottom:10px">
        <span class="muted small"><?= t('vis_assign_selected') ?></span>
        <select class="form-select" name="employee_id" style="max-width:220px">
            <?php foreach ($sales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?>
            <option value="0"><?= t('vis_unassign') ?></option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit"><?= t('vis_assign') ?></button>
    </div>
    <div class="card"><div class="table-wrap"><table>
        <thead><tr><th><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = this.checked)" aria-label="all"></th>
            <th>Nama Perusahaan</th><th>Kategori</th><th>Wilayah</th><th class="right">Skor</th><th>Telepon</th><th><?= t('vis_assignee') ?></th><th><?= t('vis_last_visit') ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= t('vis_no_prospects') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $p): ?>
            <tr class="<?= (int) $p['is_customer'] === 1 ? 'muted' : '' ?>">
                <td><?php if ((int) $p['is_customer'] === 0): ?><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"><?php endif; ?></td>
                <td><strong><?= e($p['name']) ?></strong><?= (int) $p['is_customer'] === 1 ? ' <span class="tag tag-gray">' . t('vis_already_customer') . '</span>' : '' ?><br><span class="muted small"><?= e((string) $p['address']) ?></span></td>
                <td class="small"><?= e((string) $p['category']) ?></td><td class="small"><?= e((string) $p['region']) ?></td>
                <td class="right"><?= $p['priority'] !== null ? (int) $p['priority'] : '—' ?></td><td class="small nowrap"><?= e((string) $p['phone']) ?></td>
                <td><?= $p['assignee'] ? e($p['assignee']) : '<span class="muted">—</span>' ?></td>
                <td class="small"><?php if ($p['last_visit_at']): ?><?= e(Hr::dmy(substr((string) $p['last_visit_at'], 0, 10))) ?> <?= $outcomeTag((string) $p['last_outcome']) ?> <span class="muted">×<?= (int) $p['visit_count'] ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</form>
<p class="muted small"><?= t('vis_prospects_hint') ?></p>
