<?php /** @var array $rows */ /** @var string $from */ /** @var string $to */ /** @var bool $only */ /** @var bool $canRecord */ /** @var bool $canImport */
$rp = fn($v) => $v === null ? '<span class="muted">—</span>' : number_format((float) $v, 0, ',', '.');
?>
<div class="page-head"><h1><?= t('vis_competitors') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="visits.competitors">
    <input class="form-input" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px"> – <input class="form-input" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px">
    <label class="check-all"><input type="checkbox" name="all" value="1" <?= $only ? '' : 'checked' ?>> <?= t('vis_comp_all') ?></label>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('vis_brand') ?></th><th><?= t('vis_area') ?></th><th class="right"><?= t('vis_count') ?></th><th class="right"><?= t('vis_priced') ?></th>
        <th class="right"><?= t('vis_price_min') ?></th><th class="right"><?= t('vis_price_avg') ?></th><th class="right"><?= t('vis_price_max') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td><strong><?= e((string) $r['brand']) ?></strong></td><td><?= e((string) $r['area']) ?></td><td class="right"><?= (int) $r['n'] ?></td><td class="right"><?= (int) $r['priced'] ?></td>
            <td class="right"><?= $rp($r['min_price']) ?></td><td class="right"><?= $rp($r['avg_price'] !== null ? round((float) $r['avg_price']) : null) ?></td><td class="right"><?= $rp($r['max_price']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('vis_comp_hint') ?></p>
