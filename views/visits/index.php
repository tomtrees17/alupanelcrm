<?php /** @var array $rows */ /** @var array $me */ /** @var bool $all */ /** @var string $today */
include __DIR__ . '/_helpers.php';
?>
<div class="page-head">
    <h1><?= t('nav_visits') ?></h1>
    <?php if ($all): ?><a class="btn btn-ghost" href="<?= url('visits.list') ?>"><?= t('vis_list') ?></a><?php endif; ?>
</div>
<a class="btn btn-primary vis-big" href="<?= url('visits.new') ?>">＋ <?= t('vis_new') ?></a>

<div class="vis-cards">
    <?php if (!$rows): ?><div class="card"><div class="empty"><?= t('vis_none_yet') ?></div></div><?php endif; ?>
    <?php foreach ($rows as $v): ?>
        <div class="card vis-card"><div class="card-body">
            <div class="vis-card-top">
                <strong><?= $targetLabel($v) ?></strong>
                <?= $outcomeTag((string) $v['outcome']) ?>
            </div>
            <div class="muted small"><?= e(Hr::dmy(substr((string) $v['visited_at'], 0, 10))) ?> · <code><?= e($v['visit_no']) ?></code><?= $v['photo_path'] ? ' · <a href="' . url('visits.photo', ['id' => $v['id']]) . '" target="_blank" rel="noopener">' . t('vis_photo') . '</a>' : '' ?></div>
            <?php if (Visits::catatan($v) !== ''): ?><div class="small"><?= e(Visits::catatan($v)) ?></div><?php endif; ?>
            <?php if (Visits::editable($v, $me)): ?><a class="small" href="<?= url('visits.edit', ['id' => $v['id']]) ?>"><?= t('btn_edit') ?></a><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
</div>
<p class="muted small"><?= sprintf(t('vis_mine_hint'), Visits::EDIT_HOURS) ?></p>
