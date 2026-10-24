<?php

// ── Test harness for the online upgrade path (Database::ensureSchema) ──
// Simulates the production DB as it exists today (no audit_log) and checks
// that a plain `git pull` + one page view brings it up to date, idempotently.

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    $schema = (string) ($GLOBALS['__schema'] ?? '');
}
if ($schema === '') {
    throw new RuntimeException('schema.sql not found — cannot run the migration test.');
}
$pdo->exec($schema);

// Roll the schema back to "production today": audit_log did not exist yet.
$pdo->exec('DROP INDEX IF EXISTS idx_audit_created');
$pdo->exec('DROP INDEX IF EXISTS idx_audit_user');
$pdo->exec('DROP INDEX IF EXISTS idx_audit_entity');
$pdo->exec('DROP TABLE IF EXISTS audit_log');
// payments before the reversal feature: no created_by / reversal_of, no index.
$pdo->exec('DROP INDEX IF EXISTS idx_payments_invoice');
$pdo->exec('ALTER TABLE payments DROP COLUMN created_by');
$pdo->exec('ALTER TABLE payments DROP COLUMN reversal_of');
$pdo->exec("INSERT INTO payments (invoice_id,customer,amount,pay_date,receipt_no) VALUES (9001,'PT Lama',250000,'2026-07-01','RC-OLD')");
// before WhatsApp notifications: no users.phone/lang, no orders.created_by, no queue.
$pdo->exec('DROP INDEX IF EXISTS idx_notif_pending');
$pdo->exec('DROP INDEX IF EXISTS idx_notif_user');
$pdo->exec('DROP TABLE IF EXISTS notifications');
$pdo->exec('DROP INDEX IF EXISTS idx_ai_user_day');
$pdo->exec('DROP TABLE IF EXISTS ai_queries');
$pdo->exec('ALTER TABLE invoices DROP COLUMN voided_at');
$pdo->exec('ALTER TABLE invoices DROP COLUMN voided_by');
$pdo->exec('ALTER TABLE invoices DROP COLUMN void_reason');
$pdo->exec('ALTER TABLE invoices DROP COLUMN rounding');
$pdo->exec('ALTER TABLE invoices DROP COLUMN calc');
$pdo->exec('ALTER TABLE invoices DROP COLUMN dpp');
$pdo->exec("INSERT INTO invoices (invoice_no,customer,total,payment_status) VALUES ('INV-LAMA','PT Lama',5000,'pending')");
$pdo->exec('ALTER TABLE users DROP COLUMN phone');
$pdo->exec('ALTER TABLE users DROP COLUMN lang');
$pdo->exec('ALTER TABLE orders DROP COLUMN created_by');
$pdo->exec('ALTER TABLE orders DROP COLUMN price_basis');
$pdo->exec('ALTER TABLE order_items DROP COLUMN price_excl');
$pdo->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Admin','admin@alupanel.local','x','admin')");
// before the customer analysis: no industry/region columns, no geo columns, no geocode cache.
$pdo->exec('DROP INDEX IF EXISTS idx_orders_customer');
$pdo->exec('DROP INDEX IF EXISTS idx_followups_customer');
$pdo->exec('DROP TABLE IF EXISTS customer_followups');
$pdo->exec('DROP TABLE IF EXISTS customer_insights');
$pdo->exec('DROP TABLE IF EXISTS insights_history');
$pdo->exec("INSERT INTO role_permissions (role, module) VALUES ('clerk','customers')");   // like production: sales has no 客户管理
$pdo->exec('DROP TABLE IF EXISTS geocode_cache');
foreach (['industry', 'industry_source', 'industry_confidence', 'industry_pending', 'industry_note', 'region', 'delivery_regions', 'enriched_at'] as $col) {
    $pdo->exec("ALTER TABLE customers DROP COLUMN {$col}");
}
foreach (['delivery_lat', 'delivery_lng', 'delivery_region', 'geo_source'] as $col) {
    $pdo->exec("ALTER TABLE orders DROP COLUMN {$col}");
}
// before 人力 (HR-00/01): no employee tables, no users.employee_id / users.active.
foreach (['idx_hr_todos_open', 'idx_emp_changes', 'idx_emp_attach', 'idx_contracts_emp', 'idx_contracts_end', 'idx_bpjs_deps', 'idx_visits_emp', 'idx_visits_day', 'idx_visits_prospect', 'idx_prospects_assigned', 'idx_visits_match', 'idx_visits_sender', 'idx_digests_week'] as $ix) {
    $pdo->exec("DROP INDEX IF EXISTS {$ix}");
}
foreach (['visit_digests', 'wa_imports', 'customer_name_aliases', 'employee_wa_ids', 'visits', 'prospects', 'bpjs_monthly', 'bpjs_rates', 'bpjs_ketenagakerjaan', 'bpjs_kesehatan_dependents', 'bpjs_kesehatan', 'employment_contracts', 'hr_todos', 'employee_changes', 'employee_attachments', 'employees'] as $tbl) {
    $pdo->exec("DROP TABLE IF EXISTS {$tbl}");
}
$pdo->exec('ALTER TABLE users DROP COLUMN employee_id');
$pdo->exec('ALTER TABLE users DROP COLUMN active');
$pdo->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Rina','rina@alupanel.local','x','sales'),('tania','tania@alupanel.local','x','manager')");
$pdo->exec("INSERT INTO customers (id, name, company, owner, city) VALUES (777, 'Pak Lama', 'Toko Kaca Lama', 'ridwan', 'Kec Koja')");
$pdo->exec("INSERT INTO role_permissions (role, module) VALUES ('manager','orders'),('sales','orders')");
// Production has already run every earlier one-time migration; without these
// markers the old migrations would re-fire and this test would measure them
// instead of the audit_log upgrade.
$pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES
    ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),
    ('perm_approvals','1'),('pwd_policy_v1','1'),('roles_v3','1')");

$has = fn(string $t): bool => (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$t}'")->fetchColumn();
ok('starts without audit_log (old DB simulated)', !$has('audit_log'));

// First page view after the deploy.
Database::migrate($pdo);
ok('ensureSchema creates audit_log', $has('audit_log'));

$cols = array_column($pdo->query('PRAGMA table_info(audit_log)')->fetchAll(), 'name');
ok('upgraded table has all columns', $cols === [
    'id', 'created_at', 'user_id', 'user_name', 'user_role', 'module',
    'action', 'entity', 'entity_id', 'label', 'detail', 'ip',
], implode(',', $cols));

$idx = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='audit_log'")->fetchAll(), 'name');
ok('upgraded table has all indexes', count(array_intersect(['idx_audit_created', 'idx_audit_user', 'idx_audit_entity'], $idx)) === 3, implode(',', $idx));

// The upgraded table must actually accept writes (created_at default present).
$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
audit($pdo, 'orders', 'approve', 'order', 1, 'SO-1', 'after migration');
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
ok('upgraded table accepts writes', ($row['label'] ?? '') === 'SO-1' && ($row['created_at'] ?? '') !== '');

// payments upgrade: columns added, historical rows preserved and left NULL.
$pcols = array_column($pdo->query('PRAGMA table_info(payments)')->fetchAll(), 'name');
ok('payments gains created_by + reversal_of', in_array('created_by', $pcols, true) && in_array('reversal_of', $pcols, true), implode(',', $pcols));
$old = $pdo->query("SELECT * FROM payments WHERE receipt_no = 'RC-OLD'")->fetch();
ok('historical payment survives untouched', $old && (float) $old['amount'] === 250000.0 && $old['reversal_of'] === null);
ok('historical payment is reversible', payment_reversal_block($pdo, $old) === null);
$pidx = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='payments'")->fetchAll(), 'name');
ok('payments index created', in_array('idx_payments_invoice', $pidx, true), implode(',', $pidx));

// notification upgrade: queue table + user columns, existing users default to id.
ok('notifications table created', $has('notifications'));
$ucols2 = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
ok('users gains phone + lang', in_array('phone', $ucols2, true) && in_array('lang', $ucols2, true), implode(',', $ucols2));
ok('orders gains created_by', in_array('created_by', array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name'), true));
ok('orders gains price_basis, order_items gains price_excl', in_array('price_basis', array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name'), true)
    && in_array('price_excl', array_column($pdo->query('PRAGMA table_info(order_items)')->fetchAll(), 'name'), true));
$existing = $pdo->query("SELECT * FROM users WHERE email='admin@alupanel.local'")->fetch();
ok('existing user survives with lang default', $existing && $existing['lang'] === 'id' && $existing['phone'] === null);
ok('existing user has no phone yet (notifications skip, not crash)', Notify::normalise_phone((string) $existing['phone']) === '');

ok('ai_queries table created', $has('ai_queries'));
$ivc = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
ok('invoices gains the void columns', count(array_intersect(['voided_at','voided_by','void_reason'], $ivc)) === 3, implode(',', $ivc));
$oldInv = $pdo->query("SELECT * FROM invoices WHERE invoice_no='INV-LAMA'")->fetch();
ok('existing invoice survives and is not void', $oldInv && !invoice_is_void($oldInv));
ok('existing invoice can be voided after upgrade', invoice_void_block($pdo, $oldInv) === null);
$ivc2 = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
ok('invoices gains rounding', in_array('rounding', $ivc2, true));
$oldInv2 = $pdo->query("SELECT * FROM invoices WHERE invoice_no='INV-LAMA'")->fetch();
ok('upgrade does not rewrite existing totals (that is the CLI tool\'s job)', (float) $oldInv2['total'] === 5000.0 && (float) $oldInv2['rounding'] === 0.0);
ok('existing invoices are marked legacy, no DPP stored (never recomputed)', $oldInv2['calc'] === 'legacy' && $oldInv2['dpp'] === null);

// customer-analysis upgrade: columns added, nothing inferred on a page load.
$cc = array_column($pdo->query('PRAGMA table_info(customers)')->fetchAll(), 'name');
ok('customers gains industry/region columns', count(array_intersect(['industry', 'industry_source', 'industry_confidence', 'industry_pending', 'industry_note', 'region', 'delivery_regions', 'enriched_at'], $cc)) === 8, implode(',', $cc));
$oc = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
ok('orders gains delivery_lat/lng/region + geo_source', count(array_intersect(['delivery_lat', 'delivery_lng', 'delivery_region', 'geo_source'], $oc)) === 4);
ok('geocode_cache created', $has('geocode_cache'));
ok('call-list tables created (followups + insights + history)', $has('customer_followups') && $has('customer_insights') && $has('insights_history'));
$insRoles = $pdo->query("SELECT role FROM role_permissions WHERE module = 'insights' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
$custRoles = $pdo->query("SELECT role FROM role_permissions WHERE module = 'customers' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
ok('回访清单 granted to the roles that see customers, plus sales (who do not)', array_values(array_diff($insRoles, ['sales'])) === array_values(array_diff($custRoles, ['sales'])) && !in_array('sales', $custRoles, true), implode(',', $insRoles));
ok('sales get 回访清单; supervisory roles get 全部客户分析', in_array('sales', $insRoles, true)
    && $pdo->query("SELECT GROUP_CONCAT(role) FROM (SELECT role FROM role_permissions WHERE module = 'insights_all' ORDER BY role)")->fetchColumn() === 'manager,ops_supervisor,supervisor');
$pdo->exec("DELETE FROM role_permissions WHERE module = 'insights' AND role = 'clerk'");
$pdo->exec("DELETE FROM role_permissions WHERE module = 'insights_all' AND role = 'supervisor'");
Database::migrate($pdo);
ok('an admin revoking 回访清单 later is not undone by the next page load', !in_array('clerk', $pdo->query("SELECT role FROM role_permissions WHERE module = 'insights'")->fetchAll(PDO::FETCH_COLUMN), true)
    && !in_array('supervisor', $pdo->query("SELECT role FROM role_permissions WHERE module = 'insights_all'")->fetchAll(PDO::FETCH_COLUMN), true));
$lama = $pdo->query('SELECT * FROM customers WHERE id = 777')->fetch();
ok('existing customer untouched, industry/region left for the enrichment job', $lama['name'] === 'Pak Lama' && $lama['owner'] === 'ridwan'
    && $lama['industry'] === null && $lama['region'] === null && (int) $lama['industry_pending'] === 0);

// 人力: tables + columns added; every CRM account except admin gets a record, logins unaffected.
ok('HR tables created (incl. HR-02 contracts, HR-03 BPJS)', $has('employees') && $has('employee_attachments') && $has('employee_changes') && $has('hr_todos') && $has('employment_contracts')
    && $has('bpjs_kesehatan') && $has('bpjs_kesehatan_dependents') && $has('bpjs_ketenagakerjaan') && $has('bpjs_rates') && $has('bpjs_monthly'));
ok('HR-04 tables created (visits, prospects, WhatsApp import)', $has('visits') && $has('prospects') && $has('employee_wa_ids') && $has('customer_name_aliases') && $has('wa_imports') && $has('visit_digests'));
ok('HR-04: sales staff in visit mode, visits permissions granted', $pdo->query("SELECT attendance_mode FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.name = 'Rina'")->fetchColumn() === 'visit'
    && $pdo->query("SELECT GROUP_CONCAT(module) FROM (SELECT module FROM role_permissions WHERE role = 'manager' AND module LIKE 'visits%' ORDER BY module)")->fetchColumn() === 'visits,visits_all,visits_import');
$ucolsHr = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
ok('users gains employee_id + active', in_array('employee_id', $ucolsHr, true) && in_array('active', $ucolsHr, true));
$links = $pdo->query('SELECT u.name, u.active, e.department FROM users u LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.id')->fetchAll();
ok('existing accounts get employee records (not admin), all still active', array_column($links, 'department', 'name') === ['Admin' => null, 'Rina' => 'sales', 'tania' => 'management']
    && array_unique(array_map('intval', array_column($links, 'active'))) === [1], json_encode($links));
ok('人力 module granted to staff roles; 全部人力档案 to manager only', in_array('sales', $pdo->query("SELECT role FROM role_permissions WHERE module = 'hr'")->fetchAll(PDO::FETCH_COLUMN), true)
    && $pdo->query("SELECT GROUP_CONCAT(role) FROM role_permissions WHERE module = 'hr_all'")->fetchColumn() === 'manager');

// 'audit' must NOT be granted to anyone by the migration — admin-only by default.
$granted = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE module='audit'")->fetchColumn();
ok('no role is granted audit by default', (int) $granted === 0, (string) $granted);

// Pre-existing permissions must survive the upgrade untouched.
$kept = (int) $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE module='orders'")->fetchColumn();
ok('existing permissions untouched', $kept === 2, (string) $kept);

// Idempotency: running it again (every request does) must change nothing and must not throw.
$before = $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$threw = false;
try {
    Database::migrate($pdo);
    Database::migrate($pdo);
} catch (Throwable $e) {
    $threw = true;
    echo '    ' . $e->getMessage() . "\n";
}
ok('re-running migrate() does not throw', !$threw);
ok('re-running migrate() preserves existing rows', (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === (int) $before);
ok('re-running migrate() does not duplicate indexes', count(array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='audit_log'")->fetchAll(), 'name')) === count($idx));

// can_access('audit'): admin yes, everyone else no until granted.
$GLOBALS['permissions'] = ['manager' => ['orders'], 'sales' => ['orders']];
$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
ok('admin can access audit', can_access('audit'));
$GLOBALS['auth'] = new AuthStub(['id' => 2, 'name' => 'Manager', 'role' => 'manager']);
ok('manager cannot access audit by default', !can_access('audit'));
$GLOBALS['permissions']['manager'][] = 'audit';
ok('manager can access audit once granted', can_access('audit'));
ok('audit is in the permission matrix', in_array('audit', permission_keys(), true));
