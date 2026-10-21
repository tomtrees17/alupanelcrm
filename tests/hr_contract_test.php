<?php

// ── 人力 / SDM: HR-02 contracts (docs/任务卡-人力一期.md) ──
// Numbering, legal-limit hints, one active contract at a time, sync to the
// employee record, reminders (no cron: scan on page load), and the card's
// acceptance: a PKWT ending in 20 days raises a todo; a renewal chains with
// renewal_count = 1; terminating a contract makes the employee resigned.

$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$key = base64_encode(random_bytes(32));
$T = date('Y-m-d');
$d = fn(int $n) => HrContract::add_days($T, $n);

// ── pure helpers ──
ok('period end: 15-01 + 1 month → 14-02', HrContract::months_end('2026-01-15', 1) === '2026-02-14');
ok('period end: 31-01 + 1 month → 28-02, not March', HrContract::months_end('2026-01-31', 1) === '2026-02-28');
ok('period end: 30-11 + 3 months → 28-02', HrContract::months_end('2026-11-30', 3) === '2027-02-28');
ok('probation end only for a probation PKWTT', HrContract::probation_end(['is_probation' => 1, 'probation_months' => 3, 'start_date' => '2026-01-01']) === '2026-03-31'
    && HrContract::probation_end(['is_probation' => 0, 'probation_months' => null, 'start_date' => '2026-01-01']) === null);
ok('day arithmetic', HrContract::days('2026-10-01', '2026-10-21') === 20 && HrContract::days('2026-10-21', '2026-10-01') === -20 && HrContract::add_days('2026-12-31', 1) === '2027-01-01');

// ── a company: CRM accounts → employee records ──
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$db = tempnam(sys_get_temp_dir(), 'hrc');
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$pw = password_hash('Rahasia#2026', PASSWORD_DEFAULT);
$ins = $p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)');
foreach ([[1, 'Administrator', 'admin@t', 'admin'], [2, 'Hana HR', 'hana@t', 'hr'], [3, 'tania', 'tania@t', 'manager'],
          [4, 'Rina Wati', 'rina@t', 'sales'], [5, 'Budi Santoso', 'budi@t', 'sales'], [6, 'Liu Xian', 'liu@t', 'warehouse']] as [$id, $n, $m, $r]) {
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
$tania = $u(3, 'tania', 'manager', $emp('tania'));
$rina = $u(4, 'Rina Wati', 'sales', $emp('Rina Wati'));
$budi = $u(5, 'Budi Santoso', 'sales', $emp('Budi Santoso'));
$liu = $u(6, 'Liu Xian', 'warehouse', $emp('Liu Xian'));
$p->prepare('UPDATE employees SET is_dept_head = 1 WHERE id = ?')->execute([$budi['employee_id']]);
$p->prepare("UPDATE employees SET position = 'Sales', join_date = ? WHERE id IN (?, ?)")->execute([$d(-400), $rina['employee_id'], $budi['employee_id']]);

// numbering (in-process)
ok('number: start month / year, serial 001', HrContract::next_no($p, '2026-11-05') === 'KK/AMI/11/2026/001');
$p->exec("INSERT INTO employment_contracts (contract_no, employee_id, contract_type, start_date) VALUES ('KK/AMI/11/2026/001', 1, 'PKWT', '2026-11-05')");
ok('number: serial runs through the year', HrContract::next_no($p, '2026-03-01') === 'KK/AMI/03/2026/002');
ok('number: restarts each year', HrContract::next_no($p, '2027-01-10') === 'KK/AMI/01/2027/001');
$p->exec('DELETE FROM employment_contracts');   // test fixture only

// legal-limit hint (in-process)
$chain = fn(int $rc, string $s, string $e, ?int $prev = null) => ['id' => 0, 'contract_type' => 'PKWT', 'renewal_count' => $rc, 'start_date' => $s, 'end_date' => $e, 'previous_contract_id' => $prev];
ok('limit: renewed twice → hint', HrContract::over_limit($p, $chain(2, '2026-01-01', '2026-12-31')));
ok('limit: first renewal of a 1-year PKWT → no hint', !HrContract::over_limit($p, $chain(0, '2026-01-01', '2026-12-31'), '2027-12-31'));
ok('limit: chain passing 5 years → hint', HrContract::over_limit($p, $chain(1, '2022-01-01', '2025-12-31'), '2027-06-30'));
$p = null;

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $root = sys_get_temp_dir() . '/hrc-files-' . bin2hex(random_bytes(4));
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
    $isOk = fn(array $fl) => ($fl[0]['type'] ?? '') === 'success';
    $scan = function () use ($root): array {
        $f = tempnam(sys_get_temp_dir(), 'kk');
        file_put_contents($f, "%PDF-1.4\nKontrak\n%%EOF");
        return ['TEST_FILES' => json_encode(['file' => ['name' => 'kontrak.pdf', 'tmp_name' => $f, 'size' => filesize($f), 'error' => 0]])];
    };
    $last = fn() => $q()->query('SELECT * FROM employment_contracts ORDER BY id DESC LIMIT 1')->fetch();
    $todo = fn(string $type, int $cid) => $q()->query("SELECT * FROM hr_todos WHERE type = '{$type}' AND ref = 'contract:{$cid}'")->fetch() ?: null;
    $rid = (int) $rina['employee_id'];
    Hr::$keyOverride = $key;

    // 1. create a PKWT for Rina (draft), employee locked
    $pk = ['employee_id' => $rid, 'contract_type' => 'PKWT', 'start_date' => $d(-300), 'end_date' => $d(65), 'position' => 'Sales Senior', 'work_location' => 'Bandung', 'salary' => '7000000', 'signed_date' => $d(-305)];
    [, $fl] = $run('contract_store', $tania, [], $pk);
    ok('create: 总经理 cannot', $isErr($fl, t('hr_msg_read_only')));
    [, $fl] = $run('contract_store', $hr, [], ['end_date' => ''] + $pk);
    ok('create: PKWT needs an end date', $isErr($fl, t('hrc_err_end')));
    [, $fl] = $run('contract_store', $hr, [], ['contract_type' => 'PKWTT', 'is_probation' => '1', 'probation_months' => '4'] + $pk);
    ok('create: probation ≤ 3 months', $isErr($fl, t('hrc_err_probation')));
    [$h] = $run('contract_new', $hr, ['employee_id' => $rid]);
    ok('new-contract form: employee locked', str_contains($h, 'Rina Wati') && str_contains($h, 'name="employee_id" value="' . $rid . '"') && !str_contains($h, '<select class="form-select" name="employee_id"'));
    [, $fl] = $run('contract_store', $hr, [], $pk);
    $c1 = $last();
    $no1 = sprintf('KK/AMI/%s/%s/001', substr($d(-300), 5, 2), substr($d(-300), 0, 4));
    ok('create: draft numbered KK/AMI/MM/YYYY/001', $isOk($fl) && $c1['status'] === 'draft' && $c1['contract_no'] === $no1 && (int) $c1['renewal_count'] === 0, (string) ($c1['contract_no'] ?? ''));
    ok('create: contract salary encrypted', !str_contains((string) $c1['salary_enc'], '7000000') && Hr::decrypt($c1['salary_enc']) === '7000000');
    [, $fl] = $run('contract_activate', $hr, [], ['id' => $c1['id']]);
    ok('activate: refused without the scan', $isErr($fl, t('hrc_err_need_scan')) && $last()['status'] === 'draft');
    [, $fl] = $run('contract_upload', $hr, [], ['id' => $c1['id']], $scan());
    [, $fl] = $run('contract_activate', $hr, [], ['id' => $c1['id']]);
    $c1 = $q()->query("SELECT * FROM employment_contracts WHERE id = {$c1['id']}")->fetch();
    ok('activate: active after the scan', $isOk($fl) && $c1['status'] === 'active' && $c1['file_path'] !== null);
    $r = $q()->query("SELECT * FROM employees WHERE id = {$rid}")->fetch();
    ok('sync: position / location / salary copied to the employee record', $r['position'] === 'Sales Senior' && $r['work_location'] === 'Bandung' && Hr::decrypt($r['base_salary_enc']) === '7000000');
    $ch = $q()->query("SELECT field, effective_date, note FROM employee_changes WHERE employee_id = {$rid} ORDER BY id")->fetchAll();
    ok('sync: change history dated from the contract start, noted with the contract no.', count($ch) === 3 && array_unique(array_column($ch, 'effective_date')) === [$d(-300)] && array_unique(array_column($ch, 'note')) === [$no1], json_encode($ch));
    $audit = implode("\n", $q()->query("SELECT detail FROM audit_log WHERE module = 'hr'")->fetchAll(PDO::FETCH_COLUMN));
    ok('audit: contract trail, never a salary amount', str_contains($audit, 'employment_contracts.status: draft → active') && !preg_match('/7000000|7\.000\.000/', $audit));

    // one active contract at a time
    [, $fl] = $run('contract_store', $hr, [], ['start_date' => $d(-10), 'end_date' => $d(100)] + $pk);
    $c2 = $last();
    $run('contract_upload', $hr, [], ['id' => $c2['id']], $scan());
    [, $fl] = $run('contract_activate', $hr, [], ['id' => $c2['id']]);
    ok('one active contract: overlapping period refused, names the active one', $isErr($fl, $no1) && $last()['status'] === 'draft');
    [, $fl] = $run('contract_terminate', $hr, [], ['id' => $c2['id'], 'termination_date' => $T, 'termination_reason' => 'Salah input']);
    ok('a draft is closed without touching the employee', $last()['status'] === 'terminated' && $q()->query("SELECT employment_status FROM employees WHERE id = {$rid}")->fetchColumn() === 'active');

    // ── acceptance 1: end date moved to 20 days out → "合同即将到期" todo ──
    ok('no reminder 65 days out', $todo('contract_expiring', (int) $c1['id']) === null);
    [, $fl] = $run('contract_update', $hr, [], ['id' => $c1['id'], 'end_date' => $d(20), 'signed_date' => $d(-305), 'position' => 'ignored']);
    $c1 = $q()->query("SELECT * FROM employment_contracts WHERE id = {$c1['id']}")->fetch();
    ok('active contract: end date corrected, other fields locked', $c1['end_date'] === $d(20) && $c1['position'] === 'Sales Senior');
    $t1 = $todo('contract_expiring', (int) $c1['id']);
    ok('ACCEPTANCE 1: PKWT ending in 20 days → 合同即将到期 todo, due on the end date', $t1 !== null && $t1['status'] === 'open' && $t1['due_date'] === $d(20));
    [$h] = $run('index', $hr);
    ok('home shows it with a link to the expiry page', str_contains($h, t('hrtodo_contract_expiring')) && str_contains($h, 'hr.contract_decide'));
    [$h] = $run('contracts', $hr, ['within' => '30']);
    [$h60] = $run('contracts', $hr, ['within' => '60', 'type' => 'PKWTT']);
    ok('list: 30-day filter, days left', str_contains($h, $no1) && str_contains($h, '>20<') && !str_contains($h60, $no1));
    [$h] = $run('employees', $hr);
    ok('employee list: contract end date with days left', str_contains($h, Hr::dmy($d(20))) && str_contains($h, '(20)'));

    // who sees contracts
    [$h] = $run('contract', $budi, ['id' => $c1['id']]);
    ok('dept head: own department\'s contract, no salary', str_contains($h, $no1) && !str_contains($h, '7.000.000'));
    [$h] = $run('contract', $tania, ['id' => $c1['id']]);
    ok('总经理: contract without salary, no edit buttons', str_contains($h, $no1) && !str_contains($h, '7.000.000') && !str_contains($h, 'hr.contract_decide'));
    [$h] = $run('contract', $hr, ['id' => $c1['id']]);
    ok('HR: salary shown', str_contains($h, 'Rp 7.000.000'));
    [, $fl] = $run('contract', $liu, ['id' => $c1['id']]);
    ok('another employee: refused', $isErr($fl, t('hr_msg_not_yours')));
    [$h] = $run('contracts', $liu);
    ok('another employee: list does not show it', !str_contains($h, $no1));
    [$h] = $run('contract_file', $hr, ['id' => $c1['id']]);
    [$h2, $fl] = $run('contract_file', $liu, ['id' => $c1['id']]);
    ok('scan: through the checked endpoint only', str_contains($h, 'Kontrak') && !str_contains($h2, '%PDF'));

    // past the end, undecided → expired + red todo
    $run('contract_update', $hr, [], ['id' => $c1['id'], 'end_date' => $d(-1)]);
    $c1 = $q()->query("SELECT * FROM employment_contracts WHERE id = {$c1['id']}")->fetch();
    ok('after the end date: status expired', $c1['status'] === 'expired');
    ok('合同已到期未处理 todo', ($todo('contract_expired', (int) $c1['id'])['status'] ?? '') === 'open');
    [$h] = $run('index', $hr);
    ok('…shown red', (bool) preg_match('/hr-overdue[^>]*>\s*<td[^>]*>[^<]*' . preg_quote(Hr::dmy($d(-1)), '/') . '/', $h) && str_contains($h, t('hrtodo_contract_expired')));

    // ── acceptance 2: renew once → chain + renewal_count = 1 ──
    [$h] = $run('contract_decide', $hr, ['id' => $c1['id']]);
    ok('expiry page: three choices, no legal-limit warning yet', str_contains($h, 'hr.contract_renew') && str_contains($h, 'hr.contract_convert') && str_contains($h, 'hr.contract_terminate') && str_contains($h, 'id="hrc-limit" style="display:none"'));
    [, $fl] = $run('contract_renew', $hr, [], ['id' => $c1['id'], 'end_date' => $d(200), 'position' => 'Sales Senior', 'work_location' => 'Bandung', 'salary' => '7500000']);
    $c3 = $last();
    ok('ACCEPTANCE 2: renewal chains to the old contract, renewal_count = 1, starts the day after', $isOk($fl) && (int) $c3['previous_contract_id'] === (int) $c1['id']
        && (int) $c3['renewal_count'] === 1 && $c3['start_date'] === $d(0) && $c3['contract_type'] === 'PKWT' && $c3['status'] === 'draft');
    ok('renewal: the expiry todos are closed', ($todo('contract_expired', (int) $c1['id'])['status'] ?? '') === 'done' && ($todo('contract_expiring', (int) $c1['id'])['status'] ?? '') === 'done');
    ok('renewal: not decidable twice', $isErr($run('contract_renew', $hr, [], ['id' => $c1['id'], 'end_date' => $d(300)])[1], t('hrc_err_not_decidable')));
    $run('contract_upload', $hr, [], ['id' => $c3['id']], $scan());
    [, $fl] = $run('contract_activate', $hr, [], ['id' => $c3['id']]);
    ok('renewal activated (old one expired, no overlap)', $q()->query("SELECT status FROM employment_contracts WHERE id = {$c3['id']}")->fetchColumn() === 'active');
    ok('renewal: new salary synced', Hr::decrypt($q()->query("SELECT base_salary_enc FROM employees WHERE id = {$rid}")->fetchColumn()) === '7500000');
    [$h] = $run('show', $hr, ['id' => $rid, 'tab' => 'contracts']);
    ok('employee page: contracts newest first, current one on top', str_contains($h, t('hrc_current')) && strpos($h, (string) $c3['contract_no']) < strpos($h, $no1));

    // legal-limit hint after two renewals (never blocks)
    $p2 = $q();
    $p2->prepare('UPDATE employment_contracts SET renewal_count = 2 WHERE id = ?')->execute([$c3['id']]);
    [$h] = $run('contract_decide', $hr, ['id' => $c3['id']]);
    ok('limit: red hint on the expiry page after 2 renewals', str_contains($h, 'id="hrc-limit" style=""') && str_contains($h, e(t('hrc_limit_warning'))));
    $p2->prepare('UPDATE employment_contracts SET renewal_count = 1 WHERE id = ?')->execute([$c3['id']]);

    // convert to PKWTT (Budi)
    $bid = (int) $budi['employee_id'];
    $run('contract_store', $hr, [], ['employee_id' => $bid, 'start_date' => $d(-100), 'end_date' => $d(10)] + $pk);
    $b1 = $last();
    $run('contract_upload', $hr, [], ['id' => $b1['id']], $scan());
    $run('contract_activate', $hr, [], ['id' => $b1['id']]);
    ok('Budi: 10 days left → reminder', $todo('contract_expiring', (int) $b1['id']) !== null);
    [, $fl] = $run('contract_convert', $hr, [], ['id' => $b1['id'], 'start_date' => $d(11), 'position' => 'Sales Lead', 'work_location' => 'Jakarta']);
    $b2 = $last();
    ok('convert: new PKWTT draft chained, old one 已转 PKWTT', $isOk($fl) && $b2['contract_type'] === 'PKWTT' && $b2['end_date'] === null && (int) $b2['previous_contract_id'] === (int) $b1['id']
        && $q()->query("SELECT status FROM employment_contracts WHERE id = {$b1['id']}")->fetchColumn() === 'converted');
    ok('convert: reminder closed', ($todo('contract_expiring', (int) $b1['id'])['status'] ?? '') === 'done');

    // probation reminder 7 days before (Liu, PKWTT with 3 months probation ending in 4 days)
    $lid = (int) $liu['employee_id'];
    $pStart = $d(-86);
    while (HrContract::months_end($pStart, 3) > $d(4)) {   // probation (3 months) ending in ≤ 4 days
        $pStart = HrContract::add_days($pStart, -1);
    }
    $run('contract_store', $hr, [], ['employee_id' => $lid, 'contract_type' => 'PKWTT', 'is_probation' => '1', 'probation_months' => '3', 'start_date' => $pStart, 'position' => 'Gudang', 'work_location' => 'Jakarta']);
    $l1 = $last();
    $run('contract_upload', $hr, [], ['id' => $l1['id']], $scan());
    $run('contract_activate', $hr, [], ['id' => $l1['id']]);
    $pe = HrContract::probation_end($l1);
    [$h] = $run('contract', $hr, ['id' => $l1['id']]);
    ok('probation end kept as data on the contract, no todo (收尾)', $pe >= $T && $pe <= $d(7) && $todo('probation_end', (int) $l1['id']) === null && str_contains($h, Hr::dmy($pe)), "{$pStart} → {$pe}");

    // historical contract: past dates → straight to expired, no reminder (a later contract exists)
    $run('contract_store', $hr, [], ['employee_id' => $lid, 'contract_type' => 'PKWT', 'start_date' => '2023-01-02', 'end_date' => '2023-12-31', 'position' => 'Gudang', 'work_location' => 'Jakarta']);
    $l0 = $last();
    $run('contract_upload', $hr, [], ['id' => $l0['id']], $scan());
    [, $fl] = $run('contract_activate', $hr, [], ['id' => $l0['id']]);
    ok('historical: entered as 已到期', $isOk($fl) && $q()->query("SELECT status FROM employment_contracts WHERE id = {$l0['id']}")->fetchColumn() === 'expired');
    ok('historical: number from its own start date', str_starts_with((string) $l0['contract_no'], 'KK/AMI/01/2023/'));
    ok('historical: no "expired" todo (a later contract exists)', $todo('contract_expired', (int) $l0['id']) === null);
    ok('historical: does not overwrite the employee record', $q()->query("SELECT position FROM employees WHERE id = {$lid}")->fetchColumn() === 'Gudang');

    // ── acceptance 3: terminate → employee resigned ──
    [, $fl] = $run('contract_terminate', $hr, [], ['id' => $c3['id'], 'termination_date' => $T]);
    ok('terminate: reason required', $isErr($fl, t('hrc_err_terminate_req')));
    [, $fl] = $run('contract_terminate', $hr, [], ['id' => $c3['id'], 'termination_date' => $T, 'termination_reason' => 'Mengundurkan diri']);
    $r = $q()->query("SELECT * FROM employees WHERE id = {$rid}")->fetch();
    ok('ACCEPTANCE 3: contract terminated → employee resigned', $q()->query("SELECT status FROM employment_contracts WHERE id = {$c3['id']}")->fetchColumn() === 'terminated'
        && $r['employment_status'] === 'resigned' && $r['resign_date'] === $T && $r['resign_reason'] === 'Mengundurkan diri');
    ok('terminate: CRM account deactivated', (int) $q()->query('SELECT active FROM users WHERE id = 4')->fetchColumn() === 0);
    $rt = $q()->query("SELECT type, status FROM hr_todos WHERE employee_id = {$rid} AND ref LIKE 'resign:%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('terminate: only the BPJS 停保 todo is raised', $rt === ['bpjs_stop' => 'open'], json_encode($rt));
    ok('terminate: audited', (int) $q()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'terminate'")->fetchColumn() >= 1 && (int) $q()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'resign'")->fetchColumn() >= 1);
    ok('nothing deleted', (int) $q()->query('SELECT COUNT(*) FROM employment_contracts')->fetchColumn() === 7);

    // resigned via HR-01 first, then the contract is terminated: no second resignation
    $run('resign', $hr, [], ['id' => $lid, 'resign_date' => $T, 'resign_reason' => 'Pindah']);
    $l1 = $q()->query("SELECT * FROM employment_contracts WHERE id = {$l1['id']}")->fetch();
    ok('resignation (HR-01) terminates the active contract with its date and reason', $l1['status'] === 'terminated' && $l1['termination_date'] === $T && $l1['termination_reason'] === 'Pindah'
        && $q()->query("SELECT status FROM employment_contracts WHERE id = {$l0['id']}")->fetchColumn() === 'expired');
    ok('…audited as terminate', str_contains((string) $q()->query("SELECT GROUP_CONCAT(detail, ' | ') FROM audit_log WHERE action = 'terminate' AND entity_id = {$l1['id']}")->fetchColumn(), 'employment_contracts.status: active → terminated'));
    [, $fl] = $run('contract_terminate', $hr, [], ['id' => $l1['id'], 'termination_date' => $T, 'termination_reason' => 'Pindah']);
    ok('already terminated: no second termination, still one resignation', $isErr($fl, t('hrc_err_locked'))
        && (int) $q()->query("SELECT COUNT(*) FROM employee_changes WHERE employee_id = {$lid} AND new_value = 'resigned'")->fetchColumn() === 1);

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
