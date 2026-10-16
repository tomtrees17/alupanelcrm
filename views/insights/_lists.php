<?php
/**
 * The three call lists as tabs, one customer per row (phone first).
 * @var array  $lists       ['a' => [...], 'b' => [...], 'c' => [...]]
 * @var string $tab
 * @var bool   $showOwner
 * @var string $route       insights.index | insights.admin (tab links + post-back target)
 * @var array  $routeParams extra query params to keep (e.g. owner filter)
 */
$backKey = $route === 'insights.admin' ? 'insights_admin' : 'insights';
$days = fn($n) => sprintf(t('ins_days'), (int) $n);
$wa = function (string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($d, '0')) {
        $d = '62' . substr($d, 1);
    }
    return strlen($d) >= 9 ? 'https://wa.me/' . $d : '';
};
?>
<nav class="ins-tabs" aria-label="<?= t('nav_insights') ?>">
    <?php foreach (Insights::LISTS as $l): ?>
        <a class="ins-tab ins-tab-<?= $l ?><?= $tab === $l ? ' active' : '' ?>" href="<?= url($route, $routeParams + ['tab' => $l]) ?>">
            <span class="ins-tab-n"><?= count($lists[$l]) ?></span>
            <span class="ins-tab-label"><?= t('list_' . $l) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<p class="muted small ins-rule"><?= t('ins_rule_' . $tab) ?></p>

<?php if (!$lists[$tab]): ?>
    <div class="card"><div class="empty"><?= t('ins_empty_' . $tab) ?></div></div>
<?php endif; ?>

<div class="ins-list">
<?php foreach ($lists[$tab] as $r): ?>
    <?php $cid = (int) $r['customer_id']; $red = $tab === 'b' && (int) $r['overdue_red'] === 1; ?>
    <article class="ins-item<?= $red ? ' ins-red' : '' ?>">
        <div class="ins-head">
            <a class="ins-name" href="<?= url('customers.show', ['id' => $cid]) ?>#followups"><?= e($r['name']) ?></a>
            <?php if ($showOwner): ?><span class="ins-owner"><?= e(trim((string) $r['owner']) ?: '—') ?></span><?php endif; ?>
        </div>
        <?php if (!empty($r['company']) && mb_strtolower(trim((string) $r['company'])) !== mb_strtolower(trim((string) $r['name']))): ?>
            <div class="muted small"><?= e($r['company']) ?></div>
        <?php endif; ?>

        <div class="ins-facts">
            <span><strong><?= $days($r['days_since']) ?></strong> <?= t('ins_since') ?></span>
            <?php if ($r['avg_interval'] !== null): ?>
                <span><?= sprintf(t('fu_usually'), (int) round((float) $r['avg_interval'])) ?><?= $r['interval_source'] !== 'own' ? '*' : '' ?></span>
            <?php endif; ?>
            <?php if ($tab === 'b'): ?>
                <span class="ins-overdue<?= $red ? ' is-red' : '' ?>"><?= sprintf(t('ins_overdue'), (int) $r['overdue_days']) ?></span>
            <?php endif; ?>
            <?php if ($tab === 'c'): ?>
                <span><?= t('ins_amount12') ?> <strong><?= idr_short($r['total_amount']) ?></strong></span>
            <?php endif; ?>
        </div>
        <div class="ins-last muted small">
            <?php if ($tab === 'c' && !empty($r['top_specs'])): ?>
                <?= t('ins_main') ?>: <?= e($r['top_specs']) ?>
            <?php else: ?>
                <?= t('ins_last') ?> <?= e((string) $r['last_order_date']) ?><?= !empty($r['last_items']) ? ' · ' . e(mb_strimwidth((string) $r['last_items'], 0, 90, '…')) : '' ?>
            <?php endif; ?>
        </div>

        <div class="ins-actions">
            <?php if (!empty($r['phone'])): ?>
                <a class="btn btn-ghost ins-btn" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $r['phone'])) ?>"><?= t('ins_call') ?></a>
                <?php if ($wa((string) $r['phone']) !== ''): ?><a class="btn btn-ghost ins-btn" href="<?= e($wa((string) $r['phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
            <?php endif; ?>
            <?php foreach (['followup' => ['btn_followup', 'fu_placeholder', 'btn-primary'], 'no_need' => ['btn_no_need', 'ins_no_need_placeholder', 'btn-ghost']] as $act => [$label, $ph, $cls]): ?>
                <details class="ins-form">
                    <summary class="btn <?= $cls ?> ins-btn"><?= t($label) ?></summary>
                    <form method="post" action="<?= url('customers.' . $act) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="hidden" name="back" value="<?= $backKey ?>">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <?php foreach ($routeParams as $k => $v): ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
                        <textarea class="form-input" name="note" rows="2" required placeholder="<?= t($ph) ?>"></textarea>
                        <button class="btn <?= $cls ?> ins-btn" type="submit"><?= t('btn_save') ?></button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php if ($lists[$tab]): ?><p class="muted small">* <?= t('ins_estimated') ?></p><?php endif; ?>
