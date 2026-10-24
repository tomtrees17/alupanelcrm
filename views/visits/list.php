<?php /** @var array $rows */ /** @var array $f */ /** @var array $sales */ /** @var bool $canRecord */
include __DIR__ . '/_helpers.php';
?>
<div class="page-head"><h1><?= t('nav_visits') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="visits.list">
    <select class="form-select filter-select" name="employee_id" onchange="this.form.submit()">
        <option value=""><?= t('ins_all_sales') ?></option>
        <?php foreach ($sales as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $f['employee_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="outcome" onchange="this.form.submit()">
        <option value=""><?= t('vis_all_outcomes') ?></option>
        <?php foreach (Visits::OUTCOMES as $k => $label): ?><option value="<?= $k ?>" <?= $f['outcome'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <input class="form-input" type="date" name="from" value="<?= e($f['from']) ?>" style="max-width:160px">
    <input class="form-input" type="date" name="to" value="<?= e($f['to']) ?>" style="max-width:160px">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('vis_date') ?></th><th><?= t('vis_sales') ?></th><th><?= t('vis_who') ?></th><th><?= t('vis_outcome') ?></th><th>Catatan</th><th class="right"><?= t('vis_distance') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $v): ?>
        <tr class="<?= $v['distance_flag'] === 'far' ? 'vis-far' : '' ?>">
            <td class="nowrap"><?= e(Hr::dmy(substr((string) $v['visited_at'], 0, 10))) ?><br><code class="small"><?= e($v['visit_no']) ?></code></td>
            <td><?= e($v['sales']) ?></td>
            <td><?= $targetLabel($v) ?></td>
            <td><?= $outcomeTag((string) $v['outcome']) ?></td>
            <td class="small"><?= e(Visits::catatan($v)) ?><?= $v['photo_path'] ? ' <a href="' . url('visits.photo', ['id' => $v['id']]) . '" target="_blank" rel="noopener">' . t('vis_photo') . '</a>' : '' ?></td>
            <td class="right nowrap small"><?php if ($v['distance_m'] === null): ?><span class="muted"><?= t('vis_no_coords') ?></span><?php else: ?><?= number_format((int) $v['distance_m'], 0, ',', '.') ?> m<?= $v['distance_flag'] === 'far' ? ' · ' . t('vis_far') : '' ?><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= sprintf(t('vis_list_hint'), Visits::FAR_METERS) ?></p>
