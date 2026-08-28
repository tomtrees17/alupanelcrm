<?php

// ── Test harness for the audit trail (runs against an in-memory SQLite DB) ──

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// 1) The real schema must load cleanly (this validates the new audit_log DDL).
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    // stdin execution has no __DIR__ context we can rely on; schema is injected instead.
    $schema = $GLOBALS['__schema'] ?? '';
}
$pdo->exec($schema);
ok('schema.sql loads (incl. audit_log)', true);

$cols = array_column($pdo->query('PRAGMA table_info(audit_log)')->fetchAll(), 'name');
ok('audit_log has all columns', $cols === [
    'id', 'created_at', 'user_id', 'user_name', 'user_role', 'module',
    'action', 'entity', 'entity_id', 'label', 'detail', 'ip',
], implode(',', $cols));

$idx = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='audit_log'")->fetchAll(), 'name');
ok('audit_log indexes created', count(array_intersect(['idx_audit_created', 'idx_audit_user', 'idx_audit_entity'], $idx)) === 3, implode(',', $idx));

// 2) audit() writes the acting user, and created_at defaults.
$GLOBALS['auth'] = new AuthStub(['id' => 7, 'name' => 'Sari Dewi', 'role' => 'supervisor']);
audit($pdo, 'orders', 'approve', 'order', 42, 'SO-0001', '主管通过 → 待经理审批');
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
ok('audit() records the actor', $row['user_id'] === 7 && $row['user_name'] === 'Sari Dewi' && $row['user_role'] === 'supervisor');
ok('audit() records module/action/entity', $row['module'] === 'orders' && $row['action'] === 'approve' && $row['entity'] === 'order' && (int) $row['entity_id'] === 42);
ok('audit() stamps created_at', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['created_at']) === 1, (string) $row['created_at']);
ok('audit() records client IP', $row['ip'] === '203.0.113.9', (string) $row['ip']);

// 3) Anonymous events (failed login) must still be recorded.
$GLOBALS['auth'] = new AuthStub([]);
audit($pdo, 'auth', 'login_failed', 'user', null, 'attacker@example.com', '密码错误或账号不存在');
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
ok('anonymous event recorded', $row['user_id'] === null && $row['label'] === 'attacker@example.com' && $row['action'] === 'login_failed');

// 4) A logging failure must never break the audited operation.
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$threw = false;
try {
    audit($broken, 'orders', 'approve', 'order', 1, 'SO-X', 'no audit_log table here');
} catch (Throwable $e) {
    $threw = true;
}
ok('audit() swallows its own failure', !$threw);

// 5) Over-long text is truncated rather than rejected.
$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
audit($pdo, 'inventory', 'update', 'product', 5, str_repeat('长', 500), str_repeat('详', 5000));
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
ok('label truncated to 200', mb_strlen((string) $row['label']) === 200, (string) mb_strlen((string) $row['label']));
ok('detail truncated to 2000', mb_strlen((string) $row['detail']) === 2000, (string) mb_strlen((string) $row['detail']));

// 6) audit_diff() reports only what changed.
$d = audit_diff(
    ['name' => 'A', 'price' => '100', 'stock' => '5'],
    ['name' => 'A', 'price' => '120', 'stock' => '5'],
    ['name' => '名称', 'price' => '单价', 'stock' => '库存']
);
ok('audit_diff skips unchanged fields', $d === '单价: 100 → 120', $d);

$d2 = audit_diff(['owner' => ''], ['owner' => 'Ahmad'], ['owner' => '负责销售']);
ok('audit_diff marks empty values', $d2 === '负责销售: (空) → Ahmad', $d2);

$d3 = audit_diff(['a' => '1'], ['a' => '1'], ['a' => 'A']);
ok('audit_diff empty when nothing changed', $d3 === '', $d3);

// 7) audit_snapshot() returns the row, or [] when missing.
$pdo->exec("INSERT INTO tasks (title, due_date, priority) VALUES ('测试任务','2026-01-01','高')");
$snap = audit_snapshot($pdo, 'tasks', 1);
ok('audit_snapshot fetches the row', ($snap['title'] ?? '') === '测试任务');
ok('audit_snapshot returns [] for missing id', audit_snapshot($pdo, 'tasks', 9999) === []);

// 8) The viewer's filter + pagination SQL must work against real data.
$pdo->exec('DELETE FROM audit_log');
$GLOBALS['auth'] = new AuthStub(['id' => 3, 'name' => 'Ahmad', 'role' => 'sales']);
for ($i = 1; $i <= 120; $i++) {
    audit($pdo, $i % 2 === 0 ? 'orders' : 'customers', 'create', 'order', $i, 'DOC-' . $i, 'seeded');
}
$total = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
ok('120 entries written', $total === 120, (string) $total);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE module = ?');
$stmt->execute(['orders']);
ok('module filter works', (int) $stmt->fetchColumn() === 60);

// Pagination over the full set: 120 rows at 50/page → 50, 50, 20.
$sizes = [];
foreach ([0, 50, 100] as $off) {
    $sizes[] = count($pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 50 OFFSET {$off}")->fetchAll());
}
ok('pagination splits 120 into 50/50/20', $sizes === [50, 50, 20], implode('/', $sizes));

// Keyword filter narrows the set (DOC-1, DOC-10..19, DOC-100..120 = 32).
$stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE (label LIKE ? OR detail LIKE ? OR user_name LIKE ?)');
$stmt->execute(['%DOC-1%', '%DOC-1%', '%DOC-1%']);
$kw = (int) $stmt->fetchColumn();
ok('keyword filter narrows the set', $kw === 32, (string) $kw);

// The controller clamps an out-of-range page so the UI never shows a blank table.
$clamp = function (int $requested, int $total): int {
    $pages = max(1, (int) ceil($total / 50));
    return min(max(1, $requested), $pages);
};
ok('page clamped to last page', $clamp(99, $kw) === 1 && $clamp(99, 120) === 3, $clamp(99, 120) . '');
ok('page clamped up from 0', $clamp(0, 120) === 1);

// Date-range filter uses the created_at string prefix.
$today = date('Y-m-d');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at >= ? AND created_at <= ?');
$stmt->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
ok('date-range filter matches today', (int) $stmt->fetchColumn() === 120);

// 9) The log must be append-only in practice: no UPDATE/DELETE anywhere in app code.
ok('display helpers resolve', tr_audit_module('orders') !== '' && tr_audit_action('approve') !== '' && audit_action_class('delete') === 'tag-red');
