<?php
declare(strict_types=1);

/** HTML-escape a value. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Build an in-app URL: url('customers.edit', ['id' => 5]). */
function url(string $route = 'dashboard.index', array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['r' => $route], $params));
}

/** Redirect to an in-app route and stop. */
function redirect(string $route, array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Format an amount as Indonesian Rupiah: Rp 1.234.567 */
function idr($amount): string
{
    $cur = $GLOBALS['config']['currency'] ?? 'Rp';
    return $cur . ' ' . number_format((float) $amount, 0, ',', '.');
}

/** Plain Indonesian number format (no currency): 211.711,71 */
function num($amount, int $dec = 0): string
{
    return number_format((float) $amount, $dec, ',', '.');
}

/** Compact Rupiah for tight UI: Rp 28,0 jt / Rp 1,2 M */
function idr_short($amount): string
{
    $v = (float) $amount;
    if ($v >= 1e9) return 'Rp ' . rtrim(rtrim(number_format($v / 1e9, 1, ',', '.'), '0'), ',') . ' M';
    if ($v >= 1e6) return 'Rp ' . rtrim(rtrim(number_format($v / 1e6, 1, ',', '.'), '0'), ',') . ' jt';
    if ($v >= 1e3) return 'Rp ' . number_format($v / 1e3, 0, ',', '.') . ' rb';
    return idr($v);
}

/** Set / pull one-time flash messages. */
function flash(?string $message = null, string $type = 'success')
{
    if ($message !== null) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        return null;
    }
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Render a view inside the main layout. */
function view(string $template, array $data = [], bool $useLayout = true): void
{
    $path = __DIR__ . '/../views/' . str_replace('.', '/', $template) . '.php';
    if (!is_file($path)) {
        http_response_code(500);
        echo "View not found: {$template}";
        return;
    }

    $auth   = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($data, EXTR_SKIP);

    ob_start();
    include $path;
    $content = ob_get_clean();

    if (!$useLayout) {
        echo $content;
        return;
    }
    include __DIR__ . '/../views/layout.php';
}

// ──────────────────────────────────────────────────────────
//  Domain label / colour maps
// ──────────────────────────────────────────────────────────

/** Pipeline stages in order. */
function deal_stages(): array
{
    return ['初步接触', '需求确认', '方案报价', '谈判中', '已成交'];
}

function deal_stage_color(string $stage): string
{
    return [
        '初步接触' => '#555f72', '需求确认' => '#0099ff', '方案报价' => '#ff6b35',
        '谈判中' => '#a855f7', '已成交' => '#00d4a8',
    ][$stage] ?? '#94a3b8';
}

function customer_tag_class(string $tag): string
{
    return ['重点' => 'tag-orange', '潜在' => 'tag-blue', '成交' => 'tag-green', '流失' => 'tag-gray'][$tag] ?? 'tag-gray';
}

function priority_class(string $p): string
{
    return ['高' => 'pri-high', '中' => 'pri-med', '低' => 'pri-low'][$p] ?? 'pri-med';
}

/** Translate a canonical (Chinese) stored value for display. */
function tr_stage(string $stage): string
{
    $map = ['初步接触' => 'stage_1', '需求确认' => 'stage_2', '方案报价' => 'stage_3', '谈判中' => 'stage_4', '已成交' => 'stage_5'];
    return isset($map[$stage]) ? t($map[$stage]) : $stage;
}

function tr_tag(string $tag): string
{
    $map = ['重点' => 'tag_vip', '潜在' => 'tag_potential', '成交' => 'tag_closed', '流失' => 'tag_lost'];
    return isset($map[$tag]) ? t($map[$tag]) : $tag;
}

function tr_priority(string $p): string
{
    $map = ['高' => 'pri_high', '中' => 'pri_med', '低' => 'pri_low'];
    return isset($map[$p]) ? t($map[$p]) : $p;
}

function tr_txn_type(string $type): string
{
    return ['in' => t('txn_in'), 'out' => t('txn_out'), 'out_auto' => t('txn_out_auto')][$type] ?? $type;
}

/** Order approval workflow. */
function order_statuses(): array
{
    return ['draft', 'pending_sup', 'pending_mgr', 'pending_wh', 'approved', 'rejected'];
}

function order_status_label(string $s): string
{
    return in_array($s, order_statuses(), true) ? t('st_' . $s) : $s;
}

function order_status_class(string $s): string
{
    return [
        'draft' => 'status-draft', 'pending_sup' => 'status-pending-sup', 'pending_mgr' => 'status-pending-mgr',
        'pending_wh' => 'status-pending-wh', 'approved' => 'status-approved', 'rejected' => 'status-rejected',
    ][$s] ?? 'status-draft';
}

/** Which role acts on an order in its current status (for approval routing). */
function order_action_role(string $status): ?string
{
    return [
        'pending_sup' => 'supervisor',
        'pending_mgr' => 'manager',
        'pending_wh'  => 'warehouse',
    ][$status] ?? null;
}

function invoice_status_label(string $s): string
{
    return ['paid' => t('inv_paid'), 'partial' => t('inv_partial'), 'pending' => t('inv_pending'), 'overdue' => t('inv_overdue')][$s] ?? $s;
}

function invoice_status_class(string $s): string
{
    return ['paid' => 'tag-green', 'partial' => 'tag-blue', 'pending' => 'tag-orange', 'overdue' => 'tag-red'][$s] ?? 'tag-gray';
}

function all_roles(): array
{
    return ['admin', 'manager', 'finance_manager', 'ops_supervisor', 'supervisor', 'sales', 'warehouse', 'hr', 'clerk'];
}

function role_label(string $r): string
{
    return in_array($r, all_roles(), true) ? t('role_' . $r) : $r;
}

/** Modules whose access is route-guarded + configurable per role. */
function controllable_modules(): array
{
    return ['customers', 'pipeline', 'tasks', 'finance', 'orders', 'inventory'];
}

/** Non-route view permissions (dashboard widgets, export, etc.), configurable per role. */
function view_permissions(): array
{
    return ['performance', 'export'];   // 全员销售业绩 / 导出 Excel
}

/** All permission keys shown in the 权限设置 matrix. */
function permission_keys(): array
{
    return array_merge(controllable_modules(), view_permissions());
}

/** Default role → allowed permissions (used to seed role_permissions). */
function default_permissions(): array
{
    return [
        'manager'         => ['customers', 'pipeline', 'tasks', 'finance', 'orders', 'inventory', 'performance', 'export'],
        'finance_manager' => ['customers', 'finance', 'orders', 'inventory', 'performance'],
        'ops_supervisor'  => ['customers', 'pipeline', 'tasks', 'orders', 'inventory', 'performance'],
        'supervisor'      => ['customers', 'pipeline', 'tasks', 'orders', 'inventory'],
        'sales'           => ['customers', 'pipeline', 'tasks', 'orders', 'inventory'],
        'warehouse'       => ['orders', 'inventory'],
        'hr'              => ['customers', 'tasks'],
        'clerk'           => ['customers', 'tasks', 'orders'],
        // admin is omitted on purpose → always full access.
    ];
}

/** Only admin and warehouse(库存管理员) may modify inventory; others are read-only. */
function can_edit_inventory(): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null) {
        return false;
    }
    if ($auth->isAdmin()) {
        return true;
    }
    return ($auth->user()['role'] ?? '') === 'warehouse';
}

/** May the current user export spreadsheets? (configurable 'export' permission). */
function can_export(): bool
{
    return can_access('export');
}

/** Restricted users (sales) only see/modify their own orders & customers. */
function sees_only_own(): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null || $auth->isAdmin()) {
        return false;
    }
    return ($auth->user()['role'] ?? '') === 'sales';
}

/** Current user's display name (used as order submitter / customer owner). */
function own_name(): string
{
    $auth = $GLOBALS['auth'] ?? null;
    return $auth ? (string) ($auth->user()['name'] ?? '') : '';
}

/** Can the current user access a module? Admin = all; dashboard always allowed. */
function can_access(string $module): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null || !$auth->check()) {
        return false;
    }
    if ($auth->isAdmin()) {
        return true;
    }
    if (in_array($module, ['dashboard', 'auth', 'lang'], true)) {
        return true;
    }
    if ($module === 'users') {
        return false;   // admin-only
    }
    $role = $auth->user()['role'] ?? '';
    $perms = $GLOBALS['permissions'][$role] ?? [];
    return in_array($module, $perms, true);
}

function payment_terms(): array
{
    return ['CBD' => 'CBD（货前付款）', 'COD' => 'COD（货到付款）', 'custom' => '账期 Net'];
}

function client_types(): array
{
    return ['Contractor', 'Distributor', 'Retailer', 'End User'];
}

function delivery_services(): array
{
    return ['Self Pickup', 'Lala Move', 'Truck', 'JNE Trucking', 'Gojek', 'Grab'];
}
