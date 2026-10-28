<?php
/** @var string $week */ /** @var array $weeks */ /** @var ?array $review */ /** @var array $items */
/** @var bool $boss */ /** @var bool $canGenerate */ /** @var bool $aiOn */
// HR-07 销售周评: team overview, then one card per salesperson. Week-over-week
// is an arrow and a number only, in neutral grey: no ranking, no colours of judgement.
$zh = current_lang() === 'zh';
$arrow = function (float $now, float $before): string {
    if (abs($now - $before) < 0.005) {
        return '<span class="delta flat">＝</span>';
    }
    return '<span class="delta flat">' . ($now > $before ? '▲' : '▼') . '</span>';
};
$team = $review['data']['team'] ?? [];
?>
<div class="page-head">
    <h1><?= t('srv_title') ?></h1>
    <div class="head-actions">
        <form method="get" class="inline-form">
            <input type="hidden" name="r" value="hr.review">
            <select class="form-input" name="week" onchange="this.form.submit()">
                <?php foreach ($weeks as $w): ?>
                    <option value="<?= e($w) ?>" <?= $w === $week ? 'selected' : '' ?>><?= e(Hr::dmy($w) . ' – ' . Hr::dmy(HrContract::add_days($w, 6))) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($review): ?><a class="btn btn-ghost" href="<?= url('hr.review_export', ['week' => $week]) ?>"><?= t('srv_export') ?></a><?php endif; ?>
        <?php if ($canGenerate): ?>
            <form method="post" action="<?= url('hr.review_generate', ['week' => $week]) ?>" class="inline-form" <?= $review ? 'onsubmit="return confirm(\'' . e(t('srv_regen_confirm')) . '\')"' : '' ?>>
                <?= Csrf::field() ?><button class="btn btn-primary" type="submit"><?= t($review ? 'srv_regen' : 'srv_generate') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$review): ?>
    <div class="card"><div class="card-body"><p class="muted"><?= t('srv_none') ?></p></div></div>
<?php else: ?>
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('srv_team') ?></span>
            <span class="muted small"><?= e(sprintf(t('srv_version'), (int) $review['version'], Hr::dmy(substr((string) $review['generated_at'], 0, 10)) . substr((string) $review['generated_at'], 10, 6), $review['generated_by'] === 'cron' ? t('srv_by_cron') : (string) $review['generated_by'])) ?></span></div>
        <div class="card-body">
            <?php $tt = $zh ? $review['team_zh'] : $review['team_id']; ?>
            <?php if ($tt): ?><p><?= nl2br(e((string) $tt)) ?></p><?php else: ?><p class="muted"><?= t($aiOn ? 'srv_no_comment' : 'srv_ai_off') ?></p><?php endif; ?>
            <div class="srv-facts">
                <span><?= t('srv_visits') ?> <b class="num"><?= (int) ($team['visits'] ?? 0) ?></b> <?= $arrow((float) ($team['visits'] ?? 0), (float) ($team['visits_last_week'] ?? 0)) ?> <span class="muted small"><?= e(sprintf(t('srv_last_week'), (int) ($team['visits_last_week'] ?? 0))) ?></span></span>
                <?php if (!empty($team['zero_visits'])): ?><span><?= t('srv_zero') ?>: <?= e(implode(', ', $team['zero_visits'])) ?></span><?php endif; ?>
                <?php if (!empty($team['order_regions'])): ?><span><?= t('srv_order_regions') ?>: <?= e(implode(', ', array_map(fn($r, $n) => region_label((string) $r) . ' ' . $n, array_keys($team['order_regions']), $team['order_regions']))) ?></span><?php endif; ?>
                <?php if (!empty($team['unmapped_visits'])): ?><span class="tag tag-orange"><?= e(sprintf(t('srv_unmapped'), (int) $team['unmapped_visits'])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$items): ?><div class="card"><div class="card-body"><p class="muted"><?= t('srv_no_people') ?></p></div></div><?php endif; ?>
    <div class="srv-grid">
    <?php foreach ($items as $it): ?>
        <?php $m = $it['m']; $v = $m['visits']; $f = $m['follow']; $res = $m['results']; $c = $zh ? $it['comment_zh'] : $it['comment_id']; ?>
        <div class="card srv-card">
            <div class="card-header"><span class="card-title"><?= e((string) $it['full_name']) ?></span><?php if (!$m['accounts']): ?><span class="tag tag-gray"><?= t('srv_no_account') ?></span><?php endif; ?></div>
            <div class="card-body">
                <div class="srv-block">
                    <div class="srv-label"><?= t('srv_b_visits') ?></div>
                    <div><b class="num srv-big"><?= (int) $v['total'] ?></b> <?= $arrow((float) $v['total'], (float) $v['prev']) ?> <span class="muted small"><?= e(sprintf(t('srv_last_week'), (int) $v['prev'])) ?></span>
                        · Baru <b class="num"><?= (int) $v['baru'] ?></b> · Lama <b class="num"><?= (int) $v['lama'] ?></b></div>
                    <?php if ($v['total'] > 0): ?>
                        <div class="small srv-outcomes">
                            <?php foreach ([...array_keys(Visits::OUTCOMES), Visits::UNKNOWN] as $o): ?><?php if (!empty($v['outcomes'][$o])): ?><span class="tag tag-gray"><?= e(Visits::outcome_label($o)) ?> <?= (int) $v['outcomes'][$o] ?></span><?php endif; ?><?php endforeach; ?>
                            <?php if ($v['wa'] > 0): ?><span class="muted"><?= e(sprintf(t('srv_tagged'), (int) $v['tagged'], (int) $v['wa'])) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="srv-block">
                    <div class="srv-label"><?= t('srv_b_follow') ?></div>
                    <div><?= e(sprintf(t('srv_pending'), (int) $f['pending_count'], SalesReview::PENDING_DAYS)) ?> · <?= e(sprintf(t('srv_a_unvisited'), (int) $f['a_unvisited'])) ?></div>
                    <?php if ($f['pending']): ?>
                        <ul class="srv-list small">
                            <?php foreach ($f['pending'] as $q): ?>
                                <li><a href="<?= $q['kind'] === 'customer' ? url('customers.show', ['id' => $q['id']]) : url('visits.prospects', ['q' => $q['name']]) ?>"><?= e($q['name']) ?></a>
                                    <span class="muted"><?= e(Hr::dmy($q['quoted'])) ?> · <?= e(sprintf(t('srv_days'), (int) $q['days'])) ?></span></li>
                            <?php endforeach; ?>
                            <?php if ($f['pending_count'] > count($f['pending'])): ?><li class="muted"><?= e(sprintf(t('srv_more'), $f['pending_count'] - count($f['pending']))) ?></li><?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="srv-block">
                    <div class="srv-label"><?= t('srv_b_results') ?></div>
                    <div class="srv-results small">
                        <span><?= t('srv_orders') ?> <b class="num"><?= (int) $res['orders'] ?></b> <?= $arrow((float) $res['orders'], (float) $res['prev']['orders']) ?></span>
                        <span><?= t('srv_sheets') ?> <b class="num"><?= e(rtrim(rtrim(number_format((float) $res['sheets'], 2, ',', '.'), '0'), ',')) ?></b> <?= $arrow((float) $res['sheets'], (float) $res['prev']['sheets']) ?></span>
                        <span><?= t('srv_sales') ?> <b class="num"><?= e(idr($res['sales'])) ?></b> <?= $arrow((float) $res['sales'], (float) $res['prev']['sales']) ?></span>
                        <span><?= t('srv_collected') ?> <b class="num"><?= e(idr($res['collected'])) ?></b> <?= $arrow((float) $res['collected'], (float) $res['prev']['collected']) ?></span>
                        <?php if ($res['overdue_invoices'] > 0): ?><span><?= t('srv_overdue') ?> <b class="num"><?= e(idr($res['overdue'])) ?></b> <span class="muted">(<?= (int) $res['overdue_invoices'] ?>)</span></span><?php endif; ?>
                    </div>
                </div>
                <div class="srv-block srv-comment">
                    <div class="srv-label"><?= t('srv_b_comment') ?></div>
                    <?php if ($c): ?><p><?= nl2br(e((string) $c)) ?></p><?php else: ?><p class="muted"><?= t($v['total'] === 0 ? 'srv_no_visits' : 'srv_no_comment') ?></p><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
