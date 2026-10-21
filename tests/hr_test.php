<?php

// ── 人力 / SDM: HR-00 + HR-01 (docs/任务卡-人力一期.md) ──
// Salary encryption, who-sees-what, NIK rules, and the card's acceptance flow:
// create → upload KTP → change position/salary → resign, each leaving
// employee_changes + audit_log rows, the CRM account deactivated, login refused.

$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$savedConfig = $GLOBALS['config'];
$key = base64_encode(random_bytes(32));

// ── salary encryption ──
Hr::$keyOverride = '';
ok('no key → salary entry disabled', !Hr::crypto_ready());
$threw = false;
try {
    Hr::encrypt('8500000');
} catch (Throwable $e) {
    $threw = true;
}
ok('no key → encrypt refuses instead of storing plain text', $threw);
Hr::$keyOverride = $key;
$c1 = (string) Hr::encrypt('8500000');
$c2 = (string) Hr::encrypt('8500000');
ok('ciphertext is versioned and hides the amount', str_starts_with($c1, 'v1:') && !str_contains($c1, '8500000'));
ok('same amount → different ciphertext (random IV)', $c1 !== $c2);
ok('round trip', Hr::decrypt($c1) === '8500000');
$raw = base64_decode(substr($c1, 3));
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
ok('tampered ciphertext → null, not garbage', Hr::decrypt('v1:' . base64_encode($raw)) === null);
Hr::$keyOverride = base64_encode(random_bytes(32));
ok('wrong key → null', Hr::decrypt($c1) === null);
Hr::$keyOverride = 'too-short';
ok('malformed key → not ready', !Hr::crypto_ready());
Hr::$keyOverride = $key;
ok('null stays null', Hr::encrypt(null) === null && Hr::decrypt(null) === null);

// ── NIK, dates, completeness ──
ok('NIK: 16 digits', Hr::valid_nik('3171234567890001'));
ok('NIK: 15 digits refused', !Hr::valid_nik('317123456789000'));
ok('NIK: letters refused', !Hr::valid_nik('31712345678900AB'));
ok('dates shown DD-MM-YYYY', Hr::dmy('2026-10-22') === '22-10-2026' && Hr::dmy(null) === '');
ok('date validation', Hr::valid_date('2026-02-28') && !Hr::valid_date('2026-02-30') && !Hr::valid_date('22-10-2026'));
$full = array_fill_keys(Hr::COMPLETENESS_FIELDS, 'x');
ok('completeness: everything filled → 100', Hr::completeness($full) === 100);
ok('completeness: NPWP / supervisor do not count', !in_array('npwp', Hr::COMPLETENESS_FIELDS, true) && !in_array('supervisor_id', Hr::COMPLETENESS_FIELDS, true));
$half = $full;
foreach (array_slice(Hr::COMPLETENESS_FIELDS, 0, 10) as $k) {
    $half[$k] = null;
}
ok('completeness: missing fields lower it below 80', Hr::completeness($half) < 80);

// ── i18n: every HR key in both languages ──
$hrKeys = fn(string $l) => array_filter(array_keys(I18N[$l]), fn($k) => preg_match('/^(hr|nav_hr|audit_mod_hr)/', $k));
ok('HR strings exist in zh and id', $hrKeys('zh') !== [] && array_values(array_diff($hrKeys('zh'), $hrKeys('id'))) === [] && array_values(array_diff($hrKeys('id'), $hrKeys('zh'))) === []);
ok('HR is a permission module; 总经理 reads all', in_array('hr', controllable_modules(), true) && in_array('hr_all', default_permissions()['manager'], true));

// ── a company like production: CRM accounts, employee records built from them ──
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$db = tempnam(sys_get_temp_dir(), 'hrt');
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$pw = password_hash('Rahasia#2026', PASSWORD_DEFAULT);
$ins = $p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)');
foreach ([[1, 'Administrator', 'admin@t', 'admin'], [2, 'Hana HR', 'hana@t', 'hr'], [3, 'tania', 'tania@t', 'manager'],
          [4, 'Rina Wati', 'rina@t', 'sales'], [5, 'Budi Santoso', 'budi@t', 'sales'], [6, 'Liu Xian', 'liu@t', 'warehouse'],
          [7, 'antonni', 'antonni@t', 'supervisor']] as [$id, $n, $m, $r]) {
    $ins->execute([$id, $n, $m, $pw, $r]);
}
foreach (default_permissions() as $role => $mods) {
    foreach ($mods as $mod) {
        $p->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
    }
}
ok('migration: one record per CRM account except the administrator', Hr::migrate_users($p) === 6
    && (int) $p->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 6
    && $p->query('SELECT employee_id FROM users WHERE id = 1')->fetchColumn() === null);
ok('migration runs once', Hr::migrate_users($p) === 0 && (int) $p->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 6);
$dept = $p->query('SELECT u.name, e.department, e.work_location, e.employment_status FROM users u JOIN employees e ON e.id = u.employee_id')->fetchAll();
$deptOf = array_column($dept, 'department', 'name');
ok('migration: departments from roles', $deptOf === ['Hana HR' => 'office', 'tania' => 'management', 'Rina Wati' => 'sales', 'Budi Santoso' => 'sales', 'Liu Xian' => 'warehouse', 'antonni' => 'finance'], json_encode($deptOf));
ok('migration: Jakarta, active, numbered AMI-xxxx', array_unique(array_column($dept, 'work_location')) === ['Jakarta'] && array_unique(array_column($dept, 'employment_status')) === ['active']
    && $p->query('SELECT employee_no FROM employees ORDER BY id LIMIT 1')->fetchColumn() === 'AMI-0001');
require_once __DIR__ . '/../app/Auth.php';
ok('migration: CRM login unaffected', @(new Auth($p))->attempt('budi@t', 'Rahasia#2026'));
$p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)')->execute([8, 'Siti A', 'siti@t', $pw, 'sales']);   // a new hire's account, not yet linked

$emp = fn(string $userName) => (int) $p->query('SELECT employee_id FROM users WHERE name = ' . $p->quote($userName))->fetchColumn();
$p->prepare('UPDATE employees SET is_dept_head = 1 WHERE id = ?')->execute([$emp('Budi Santoso')]);
$u = fn(int $id, string $name, string $role, ?int $empId) => ['id' => $id, 'name' => $name, 'role' => $role, 'employee_id' => $empId];
$hr = $u(2, 'Hana HR', 'hr', $emp('Hana HR'));
$admin = $u(1, 'Administrator', 'admin', null);
$tania = $u(3, 'tania', 'manager', $emp('tania'));
$rina = $u(4, 'Rina Wati', 'sales', $emp('Rina Wati'));
$budi = $u(5, 'Budi Santoso', 'sales', $emp('Budi Santoso'));
$liu = $u(6, 'Liu Xian', 'warehouse', $emp('Liu Xian'));

// ── who sees what (in-process) ──
$GLOBALS['permissions'] = [];
foreach ($p->query('SELECT role, module FROM role_permissions') as $r) {
    $GLOBALS['permissions'][$r['role']][] = $r['module'];
}
$viewerOf = function (array $user) use ($p): array {
    $GLOBALS['auth'] = new AuthStub($user);
    return Hr::viewer($p);
};
$v = $viewerOf($hr);
ok('HR: edits, sees all, sees salary', $v['edit'] && $v['scope'] === 'all' && $v['salary']);
$v = $viewerOf($admin);
ok('administrator: reads all incl. salary, cannot edit', !$v['edit'] && $v['scope'] === 'all' && $v['salary']);
$v = $viewerOf($tania);
ok('总经理 tania: reads all, no salary, cannot edit', !$v['edit'] && $v['scope'] === 'all' && !$v['salary']);
$v = $viewerOf($budi);
ok('dept head: own department only, no salary', $v['scope'] === 'department' && $v['department'] === 'sales' && !$v['salary']);
$v = $viewerOf($rina);
ok('employee: self only', $v['scope'] === 'self' && $v['employee_id'] === $rina['employee_id']);
ok('employee sees own salary, not others\'', Hr::can_see_salary($v, ['id' => $rina['employee_id']]) && !Hr::can_see_salary($v, ['id' => $budi['employee_id']]));
ok('no employee record → nothing', $viewerOf($u(9, 'X', 'sales', null))['scope'] === 'none');
$GLOBALS['auth'] = $savedAuth;
$GLOBALS['permissions'] = $savedPerms;
$p = null;

// ── acceptance flow through the real controller ──
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $root = sys_get_temp_dir() . '/hrt-files-' . bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    $run = function (string $action, array $user, array $get = [], ?array $post = null, array $env = []) use ($db, $harness, $key, $root): array {
        $env += ['TEST_HR_KEY' => $key, 'TEST_HR_UPLOAD_ROOT' => $root];
        $args = [PHP_BINARY, $harness, $db, 'hr', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $envs = '';
        foreach ($env as $k => $val) {
            $envs .= $k . '=' . escapeshellarg($val) . ' ';
        }
        $out = [];
        exec($envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn() => new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $isErr = fn(array $fl, string $msg) => ($fl[0]['type'] ?? '') === 'error' && str_contains((string) ($fl[0]['message'] ?? ''), $msg);
    $auditCount = fn(string $action) => (int) $q()->query("SELECT COUNT(*) FROM audit_log WHERE module = 'hr' AND action = " . $q()->quote($action))->fetchColumn();

    // 1. create
    $new = ['full_name' => 'Siti Aminah', 'nik' => '3171234567890001', 'birth_place' => 'Bekasi', 'birth_date' => '1995-05-12', 'gender' => 'P',
            'department' => 'sales', 'position' => 'Sales', 'work_location' => 'Surabaya', 'join_date' => '2026-10-01', 'employment_status' => 'probation', 'base_salary' => '8500000'];
    [, $fl] = $run('store', $tania, [], $new);
    ok('create: 总经理 cannot write', $isErr($fl, t('hr_msg_read_only')) && (int) $q()->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 6);
    [, $fl] = $run('store', $hr, [], ['nik' => '12345'] + $new);
    ok('create: NIK must be 16 digits', $isErr($fl, t('hr_err_nik_format')));
    [, $fl] = $run('store', $hr, [], ['join_date' => '2099-01-01'] + $new);
    ok('create: join date not in the future', $isErr($fl, t('hr_err_join_future')));
    [, $fl] = $run('store', $hr, [], ['join_date' => ''] + $new);
    ok('create: join date required', $isErr($fl, t('hr_err_join_req')));
    [, $fl] = $run('store', $hr, [], $new, ['TEST_HR_KEY' => '']);
    ok('create: salary refused without a key (never stored in clear)', $isErr($fl, t('hr_err_no_key')) && (int) $q()->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 6);
    [, $fl] = $run('store', $hr, [], $new);
    $siti = $q()->query("SELECT * FROM employees WHERE nik = '3171234567890001'")->fetch() ?: [];
    ok('create: saved with the next number', ($siti['employee_no'] ?? '') === 'AMI-0007' && ($fl[0]['type'] ?? '') === 'success', json_encode($fl));
    ok('create: salary encrypted at rest', isset($siti['base_salary_enc']) && !str_contains((string) $siti['base_salary_enc'], '8500000') && Hr::decrypt($siti['base_salary_enc']) === '8500000');
    ok('create: audited', $auditCount('create') === 1);
    [, $fl] = $run('store', $hr, [], ['full_name' => 'Orang Lain'] + $new);
    ok('create: duplicate NIK names the existing record', $isErr($fl, 'AMI-0007'));
    $sid = (int) ($siti['id'] ?? 0);
    [, $fl] = $run('store', $hr, [], ['full_name' => 'Joko Minimal', 'nik' => '3171234567890002', 'join_date' => '2026-10-05']);
    $joko = $q()->query("SELECT * FROM employees WHERE nik = '3171234567890002'")->fetch() ?: [];
    ok('create: name + NIK + join date are enough (收尾)', ($fl[0]['type'] ?? '') === 'success' && ($joko['employee_no'] ?? '') === 'AMI-0008'
        && $joko['department'] === null && $joko['position'] === null && $joko['work_location'] === null && $joko['birth_date'] === null, json_encode($fl));
    [$h] = $run('create', $hr);
    ok('create form: only name, NIK, join date marked required', substr_count($h, ' required') === 3 && !str_contains($h, 'hr-pct'), (string) substr_count($h, ' required'));

    // 1b. link the new hire to her CRM account (dropdown on the detail page)
    [$h] = $run('show', $hr, ['id' => $sid, 'tab' => 'job']);
    ok('link: dropdown offers unlinked accounts only, never admin', str_contains($h, 'siti@t') && !str_contains($h, 'rina@t') && !str_contains($h, 'admin@t'));
    [, $fl] = $run('link', $tania, [], ['id' => $sid, 'user_id' => 8]);
    ok('link: only HR', $isErr($fl, t('hr_msg_read_only')));
    [, $fl] = $run('link', $hr, [], ['id' => $sid, 'user_id' => 4]);
    ok('link: an account already linked to someone else is refused', $isErr($fl, t('hr_err_link_taken')) && (int) $q()->query('SELECT employee_id FROM users WHERE id = 4')->fetchColumn() === $rina['employee_id']);
    [, $fl] = $run('link', $hr, [], ['id' => $sid, 'user_id' => 1]);
    ok('link: the administrator account is refused', $isErr($fl, t('hr_err_link_taken')));
    [, $fl] = $run('link', $hr, [], ['id' => $sid, 'user_id' => 8]);
    ok('link: linked', (int) $q()->query('SELECT employee_id FROM users WHERE id = 8')->fetchColumn() === $sid && ($fl[0]['type'] ?? '') === 'success');
    ok('link: audited', str_contains(implode("\n", $q()->query("SELECT detail FROM audit_log WHERE entity = 'users' AND entity_id = 8")->fetchAll(PDO::FETCH_COLUMN)), "users.employee_id: (空) → {$sid}"));
    $sitiUser = $u(8, 'Siti A', 'sales', $sid);
    [$h, $fl] = $run('show', $sitiUser, ['id' => $sid]);
    ok('link: she now sees her own record', $fl === [] && str_contains($h, 'Siti Aminah'));
    $run('link', $hr, [], ['id' => $sid, 'user_id' => 0]);
    ok('link: unlink', $q()->query('SELECT employee_id FROM users WHERE id = 8')->fetchColumn() === null);
    $run('link', $hr, [], ['id' => $sid, 'user_id' => 8]);

    // 2. upload KTP (content must match the extension)
    $tmp = tempnam(sys_get_temp_dir(), 'ktp');
    file_put_contents($tmp, "%PDF-1.4\nKTP Siti\n%%EOF");
    $files = fn(string $name, string $path) => ['TEST_FILES' => json_encode(['file' => ['name' => $name, 'tmp_name' => $path, 'size' => filesize($path), 'error' => 0]])];
    $fake = tempnam(sys_get_temp_dir(), 'fake');
    file_put_contents($fake, '<?php echo 1;');
    [, $fl] = $run('upload', $hr, [], ['id' => $sid, 'doc_type' => 'ktp'], $files('ktp.pdf', $fake));
    ok('upload: a non-PDF named .pdf is refused', $isErr($fl, t('hr_err_type')));
    [, $fl] = $run('upload', $hr, [], ['id' => $sid, 'doc_type' => 'ktp'], $files('ktp.pdf', $tmp));
    $att = $q()->query("SELECT * FROM employee_attachments WHERE employee_id = {$sid}")->fetchAll();
    ok('upload: one KTP attachment', count($att) === 1 && $att[0]['doc_type'] === 'ktp' && $att[0]['mime'] === 'application/pdf');
    ok('upload: stored under a random name in the private dir', is_file("{$root}/{$sid}/" . ($att[0]['file_path'] ?? 'x')) && ($att[0]['file_path'] ?? '') !== 'ktp.pdf');
    ok('upload: audited', $auditCount('upload') === 1);
    [$h] = $run('file', $hr, ['fid' => (int) ($att[0]['id'] ?? 0)]);
    ok('file: HR can open it through the checked endpoint', str_contains($h, 'KTP Siti'));
    [$h, $fl] = $run('file', $liu, ['fid' => (int) ($att[0]['id'] ?? 0)]);
    ok('file: another employee cannot', !str_contains($h, 'KTP Siti') && $isErr($fl, t('hr_msg_not_yours')));

    // 3. position + salary change: effective date and note mandatory
    $job = ['id' => $sid, 'department' => 'sales', 'position' => 'Sales Senior', 'work_location' => 'Surabaya', 'supervisor_id' => '', 'employment_status' => 'active', 'base_salary' => '9000000', 'effective_date' => '2026-10-15'];
    [, $fl] = $run('job', $hr, [], $job);
    ok('job: no note → refused, nothing written', $isErr($fl, t('hr_err_effective_note')) && (int) $q()->query('SELECT COUNT(*) FROM employee_changes')->fetchColumn() === 0);
    [, $fl] = $run('job', $hr, [], $job + ['note' => 'Promosi Q4']);
    $ch = $q()->query("SELECT * FROM employee_changes WHERE employee_id = {$sid} ORDER BY id")->fetchAll();
    $byField = array_column($ch, null, 'field');
    ok('job: history rows for position, status and salary', isset($byField['position'], $byField['employment_status'], $byField['base_salary']) && $byField['position']['change_type'] === 'position'
        && $byField['position']['old_value'] === 'Sales' && $byField['position']['new_value'] === 'Sales Senior' && $byField['position']['effective_date'] === '2026-10-15' && $byField['position']['note'] === 'Promosi Q4', json_encode(array_keys($byField)));
    ok('job: salary history encrypted', isset($byField['base_salary']) && Hr::decrypt($byField['base_salary']['old_value']) === '8500000' && Hr::decrypt($byField['base_salary']['new_value']) === '9000000'
        && !str_contains($byField['base_salary']['new_value'], '9000000'));
    $details = implode("\n", $q()->query("SELECT detail FROM audit_log WHERE module = 'hr'")->fetchAll(PDO::FETCH_COLUMN));
    ok('job: field-level audit (table.field: old → new)', str_contains($details, 'employees.position: Sales → Sales Senior'));
    ok('audit never contains a salary amount', !preg_match('/8500000|9000000|8\.500\.000|9\.000\.000/', $details) && str_contains($details, 'employees.base_salary'));

    // 4. who sees the page
    [$h] = $run('show', $hr, ['id' => $sid, 'tab' => 'job']);
    ok('HR sees salary', str_contains($h, 'Rp 9.000.000'));
    [$h] = $run('show', $admin, ['id' => $sid, 'tab' => 'history']);
    ok('administrator sees salary history', str_contains($h, 'Rp 9.000.000') && str_contains($h, 'Rp 8.500.000'));
    [$h] = $run('show', $tania, ['id' => $sid, 'tab' => 'job']);
    [$h2] = $run('show', $tania, ['id' => $sid, 'tab' => 'history']);
    ok('总经理 sees the record but no salary', str_contains($h, 'Siti Aminah') && !str_contains($h . $h2, '9.000.000') && !str_contains($h . $h2, '8.500.000') && str_contains($h2, t('hr_hidden')));
    ok('总经理 gets no edit controls', !str_contains($h, 'hr.job') && !str_contains($h, 'hr.resign'));
    [, $fl] = $run('job', $tania, [], $job + ['note' => 'x', 'position' => 'Boss']);
    ok('总经理 cannot change a position', $isErr($fl, t('hr_msg_read_only')));
    [$h] = $run('employees', $budi);
    ok('dept head lists own department only', str_contains($h, 'Siti Aminah') && str_contains($h, 'Rina Wati') && !str_contains($h, 'Liu Xian'));
    [$h, $fl] = $run('show', $budi, ['id' => $sid, 'tab' => 'job']);
    ok('dept head: no salary', str_contains($h, 'Siti Aminah') && !str_contains($h, '9.000.000'));
    [, $fl] = $run('show', $budi, ['id' => $liu['employee_id']]);
    ok('dept head: other departments refused', $isErr($fl, t('hr_msg_not_yours')));
    [$h] = $run('employees', $rina);
    ok('employee lists only self', str_contains($h, 'Rina Wati') && !str_contains($h, 'Siti Aminah'));
    [, $fl] = $run('show', $rina, ['id' => $sid]);
    ok('employee cannot open a colleague', $isErr($fl, t('hr_msg_not_yours')));
    [$h, $fl] = $run('show', $rina, ['id' => $rina['employee_id']]);
    ok('employee opens own record', $fl === [] && str_contains($h, 'Rina Wati'));
    [$h] = $run('employees', $hr, ['q' => '3171234567890001']);
    ok('list: search by NIK', str_contains($h, 'Siti Aminah') && !str_contains($h, 'Liu Xian'));
    [$h] = $run('employees', $hr, ['location' => 'Surabaya']);
    ok('list: filter by location', str_contains($h, 'Siti Aminah') && !str_contains($h, 'Rina Wati'));
    ok('list: dates DD-MM-YYYY', str_contains($h, '01-10-2026'));
    ok('list: no colour marks (收尾)', Hr::completeness($siti) < 80 && !str_contains($h, 'tag-yellow') && !str_contains($h, 'hr-low'));
    ok('list: no salary column', !str_contains($h, '9.000.000') && !str_contains($h, t('hr_base_salary')));

    // 5. resign Rina (has a CRM account)
    $rid = (int) $rina['employee_id'];
    [, $fl] = $run('resign', $hr, [], ['id' => $rid, 'resign_date' => '2026-10-20']);
    ok('resign: reason required', $isErr($fl, t('hr_err_resign_req')));
    [, $fl] = $run('resign', $hr, [], ['id' => $rid, 'resign_date' => '2026-10-20', 'resign_reason' => 'Pindah kerja']);
    $r = $q()->query("SELECT * FROM employees WHERE id = {$rid}")->fetch();
    ok('resign: status resigned with date and reason', $r['employment_status'] === 'resigned' && $r['resign_date'] === '2026-10-20' && $r['resign_reason'] === 'Pindah kerja');
    $todos = $q()->query("SELECT type FROM hr_todos WHERE employee_id = {$rid} AND status = 'open' ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
    ok('resign: only the BPJS 停保 todo (收尾: four todo types)', $todos === ['bpjs_stop'], json_encode($todos));
    ok('resign: CRM account deactivated, not deleted', (int) $q()->query('SELECT active FROM users WHERE id = 4')->fetchColumn() === 0);
    ok('resign: history row', (int) $q()->query("SELECT COUNT(*) FROM employee_changes WHERE employee_id = {$rid} AND change_type = 'status' AND new_value = 'resigned'")->fetchColumn() === 1);
    ok('resign: audited (record + account)', $auditCount('resign') >= 4 && str_contains(implode("\n", $q()->query("SELECT detail FROM audit_log WHERE action = 'resign'")->fetchAll(PDO::FETCH_COLUMN)), 'users.active: 1 → 0'));
    $authDb = new Auth($q());
    ok('resign: login refused', !$authDb->attempt('rina@t', 'Rahasia#2026'));
    $savedSession = $_SESSION;
    $_SESSION['user_id'] = 4;
    ok('resign: an open session is logged out', (new Auth($q()))->user() === null);
    $_SESSION = $savedSession;
    [, $fl] = $run('resign', $hr, [], ['id' => $rid, 'resign_date' => '2026-10-21', 'resign_reason' => 'again']);
    ok('resign: not twice', $isErr($fl, t('hr_err_already_resigned')) && (int) $q()->query("SELECT COUNT(*) FROM hr_todos WHERE employee_id = {$rid}")->fetchColumn() === 1);

    // 6. the 人力 home: todos, mark done
    [$h] = $run('index', $hr);
    ok('home lists the todos, overdue marked', str_contains($h, t('hrtodo_bpjs_stop')) && str_contains($h, 'hr-overdue') && str_contains($h, '20-10-2026'));
    $p3 = $q();
    $p3->exec("INSERT INTO hr_todos (type, employee_id, ref, due_date) VALUES ('probation_end', {$rid}, 'legacy', '2026-10-01')");
    [$h] = $run('index', $hr);
    ok('home shows only the four todo types (an old other-type row stays hidden)', !str_contains($h, '2026-10-01') && !str_contains($h, 'probation_end'));
    $tid = (int) $q()->query("SELECT id FROM hr_todos WHERE type = 'bpjs_stop'")->fetchColumn();
    [, $fl] = $run('todo_done', $tania, [], ['id' => $tid]);
    ok('todo: only HR handles it', $isErr($fl, t('hr_msg_read_only')));
    $run('todo_done', $hr, [], ['id' => $tid, 'note' => 'done in CRM']);
    ok('todo: done and audited', $q()->query("SELECT status FROM hr_todos WHERE id = {$tid}")->fetchColumn() === 'done' && $auditCount('handle') === 1);

    // 7. undo the resignation
    [, $fl] = $run('unresign', $hr, [], ['id' => $rid]);
    ok('unresign: reason required', $isErr($fl, t('hr_err_unresign_note')));
    [, $fl] = $run('unresign', $tania, [], ['id' => $rid, 'note' => 'x']);
    ok('unresign: only HR', $isErr($fl, t('hr_msg_read_only')));
    [, $fl] = $run('unresign', $hr, [], ['id' => $sid, 'note' => 'x']);
    ok('unresign: only a resigned employee', $isErr($fl, t('hr_err_not_resigned')));
    [, $fl] = $run('unresign', $hr, [], ['id' => $rid, 'note' => 'Salah input']);
    $r = $q()->query("SELECT * FROM employees WHERE id = {$rid}")->fetch();
    ok('unresign: status back, resign fields cleared', $r['employment_status'] === 'active' && $r['resign_date'] === null && $r['resign_reason'] === null, json_encode($fl));
    $st = $q()->query("SELECT type, status FROM hr_todos WHERE employee_id = {$rid} ORDER BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('unresign: handled todo kept as handled', ($st['bpjs_stop'] ?? '') === 'done', json_encode($st));
    ok('unresign: CRM account re-enabled', (int) $q()->query('SELECT active FROM users WHERE id = 4')->fetchColumn() === 1);
    ok('unresign: login works again', @(new Auth($q()))->attempt('rina@t', 'Rahasia#2026'));
    ok('unresign: history row', (int) $q()->query("SELECT COUNT(*) FROM employee_changes WHERE employee_id = {$rid} AND old_value = 'resigned' AND new_value = 'active' AND note = 'Salah input'")->fetchColumn() === 1);
    $ud = implode("\n", $q()->query("SELECT detail FROM audit_log WHERE action = 'unresign'")->fetchAll(PDO::FETCH_COLUMN));
    ok('unresign: audited (record, account)', str_contains($ud, 'employees.employment_status: resigned → active') && str_contains($ud, 'users.active: 0 → 1'));
    [$h] = $run('index', $hr);
    ok('unresign: cancelled todos leave the home list', !str_contains($h, t('hrtodo_bpjs_stop')));
    $run('resign', $hr, [], ['id' => $rid, 'resign_date' => '2026-10-21', 'resign_reason' => 'Pindah kerja']);
    ok('resign again: a fresh BPJS 停保 todo', (int) $q()->query("SELECT COUNT(*) FROM hr_todos WHERE employee_id = {$rid} AND status = 'open' AND type = 'bpjs_stop'")->fetchColumn() === 1
        && (int) $q()->query('SELECT active FROM users WHERE id = 4')->fetchColumn() === 0);
    ok('nothing in employees was deleted', (int) $q()->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 8);

    @unlink($tmp);
    @unlink($fake);
    foreach (glob("{$root}/*/*") ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob("{$root}/*") ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($root);
}
@unlink($db);
Hr::$keyOverride = null;
$GLOBALS['config'] = $savedConfig;
