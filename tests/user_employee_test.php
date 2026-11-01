<?php

// ── New CRM account → employee record created and linked right away (owner 2026-11-01) ──
// Before this, only the one-time migration made employee records, so a sales
// account opened later never appeared in the visit-sender dropdown.

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'uemp');
    $f = new PDO('sqlite:' . $db);
    $f->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@x', 'x', 'admin'), (2, 'tania', 't@x', 'x', 'sales_manager')");
    $f->exec("INSERT INTO employees (id, employee_no, full_name, department, employment_status, created_by, updated_by) VALUES
        (1, 'AMI-0001', 'tania', 'sales', 'active', 'system', 'system'),
        (2, 'AMI-0002', 'Dewi Lestari', 'finance', 'active', 'hr', 'hr'),
        (3, 'AMI-0003', 'Budi', 'sales', 'resigned', 'hr', 'hr')");
    $f->exec('UPDATE users SET employee_id = 1 WHERE id = 2');
    $f = null;
    $run = function (string $action, array $post) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'users', $action, '{}', json_encode(['id' => 1, 'real' => true]), json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        preg_match('/<!--FLASH:(.*?)-->/s', implode("\n", $out), $fm);
        return json_decode($fm[1] ?? '[]', true) ?: [];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $acct = fn(string $email) => $q("SELECT u.id, u.employee_id, e.employee_no, e.full_name, e.department, e.attendance_mode, e.employment_status, e.created_by
                                     FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.email = '{$email}'")[0] ?? [];
    $base = ['password' => 'secret12', 'lang' => 'id', 'title' => '', 'phone' => ''];

    $fl = $run('store', $base + ['name' => 'Hendra Prayoga', 'email' => 'h@x', 'role' => 'sales']);
    $h = $acct('h@x');
    ok('sales account → new employee record, linked, sales dept, records visits, created by the admin',
        $h && $h['employee_no'] === 'AMI-0004' && $h['full_name'] === 'Hendra Prayoga' && $h['department'] === 'sales' && $h['attendance_mode'] === 'visit'
        && $h['employment_status'] === 'active' && $h['created_by'] === 'Admin', json_encode($h));
    ok('…the flash names the new employee no.', str_contains((string) ($fl[0]['msg'] ?? json_encode($fl)), 'AMI-0004'), json_encode($fl));
    $aud = $q("SELECT module, action, entity FROM audit_log WHERE entity IN ('employees', 'users') ORDER BY id");
    ok('…audit: employee created + account linked', count($aud) === 2 && $aud[0]['entity'] === 'employees' && $aud[1]['entity'] === 'users', json_encode($aud));

    $run('store', $base + ['name' => 'dewi lestari ', 'email' => 'd@x', 'role' => 'finance_assistant']);
    $d = $acct('d@x');
    ok('HR already made an unlinked record with the same name → linked to it, no duplicate',
        (int) $d['employee_id'] === 2 && (int) $q('SELECT COUNT(*) n FROM employees')[0]['n'] === 4, json_encode($d));

    $run('store', $base + ['name' => 'tania', 'email' => 't2@x', 'role' => 'sales']);
    $t = $acct('t2@x');
    ok('same name but that record already has an account → a separate record', $t['employee_no'] === 'AMI-0005' && (int) $t['employee_id'] !== 1, json_encode($t));

    $run('store', $base + ['name' => 'Budi', 'email' => 'b@x', 'role' => 'warehouse']);
    $b = $acct('b@x');
    ok('a resigned namesake is not reused; non-sales → no visit mode', $b['employee_no'] === 'AMI-0006' && $b['department'] === 'warehouse' && $b['attendance_mode'] === null, json_encode($b));

    $run('store', $base + ['name' => 'Admin2', 'email' => 'a2@x', 'role' => 'admin']);
    $a2 = $acct('a2@x');
    ok('administrator account → no employee record', $a2 && $a2['employee_id'] === null && (int) $q('SELECT COUNT(*) n FROM employees')[0]['n'] === 6);

    $fl = $run('store', $base + ['name' => 'Someone', 'email' => 'h@x', 'role' => 'sales']);
    ok('duplicate email → refused, and no orphan employee record left behind',
        ($fl[0]['type'] ?? '') === 'error' && (int) $q('SELECT COUNT(*) n FROM employees')[0]['n'] === 6 && !$q("SELECT 1 FROM employees WHERE full_name = 'Someone'"));

    $staff = array_column($q("SELECT full_name FROM employees WHERE employment_status <> 'resigned'"), 'full_name');
    ok('the new salesperson is now in the visit-sender staff list', in_array('Hendra Prayoga', $staff, true));
    @unlink($db);
}
