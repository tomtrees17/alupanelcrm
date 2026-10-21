<?php

// ── 人力 / SDM: HR-03 BPJS (docs/任务卡-人力一期.md) ──
// Amount maths (caps, JP, extra dependants, rounding), rate periods, who sees
// what, and the card's acceptance: a new employee gets two pending records; a
// 2026-11 list is right for one person in both programs and exports to an
// .xlsx that opens; a resignation puts both programs on 待停保 with a todo.

$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$key = base64_encode(random_bytes(32));
$T = date('Y-m-d');
$d = fn(int $n) => HrContract::add_days($T, $n);

// ── maths ──
$rates = ['id' => 1, 'kes_company' => 4.0, 'kes_employee' => 1.0, 'jht_company' => 3.7, 'jht_employee' => 2.0, 'jkk' => 0.24, 'jkm' => 0.3,
          'jp_company' => 2.0, 'jp_employee' => 1.0, 'kes_cap' => 12000000.0, 'jp_cap' => 10000000.0];
$k = HrBpjs::calc_kesehatan(8000000, $rates, 0);
ok('Kesehatan: 4% / 1% of the base', $k['company'] === 320000 && $k['employee'] === 80000 && $k['total'] === 400000);
$k = HrBpjs::calc_kesehatan(15000000, $rates, 0);
ok('Kesehatan: base capped', $k['base'] === 12000000.0 && $k['company'] === 480000 && $k['employee'] === 120000);
$k = HrBpjs::calc_kesehatan(8000000, $rates, 2);
ok('Kesehatan: +1% per extra dependant, employee side', $k['employee'] === 80000 + 2 * 80000 && $k['company'] === 320000);
$j = HrBpjs::calc_ketenagakerjaan(8000000, $rates, true);
ok('Ketenagakerjaan: JHT / JKK / JKM / JP', $j['parts']['jht_company'] === 296000 && $j['parts']['jht_employee'] === 160000 && $j['parts']['jkk'] === 19200
    && $j['parts']['jkm'] === 24000 && $j['parts']['jp_company'] === 160000 && $j['parts']['jp_employee'] === 80000
    && $j['company'] === 499200 && $j['employee'] === 240000 && $j['total'] === 739200);
$j = HrBpjs::calc_ketenagakerjaan(15000000, $rates, true);
ok('JP on the capped base, JHT on the full base', $j['parts']['jp_company'] === 200000 && $j['parts']['jp_employee'] === 100000 && $j['parts']['jht_company'] === 555000);
$j = HrBpjs::calc_ketenagakerjaan(8000000, $rates, false);
ok('not in JP → no JP', $j['parts']['jp_company'] === 0 && $j['parts']['jp_employee'] === 0 && $j['total'] === 739200 - 240000);
ok('each component rounded to whole rupiah', HrBpjs::calc_ketenagakerjaan(7123457, $rates, false)['parts']['jkk'] === 17096);
ok('period bounds', HrBpjs::period_bounds('2026-02') === ['2026-02-01', '2026-02-28'] && HrBpjs::valid_period('2026-11') && !HrBpjs::valid_period('2026-13'));

// ── a company ──
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$db = tempnam(sys_get_temp_dir(), 'hrb');
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$pw = password_hash('Rahasia#2026', PASSWORD_DEFAULT);
$ins = $p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)');
foreach ([[1, 'Administrator', 'admin@t', 'admin'], [2, 'Hana HR', 'hana@t', 'hr'], [3, 'tania', 'tania@t', 'manager'],
          [4, 'Rina Wati', 'rina@t', 'sales'], [5, 'Budi Santoso', 'budi@t', 'sales']] as [$id, $n, $m, $r]) {
    $ins->execute([$id, $n, $m, $pw, $r]);
}
foreach (default_permissions() as $role => $mods) {
    foreach ($mods as $mod) {
        $p->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
    }
}
Hr::migrate_users($p);
$emp = fn(string $userName) => (int) $p->query('SELECT employee_id FROM users WHERE name = ' . $p->quote($userName))->fetchColumn();
$u = fn(int $id, string $name, string $role, ?int $empId) => ['id' => $id, 'name' => $name, 'role' => $role, 'employee_id' => $empId];
$hr = $u(2, 'Hana HR', 'hr', $emp('Hana HR'));
$admin = $u(1, 'Administrator', 'admin', null);
$tania = $u(3, 'tania', 'manager', $emp('tania'));
$rina = $u(4, 'Rina Wati', 'sales', $emp('Rina Wati'));
$budi = $u(5, 'Budi Santoso', 'sales', $emp('Budi Santoso'));
$p->prepare('UPDATE employees SET is_dept_head = 1 WHERE id = ?')->execute([$budi['employee_id']]);
$p->exec("INSERT INTO bpjs_rates (effective_from, kes_company, kes_employee, jht_company, jht_employee, jkk, jkm, jp_company, jp_employee, kes_cap, jp_cap)
          VALUES ('2026-01', 4, 1, 3.7, 2, 0.24, 0.3, 2, 1, 12000000, 10000000), ('2027-01', 4, 1, 3.7, 2, 0.54, 0.3, 2, 1, 12500000, 10500000)");
ok('rates: the row in force that month', (HrBpjs::rates_for($p, '2026-11')['effective_from'] ?? '') === '2026-01' && (HrBpjs::rates_for($p, '2027-03')['jkk'] ?? 0) == 0.54
    && HrBpjs::rates_for($p, '2025-12') === null);
$p->exec("INSERT INTO employees (employee_no, full_name, department) VALUES ('AMI-0900', 'Dep Test', 'sales')");
$dt = (int) $p->lastInsertId();
$addDep = $p->prepare("INSERT INTO bpjs_kesehatan_dependents (employee_id, name, relation, active) VALUES (?,?,?,?)");
foreach ([['A', 'spouse', 1], ['B', 'child', 1], ['C', 'child', 1], ['D', 'child', 1], ['E', 'child', 1], ['F', 'child', 0]] as [$n, $rel, $act]) {
    $addDep->execute([$dt, $n, $rel, $act]);
}
ok('extra dependants: 4 children → 1 extra (removed ones do not count)', HrBpjs::extra_dependents($p, $dt) === 1);
$addDep->execute([$dt, 'G', 'spouse', 1]);
ok('extra dependants: a second spouse counts too', HrBpjs::extra_dependents($p, $dt) === 2);
$p->exec("UPDATE employees SET employment_status = 'resigned' WHERE id = {$dt}");
$p = null;

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $root = sys_get_temp_dir() . '/hrb-files-' . bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    $cmd = function (string $action, array $user, array $get, ?array $post, array $env) use ($db, $harness, $key, $root): string {
        $env += ['TEST_HR_KEY' => $key, 'TEST_HR_UPLOAD_ROOT' => $root];
        $args = [PHP_BINARY, $harness, $db, 'hr', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $envs = '';
        foreach ($env as $kk => $val) {
            $envs .= $kk . '=' . escapeshellarg($val) . ' ';
        }
        return $envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    };
    $run = function (string $action, array $user, array $get = [], ?array $post = null, array $env = []) use ($cmd): array {
        $out = [];
        exec($cmd($action, $user, $get, $post, $env), $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn() => new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $isErr = fn(array $fl, string $msg) => ($fl[0]['type'] ?? '') === 'error' && str_contains((string) ($fl[0]['message'] ?? ''), $msg);
    $isOk = fn(array $fl) => ($fl[0]['type'] ?? '') === 'success';
    $rec = fn(string $prog, int $eid) => $q()->query('SELECT * FROM ' . HrBpjs::table($prog) . " WHERE employee_id = {$eid}")->fetch() ?: null;
    Hr::$keyOverride = $key;

    // ── acceptance 1: new employee → two pending records ──
    $run('store', $hr, [], ['full_name' => 'Siti Aminah', 'nik' => '3171234567890001', 'birth_date' => '1995-05-12', 'department' => 'sales', 'position' => 'Sales',
        'work_location' => 'Jakarta', 'join_date' => $d(-40), 'base_salary' => '8000000']);
    $sid = (int) $q()->query("SELECT id FROM employees WHERE nik = '3171234567890001'")->fetchColumn();
    [$h] = $run('show', $hr, ['id' => $sid, 'tab' => 'bpjs']);
    ok('ACCEPTANCE 1: new employee → BPJS tab shows two 待登记 records', $rec('kesehatan', $sid)['status'] === 'pending' && $rec('ketenagakerjaan', $sid)['status'] === 'pending'
        && substr_count($h, '>' . t('hrbstatus_pending') . '<') === 2);
    ok('existing employees got records too; resigned ones not', $rec('kesehatan', $rina['employee_id']) !== null && $rec('kesehatan', $dt) === null);
    $od = $q()->query("SELECT ref, due_date FROM hr_todos WHERE type = 'bpjs_overdue' AND employee_id = {$sid} AND status = 'open' ORDER BY ref")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('30 days after joining, still pending → BPJS 逾期未登记 (one per program)', $od === ['kesehatan' => $d(-10), 'ketenagakerjaan' => $d(-10)], json_encode($od));
    ok('no reminder for people without a join date', (int) $q()->query("SELECT COUNT(*) FROM hr_todos WHERE type = 'bpjs_overdue' AND employee_id <> {$sid}")->fetchColumn() === 0);
    [$h] = $run('bpjs', $hr);
    ok('overview: 在职未登记 count in red', (bool) preg_match('/hr-kpi-bad">\d+</', $h) && str_contains($h, 'Siti Aminah'));

    // register; base override needs a reason and never reaches the audit log
    [, $fl] = $run('bpjs_save', $hr, [], ['id' => $sid, 'program' => 'kesehatan', 'bpjs_no' => '0001234567890', 'registered_date' => '2026-10-01', 'class' => 'I', 'salary_base' => '9000000']);
    ok('override without a reason refused', $isErr($fl, t('hrb_err_base_note')) && $rec('kesehatan', $sid)['status'] === 'pending');
    [, $fl] = $run('bpjs_save', $hr, [], ['id' => $sid, 'program' => 'kesehatan', 'bpjs_no' => '0001234567890', 'registered_date' => '2026-10-01', 'class' => 'I']);
    ok('number + date → 已参保', $isOk($fl) && $rec('kesehatan', $sid)['status'] === 'active');
    ok('its overdue reminder closes', $q()->query("SELECT status FROM hr_todos WHERE type = 'bpjs_overdue' AND employee_id = {$sid} AND ref = 'kesehatan'")->fetchColumn() === 'done'
        && $q()->query("SELECT status FROM hr_todos WHERE type = 'bpjs_overdue' AND employee_id = {$sid} AND ref = 'ketenagakerjaan'")->fetchColumn() === 'open');
    $run('bpjs_save', $hr, [], ['id' => $sid, 'program' => 'ketenagakerjaan', 'bpjs_no' => '26001234567', 'registered_date' => '2026-10-01', 'jp_enrolled' => '1']);
    $run('bpjs_save', $hr, [], ['id' => (int) $rina['employee_id'], 'program' => 'ketenagakerjaan', 'bpjs_no' => '26009999999', 'registered_date' => '2026-10-01', 'jp_enrolled' => '1',
        'salary_base' => '5500000', 'base_note' => 'UMP']);
    $rt = $rec('ketenagakerjaan', (int) $rina['employee_id']);
    ok('override stored encrypted with its reason', Hr::decrypt($rt['salary_base_enc']) === '5500000' && $rt['base_note'] === 'UMP' && !str_contains((string) $rt['salary_base_enc'], '5500000'));
    $run('bpjs_save', $hr, [], ['id' => (int) $hr['employee_id'], 'program' => 'kesehatan', 'bpjs_no' => '0009', 'registered_date' => '2026-10-01']);   // no salary, no override

    // dependants: 1 spouse + 4 children → 1 extra
    foreach ([['Ahmad', 'spouse'], ['A1', 'child'], ['A2', 'child'], ['A3', 'child'], ['A4', 'child']] as [$n, $rel]) {
        $run('bpjs_dep_add', $hr, [], ['id' => $sid, 'name' => $n, 'relation' => $rel]);
    }
    [, $fl] = $run('bpjs_dep_add', $hr, [], ['id' => $sid, 'name' => 'X', 'relation' => 'child', 'nik' => '123']);
    ok('dependant: NIK must be 16 digits if given', ($fl[0]['type'] ?? '') === 'error');
    ok('dependants recorded', HrBpjs::extra_dependents($q(), $sid) === 1);

    // ── who sees what ──
    [$h] = $run('show', $tania, ['id' => $sid, 'tab' => 'bpjs']);
    ok('总经理: BPJS status yes, base no', str_contains($h, '0001234567890') && !str_contains($h, '8.000.000'));
    [, $fl] = $run('bpjs_monthly', $tania);
    ok('总经理: no monthly list', $isErr($fl, t('hrb_msg_money_only')));
    [, $fl] = $run('bpjs_rates', $tania);
    ok('总经理: no rates page', $isErr($fl, t('hrb_msg_money_only')));
    [$h] = $run('show', $budi, ['id' => (int) $rina['employee_id'], 'tab' => 'bpjs']);
    ok('dept head: no BPJS of others (HR-00 matrix)', str_contains($h, t('hrb_not_visible')) && !str_contains($h, '26009999999'));
    [$h] = $run('show', $rina, ['id' => (int) $rina['employee_id'], 'tab' => 'bpjs']);
    ok('employee: own BPJS incl. base', str_contains($h, '26009999999') && str_contains($h, 'Rp 5.500.000'));
    [$h] = $run('employees', $budi);
    ok('dept head list: BPJS column hidden for others', !str_contains($h, t('hrbstatus_active')));

    // ── rates page ──
    [$h] = $run('bpjs_rates', $admin);
    ok('boss reads rates, no form', str_contains($h, '2026-01') && !str_contains($h, 'hr.bpjs_rate_save'));
    [, $fl] = $run('bpjs_rate_save', $hr, [], ['effective_from' => '2026-12', 'jkk' => '0.33', 'kes_cap' => '12000000', 'jp_cap' => '10000000'] + array_map('strval', HrBpjs::DEFAULT_RATES));
    ok('rates: JKK must be a risk level', ($fl[0]['type'] ?? '') === 'error');
    [, $fl] = $run('bpjs_rate_save', $hr, [], ['effective_from' => '2026-12', 'kes_cap' => '', 'jp_cap' => '10000000'] + array_map('strval', HrBpjs::DEFAULT_RATES));
    ok('rates: caps required', ($fl[0]['type'] ?? '') === 'error');
    [, $fl] = $run('bpjs_rate_save', $hr, [], ['effective_from' => '2026-12', 'note' => 'PP baru', 'kes_cap' => '12000000', 'jp_cap' => '10200000'] + array_map('strval', HrBpjs::DEFAULT_RATES));
    ok('rates: new period saved and audited', $isOk($fl) && str_contains((string) $q()->query("SELECT detail FROM audit_log WHERE entity = 'bpjs_rates' ORDER BY id DESC")->fetchColumn(), 'jp_cap=10200000'));

    // ── acceptance 2: 2026-11 list, one person checked by hand, Excel opens ──
    [, $fl] = $run('bpjs_generate', $tania, [], ['period' => '2026-11']);
    ok('generate: HR only', $isErr($fl, t('hr_msg_read_only')));
    [, $fl] = $run('bpjs_generate', $hr, [], ['period' => '2026-11']);
    ok('generate 2026-11 run 1', $isOk($fl) && (int) $q()->query("SELECT COUNT(*) FROM bpjs_monthly WHERE period = '2026-11' AND run = 1")->fetchColumn() === 3);
    ok('people without a base are named, not guessed', ($fl[1]['type'] ?? '') === 'error' && str_contains((string) $fl[1]['message'], 'Hana HR'));
    $data = HrBpjs::load_run($q(), '2026-11', 1);
    $s = $data[$sid] ?? null;
    // By hand: base 8,000,000. Kesehatan 4% = 320,000; 1% = 80,000 + 1 extra dependant 80,000. Ketenagakerjaan
    // JHT 296,000 + 160,000, JKK 0.24% 19,200, JKM 0.3% 24,000, JP 160,000 + 80,000.
    ok('ACCEPTANCE 2: Siti, Kesehatan by hand', $s && $s['kesehatan']['company'] === 320000 && $s['kesehatan']['employee'] === 160000 && $s['kesehatan']['total'] === 480000);
    ok('ACCEPTANCE 2: Siti, Ketenagakerjaan by hand', $s && $s['ketenagakerjaan']['company'] === 499200 && $s['ketenagakerjaan']['employee'] === 240000 && $s['ketenagakerjaan']['total'] === 739200);
    ok('override used as the base', ($data[(int) $rina['employee_id']]['ketenagakerjaan']['base'] ?? 0) === 5500000.0 && $data[(int) $rina['employee_id']]['kesehatan'] === null);
    ok('amounts encrypted at rest', !preg_match('/320000|739200/', implode('', $q()->query("SELECT company_amount_enc || total_enc FROM bpjs_monthly")->fetchAll(PDO::FETCH_COLUMN))));
    [$h] = $run('bpjs_monthly', $hr, ['period' => '2026-11']);
    ok('page: totals and the reconcile reminder', str_contains($h, '1.219.200') && str_contains($h, e(t('hrb_check_official'))));
    $raw = (string) shell_exec($cmd('bpjs_export', $admin, ['period' => '2026-11', 'run' => '1'], null, []));
    $xlsx = substr($raw, 0, (int) strrpos($raw, "\n<!--FLASH:"));
    if (class_exists('ZipArchive')) {
        $f = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        file_put_contents($f, $xlsx);
        $z = new ZipArchive();
        $opened = $z->open($f) === true;
        $sheet = $opened ? (string) $z->getFromName('xl/worksheets/sheet1.xml') . (string) $z->getFromName('xl/sharedStrings.xml') : '';
        if ($opened) {
            $z->close();
        }
        @unlink($f);
        ok('ACCEPTANCE 2: export is a real .xlsx that opens, with the figures', $opened && str_contains($sheet, 'Siti Aminah') && str_contains($sheet, '739200') && str_contains($sheet, '480000'));
    } else {
        ok('ACCEPTANCE 2: export (CSV fallback) has the figures', str_contains($xlsx, 'Siti Aminah') && str_contains($xlsx, '739200'));
    }
    ok('export audited', (int) $q()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'export' AND entity = 'bpjs_monthly'")->fetchColumn() === 1);

    // locked: a recalculation is a new run, run 1 untouched; the used rate row is frozen
    $before = $q()->query("SELECT GROUP_CONCAT(total_enc) FROM bpjs_monthly WHERE period = '2026-11' AND run = 1")->fetchColumn();
    $run('bpjs_dep_add', $hr, [], ['id' => $sid, 'name' => 'A5', 'relation' => 'child']);
    [, $fl] = $run('bpjs_generate', $hr, [], ['period' => '2026-11']);
    ok('recalculate → run 2; run 1 kept as it was', (int) $q()->query("SELECT MAX(run) FROM bpjs_monthly WHERE period = '2026-11'")->fetchColumn() === 2
        && $q()->query("SELECT GROUP_CONCAT(total_enc) FROM bpjs_monthly WHERE period = '2026-11' AND run = 1")->fetchColumn() === $before
        && (HrBpjs::load_run($q(), '2026-11', 2)[$sid]['kesehatan']['employee'] ?? 0) === 240000);
    [, $fl] = $run('bpjs_rate_save', $hr, [], ['effective_from' => '2026-01', 'kes_cap' => '1', 'jp_cap' => '1'] + array_map('strval', HrBpjs::DEFAULT_RATES));
    ok('a rate row used by a list cannot be changed', $isErr($fl, t('hrb_err_rate_used')));

    // ── acceptance 3: resignation → 待停保 + todo; deactivation dates → 已停保, todo closes ──
    $run('resign', $hr, [], ['id' => $sid, 'resign_date' => $T, 'resign_reason' => 'Pindah']);
    ok('ACCEPTANCE 3: both programs 待停保', $rec('kesehatan', $sid)['status'] === 'pending_stop' && $rec('ketenagakerjaan', $sid)['status'] === 'pending_stop');
    [$h] = $run('index', $hr);
    ok('ACCEPTANCE 3: BPJS 停保 todo on the home list', $q()->query("SELECT status FROM hr_todos WHERE type = 'bpjs_stop' AND employee_id = {$sid}")->fetchColumn() === 'open'
        && str_contains($h, t('hrtodo_bpjs_stop')));
    [, $fl] = $run('bpjs_stop', $hr, [], ['id' => $sid, 'program' => 'kesehatan', 'deactivated_date' => '2026-09-01']);
    ok('stop date not before registration', $isErr($fl, t('hrb_err_stop_date')));
    $run('bpjs_stop', $hr, [], ['id' => $sid, 'program' => 'kesehatan', 'deactivated_date' => '2026-11-20']);
    ok('one stopped, todo still open', $rec('kesehatan', $sid)['status'] === 'stopped' && $q()->query("SELECT status FROM hr_todos WHERE type = 'bpjs_stop' AND employee_id = {$sid}")->fetchColumn() === 'open');
    $run('bpjs_stop', $hr, [], ['id' => $sid, 'program' => 'ketenagakerjaan', 'deactivated_date' => '2026-11-20']);
    ok('both stopped → todo done', $q()->query("SELECT status FROM hr_todos WHERE type = 'bpjs_stop' AND employee_id = {$sid}")->fetchColumn() === 'done');
    $run('bpjs_generate', $hr, [], ['period' => '2026-12']);
    $dec = HrBpjs::load_run($q(), '2026-12', 1);
    $run('bpjs_generate', $hr, [], ['period' => '2026-11']);
    ok('stop month still due, the month after not', !isset($dec[$sid]) && isset(HrBpjs::load_run($q(), '2026-11', 3)[$sid]));

    // resignation undone: 待停保 goes back
    $rid = (int) $rina['employee_id'];
    $run('resign', $hr, [], ['id' => $rid, 'resign_date' => $T, 'resign_reason' => 'x']);
    $run('unresign', $hr, [], ['id' => $rid, 'note' => 'Salah input']);
    ok('unresign: registered → 已参保, never registered → 待登记', $rec('ketenagakerjaan', $rid)['status'] === 'active' && $rec('kesehatan', $rid)['status'] === 'pending');
    $audit = implode("\n", $q()->query("SELECT detail FROM audit_log WHERE module = 'hr'")->fetchAll(PDO::FETCH_COLUMN));
    ok('audit never shows a salary or BPJS amount', !preg_match('/8000000|5500000|8\.000\.000|5\.500\.000|739200|320000/', $audit));

    foreach (glob("{$root}/*/*") ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob("{$root}/*") ?: [] as $dd) {
        @rmdir($dd);
    }
    @rmdir($root);
}
@unlink($db);
Hr::$keyOverride = null;
$GLOBALS['auth'] = $savedAuth;
$GLOBALS['permissions'] = $savedPerms;
