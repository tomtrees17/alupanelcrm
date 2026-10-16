<?php
declare(strict_types=1);

/**
 * 回访清单 — the call lists, read-only. Lists are rebuilt nightly by
 * tools/compute_insights.php; follow-ups are written through customers.followup
 * / customers.no_need, which apply the snooze immediately.
 *
 *   index — a salesperson's own customers (everyone with the module)
 *   admin — every salesperson, activity, distribution, map, data quality
 *           (insights_sees_all(): admin / manager / supervisor / ops_supervisor)
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
        $lists = Insights::lists($pdo, $config, $today, own_name());
        view('insights.index', [
            'pageTitle' => t('nav_insights'), 'pageSub' => t('sub_insights'),
            'lists' => $lists, 'tab' => $tab, 'computedOn' => $computedOn,
            'showOwner' => false, 'canSeeAll' => insights_sees_all(), 'route' => 'insights.index', 'routeParams' => [],
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
            'activity' => Insights::activity($pdo, $today),
            'byIndustry' => Insights::distribution($pdo, 'industry'),
            'byRegion' => Insights::distribution($pdo, 'region'),
            'quality' => Insights::quality($pdo),
            'map' => $layout, 'mapTotal' => count($points), 'mapOwners' => array_values(array_unique(array_filter(array_column($layout['points'], 'owner')))),
            'showOwner' => true, 'canSeeAll' => true, 'route' => 'insights.admin', 'routeParams' => array_filter(['owner' => $owner]),
        ]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
