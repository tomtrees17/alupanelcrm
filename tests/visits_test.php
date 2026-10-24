<?php

// ── 拜访记录 HR-04 (v2 part: form, prospects, report, assignment) ──
// Numbering, distance, weeks, the prospect import, and the manual form path
// through the real controller: location optional, back-fill ≤ 7 days, a
// customer visit = a follow-up, 24 h edit window, who sees what, and the weekly
// export with exactly the 回收表 columns.

$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$T = date('Y-m-d');
$d = fn(int $n) => HrContract::add_days($T, $n);

// ── pure helpers ──
ok('distance: Monas → Bundaran HI ≈ 2.2 km', abs(Visits::meters(-6.1754, 106.8272, -6.1950, 106.8230) - 2220) < 80);
ok('distance flag: ≤ 1 km ok, beyond far, missing point none', Visits::distance(['lat' => -6.2, 'lng' => 106.8], ['lat' => -6.2045, 'lng' => 106.8])[1] === 'ok'
    && Visits::distance(['lat' => -6.2, 'lng' => 106.8], ['lat' => -6.21, 'lng' => 106.8])[1] === 'far' && Visits::distance(null, ['lat' => 0, 'lng' => 0]) === [null, 'none']);
ok('weeks: Monday to Sunday', Visits::week_bounds('2026-W43') === ['2026-10-19', '2026-10-25'] && Visits::week_of('2026-10-25') === '2026-W43' && Visits::week_of('2026-10-26') === '2026-W44'
    && Visits::week_bounds('2026-W99') === null);
ok('outcomes: the Excel four + Order; unknown has a label but is not offered', array_values(Visits::OUTCOMES) === ['Berminat', 'Sudah Penawaran', 'Order', 'Tidak Butuh', 'Tidak Terhubung']
    && !isset(Visits::OUTCOMES[Visits::UNKNOWN]) && Visits::outcome_label(Visits::UNKNOWN) !== Visits::UNKNOWN);
ok('report columns = the Daftar Calon Pelanggan sheet without its system column', Visits::REPORT_HEADERS === ['Nama Perusahaan', 'Kategori', 'Wilayah', 'Skor Prioritas', 'Telepon',
    'Rating', 'Jml Ulasan', 'Sudah Pelanggan', 'Alamat', 'Link Maps', 'Lat', 'Lng', 'Sales PIC', 'Hasil Follow-up', 'Tanggal Kunjungan', 'Catatan']);

// ── a company ──
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$db = tempnam(sys_get_temp_dir(), 'vis');
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$pw = password_hash('Rahasia#2026', PASSWORD_DEFAULT);
$ins = $p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)');
foreach ([[1, 'Administrator', 'admin@t', 'admin'], [2, 'Hana HR', 'hana@t', 'hr'], [3, 'tania', 'tania@t', 'manager'],
          [4, 'ridwan', 'ridwan@t', 'sales'], [5, 'angga', 'angga@t', 'sales'], [6, 'liuxian', 'liu@t', 'warehouse']] as [$id, $n, $m, $r]) {
    $ins->execute([$id, $n, $m, $pw, $r]);
}
foreach (default_permissions() as $role => $mods) {
    foreach ($mods as $mod) {
        $p->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
    }
}
Hr::migrate_users($p);
$emp = fn(string $userName) => (int) $p->query('SELECT employee_id FROM users WHERE name = ' . $p->quote($userName))->fetchColumn();
ok('migration: sales staff are in visit mode, others not', $p->query("SELECT attendance_mode FROM employees WHERE id = {$emp('ridwan')}")->fetchColumn() === 'visit'
    && $p->query("SELECT attendance_mode FROM employees WHERE id = {$emp('liuxian')}")->fetchColumn() === null);
$p->prepare('UPDATE employees SET full_name = ? WHERE id = ?')->execute(['Ridwan Saputra', $emp('ridwan')]);
$u = fn(int $id, string $name, string $role, ?int $empId) => ['id' => $id, 'name' => $name, 'role' => $role, 'employee_id' => $empId];
$admin = $u(1, 'Administrator', 'admin', null);
$tania = $u(3, 'tania', 'manager', $emp('tania'));
$ridwan = $u(4, 'ridwan', 'sales', $emp('ridwan'));
$angga = $u(5, 'angga', 'sales', $emp('angga'));
$liu = $u(6, 'liuxian', 'warehouse', $emp('liuxian'));
$p->exec("INSERT INTO customers (id, name, company, owner, city, phone) VALUES (10, 'Toko Kaca Jaya', 'CV Kaca Jaya', 'ridwan', 'Jakarta Utara', '0812-1'), (11, 'Sinar Reklame', NULL, 'angga', 'Bekasi', NULL)");
$p->exec("INSERT INTO orders (order_no, customer_id, customer_name, status, delivery_lat, delivery_lng) VALUES ('1001', 10, 'Toko Kaca Jaya', 'approved', -6.1380, 106.8740)");

// ── prospect import (a workbook shaped like the real list, written and read back) ──
$xl = tempnam(sys_get_temp_dir(), 'pr') . '.xlsx';
$hdr = ['Nama Perusahaan', 'Kategori', 'Wilayah', 'Skor Prioritas', 'Telepon', 'Rating', 'Jml Ulasan', 'Sudah Pelanggan', 'Alamat', 'Link Maps', 'Lat', 'Lng', 'Sales PIC', 'Hasil Follow-up', 'Tanggal Kunjungan', 'Catatan', 'Cek Data (sistem)'];
$semua = [
    ['Abadi Glass', 'Toko Aluminium & Kaca', 'Jakarta Utara', 88.0, '+62 812-0000-1111', 4.8, 120, '', 'Jl. Danau Sunter No.1', 'Buka Maps', -6.1376, 106.8745, 'Ridwan Saputra', '', '', '', ''],
    ['Mitra Laser', 'Advertising / Reklame', 'Jakarta Barat', 70.0, '+62 812-0000-2222', 4.6, 80, '', 'Jl. Meruya Utara', 'Buka Maps', -6.1940, 106.7280, 'Pak Angga', 'Berminat', '20/10', 'pakai Seven', ''],
    ['Karya Plafon', 'Kontraktor', 'Bekasi', 50.0, '', 4.9, 60, 'Ya', 'Jl. Rambutan III', 'Buka Maps', -6.2539, 106.9342, '', '', '', '', ''],
    ['Nusa Alumunium', 'Toko Aluminium & Kaca', 'Surabaya', 95.0, '+62 31 000', 5.0, 300, '', 'Jl. Wonorejo', 'Buka Maps', -7.3160, 112.7976, 'Orang Lain', '', '', '', ''],
    ['Abadi Glass', 'Toko Aluminium & Kaca', 'Jakarta Utara', 88.0, '+62 812-0000-1111', 4.8, 120, '', 'Jl. Danau Sunter No.1', 'Buka Maps', -6.1376, 106.8745, 'Ridwan Saputra', '', '', '', ''],
];
$zip = new ZipArchive();
$zip->open($xl, ZipArchive::CREATE | ZipArchive::OVERWRITE);
xlsx_build_book($zip, [['Petunjuk', ['DAFTAR CALON PELANGGAN'], [['…']]], ['Semua', $hdr, $semua], ['Jakarta Utara', $hdr, [$semua[0]]]]);
$zip->close();
$rows = SalesHistory::read_xlsx($xl, 'Semua');
$dry = Visits::import_prospects($p, $rows, false, 'daftar.xlsx', 'test');
ok('import dry run: counts, nothing written', $dry['rows'] === 5 && $dry['new'] === 4 && $dry['skipped'] === 1 && $dry['customers'] === 1
    && (int) $p->query('SELECT COUNT(*) FROM prospects')->fetchColumn() === 0, json_encode($dry));
ok('import: unknown Sales PIC named, filled 回收表 rows counted', $dry['unknown_pic'] === ['Pak Angga', 'Orang Lain'] && $dry['filled_results'] === 1 && $dry['missing_columns'] === []);
$res = Visits::import_prospects($p, $rows, true, 'daftar.xlsx', 'test', ['pak angga' => 'angga']);
$pr = fn(string $name) => $p->query('SELECT * FROM prospects WHERE name = ' . $p->quote($name))->fetch();
ok('import apply: 4 prospects; PIC by employee name or alias', $res['new'] === 4 && (int) $pr('Abadi Glass')['assigned_to'] === $emp('ridwan') && (int) $pr('Mitra Laser')['assigned_to'] === $emp('angga')
    && $pr('Nusa Alumunium')['assigned_to'] === null && (float) $pr('Abadi Glass')['lat'] === -6.1376);
ok('import: Sudah Pelanggan kept and flagged', (int) $pr('Karya Plafon')['is_customer'] === 1);
$p->exec("UPDATE prospects SET assigned_to = NULL WHERE name = 'Abadi Glass'");
$again = Visits::import_prospects($p, $rows, true, 'daftar-v2.xlsx', 'test');
ok('re-import: no duplicates, attributes updated, an empty PIC filled again', $again['new'] === 0 && $again['updated'] === 4 && (int) $p->query('SELECT COUNT(*) FROM prospects')->fetchColumn() === 4
    && (int) $pr('Abadi Glass')['assigned_to'] === $emp('ridwan') && $pr('Abadi Glass')['source'] === 'daftar-v2.xlsx');
ok('import: a sheet without the name column is refused', Visits::import_prospects($p, [['Foo', 'Bar'], ['x', 'y']], false, 'x', 'test')['missing_columns'] === ['name', 'lat', 'lng']);
@unlink($xl);
$abadi = (int) $pr('Abadi Glass')['id'];
$mitra = (int) $pr('Mitra Laser')['id'];
$karya = (int) $pr('Karya Plafon')['id'];

// search (in-process)
$GLOBALS['permissions'] = [];
foreach ($p->query('SELECT role, module FROM role_permissions') as $r) {
    $GLOBALS['permissions'][$r['role']][] = $r['module'];
}
$GLOBALS['auth'] = new AuthStub($ridwan);
$meR = Visits::employee_of($p, $ridwan);
$s0 = Visits::search($p, $ridwan, $meR, '');
ok('search, empty: my assigned prospects', array_column($s0, 'name') === ['Abadi Glass']);
$s1 = Visits::search($p, $ridwan, $meR, 'kaca');
ok('search: my customers first, never another salesperson\'s customer', ($s1[0]['type'] ?? '') === 'customer' && $s1[0]['name'] === 'Toko Kaca Jaya' && !in_array('Sinar Reklame', array_column(Visits::search($p, $ridwan, $meR, 'sinar'), 'name'), true));
ok('search: prospects already customers are never offered', !in_array('Karya Plafon', array_column(Visits::search($p, $ridwan, $meR, 'karya'), 'name'), true));
ok('visit numbers per month of saving', Visits::next_no($p, '2026-10-24') === 'KV-2610-0001');
ok('managers see all, sales not', !Visits::sees_all() && (function () use ($tania) { $GLOBALS['auth'] = new AuthStub($tania); return Visits::sees_all(); })());
$GLOBALS['auth'] = $savedAuth;
$GLOBALS['permissions'] = $savedPerms;
$p = null;

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $root = sys_get_temp_dir() . '/vis-files-' . bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    $cmd = function (string $action, array $user, array $get, ?array $post, array $env) use ($db, $harness, $root): string {
        $env += ['TEST_VISIT_PHOTO_ROOT' => $root];
        $args = [PHP_BINARY, $harness, $db, 'visits', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $envs = '';
        foreach ($env as $k => $val) {
            $envs .= $k . '=' . escapeshellarg($val) . ' ';
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
    $last = fn() => $q()->query('SELECT * FROM visits ORDER BY id DESC LIMIT 1')->fetch();

    // ── the form (补录): who → result → save ──
    [, $fl] = $run('new', $liu);
    ok('warehouse (not visit mode) cannot record', $isErr($fl, t('vis_msg_not_visit_mode')));
    [$h] = $run('new', $ridwan);
    ok('form: five result buttons; location read only when already allowed', substr_count($h, 'name="outcome"') === 5 && str_contains($h, "p.state !== 'granted'"));
    [, $fl] = $run('store', $ridwan, [], ['target_type' => 'prospect', 'target_id' => $abadi, 'q' => 'Abadi Glass', 'outcome' => 'berminat', 'lat' => '-6.1380', 'lng' => '106.8750', 'accuracy' => '12']);
    $v1 = $last();
    ok('prospect visit with location: saved, numbered, distance ok', ($fl[0]['type'] ?? '') === 'success' && $v1['visit_no'] === 'KV-' . date('ym') . '-0001' && $v1['target_type'] === 'prospect'
        && $v1['distance_flag'] === 'ok' && (int) $v1['distance_m'] < 100 && (float) $v1['accuracy'] === 12.0, json_encode($fl));
    $pp = $q()->query("SELECT * FROM prospects WHERE id = {$abadi}")->fetch();
    ok('prospect record follows: last visit, result, count', $pp['last_outcome'] === 'berminat' && (int) $pp['visit_count'] === 1 && substr((string) $pp['last_visit_at'], 0, 10) === $T);
    $run('store', $ridwan, [], ['target_type' => 'prospect', 'target_id' => $mitra, 'q' => 'Mitra Laser', 'outcome' => 'tidak_terhubung', 'lat' => '-6.10', 'lng' => '106.70']);
    ok('far from the target → flagged far, still saved', $last()['distance_flag'] === 'far');
    [, $fl] = $run('store', $ridwan, [], ['target_type' => 'customer', 'target_id' => 10, 'q' => 'Toko Kaca Jaya', 'outcome' => 'tidak_butuh', 'current_supplier' => 'Seven', 'current_price' => '180rb', 'note' => 'stok penuh']);
    $v3 = $last();
    ok('no location (browser never allowed it): saved, distance none', ($fl[0]['type'] ?? '') === 'success' && $v3['lat'] === null && $v3['distance_flag'] === 'none');
    $fu = $q()->query("SELECT * FROM customer_followups WHERE id = " . (int) $v3['followup_id'])->fetch();
    ok('customer visit = a follow-up: Tidak Butuh is a "no need" with supplier and price', $fu && $fu['kind'] === 'no_need' && $fu['customer_id'] == 10
        && str_contains($fu['note'], 'Supplier: Seven') && str_contains($fu['note'], 'Harga: 180rb') && str_contains($fu['note'], $v3['visit_no']));
    ok('customer last contact = visit day', $q()->query('SELECT last_contact FROM customers WHERE id = 10')->fetchColumn() === $T);
    $run('store', $ridwan, [], ['q' => 'Toko Baru Sejahtera', 'outcome' => 'order', 'visit_date' => $d(-3), 'lat' => '-6.1', 'lng' => '106.8']);
    $v4 = $last();
    ok('new store typed in, Order, back-filled 3 days: no distance', $v4['target_type'] === 'new' && $v4['new_customer_name'] === 'Toko Baru Sejahtera' && $v4['outcome'] === 'order'
        && substr($v4['visited_at'], 0, 10) === $d(-3) && $v4['distance_flag'] === 'none' && $v4['lat'] === null);
    [, $fl] = $run('store', $ridwan, [], ['q' => 'X', 'outcome' => 'order', 'visit_date' => $d(-8)]);
    ok('back-fill beyond 7 days refused', $isErr($fl, sprintf(t('vis_err_date'), 7)));
    [, $fl] = $run('store', $ridwan, [], ['q' => 'X', 'outcome' => 'order', 'visit_date' => $d(1)]);
    ok('future date refused', $isErr($fl, sprintf(t('vis_err_date'), 7)));
    [, $fl] = $run('store', $ridwan, [], ['q' => '', 'outcome' => 'order']);
    ok('nobody picked and no name typed → refused', $isErr($fl, t('vis_err_target')));
    [, $fl] = $run('store', $ridwan, [], ['target_type' => 'prospect', 'target_id' => $karya, 'q' => 'Karya Plafon', 'outcome' => 'berminat']);
    ok('a prospect that is already a customer cannot be picked (kept as a typed name)', $last()['target_type'] === 'new');
    $img = tempnam(sys_get_temp_dir(), 'ph');
    file_put_contents($img, "\xFF\xD8\xFF\xE0" . str_repeat('x', 64));
    $run('store', $ridwan, [], ['target_type' => 'customer', 'target_id' => 10, 'q' => 'Toko Kaca Jaya', 'outcome' => 'penawaran'],
        ['TEST_FILES' => json_encode(['photo' => ['name' => 'toko.jpg', 'tmp_name' => $img, 'size' => 68, 'error' => 0]])]);
    $v6 = $last();
    ok('optional photo stored privately', $v6['photo_path'] && is_file("{$root}/{$ridwan['employee_id']}/{$v6['photo_path']}"));
    [$h] = $run('photo', $tania, ['id' => $v6['id']]);
    [$h2, $fl] = $run('photo', $angga, ['id' => $v6['id']]);
    ok('photo: managers and the owner only', str_contains($h, 'xxxx') && !str_contains($h2, 'xxxx') && $isErr($fl, t('vis_msg_not_yours')));

    // ── 24 h edit window ──
    [, $fl] = $run('update', $ridwan, [], ['id' => $v3['id'], 'outcome' => 'berminat', 'note' => 'ternyata minat']);
    $fu = $q()->query("SELECT * FROM customer_followups WHERE id = " . (int) $v3['followup_id'])->fetch();
    ok('edit within 24 h: result changes, the same follow-up row follows (now a follow-up)', $q()->query("SELECT outcome FROM visits WHERE id = {$v3['id']}")->fetchColumn() === 'berminat'
        && $fu['kind'] === 'followup' && str_contains($fu['note'], 'ternyata minat') && (int) $q()->query('SELECT COUNT(*) FROM customer_followups')->fetchColumn() === 2);
    [, $fl] = $run('update', $angga, [], ['id' => $v3['id'], 'outcome' => 'order']);
    ok('someone else\'s visit: refused', $isErr($fl, t('vis_msg_not_yours')));
    $q()->exec("UPDATE visits SET created_at = datetime('now','localtime','-25 hours') WHERE id = {$v1['id']}");
    [, $fl] = $run('update', $ridwan, [], ['id' => $v1['id'], 'outcome' => 'order']);
    ok('after 24 h: locked', $isErr($fl, t('vis_msg_locked')) && $q()->query("SELECT outcome FROM visits WHERE id = {$v1['id']}")->fetchColumn() === 'berminat');
    ok('edits audited', str_contains((string) $q()->query("SELECT GROUP_CONCAT(detail, ' | ') FROM audit_log WHERE module = 'visits' AND action = 'update'")->fetchColumn(), 'visits.outcome: tidak_butuh → berminat'));
    $run('store', $angga, [], ['target_type' => 'customer', 'target_id' => 11, 'q' => 'Sinar Reklame', 'outcome' => 'berminat']);

    // ── who sees what ──
    [$h] = $run('index', $ridwan);
    ok('sales: own visits only', str_contains($h, 'Abadi Glass') && !str_contains($h, 'Sinar Reklame'));
    [, $fl] = $run('list', $ridwan);
    ok('sales: no management list', $isErr($fl, t('vis_msg_managers_only')));
    [$h] = $run('list', $tania);
    ok('manager: everyone, far rows faded', str_contains($h, 'Sinar Reklame') && str_contains($h, 'Abadi Glass') && str_contains($h, 'vis-far'));

    // ── weekly report + export ──
    $week = Visits::week_of($T);
    [$h] = $run('report', $tania, ['week' => $week]);
    ok('report: per salesperson, Order column', str_contains($h, 'Ridwan Saputra') && str_contains($h, '>Order<'));
    $raw = (string) shell_exec($cmd('export', $tania, ['week' => $week], null, []));
    $bin = substr($raw, 0, (int) strrpos($raw, "\n<!--FLASH:"));
    $f = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
    file_put_contents($f, $bin);
    $first = class_exists('ZipArchive') ? SalesHistory::read_xlsx($f, 'Kunjungan') : [];
    $sum = class_exists('ZipArchive') ? SalesHistory::read_xlsx($f, 'Ringkasan') : [];
    @unlink($f);
    ok('export: first sheet columns exactly the 回收表', ($first[0] ?? []) === Visits::REPORT_HEADERS, json_encode($first[0] ?? []));
    $abadiRow = current(array_filter($first, fn($r) => ($r[0] ?? '') === 'Abadi Glass')) ?: [];
    ok('export: a prospect row carries the list data, PIC, result, date', ($abadiRow[1] ?? '') === 'Toko Aluminium & Kaca' && ($abadiRow[12] ?? '') === 'Ridwan Saputra'
        && ($abadiRow[13] ?? '') === 'Berminat' && ($abadiRow[14] ?? '') === Hr::dmy($T) && (float) ($abadiRow[10] ?? 0) === -6.1376);
    $ridSum = current(array_filter($sum, fn($r) => ($r[0] ?? '') === 'Ridwan Saputra')) ?: [];
    ok('export: summary sheet, visits and new customers', ($sum[0][0] ?? '') === 'Sales' && (int) ($ridSum[1] ?? 0) >= 5 && (int) end($ridSum) >= 1);

    // ── assignment ──
    [, $fl] = $run('assign', $ridwan, [], ['ids' => [$mitra], 'employee_id' => $ridwan['employee_id']]);
    ok('sales cannot assign', $isErr($fl, t('vis_msg_managers_only')));
    $nusa = (int) $q()->query("SELECT id FROM prospects WHERE name = 'Nusa Alumunium'")->fetchColumn();
    $run('assign', $tania, [], ['ids' => [$nusa, $karya], 'employee_id' => $angga['employee_id']]);
    ok('manager assigns in one go (an already-customer row is skipped by the page, still allowed here)', (int) $q()->query("SELECT assigned_to FROM prospects WHERE id = {$nusa}")->fetchColumn() === $angga['employee_id']
        && str_contains((string) $q()->query("SELECT GROUP_CONCAT(detail, ' | ') FROM audit_log WHERE action = 'assign'")->fetchColumn(), 'prospects.assigned_to: (空) → '));
    [$h] = $run('prospects', $tania, ['assigned' => 'none']);
    ok('prospect page: filter unassigned (none left)', str_contains($h, t('vis_no_prospects')) && !str_contains($h, 'Nusa Alumunium'));
    [$h] = $run('map', $tania, ['week' => $week]);
    ok('map: located visits drawn, coloured by salesperson', substr_count($h, 'class="vis-pt vis-c') >= 2);

    foreach (glob("{$root}/*/*") ?: [] as $ff) {
        @unlink($ff);
    }
    foreach (glob("{$root}/*") ?: [] as $dd) {
        @rmdir($dd);
    }
    @rmdir($root);
}
@unlink($db);
