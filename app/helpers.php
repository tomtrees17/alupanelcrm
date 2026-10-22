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

const PER_PAGE = 50;

/**
 * Page window for a list query. $countSql must return a single COUNT(*);
 * the caller appends $pg['limit'] to its own SELECT. An out-of-range ?page=
 * clamps to the last page rather than showing an empty table.
 *
 * @return array{page:int,pages:int,total:int,offset:int,limit:string}
 */
function paginate(PDO $pdo, string $countSql, array $args, int $perPage = PER_PAGE): array
{
    $st = $pdo->prepare($countSql);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) input('page', 1)), $pages);
    $offset = ($page - 1) * $perPage;
    // Both numbers are ints computed here, never raw input.
    return ['page' => $page, 'pages' => $pages, 'total' => $total, 'offset' => $offset,
            'limit' => ' LIMIT ' . $perPage . ' OFFSET ' . $offset];
}

/** Prev / "page x of y · n rows" / next bar; keeps the current filters in the links. */
function pager(string $route, array $params, array $pg): string
{
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    $info = sprintf(t('pager_info'), $pg['page'], $pg['pages'], $pg['total']);
    if ($pg['pages'] <= 1) {
        return $pg['total'] > 0 ? '<div class="pager"><span class="muted">' . e(sprintf(t('pager_total'), $pg['total'])) . '</span></div>' : '';
    }
    $html = '<nav class="pager">';
    $html .= $pg['page'] > 1
        ? '<a class="btn btn-ghost" href="' . e(url($route, $params + ['page' => $pg['page'] - 1])) . '">' . e(t('audit_prev')) . '</a>'
        : '<span class="btn btn-ghost pager-off">' . e(t('audit_prev')) . '</span>';
    $html .= '<span class="muted">' . e($info) . '</span>';
    $html .= $pg['page'] < $pg['pages']
        ? '<a class="btn btn-ghost" href="' . e(url($route, $params + ['page' => $pg['page'] + 1])) . '">' . e(t('audit_next')) . '</a>'
        : '<span class="btn btn-ghost pager-off">' . e(t('audit_next')) . '</span>';
    return $html . '</nav>';
}

function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Format an amount as Indonesian Rupiah: Rp 1.234.567
 *
 * $dec matters for invoice unit prices: those are back-calculated from a
 * tax-inclusive price and carry two decimals (Rp 214.414,41). Printing them
 * whole makes qty x price disagree with the line total on screen.
 */
function idr($amount, int $dec = 0): string
{
    $cur = $GLOBALS['config']['currency'] ?? 'Rp';
    return $cur . ' ' . number_format((float) $amount, $dec, ',', '.');
}

/** Strip CJK characters (Indonesian-facing printed documents must not contain Chinese). */
function no_cjk($s): string
{
    $out = (string) preg_replace(
        '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]+/u',
        '',
        (string) $s
    );
    return trim((string) preg_replace('/\s{2,}/', ' ', $out), " \t\n\r·/,-");
}

/** Plain Indonesian number format (no currency): 211.711,71 */
function num($amount, int $dec = 0): string
{
    return number_format((float) $amount, $dec, ',', '.');
}

/** "Rp1.234" / "-Rp1" — the rounding line can be negative. */
function rp_signed(float $v): string
{
    return ($v < 0 ? '-' : '') . 'Rp' . num(abs($v));
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

function industry_label(string $code): string
{
    return $code === '' ? '—' : t('ind_' . $code);
}

function region_label(string $code): string
{
    return $code === '' ? '—' : t('reg_' . $code);
}

function industry_source_label(string $src): string
{
    return in_array($src, ['rule', 'ai', 'manual'], true) ? t('indsrc_' . $src) : '—';
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
    return ['customers', 'insights', 'pipeline', 'tasks', 'finance', 'orders', 'inventory', 'approvals', 'hr', 'audit'];
}

/** Non-route view permissions (dashboard widgets, export, etc.), configurable per role. */
function view_permissions(): array
{
    return ['performance', 'export', 'insights_all', 'hr_all', 'ai_usage'];   // 全员销售业绩 / 导出 Excel / 全部客户分析 / 人力档案看全部（不含薪资）/ AI 使用情况
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
        'manager'         => ['hr', 'hr_all', 'customers', 'insights', 'insights_all', 'pipeline', 'tasks', 'finance', 'orders', 'inventory', 'approvals', 'performance', 'export'],
        'finance_manager' => ['hr', 'customers', 'insights', 'finance', 'orders', 'inventory', 'approvals', 'performance', 'ai_usage'],
        'ops_supervisor'  => ['hr', 'customers', 'insights', 'insights_all', 'pipeline', 'tasks', 'orders', 'inventory', 'approvals', 'performance'],
        'supervisor'      => ['hr', 'customers', 'insights', 'insights_all', 'pipeline', 'tasks', 'orders', 'inventory', 'approvals'],
        'sales'           => ['hr', 'customers', 'insights', 'pipeline', 'tasks', 'orders', 'inventory', 'approvals'],
        'warehouse'       => ['hr', 'orders', 'inventory', 'approvals'],
        'hr'              => ['hr', 'customers', 'insights', 'tasks', 'approvals'],
        'clerk'           => ['hr', 'customers', 'insights', 'tasks', 'orders', 'approvals'],
        // admin is omitted on purpose → always full access.
    ];
}

/**
 * Who sees every salesperson's call lists and the manager view (老板/主管):
 * admin, or a role granted 全部客户分析 in 权限设置. Everyone else with the
 * 回访清单 module sees only customers they own.
 */
function insights_sees_all(): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    return $auth !== null && ($auth->isAdmin() || can_access('insights_all'));
}

/** May the current user act on this customer from the call lists? (record-level check) */
function insights_can_touch(array $customer): bool
{
    if (insights_sees_all()) {
        return true;
    }
    $own = mb_strtolower(trim(own_name()));
    return $own !== '' && mb_strtolower(trim((string) ($customer['owner'] ?? ''))) === $own;
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

/** Editable Word export of invoice / delivery order: finance staff + warehouse admin. */
function can_word_export(): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null || !$auth->check()) {
        return false;
    }
    return $auth->isAdmin() || can_edit_inventory() || can_access('finance');
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

/** An order can be edited only while draft/rejected, by its submitter or admin. */
function order_editable(array $order): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null) {
        return false;
    }
    if (!in_array($order['status'] ?? '', ['draft', 'rejected'], true)) {
        return false;
    }
    return $auth->isAdmin() || ($order['submitter'] ?? '') === ($auth->user()['name'] ?? '');
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

// ──────────────────────────────────────────────────────────
//  Administrative requests (出差 / 报销 / 请假)
// ──────────────────────────────────────────────────────────

function request_types(): array
{
    return ['trip', 'expense', 'leave', 'payment'];
}

/** Types whose approval continues to finance for payment confirmation. */
function request_needs_finance(string $type): bool
{
    return in_array($type, ['expense', 'payment'], true);
}

/** Types that start with an HR compliance review (trip / expense / leave). */
function request_needs_hr(string $type): bool
{
    return in_array($type, ['trip', 'expense', 'leave'], true);
}

/** First pending stage after submission, by type. */
function request_first_stage(string $type): string
{
    return request_needs_hr($type) ? 'pending_hr' : 'pending_mgr';
}

function request_type_label(string $t): string
{
    return in_array($t, request_types(), true) ? t('rt_' . $t) : $t;
}

function request_statuses(): array
{
    return ['draft', 'pending_hr', 'pending_mgr', 'pending_fin', 'approved'];
}

function request_status_label(string $s): string
{
    return in_array($s, request_statuses(), true) ? t('st_' . $s) : $s;
}

function request_status_class(string $s): string
{
    return [
        'draft' => 'status-draft', 'pending_hr' => 'status-pending-sup', 'pending_mgr' => 'status-pending-mgr',
        'pending_fin' => 'status-pending-wh', 'approved' => 'status-approved',
    ][$s] ?? 'status-draft';
}

/** Which role acts on a request in its current status. */
function request_action_role(string $status): ?string
{
    return ['pending_hr' => 'hr', 'pending_mgr' => 'manager', 'pending_fin' => 'finance_manager'][$status] ?? null;
}

/** A request is editable while draft, by its applicant or admin. */
function request_editable(array $req): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null || ($req['status'] ?? '') !== 'draft') {
        return false;
    }
    return $auth->isAdmin() || ($req['applicant'] ?? '') === ($auth->user()['name'] ?? '');
}

/** Who sees every administrative request (others see only their own). */
function approvals_sees_all(): bool
{
    $auth = $GLOBALS['auth'] ?? null;
    if ($auth === null) {
        return false;
    }
    if ($auth->isAdmin()) {
        return true;
    }
    return in_array($auth->user()['role'] ?? '', ['manager', 'finance_manager', 'hr'], true);
}

/** Expense categories (canonical zh values, translated for display). */
function expense_categories(): array
{
    return ['交通', '住宿', '餐饮', '办公', '其他'];
}

/** Leave types (canonical zh values, translated for display). */
function leave_types(): array
{
    return ['事假', '病假', '年假'];
}

/** Payment-request purposes (canonical zh values, translated for display). */
function payment_categories(): array
{
    return ['货款', '运费', '租金', '水电', '其他'];
}

/** Translate a canonical expense/payment category / leave type for display. */
function tr_req_cat(string $c): string
{
    $map = [
        '交通' => 'cat_transport', '住宿' => 'cat_lodging', '餐饮' => 'cat_meals',
        '办公' => 'cat_office', '其他' => 'cat_other',
        '货款' => 'cat_goods', '运费' => 'cat_freight', '租金' => 'cat_rent', '水电' => 'cat_utility',
        '事假' => 'lv_personal', '病假' => 'lv_sick', '年假' => 'lv_annual',
    ];
    return isset($map[$c]) ? t($map[$c]) : $c;
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

// ──────────────────────────────────────────────────────────
//  Audit trail (审计日志) display helpers
// ──────────────────────────────────────────────────────────

/** Modules that can appear in the audit log (filter dropdown order). */
function audit_modules(): array
{
    return ['orders', 'finance', 'bank', 'inventory', 'customers', 'approvals', 'pipeline', 'tasks', 'hr', 'users', 'roles', 'account', 'auth'];
}

/** Actions that can appear in the audit log (filter dropdown order). */
function audit_actions(): array
{
    return [
        'create', 'update', 'delete', 'submit', 'approve', 'reject', 'fulfill',
        'pay', 'reverse', 'void', 'unvoid', 'adjust', 'move', 'permission', 'password', 'login', 'logout', 'login_failed',
        'merge', 'assign', 'enrich', 'cleanup', 'resign', 'unresign', 'upload', 'handle', 'activate', 'renew', 'convert', 'terminate', 'export',
        'import', 'confirm', 'classify', 'link',
    ];
}

function tr_audit_module(string $m): string
{
    return in_array($m, audit_modules(), true) ? t('audit_mod_' . $m) : $m;
}

function tr_audit_action(string $a): string
{
    return in_array($a, audit_actions(), true) ? t('audit_act_' . $a) : $a;
}

/** Colour class for an audit action tag (destructive = red, approval = green). */
function audit_action_class(string $a): string
{
    return [
        'create'  => 'tag-green',  'update'      => 'tag-blue',  'delete' => 'tag-red',
        'approve' => 'tag-green',  'reject'      => 'tag-red',   'fulfill' => 'tag-green',
        'pay'     => 'tag-green',  'adjust'      => 'tag-orange', 'submit' => 'tag-blue',
        'reverse' => 'tag-red', 'void' => 'tag-red', 'unvoid' => 'tag-orange',
        'login'   => 'tag-gray',   'logout'      => 'tag-gray',  'login_failed' => 'tag-red',
        'permission' => 'tag-orange', 'password' => 'tag-orange', 'move' => 'tag-blue',
        'merge' => 'tag-orange', 'assign' => 'tag-blue', 'enrich' => 'tag-blue', 'cleanup' => 'tag-orange',
        'resign' => 'tag-red', 'unresign' => 'tag-orange', 'upload' => 'tag-blue', 'handle' => 'tag-green',
        'activate' => 'tag-green', 'renew' => 'tag-blue', 'convert' => 'tag-blue', 'terminate' => 'tag-red', 'export' => 'tag-purple',
        'import' => 'tag-blue', 'confirm' => 'tag-green', 'classify' => 'tag-orange', 'link' => 'tag-blue',
    ][$a] ?? 'tag-gray';
}
