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
$pdo->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Admin','admin@alupanel.local','x','admin')");
$pdo->exec("INSERT INTO role_permissions (role, module) VALUES ('manager','orders'),('sales','orders')");
// Production has already run every earlier one-time migration; without these
// markers the old migrations would re-fire and this test would measure them
// instead of the audit_log upgrade.
$pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES
    ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),
    ('perm_approvals','1'),('pwd_policy_v1','1')");

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
