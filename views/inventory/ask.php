<?php
/** @var string $question */ /** @var ?array $answer */ /** @var string $error */
/** @var int $used */ /** @var int $limit */ /** @var bool $stub */
?>
<div class="page-head">
    <h1><?= t('page_ask') ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('inventory.index') ?>"><?= t('nav_inventory') ?></a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= url('inventory.ask') ?>" class="ask-form">
            <?= Csrf::field() ?>
            <textarea class="form-input ask-input" name="q" rows="2" autofocus
                      placeholder="<?= t('ask_placeholder') ?>"><?= e($question) ?></textarea>
            <button class="btn btn-primary ask-send" type="submit"><?= t('ask_send') ?></button>
        </form>
        <div class="muted small" style="margin-top:8px">
            <?= t('ask_hint') ?>
            · <?= sprintf(t('ai_used_today'), $used, $limit) ?>
            <?php if ($stub): ?> · <span class="tag tag-orange"><?= t('ai_stub_mode') ?></span><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="card"><div class="card-body"><div class="empty"><?= e($error) ?></div></div></div>
<?php endif; ?>

<?php if ($answer !== null): ?>
    <?php if (!empty($answer['clarify']) && !$answer['products']): ?>
        <div class="card"><div class="card-body">
            <div class="empty"><?= t('ask_no_match') ?></div>
        </div></div>
    <?php else: ?>
        <?php if (!empty($answer['qty'])): ?>
            <div class="notes" style="margin-bottom:12px"><?= sprintf(t('ask_qty_note'), (int) $answer['qty']) ?></div>
        <?php endif; ?>

        <?php foreach ($answer['products'] as $p): ?>
            <?php
            $need = (int) ($answer['qty'] ?? 0);
            $avail = (int) $p['available'];
            $enough = $need > 0 ? $avail >= $need : $avail > 0;
            ?>
            <div class="card ask-card">
                <div class="card-body">
                    <div class="ask-name">
                        <?= e($p['sku']) ?> · <?= e($p['color_zh']) ?><?= $p['color_en'] ? ' / ' . e($p['color_en']) : '' ?>
                    </div>
                    <div class="muted small"><?= e($p['spec']) ?> · <?= e($p['size']) ?> · <?= idr($p['price']) ?>/<?= e($p['unit']) ?></div>

                    <div class="ask-stock">
                        <div class="ask-avail <?= $enough ? 'ok' : 'low' ?>">
                            <span class="ask-num"><?= num($avail) ?></span>
                            <span class="muted small"><?= t('available') ?></span>
                        </div>
                        <div class="ask-side muted small">
                            <?= t('th_stock') ?> <?= num($p['stock']) ?>
                            <?php if ((int) $p['reserved'] > 0): ?>
                                · <?= t('reserved') ?> <?= num($p['reserved']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($need > 0): ?>
                        <div class="<?= $enough ? 'tag tag-green' : 'tag tag-red' ?>">
                            <?= $enough ? sprintf(t('ask_enough'), $need) : sprintf(t('ask_short'), $need - $avail) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$answer['products']): ?>
            <div class="card"><div class="card-body"><div class="empty"><?= t('ask_no_match') ?></div></div></div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
