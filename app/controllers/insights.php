<?php
declare(strict_types=1);

/**
 * 回访清单 — the call lists. The lists themselves are read-only (rebuilt
 * nightly by tools/compute_insights.php); follow-ups go through
 * Insights::record(), the same path the customer page uses, and the snooze
 * applies immediately.
 *
 *   index — a salesperson's own customers (everyone with the module)
 *   admin — every salesperson, activity, distribution, map, data quality
 *           (insights_sees_all(): admin, or 全部客户分析 in 权限设置)
 *   followup / no_need / set_industry — writes from the list rows; work with
 *           this module alone, each checked against the customer's owner
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 * @var array  $config
 */

$today = date('Y-m-d');
$computedOn = (string) $pdo->query("SELECT v FROM app_meta WHERE k = 'insights_computed_on'")->fetchColumn();
$tab = in_array(input('tab'), Insights::LISTS, true) ? (string) input('tab') : 'a';

switch ($action) {
    case 'index':
        // Sales: their own customers. Boss / 全部客户分析 (2026-10-28): everyone's, with a
        // salesperson filter ('-' = customers nobody owns).
        $seesAll = insights_sees_all();
        $owner = $seesAll ? trim((string) input('owner', '')) : '';
        $lists = Insights::lists($pdo, $config, $today, $seesAll ? null : own_name());
        $owners = [];
        if ($seesAll) {
            foreach ($lists[$tab] as $r) {
                $k = trim((string) $r['owner']) === '' ? '-' : trim((string) $r['owner']);
                $owners[$k] = ($owners[$k] ?? 0) + 1;
            }
            arsort($owners);
            if ($owner !== '') {
                foreach ($lists as $l => $rows) {
                    $lists[$l] = array_values(array_filter($rows, fn($r) => $owner === '-'
                        ? trim((string) $r['owner']) === ''
                        : mb_strtolower(trim((string) $r['owner'])) === mb_strtolower($owner)));
                }
            }
        }
        view('insights.index', [
            'pageTitle' => t('nav_insights'), 'pageSub' => t('sub_insights'),
            'lists' => $lists, 'tab' => $tab, 'computedOn' => $computedOn,
            'recent' => Insights::recent_followups($pdo, array_column($lists[$tab], 'customer_id')),
            'showOwner' => $seesAll, 'canSeeAll' => $seesAll, 'route' => 'insights.index', 'routeParams' => array_filter(['owner' => $owner]),
            'owners' => $owners, 'owner' => $owner,
        ]);
        break;

    case 'admin':
        if (!insights_sees_all()) {
            http_response_code(403);
            flash(t('msg_insights_admin_only'), 'error');
            redirect('insights.index');
        }
        $owner = trim((string) input('owner', ''));
        $lists = Insights::lists($pdo, $config, $today, $owner !== '' ? $owner : null);
        $points = Insights::map_points($pdo);
        $box = ['north' => -5.95, 'south' => -6.75, 'west' => 106.45, 'east' => 107.25];   // Jabodetabek
        $layout = Insights::map_layout($points, $box, 10);

        $owners = $pdo->query("SELECT DISTINCT TRIM(owner) FROM customers WHERE TRIM(COALESCE(owner, '')) <> '' ORDER BY TRIM(owner) COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
        view('insights.admin', [
            'pageTitle' => t('insights_admin'), 'pageSub' => t('sub_insights_admin'),
            'lists' => $lists, 'tab' => $tab, 'owner' => $owner, 'owners' => $owners, 'computedOn' => $computedOn,
            'recent' => Insights::recent_followups($pdo, array_column($lists[$tab], 'customer_id')),
            'activity' => Insights::activity($pdo, $today),
            'byIndustry' => Insights::distribution($pdo, 'industry'),
            'byRegion' => Insights::distribution($pdo, 'region'),
            'quality' => Insights::quality($pdo),
            'map' => $layout, 'mapTotal' => count($points), 'mapOwners' => array_values(array_unique(array_filter(array_column($layout['points'], 'owner')))),
            'showOwner' => true, 'canSeeAll' => true, 'route' => 'insights.admin', 'routeParams' => array_filter(['owner' => $owner]),
        ]);
        break;

    // ── Writes from the lists. They need only this module (sales have no
    // 客户管理), so each one re-checks that the customer is the user's own.
    case 'followup':
    case 'no_need':
    case 'set_industry':
        Csrf::verify();
        $back = input('back', '') === 'insights_admin' && insights_sees_all()
            ? ['insights.admin', array_filter(['tab' => $tab, 'owner' => trim((string) input('owner', ''))])]
            : ['insights.index', array_filter(['tab' => $tab, 'owner' => insights_sees_all() ? trim((string) input('owner', '')) : ''])];
        $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([(int) input('id', 0)]);
        $customer = $st->fetch();
        if (!$customer || !insights_can_touch($customer)) {
            http_response_code(403);
            flash(t('msg_cust_own_only'), 'error');
            redirect($back[0], $back[1]);
        }
        if ($action === 'set_industry') {
            $summary = CustomerData::set_industry($pdo, $customer, (string) input('industry', ''), own_name());
            if ($summary === null) {
                flash(t('msg_bad_industry'), 'error');
                redirect($back[0], $back[1]);
            }
            audit($pdo, 'customers', 'update', 'customer', (int) $customer['id'], (string) $customer['name'], $summary);
            flash(t('msg_industry_saved'));
            redirect($back[0], $back[1]);
        }
        $note = trim((string) input('note', ''));
        if ($note === '') {
            flash(t($action === 'no_need' ? 'msg_no_need_reason' : 'msg_followup_note'), 'error');
            redirect($back[0], $back[1]);
        }
        Insights::record($pdo, (int) $customer['id'], $action, mb_substr($note, 0, 1000), $auth->user(), $today);
        flash(t($action === 'no_need' ? 'msg_no_need_saved' : 'msg_followup_saved'));
        redirect($back[0], $back[1]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
