<?php
declare(strict_types=1);

/**
 * 拜访 / Kunjungan — HR-04 (docs/任务卡-拜访签到.md v2; decisions in app/Visits.php).
 * Sales: one form like a row of the Excel 回收表, own list, edit within 24 h.
 * Boss / 'visits_all': list, weekly report (+ export), map, prospects and
 * their assignment — read only except the assignment.
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 * @var array  $config
 */

$user = $auth->user();
$me = Visits::employee_of($pdo, $user);
$canRecord = Visits::can_record($me);
$all = Visits::sees_all();
$today = date('Y-m-d');
$operator = own_name();

$requireRecorder = function () use ($canRecord): void {
    if (!$canRecord) {
        http_response_code(403);
        flash(t('vis_msg_not_visit_mode'), 'error');
        redirect('dashboard.index');
    }
};
$canImport = $auth->isAdmin() || can_access('visits_import');
$canMap = $canImport || (($user['role'] ?? '') === 'hr');
$requireImport = function () use ($canImport): void {
    if (!$canImport) {
        http_response_code(403);
        flash(t('vis_msg_import_only'), 'error');
        redirect('visits.index');
    }
};
$requireAll = function () use ($all): void {
    if (!$all) {
        http_response_code(403);
        flash(t('vis_msg_managers_only'), 'error');
        redirect('visits.index');
    }
};
$findVisit = function (int $id) use ($pdo, $me, $all): array {
    $st = $pdo->prepare('SELECT v.*, c.name customer_name, p.name prospect_name FROM visits v
                          LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN prospects p ON p.id = v.prospect_id WHERE v.id = ?');
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) {
        http_response_code(404);
        exit('Not found');
    }
    if (!$all && (int) $v['employee_id'] !== (int) ($me['id'] ?? 0)) {
        http_response_code(403);
        flash(t('vis_msg_not_yours'), 'error');
        redirect('visits.index');
    }
    return $v;
};
/** Visit day from the form: today by default, back-fill up to 7 days, never the future. */
$visitDate = function () use ($today): ?string {
    $d = trim((string) input('visit_date', '')) ?: $today;
    return Hr::valid_date($d) && $d <= $today && $d >= HrContract::add_days($today, -Visits::BACKDATE_DAYS) ? $d : null;
};
$photoUpload = function (array $emp): array {
    $f = $_FILES['photo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    return Hr::accept_upload($f, (int) $emp['id'], Visits::photo_dir((int) $emp['id']), ['jpg', 'jpeg', 'png']);
};
$weekParam = function () use ($today): array {
    $w = (string) input('week', '');
    $b = Visits::week_bounds($w);
    if (!$b) {
        $w = Visits::week_of($today);
        $b = Visits::week_bounds($w);
    }
    return [$w, $b[0], $b[1]];
};
$sales = fn() => $pdo->query("SELECT id, full_name FROM employees WHERE attendance_mode = 'visit' OR id IN (SELECT DISTINCT employee_id FROM visits) ORDER BY full_name")->fetchAll();

switch ($action) {
    case 'index':
        if (!$canRecord) {
            if ($all) {
                redirect('visits.day');
            }
            if ($canImport) {
                redirect('visits.import');
            }
            $requireRecorder();
        }
        $st = $pdo->prepare('SELECT v.*, c.name customer_name, p.name prospect_name FROM visits v
                              LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN prospects p ON p.id = v.prospect_id
                              WHERE v.employee_id = ? AND date(v.visited_at) >= ? ORDER BY v.visited_at DESC, v.id DESC');
        $st->execute([$me['id'], HrContract::add_days($today, -14)]);
        view('visits.index', ['pageTitle' => t('nav_visits'), 'pageSub' => '', 'rows' => $st->fetchAll(), 'me' => $me, 'all' => $all, 'today' => $today]);
        break;

    case 'new':
        $requireRecorder();
        view('visits.form', ['pageTitle' => t('vis_new'), 'pageSub' => '', 'v' => null, 'today' => $today, 'all' => $all]);
        break;

    case 'search':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($canRecord ? Visits::search($pdo, (array) $user, $me, (string) input('q', '')) : [], JSON_UNESCAPED_UNICODE);
        exit;

    case 'store':
        Csrf::verify();
        $requireRecorder();
        $outcome = (string) input('outcome', '');
        $type = (string) input('target_type', '');
        $tid = (int) input('target_id', 0);
        $name = trim((string) input('q', ''));
        $date = $visitDate();
        $err = [];
        if (!isset(Visits::OUTCOMES[$outcome])) {
            $err[] = t('vis_err_outcome');
        }
        if ($date === null) {
            $err[] = sprintf(t('vis_err_date'), Visits::BACKDATE_DAYS);
        }
        $customerId = $prospectId = null;
        if ($type === 'customer' && $tid) {
            $ok = $pdo->prepare('SELECT 1 FROM customers WHERE id = ?');
            $ok->execute([$tid]);
            $customerId = $ok->fetchColumn() ? $tid : null;
        } elseif ($type === 'prospect' && $tid) {
            $ok = $pdo->prepare('SELECT 1 FROM prospects WHERE id = ? AND is_customer = 0');
            $ok->execute([$tid]);
            $prospectId = $ok->fetchColumn() ? $tid : null;
        }
        $type = $customerId ? 'customer' : ($prospectId ? 'prospect' : 'new');
        if ($type === 'new' && $name === '') {
            $err[] = t('vis_err_target');
        }
        $photo = $photoUpload($me);
        if (isset($photo['error'])) {
            $err[] = t($photo['error']);
        }
        if ($err) {
            flash(implode(' ', $err), 'error');
            $_SESSION['visit_form_old'] = $_POST;
            redirect('visits.new');
        }
        // Location only if the browser sent it (already allowed) and the visit is today.
        $here = null;
        if ($date === $today && is_numeric(input('lat')) && is_numeric(input('lng'))
            && abs((float) input('lat')) <= 90 && abs((float) input('lng')) <= 180) {
            $here = ['lat' => (float) input('lat'), 'lng' => (float) input('lng')];
        }
        $targetId = $customerId ?? $prospectId;
        [$dist, $flag] = Visits::distance($here, Visits::target_point($pdo, $type, $targetId));
        $v = [
            'visit_no' => Visits::next_no($pdo, $today), 'employee_id' => (int) $me['id'], 'visited_at' => $date . ' ' . date('H:i:s'),
            'target_type' => $type, 'customer_id' => $customerId, 'prospect_id' => $prospectId, 'new_customer_name' => $type === 'new' ? mb_substr($name, 0, 200) : null,
            'outcome' => $outcome, 'note' => trim((string) input('note', '')) ?: null,
            'current_supplier' => $outcome === 'tidak_butuh' ? (trim((string) input('current_supplier', '')) ?: null) : null,
            'current_price' => $outcome === 'tidak_butuh' ? (trim((string) input('current_price', '')) ?: null) : null,
            'photo_path' => $photo['stored'] ?? null, 'photo_name' => $photo['name'] ?? null, 'photo_mime' => $photo['mime'] ?? null,
            'lat' => $here['lat'] ?? null, 'lng' => $here['lng'] ?? null,
            'accuracy' => $here && is_numeric(input('accuracy')) ? (float) input('accuracy') : null,
            'distance_m' => $dist, 'distance_flag' => $flag, 'created_by' => $operator,
        ];
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO visits (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
        $vid = (int) $pdo->lastInsertId();
        if ($fid = Visits::link_customer($pdo, $v, $user)) {
            $pdo->prepare('UPDATE visits SET followup_id = ? WHERE id = ?')->execute([$fid, $vid]);
        }
        Visits::refresh_prospect($pdo, $prospectId);
        $pdo->commit();
        audit($pdo, 'visits', 'create', 'visits', $vid, $v['visit_no'],
            sprintf('visits: %s · %s · %s', $v['visit_no'], $v['new_customer_name'] ?? ($type . ' #' . $targetId), Visits::outcome_label($outcome)));
        flash(sprintf(t('vis_msg_saved'), $v['visit_no']));
        redirect('visits.index');
        break;

    // Own visit, within 24 h of saving: outcome, notes, day, photo. The target stays.
    case 'edit':
    case 'update':
        $v = $findVisit((int) input('id', 0));
        if (!Visits::editable($v, $me)) {
            flash(t('vis_msg_locked'), 'error');
            redirect($all && !$canRecord ? 'visits.list' : 'visits.index');
        }
        if ($action === 'edit') {
            view('visits.form', ['pageTitle' => (string) $v['visit_no'], 'pageSub' => '', 'v' => $v, 'today' => $today, 'all' => $all]);
            break;
        }
        Csrf::verify();
        $outcome = (string) input('outcome', '');
        $date = $visitDate();
        $photo = $photoUpload($me);
        if (!isset(Visits::OUTCOMES[$outcome]) || $date === null || isset($photo['error'])) {
            flash(trim((isset(Visits::OUTCOMES[$outcome]) ? '' : t('vis_err_outcome')) . ' ' . ($date === null ? sprintf(t('vis_err_date'), Visits::BACKDATE_DAYS) : '') . ' ' . (isset($photo['error']) ? t($photo['error']) : '')), 'error');
            redirect('visits.edit', ['id' => $v['id']]);
        }
        $d = [
            'outcome' => $outcome, 'note' => trim((string) input('note', '')) ?: null,
            'current_supplier' => $outcome === 'tidak_butuh' ? (trim((string) input('current_supplier', '')) ?: null) : null,
            'current_price' => $outcome === 'tidak_butuh' ? (trim((string) input('current_price', '')) ?: null) : null,
            'visited_at' => $date . ' ' . substr((string) $v['visited_at'], 11, 8),
        ];
        if ($date !== substr((string) $v['created_at'], 0, 10)) {   // moved to another day: the phone was not there
            $d['distance_m'] = null;
            $d['distance_flag'] = 'none';
        }
        if ($photo) {
            $d += ['photo_path' => $photo['stored'], 'photo_name' => $photo['name'], 'photo_mime' => $photo['mime']];
        }
        $d['updated_by'] = $operator;
        $d['updated_at'] = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE visits SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($d))) . ' WHERE id = ?')->execute([...array_values($d), $v['id']]);
        // An imported visit: the salesperson may correct the customer the system matched (v3 §四).
        $newType = (string) input('target_type', '');
        $newId = (int) input('target_id', 0);
        if ($v['source'] === 'wa_import' && in_array($newType, ['customer', 'prospect'], true) && $newId
            && !($newType === $v['target_type'] && $newId === (int) ($v['customer_id'] ?: $v['prospect_id']))) {
            VisitImport::attach($pdo, array_merge($v, $d), $newType, $newId, 'manual', $operator);
            audit($pdo, 'visits', 'update', 'visits', (int) $v['id'], (string) $v['visit_no'], "visits.target: {$newType} #{$newId} (sales)");
            $st = $pdo->prepare('SELECT * FROM visits WHERE id = ?');
            $st->execute([$v['id']]);
            $v = $st->fetch() + $v;
        }
        Visits::link_customer($pdo, array_merge($v, $d), $user);
        Visits::refresh_prospect($pdo, $v['prospect_id'] ? (int) $v['prospect_id'] : null);
        $pdo->commit();
        $audited = array_intersect_key($d, array_flip(['outcome', 'note', 'current_supplier', 'current_price', 'visited_at', 'photo_name']));
        foreach ($audited as $k => $new) {
            if ((string) $v[$k] !== (string) $new) {
                audit($pdo, 'visits', 'update', 'visits', (int) $v['id'], (string) $v['visit_no'], sprintf('visits.%s: %s → %s', $k, (string) ($v[$k] ?? '(空)'), (string) ($new ?? '(空)')));
            }
        }
        flash(t('hr_msg_saved'));
        redirect('visits.index');
        break;

    case 'photo':
        $v = $findVisit((int) input('id', 0));
        if (!$v['photo_path']) {
            http_response_code(404);
            exit('Not found');
        }
        if ($v['source'] === 'wa_import') {
            $photos = array_values(array_filter((array) json_decode((string) $v['photos'], true)));
            $file = $photos[(int) input('n', 0)] ?? $v['photo_path'];
            Hr::send_file(0, basename((string) $file), str_ends_with((string) $file, '.png') ? 'image/png' : 'image/jpeg', basename((string) $file), dirname(VisitImport::photo_file((string) $file)));
        }
        Hr::send_file((int) $v['employee_id'], (string) $v['photo_path'], $v['photo_mime'], (string) $v['photo_name'], Visits::photo_dir((int) $v['employee_id']));
        break;

    // ── management: read only ──
    case 'list':
        $requireAll();
        $f = [
            'employee_id' => (int) input('employee_id', 0),
            'outcome' => (isset(Visits::OUTCOMES[(string) input('outcome', '')]) || input('outcome') === Visits::UNKNOWN) ? (string) input('outcome') : '',
            'from' => Hr::valid_date((string) input('from', '')) ? (string) input('from') : HrContract::add_days($today, -6),
            'to' => Hr::valid_date((string) input('to', '')) ? (string) input('to') : $today,
        ];
        view('visits.list', ['pageTitle' => t('vis_list'), 'pageSub' => '', 'rows' => Visits::fetch($pdo, $f['from'], $f['to'], $f), 'f' => $f, 'sales' => $sales(), 'canRecord' => $canRecord, 'canImport' => $canImport]);
        break;

    case 'report':
    case 'export':
        $requireAll();
        [$week, $from, $to] = $weekParam();
        $rows = array_reverse(Visits::fetch($pdo, $from, $to));
        usort($rows, fn($a, $b) => [$a['sales'], $a['visited_at'], $a['id']] <=> [$b['sales'], $b['visited_at'], $b['id']]);
        $summary = Visits::summary($rows);
        $np = $pdo->prepare("SELECT assigned_to, COUNT(*) n FROM prospects WHERE source = 'visit' AND date(created_at) BETWEEN ? AND ? GROUP BY assigned_to");
        $np->execute([$from, $to]);
        $newProspects = array_column($np->fetchAll(), 'n', 'assigned_to');
        if ($action === 'export') {
            audit($pdo, 'visits', 'export', 'visits', null, "Kunjungan {$week}", count($rows) . ' rows');
            send_workbook("Kunjungan_{$week}", [
                ['Kunjungan', Visits::REPORT_HEADERS, array_map([Visits::class, 'report_row'], $rows)],
                ['Ringkasan', Visits::summary_headers(), Visits::summary_rows($summary, $newProspects)],
            ]);
        }
        $dg = $pdo->prepare('SELECT * FROM visit_digests WHERE week = ? ORDER BY id DESC LIMIT 1');
        $dg->execute([$week]);
        view('visits.report', ['pageTitle' => t('vis_report'), 'pageSub' => $week, 'week' => $week, 'from' => $from, 'to' => $to, 'summary' => $summary, 'count' => count($rows), 'canRecord' => $canRecord,
            'canImport' => $canImport, 'newProspects' => $newProspects, 'digest' => $dg->fetch() ?: null, 'aiOn' => AiTask::enabled($config)]);
        break;

    case 'map':
        $requireAll();
        [$week, $from, $to] = $weekParam();
        $boxes = [
            'jabodetabek' => ['north' => -5.95, 'south' => -6.75, 'west' => 106.45, 'east' => 107.25],   // same box as the customer-analysis map
            'surabaya' => ['north' => -7.10, 'south' => -7.50, 'west' => 112.55, 'east' => 112.95],
            'bandung' => ['north' => -6.80, 'south' => -7.10, 'west' => 107.45, 'east' => 107.80],
        ];
        $area = isset($boxes[(string) input('area', '')]) ? (string) input('area') : 'jabodetabek';
        $order = [];
        $points = [];
        foreach (Visits::fetch($pdo, $from, $to) as $v) {
            if ($v['lat'] === null || $v['lng'] === null) {
                continue;
            }
            $order[(int) $v['employee_id']] ??= ['name' => (string) $v['sales'], 'i' => count($order)];
            $points[] = ['lat' => (float) $v['lat'], 'lng' => (float) $v['lng'], 'sales' => (string) $v['sales'], 'color' => $order[(int) $v['employee_id']]['i'],
                         'label' => Visits::target_name($v) . ' · ' . Visits::outcome_label((string) $v['outcome']) . ' · ' . Hr::dmy(substr((string) $v['visited_at'], 0, 10)), 'far' => $v['distance_flag'] === 'far'];
        }
        $zoom = $area === 'jabodetabek' ? 10 : 11;
        view('visits.map', ['pageTitle' => t('vis_map'), 'pageSub' => $week, 'week' => $week, 'area' => $area, 'areas' => array_keys($boxes),
            'map' => Insights::map_layout($points, $boxes[$area], $zoom), 'legend' => array_values($order), 'total' => count($points), 'canRecord' => $canRecord, 'canImport' => $canImport]);
        break;

    case 'prospects':
        $requireAll();
        $f = [
            'q' => trim((string) input('q', '')), 'region' => trim((string) input('region', '')), 'category' => trim((string) input('category', '')),
            'assigned' => (string) input('assigned', ''),   // '' all | 'none' unassigned | employee id
        ];
        $where = ['1=1'];
        $args = [];
        if ($f['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.address LIKE ? OR p.phone LIKE ?)';
            array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
        }
        foreach (['region', 'category'] as $k) {
            if ($f[$k] !== '') {
                $where[] = "p.{$k} = ?";
                $args[] = $f[$k];
            }
        }
        if ($f['assigned'] === 'none') {
            $where[] = 'p.assigned_to IS NULL AND p.is_customer = 0';
        } elseif ((int) $f['assigned'] > 0) {
            $where[] = 'p.assigned_to = ?';
            $args[] = (int) $f['assigned'];
        }
        $st = $pdo->prepare('SELECT p.*, e.full_name assignee FROM prospects p LEFT JOIN employees e ON e.id = p.assigned_to WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.is_customer, p.priority DESC, p.name LIMIT 500');
        $st->execute($args);
        view('visits.prospects', ['pageTitle' => t('vis_prospects'), 'pageSub' => '', 'rows' => $st->fetchAll(), 'f' => $f, 'sales' => $sales(), 'canRecord' => $canRecord, 'canImport' => $canImport,
            'regions' => $pdo->query("SELECT DISTINCT region FROM prospects WHERE region IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN),
            'categories' => $pdo->query("SELECT DISTINCT category FROM prospects WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN),
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM prospects')->fetchColumn()]);
        break;

    // Management assigns prospects to a salesperson in one go (owner decision).
    case 'assign':
        Csrf::verify();
        $requireAll();
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', []))));
        $to = (int) input('employee_id', 0);
        $back = (string) input('back', '');
        if ($to > 0) {
            $ok = $pdo->prepare("SELECT full_name FROM employees WHERE id = ? AND employment_status <> 'resigned'");
            $ok->execute([$to]);
            $toName = $ok->fetchColumn();
            if ($toName === false) {
                $to = -1;
            }
        }
        if (!$ids || $to < 0) {
            flash(t('vis_err_assign'), 'error');
            header('Location: ' . (str_starts_with($back, 'index.php?r=visits.prospects') ? $back : url('visits.prospects')));
            exit;
        }
        $st = $pdo->prepare('SELECT p.id, p.name, p.assigned_to, e.full_name FROM prospects p LEFT JOIN employees e ON e.id = p.assigned_to WHERE p.id = ?');
        $upd = $pdo->prepare('UPDATE prospects SET assigned_to = ?, assigned_at = ?, assigned_by = ? WHERE id = ?');
        $n = 0;
        foreach ($ids as $pid) {
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p || (int) $p['assigned_to'] === $to) {
                continue;
            }
            $upd->execute([$to ?: null, $to ? date('Y-m-d H:i:s') : null, $to ? $operator : null, $pid]);
            audit($pdo, 'visits', 'assign', 'prospects', $pid, (string) $p['name'], sprintf('prospects.assigned_to: %s → %s', $p['full_name'] ?: '(空)', $to ? $toName : '(空)'));
            $n++;
        }
        flash(sprintf(t('vis_msg_assigned'), $n));
        header('Location: ' . (str_starts_with($back, 'index.php?r=visits.prospects') ? $back : url('visits.prospects')));
        exit;

    // ── v3: WhatsApp group export ──
    case 'import':
        $requireImport();
        if (is_post()) {
            Csrf::verify();
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !preg_match('/\.(zip|txt)$/i', (string) $f['name'])) {
                flash(t('vis_err_import_file'), 'error');
                redirect('visits.import');
            }
            @set_time_limit(300);
            try {
                $up = WaChat::read_upload((string) $f['tmp_name'], (string) $f['name']);
            } catch (Throwable $e) {
                flash(t('vis_err_import_file') . ' ' . $e->getMessage(), 'error');
                redirect('visits.import');
            }
            if ($up['txt'] === null || trim($up['txt']) === '') {
                flash(t('vis_err_import_no_txt'), 'error');
                redirect('visits.import');
            }
            try {
                $res = VisitImport::import($pdo, $config, $up['txt'], $up['files'], (string) $f['name'], $operator);
            } finally {
                WaChat::cleanup($up['tmp']);   // the zip itself is never kept (v3 §二)
            }
            $proc = VisitImport::process($pdo, $config, AiTask::enabled($config) ? (int) VisitImport::cfg($config)['ai_per_run'] : 100000, $operator);
            audit($pdo, 'visits', 'create', 'wa_imports', $res['import_id'], (string) $f['name'],
                sprintf('wa_imports: %d messages, %d visits found, %d new, %d photos, %d ignored, %d unmapped', $res['messages'], $res['found'], $res['new'], $res['photos'], $res['ignored'], count($res['unmapped'])));
            $_SESSION['visit_import_result'] = $res + ['left' => $proc['left']];
            redirect('visits.import');
        }
        $result = $_SESSION['visit_import_result'] ?? null;
        unset($_SESSION['visit_import_result']);
        view('visits.import', ['pageTitle' => t('vis_import'), 'pageSub' => '', 'result' => $result, 'canRecord' => $canRecord, 'canImport' => $canImport,
            'left' => count(VisitImport::todo($pdo, $config, 100000)), 'aiOn' => AiTask::enabled($config),
            'maxUpload' => (string) ini_get('upload_max_filesize'), 'maxPost' => (string) ini_get('post_max_size'),
            'recent' => $pdo->query('SELECT * FROM wa_imports ORDER BY id DESC LIMIT 10')->fetchAll(), 'unmapped' => VisitImport::unmapped($pdo)]);
        break;

    case 'ai_run':
        Csrf::verify();
        if (!$canImport && !$all) {
            $requireImport();
        }
        @set_time_limit(300);
        $proc = VisitImport::process($pdo, $config, AiTask::enabled($config) ? (int) VisitImport::cfg($config)['ai_per_run'] : 100000, $operator);
        flash(sprintf(t('vis_msg_processed'), $proc['done'], $proc['left']));
        redirect(input('back') === 'queue' ? 'visits.queue' : 'visits.import');
        break;

    // Sender ↔ employee: HR (or whoever imports) sets it once.
    case 'senders':
        if (!$canMap) {
            $requireImport();
        }
        if (is_post()) {
            Csrf::verify();
            $sender = trim((string) input('sender', ''));
            $eid = (int) input('employee_id', 0);
            $ok = $pdo->prepare("SELECT 1 FROM employees WHERE id = ? AND employment_status <> 'resigned'");
            $ok->execute([$eid]);
            if ($sender === '' || !$ok->fetchColumn()) {
                flash(t('vis_err_assign'), 'error');
                redirect('visits.senders');
            }
            $n = VisitImport::map_sender($pdo, $sender, $eid, $operator);
            flash(sprintf(t('vis_msg_mapped'), $sender, $n));
            redirect('visits.senders');
        }
        view('visits.senders', ['pageTitle' => t('vis_senders'), 'pageSub' => '', 'unmapped' => VisitImport::unmapped($pdo), 'canRecord' => $canRecord, 'canImport' => $canImport,
            'mapped' => $pdo->query('SELECT w.*, e.full_name FROM employee_wa_ids w JOIN employees e ON e.id = w.employee_id ORDER BY e.full_name, w.wa_display')->fetchAll(),
            'staff' => $pdo->query("SELECT id, full_name FROM employees WHERE employment_status <> 'resigned' ORDER BY full_name")->fetchAll()]);
        break;

    // Supervisor queue: store matches to confirm, AI outcome guesses to adopt.
    case 'queue':
        $requireAll();
        $pending = $pdo->query("SELECT v.*, COALESCE(e.full_name, v.wa_sender) sales FROM visits v LEFT JOIN employees e ON e.id = v.employee_id
                                 WHERE v.match_status = 'pending' ORDER BY v.visited_at DESC LIMIT 200")->fetchAll();
        $guesses = $pdo->query("SELECT v.*, COALESCE(e.full_name, v.wa_sender) sales, c.name customer_name, p.name prospect_name FROM visits v
                                 LEFT JOIN employees e ON e.id = v.employee_id LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN prospects p ON p.id = v.prospect_id
                                 WHERE v.outcome = 'unknown' AND v.ai_outcome_guess IS NOT NULL ORDER BY v.visited_at DESC LIMIT 200")->fetchAll();
        view('visits.queue', ['pageTitle' => t('vis_queue'), 'pageSub' => '', 'pending' => $pending, 'guesses' => $guesses, 'canRecord' => $canRecord, 'canImport' => $canImport,
            'left' => count(VisitImport::todo($pdo, $config, 100000)), 'aiOn' => AiTask::enabled($config)]);
        break;

    case 'confirm':
        Csrf::verify();
        $requireAll();
        $ok = VisitImport::confirm($pdo, (int) input('id', 0), (string) input('choice', ''), $operator);
        flash($ok ? t('hr_msg_saved') : t('vis_err_confirm'), $ok ? 'success' : 'error');
        redirect('visits.queue');
        break;

    case 'adopt':
        Csrf::verify();
        $requireAll();
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', [(int) input('id', 0)]))));
        $n = 0;
        foreach ($ids as $vid) {
            $n += VisitImport::adopt_outcome($pdo, $vid, $operator) ? 1 : 0;
        }
        flash(sprintf(t('vis_msg_adopted'), $n));
        redirect('visits.queue');
        break;

    case 'day':
        $requireAll();
        $date = Hr::valid_date((string) input('date', '')) ? (string) input('date') : $today;
        $rows = Visits::fetch($pdo, $date, $date);
        $per = [];
        $order = [];
        $points = [];
        foreach (array_reverse($rows) as $v) {
            $k = (string) $v['sales'];
            $per[$k] ??= ['sales' => $k, 'n' => 0, 'lama' => 0, 'baru' => 0, 'outcomes' => []];
            $per[$k]['n']++;
            $lb = $v['status_lama_baru'] ?: $v['system_lama_baru'];
            if ($lb === 'lama' || $lb === 'baru') {
                $per[$k][$lb]++;
            }
            $per[$k]['outcomes'][$v['outcome']] = ($per[$k]['outcomes'][$v['outcome']] ?? 0) + 1;
            if ($v['lat'] !== null) {
                $order[$k] ??= count($order);
                $points[] = ['lat' => (float) $v['lat'], 'lng' => (float) $v['lng'], 'sales' => $k, 'color' => $order[$k], 'far' => $v['distance_flag'] === 'far',
                             'label' => Visits::target_name($v) . ' · ' . Visits::outcome_label((string) $v['outcome'])];
            }
        }
        ksort($per, SORT_NATURAL | SORT_FLAG_CASE);
        $box = ['north' => -5.95, 'south' => -6.75, 'west' => 106.45, 'east' => 107.25];
        view('visits.day', ['pageTitle' => t('vis_day'), 'pageSub' => Hr::dmy($date), 'date' => $date, 'rows' => $rows, 'per' => array_values($per), 'canRecord' => $canRecord, 'canImport' => $canImport,
            'map' => Insights::map_layout($points, $box, 10), 'legend' => array_map(fn($n, $i) => ['name' => $n, 'i' => $i], array_keys($order), $order), 'located' => count($points)]);
        break;

    case 'customers':
        $requireAll();
        $q = trim((string) input('q', ''));
        $history = [];
        $found = [];
        if ($q !== '') {
            $st = $pdo->prepare('SELECT id, name, owner, city FROM customers WHERE name LIKE ? OR company LIKE ? ORDER BY name LIMIT 20');
            $st->execute(["%{$q}%", "%{$q}%"]);
            $found = $st->fetchAll();
            $cid = (int) input('id', 0) ?: (int) ($found[0]['id'] ?? 0);
            if ($cid) {
                $h = $pdo->prepare('SELECT v.*, COALESCE(e.full_name, v.wa_sender) sales FROM visits v LEFT JOIN employees e ON e.id = v.employee_id WHERE v.customer_id = ? ORDER BY v.visited_at DESC LIMIT 100');
                $h->execute([$cid]);
                $history = $h->fetchAll();
            }
        }
        $month = substr($today, 0, 7);
        $often = $pdo->prepare("SELECT c.id, c.name, COUNT(*) n, GROUP_CONCAT(DISTINCT COALESCE(e.full_name, v.wa_sender)) who FROM visits v JOIN customers c ON c.id = v.customer_id
                                 LEFT JOIN employees e ON e.id = v.employee_id WHERE substr(v.visited_at, 1, 7) = ? GROUP BY c.id HAVING n >= 3 ORDER BY n DESC");
        $often->execute([$month]);
        view('visits.customers', ['pageTitle' => t('vis_customers'), 'pageSub' => '', 'q' => $q, 'found' => $found, 'history' => $history, 'canRecord' => $canRecord, 'canImport' => $canImport,
            'often' => $often->fetchAll(), 'neglected' => VisitImport::neglected($pdo, $today, 60), 'month' => $month]);
        break;

    case 'competitors':
        $requireAll();
        [$week, $from, $to] = $weekParam();
        $from = Hr::valid_date((string) input('from', '')) ? (string) input('from') : HrContract::add_days($today, -89);
        $to = Hr::valid_date((string) input('to', '')) ? (string) input('to') : $today;
        $only = input('all') ? false : true;
        view('visits.competitors', ['pageTitle' => t('vis_competitors'), 'pageSub' => '', 'rows' => VisitImport::competitors($pdo, $from, $to, $only), 'from' => $from, 'to' => $to,
            'only' => $only, 'canRecord' => $canRecord, 'canImport' => $canImport]);
        break;

    case 'quality':
        if (!$auth->isAdmin()) {
            http_response_code(403);
            flash(t('vis_msg_boss_only'), 'error');
            redirect('visits.index');
        }
        [$week, $from, $to] = $weekParam();
        view('visits.quality', ['pageTitle' => t('vis_quality'), 'pageSub' => $week, 'week' => $week, 'from' => $from, 'to' => $to,
            'rows' => VisitImport::quality($pdo, $from, $to), 'canRecord' => $canRecord, 'canImport' => $canImport]);
        break;

    case 'digest':
        Csrf::verify();
        $requireAll();
        [$week] = $weekParam();
        $r = VisitImport::digest($pdo, $config, $week, $operator);
        flash($r['status'] === 'ok' ? t('vis_msg_digest_ok') : t('vis_msg_digest_failed') . ' ' . ($r['error'] ?? ''), $r['status'] === 'ok' ? 'success' : 'error');
        redirect('visits.report', ['week' => $week]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
