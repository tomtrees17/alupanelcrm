<?php
declare(strict_types=1);

/**
 * 销售周评 / Review Mingguan — HR-07 (docs/任务卡-拜访周评.md), a tab of the
 * 客户 group (user, 2026-10-28). The boss and visits_all see everyone, a sales
 * department head their department; sales cannot open it. Actions: index,
 * export, generate (boss only, POST: a new version, the old one stays).
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 * @var array  $config
 */

$today = date('Y-m-d');
$operator = own_name();

if (!in_array($action, ['index', 'export', 'generate'], true)) {
    http_response_code(404);
    exit('Not found');
}

$rv = SalesReview::viewer($pdo);
if ($rv['scope'] === 'none') {
    http_response_code(403);
    flash(t('hr_msg_not_yours'), 'error');
    redirect('dashboard.index');
}
$week = (string) input('week', '');
$week = SalesReview::valid_week($week) ? $week : SalesReview::last_week($today);
if ($action === 'generate') {
    Csrf::verify();
    if (!$auth->isAdmin()) {
        http_response_code(403);
        flash(t('hr_msg_read_only'), 'error');
        redirect('reviews.index', ['week' => $week]);
    }
    if ($week > SalesReview::last_week($today)) {   // only finished weeks
        flash(t('srv_msg_unfinished'), 'error');
        redirect('reviews.index', ['week' => $week]);
    }
    $r = SalesReview::generate($pdo, $config, $week, $operator);
    flash(t($r['status'] === 'ok' ? 'srv_msg_done' : 'srv_msg_done_no_ai'));
    redirect('reviews.index', ['week' => $week]);
}
$review = SalesReview::latest($pdo, $week);
$items = $review ? SalesReview::items($pdo, (int) $review['id'], $rv) : [];
if ($action === 'export') {
    if (!$review) {
        flash(t('srv_none'), 'error');
        redirect('reviews.index', ['week' => $week]);
    }
    audit($pdo, 'hr', 'export', 'sales_reviews', (int) $review['id'], $week, 'sales review export, ' . count($items) . ' rows');
    send_workbook('Review-Mingguan-' . $week, [['Review', SalesReview::export_headers(), array_map([SalesReview::class, 'export_row'], $items)]]);
}
$weeks = SalesReview::weeks($pdo);
$last = SalesReview::last_week($today);
if (!in_array($last, $weeks, true)) {
    array_unshift($weeks, $last);
}
if (!in_array($week, $weeks, true)) {
    $weeks[] = $week;
    rsort($weeks);
}
view('reviews.index', ['pageTitle' => t('srv_title'), 'pageSub' => '', 'week' => $week, 'weeks' => $weeks, 'review' => $review, 'items' => $items,
                   'boss' => $auth->isAdmin(), 'canGenerate' => $auth->isAdmin() && $week <= $last, 'aiOn' => AiTask::enabled($config)]);
