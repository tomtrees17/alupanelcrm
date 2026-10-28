<?php
/**
 * The three call lists as tabs, one customer per row (phone first).
 * @var array  $lists       ['a' => [...], 'b' => [...], 'c' => [...]]
 * @var string $tab
 * @var bool   $showOwner
 * @var string $route       insights.index | insights.admin (tab links + post-back target)
 * @var array  $routeParams extra query params to keep (e.g. owner filter)
 * @var array  $recent      customer id => latest follow-ups
 *
 * Every write posts to insights.* — it must work for sales, who have this
 * module but not 客户管理.
 */
$backKey = $route === 'insights.admin' ? 'insights_admin' : 'insights';
$days = fn($n) => sprintf(t('ins_days'), (int) $n);
// Urgency (2026-10-28, "一眼看懂"): A = how far past the usual rhythm, B = how long overdue, C = opportunity.
$level = function (array $r) use ($tab): string {
    if ($tab === 'b') {
        return (int) $r['overdue_red'] === 1 ? 'red' : 'orange';
    }
    if ($tab === 'c') {
        return 'blue';
    }
    $ratio = $r['avg_interval'] ? (float) $r['days_since'] / max(1.0, (float) $r['avg_interval']) : 0;
    return $ratio >= 1.3 ? 'orange' : 'green';
};
$rows = $lists[$tab];
if ($tab === 'a') {
    usort($rows, fn($x, $y) => ((float) $y['days_since'] / max(1.0, (float) ($y['avg_interval'] ?: 1))) <=> ((float) $x['days_since'] / max(1.0, (float) ($x['avg_interval'] ?: 1))));
} elseif ($tab === 'b') {
    usort($rows, fn($x, $y) => [(int) $y['overdue_red'], (int) $y['overdue_days']] <=> [(int) $x['overdue_red'], (int) $x['overdue_days']]);
}
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

<?php if (!$rows): ?>
    <div class="card"><div class="empty"><?= t('ins_empty_' . $tab) ?></div></div>
<?php endif; ?>

<div class="ins-list">
<?php foreach ($rows as $r): ?>
    <?php $cid = (int) $r['customer_id']; $red = $tab === 'b' && (int) $r['overdue_red'] === 1; $lv = $level($r);
    $hidden = function () use ($cid, $backKey, $tab, $routeParams): string {
        $h = Csrf::field() . '<input type="hidden" name="id" value="' . $cid . '"><input type="hidden" name="back" value="' . $backKey . '"><input type="hidden" name="tab" value="' . e($tab) . '">';
        foreach ($routeParams as $k => $v) {
            $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
        }
        return $h;
    }; ?>
    <article class="ins-item ins-lv-<?= $lv ?><?= $red ? ' ins-red' : '' ?>">
        <div class="ins-head">
            <div class="ins-who">
                <?php if (can_access('customers')): ?>
                    <a class="ins-name" href="<?= url('customers.show', ['id' => $cid]) ?>#followups"><?= e($r['name']) ?></a>
                <?php else: ?>
                    <span class="ins-name"><?= e($r['name']) ?></span>
                <?php endif; ?>
                <?php if ($showOwner): ?><span class="ins-owner"><?= e(trim((string) $r['owner']) ?: t('ins_unassigned')) ?></span><?php endif; ?>
            </div>
            <div class="ins-big"><?php if ($tab === 'c'): ?><strong><?= idr_short($r['total_amount']) ?></strong> <span><?= t('ins_amount12') ?></span><?php else: ?><strong><?= $days($r['days_since']) ?></strong> <span><?= t('ins_since') ?></span><?php endif; ?></div>
        </div>
        <div class="ins-facts">
            <?php if ($tab === 'b'): ?><span class="ins-overdue<?= $red ? ' is-red' : '' ?>"><?= sprintf(t('ins_overdue'), (int) $r['overdue_days']) ?></span><?php endif; ?>
            <?php if ($r['avg_interval'] !== null): ?><span><?= sprintf(t('fu_usually'), (int) round((float) $r['avg_interval'])) ?><?= $r['interval_source'] !== 'own' ? '*' : '' ?></span><?php endif; ?>
            <?php if ($tab === 'c'): ?><span><?= $days($r['days_since']) ?> <?= t('ins_since') ?></span><?php endif; ?>
            <?php if (!empty($r['company']) && mb_strtolower(trim((string) $r['company'])) !== mb_strtolower(trim((string) $r['name']))): ?><span class="muted"><?= e($r['company']) ?></span><?php endif; ?>
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
            <details class="ins-form">
                <summary class="btn btn-primary ins-btn"><?= t('btn_followup') ?></summary>
                <form method="post" action="<?= url('insights.followup') ?>">
                    <?= $hidden() ?>
                    <textarea class="form-input" name="note" rows="2" required placeholder="<?= t('fu_placeholder') ?>"></textarea>
                    <button class="btn btn-primary ins-btn" type="submit"><?= t('btn_save') ?></button>
                </form>
            </details>
            <details class="ins-more">
                <summary class="btn btn-ghost ins-btn"><?= t('ins_more') ?></summary>
                <div class="ins-more-body">
                    <form method="post" action="<?= url('insights.no_need') ?>" class="ins-noneed">
                        <?= $hidden() ?>
                        <textarea class="form-input" name="note" rows="2" required placeholder="<?= t('ins_no_need_placeholder') ?>"></textarea>
                        <button class="btn btn-ghost ins-btn" type="submit"><?= t('btn_no_need') ?></button>
                    </form>
                    <dl class="ins-detail">
                        <div><dt><?= t('th_phone') ?></dt><dd><?= e((string) $r['phone']) ?: '—' ?></dd></div>
                        <div><dt><?= t('th_region') ?></dt><dd><?= $r['region'] === null ? '—' : e(region_label((string) $r['region'])) ?></dd></div>
                        <div><dt><?= t('th_industry') ?></dt><dd>
                            <?= e(industry_label((string) ($r['industry'] ?? ''))) ?>
                            <?php if ((int) $r['industry_pending'] === 1 && $r['industry_source'] !== 'manual'): ?><span class="tag tag-orange"><?= t('ind_pending') ?></span><?php endif; ?>
                            <?php if ($r['industry_source'] === 'manual'): ?><span class="muted small"><?= t('indsrc_manual') ?></span><?php endif; ?>
                        </dd></div>
                    </dl>
                    <form class="industry-form" method="post" action="<?= url('insights.set_industry') ?>">
                        <?= $hidden() ?>
                        <select class="form-select" name="industry" aria-label="<?= t('th_industry') ?>">
                            <?php if (empty($r['industry'])): ?><option value="" disabled selected><?= t('ind_choose') ?></option><?php endif; ?>
                            <?php foreach (Enrich::INDUSTRIES as $code): ?><option value="<?= $code ?>" <?= ($r['industry'] ?? '') === $code ? 'selected' : '' ?>><?= e(industry_label($code)) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-ghost ins-btn" type="submit"><?= t('ind_confirm') ?></button>
                    </form>
                    <?php foreach ($recent[$cid] ?? [] as $f): ?>
                        <p class="small"><span class="muted"><?= e(substr((string) $f['created_at'], 0, 10)) ?> · <?= e((string) $f['user_name']) ?><?= $f['kind'] === 'no_need' ? ' · ' . t('btn_no_need') : '' ?>:</span> <?= e(mb_strimwidth((string) $f['note'], 0, 120, '…')) ?></p>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php if ($rows): ?><p class="muted small">* <?= t('ins_estimated') ?></p><?php endif; ?>
