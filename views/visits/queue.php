<?php /** @var array $pending */ /** @var array $guesses */ /** @var array $tags */ /** @var int $left */ /** @var bool $aiOn */ /** @var bool $canRecord */ /** @var bool $canImport */
include __DIR__ . '/_helpers.php';
?>
<div class="page-head"><h1><?= t('vis_queue') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($left > 0): ?>
<div class="card"><div class="card-body inline-form" style="justify-content:flex-start">
    <span><?= sprintf(t($aiOn ? 'vis_imp_left_ai' : 'vis_imp_left'), $left) ?></span>
    <form method="post" action="<?= url('visits.ai_run') ?>"><?= Csrf::field() ?><input type="hidden" name="back" value="queue"><button class="btn btn-ghost btn-sm" type="submit"><?= t('vis_imp_continue') ?></button></form>
</div></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_queue_match') ?></span><span class="muted small"><?= count($pending) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_date') ?></th><th><?= t('vis_sales') ?></th><th><?= t('vis_store_written') ?></th><th><?= t('vis_queue_choose') ?></th></tr></thead>
        <tbody>
        <?php if (!$pending): ?><tr><td colspan="4" class="empty"><?= t('vis_queue_empty') ?></td></tr><?php endif; ?>
        <?php foreach ($pending as $v): ?>
            <?php $cands = (array) json_decode((string) $v['match_candidates'], true); $ai = (array) json_decode((string) $v['ai_match'], true); $pick = (string) ($ai['choice'] ?? ''); ?>
            <tr>
                <td class="nowrap small"><?= e(Hr::dmy(substr((string) $v['visited_at'], 0, 10))) ?><br><code><?= e($v['visit_no']) ?></code></td>
                <td class="small"><?= e((string) $v['sales']) ?></td>
                <td><strong><?= e((string) $v['store_name_raw']) ?></strong><?= $v['store_type'] ? ' <span class="muted small">' . e($v['store_type']) . '</span>' : '' ?>
                    <?= $v['photo_path'] ? '<br><a class="small" href="' . url('visits.photo', ['id' => $v['id']]) . '" target="_blank" rel="noopener">' . t('vis_photo') . '</a>' : '' ?>
                    <?php if ($ai): ?><div class="small vis-ai"><?= t('vis_ai_says') ?>: <?= e((string) ($ai['reason_zh'] ?? '')) ?> <span class="muted">(<?= e((string) ($ai['confidence'] ?? '')) ?>)</span></div><?php endif; ?></td>
                <td>
                    <form method="post" action="<?= url('visits.confirm') ?>" class="vis-choices">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <?php foreach ($cands as $c): ?>
                            <button class="btn btn-sm <?= $c['key'] === $pick ? 'btn-primary' : 'btn-ghost' ?>" type="submit" name="choice" value="<?= e($c['key']) ?>">
                                <?= e($c['name']) ?> <span class="small"><?= t('vistype_' . $c['kind']) ?><?= $c['where'] ? ' · ' . e($c['where']) : '' ?> · <?= round(100 * (float) $c['score']) ?>%</span></button>
                        <?php endforeach; ?>
                        <button class="btn btn-sm <?= ($ai && $pick === '') ? 'btn-primary' : 'btn-ghost' ?>" type="submit" name="choice" value="new"><?= t('vis_queue_new_store') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="card-body muted small"><?= t('vis_queue_hint') ?></p>
</div>

<?php if ($tags): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_queue_tags') ?></span><span class="muted small"><?= count($tags) ?></span></div>
    <div class="table-wrap"><table>
        <tbody>
        <?php foreach ($tags as $tg => $n): ?>
            <tr><td><code>#<?= e($tg) ?></code> <span class="muted small">×<?= (int) $n ?></span></td>
                <td class="right"><form method="post" action="<?= url('visits.tag') ?>" class="inline-form">
                    <?= Csrf::field() ?><input type="hidden" name="tag" value="<?= e($tg) ?>">
                    <button class="btn btn-primary btn-sm" type="submit" name="kind" value="brand"><?= t('vis_tag_is_brand') ?></button>
                    <button class="btn btn-ghost btn-sm" type="submit" name="kind" value="ignore"><?= t('vis_tag_not_brand') ?></button>
                </form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_queue_outcome') ?></span><span class="muted small"><?= count($guesses) ?></span></div>
    <form method="post" action="<?= url('visits.adopt') ?>">
        <?= Csrf::field() ?>
        <div class="table-wrap"><table>
            <thead><tr><th><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = this.checked)" aria-label="all"></th>
                <th><?= t('vis_date') ?></th><th><?= t('vis_sales') ?></th><th><?= t('vis_who') ?></th><th><?= t('vis_ai_guess') ?></th><th>Catatan</th></tr></thead>
            <tbody>
            <?php if (!$guesses): ?><tr><td colspan="6" class="empty"><?= t('vis_queue_empty') ?></td></tr><?php endif; ?>
            <?php foreach ($guesses as $v): ?>
                <tr><td><input type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>"></td>
                    <td class="nowrap small"><?= e(Hr::dmy(substr((string) $v['visited_at'], 0, 10))) ?></td><td class="small"><?= e((string) $v['sales']) ?></td>
                    <td><?= $targetLabel($v) ?></td><td><?= $outcomeTag((string) $v['ai_outcome_guess']) ?></td><td class="small"><?= e(mb_strimwidth((string) $v['caption'], 0, 160, '…')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($guesses): ?><div class="card-body"><button class="btn btn-primary btn-sm" type="submit"><?= t('vis_adopt_selected') ?></button></div><?php endif; ?>
    </form>
</div>
