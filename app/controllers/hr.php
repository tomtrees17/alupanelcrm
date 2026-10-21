<?php
declare(strict_types=1);

/**
 * 人力 / SDM — HR-00 + HR-01 (docs/任务卡-人力一期.md).
 * Every read is filtered by Hr::viewer() server-side; every write needs the hr
 * role. Nothing is deleted. Audit rows are field-level (Hr::audit_fields).
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 * @var array  $config
 */

$viewer = Hr::viewer($pdo);
$today = date('Y-m-d');
$operator = own_name();

$requireEdit = function () use ($viewer): void {
    if (!$viewer['edit']) {
        http_response_code(403);
        flash(t('hr_msg_read_only'), 'error');
        redirect('hr.index');
    }
};
$findEmployee = function (int $id) use ($pdo, $viewer): array {
    $st = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) {
        http_response_code(404);
        exit('Not found');
    }
    if (!Hr::can_see($viewer, $e)) {
        http_response_code(403);
        flash(t('hr_msg_not_yours'), 'error');
        redirect('hr.index');
    }
    return $e;
};
$dateOrNull = fn(string $k): ?string => ($v = trim((string) input($k, ''))) === '' ? null : $v;

switch ($action) {
    // ── HR-00: the 人力 home is the todo list ──
    case 'index':
        if ($viewer['scope'] === 'self') {
            redirect('hr.show', ['id' => $viewer['employee_id']]);
        }
        if ($viewer['scope'] === 'none') {
            http_response_code(403);
            flash(t('hr_msg_no_record'), 'error');
            redirect('dashboard.index');
        }
        [$w, $a] = Hr::scope_sql($viewer);
        $st = $pdo->prepare("SELECT t.*, e.full_name, e.employee_no FROM hr_todos t LEFT JOIN employees e ON e.id = t.employee_id
                              WHERE t.status = 'open' AND (t.employee_id IS NULL OR {$w}) ORDER BY t.due_date IS NULL, t.due_date, t.id");
        $st->execute($a);
        view('hr.index', ['pageTitle' => t('nav_hr'), 'pageSub' => t('hr_sub_todos'), 'todos' => $st->fetchAll(), 'today' => $today, 'viewer' => $viewer]);
        break;

    case 'todo_done':
        Csrf::verify();
        $requireEdit();
        $id = (int) input('id', 0);
        $note = trim((string) input('note', ''));
        $st = $pdo->prepare("SELECT * FROM hr_todos WHERE id = ? AND status = 'open'");
        $st->execute([$id]);
        $todo = $st->fetch();
        if ($todo) {
            $pdo->prepare("UPDATE hr_todos SET status = 'done', handled_by = ?, handled_at = datetime('now','localtime'), handled_note = ? WHERE id = ?")
                ->execute([$operator, $note, $id]);
            audit($pdo, 'hr', 'handle', 'hr_todos', $id, t('hrtodo_' . $todo['type']), 'hr_todos.status: open → done' . ($note !== '' ? '; ' . $note : ''));
            flash(t('hr_msg_todo_done'));
        }
        redirect('hr.index');
        break;

    // ── HR-01: employees ──
    case 'employees':
        $f = [
            'department' => in_array(input('department'), Hr::DEPARTMENTS, true) ? (string) input('department') : '',
            'location'   => in_array(input('location'), Hr::LOCATIONS, true) ? (string) input('location') : '',
            'status'     => in_array(input('status'), Hr::STATUSES, true) ? (string) input('status') : '',
            'q'          => trim((string) input('q', '')),
        ];
        [$w, $args] = Hr::scope_sql($viewer);
        $cond = [$w];
        if ($f['department'] !== '') {
            $cond[] = 'e.department = ?';
            $args[] = $f['department'];
        }
        if ($f['location'] !== '') {
            $cond[] = 'e.work_location = ?';
            $args[] = $f['location'];
        }
        if ($f['status'] !== '') {
            $cond[] = 'e.employment_status = ?';
            $args[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $cond[] = '(e.full_name LIKE ? OR e.nik LIKE ? OR e.employee_no LIKE ?)';
            array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
        }
        $st = $pdo->prepare('SELECT e.* FROM employees e WHERE ' . implode(' AND ', $cond) . ' ORDER BY e.employee_no');
        $st->execute($args);
        view('hr.employees', ['pageTitle' => t('hr_employees'), 'pageSub' => '', 'rows' => $st->fetchAll(), 'f' => $f, 'viewer' => $viewer]);
        break;

    case 'show':
        $e = $findEmployee((int) input('id', 0));
        $tab = in_array(input('tab'), ['basic', 'job', 'files', 'contracts', 'bpjs', 'history'], true) ? (string) input('tab') : 'basic';
        $att = $pdo->prepare('SELECT * FROM employee_attachments WHERE employee_id = ? ORDER BY id DESC');
        $att->execute([$e['id']]);
        $ch = $pdo->prepare('SELECT * FROM employee_changes WHERE employee_id = ? ORDER BY id DESC');
        $ch->execute([$e['id']]);
        $acct = $pdo->prepare('SELECT id, name, email, role, active FROM users WHERE employee_id = ?');
        $acct->execute([$e['id']]);
        $sup = null;
        if ($e['supervisor_id']) {
            $s = $pdo->prepare('SELECT id, full_name, employee_no FROM employees WHERE id = ?');
            $s->execute([$e['supervisor_id']]);
            $sup = $s->fetch() ?: null;
        }
        view('hr.show', [
            'pageTitle' => $e['full_name'], 'pageSub' => (string) $e['employee_no'],
            'e' => $e, 'tab' => $tab, 'viewer' => $viewer, 'salaryVisible' => Hr::can_see_salary($viewer, $e),
            'salary' => Hr::can_see_salary($viewer, $e) ? Hr::decrypt($e['base_salary_enc']) : null,
            'attachments' => $att->fetchAll(), 'changes' => $ch->fetchAll(), 'account' => $acct->fetch() ?: null, 'supervisor' => $sup,
            'staff' => $pdo->query("SELECT id, full_name, employee_no FROM employees WHERE employment_status <> 'resigned' ORDER BY full_name")->fetchAll(),
            'today' => $today,
            // CRM accounts HR may link: not the administrator, not linked to someone else.
            'linkable' => $viewer['edit'] ? (function () use ($pdo, $e): array {
                $st = $pdo->prepare("SELECT id, name, email, role, active FROM users WHERE role <> 'admin' AND (employee_id IS NULL OR employee_id = ?) ORDER BY name");
                $st->execute([$e['id']]);
                return $st->fetchAll();
            })() : [],
        ]);
        break;

    // Link / unlink the employee's CRM account (one account per employee).
    case 'link':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        $back = ['hr.show', ['id' => $e['id'], 'tab' => 'job']];
        $uid = (int) input('user_id', 0);
        if ($e['employment_status'] === 'resigned') {
            flash(t('hr_err_already_resigned'), 'error');
            redirect($back[0], $back[1]);
        }
        $cur = $pdo->prepare('SELECT id, name, employee_id FROM users WHERE employee_id = ?');
        $cur->execute([$e['id']]);
        $current = $cur->fetch() ?: null;
        if ($current && (int) $current['id'] === $uid) {
            flash(t('hr_msg_nothing_changed'), 'error');
            redirect($back[0], $back[1]);
        }
        $target = null;
        if ($uid > 0) {
            $st = $pdo->prepare('SELECT id, name, role, employee_id FROM users WHERE id = ?');
            $st->execute([$uid]);
            $target = $st->fetch() ?: null;
            if (!$target || $target['role'] === 'admin' || ($target['employee_id'] !== null && (int) $target['employee_id'] !== (int) $e['id'])) {
                flash(t('hr_err_link_taken'), 'error');
                redirect($back[0], $back[1]);
            }
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET employee_id = NULL WHERE employee_id = ?')->execute([$e['id']]);
        if ($target) {
            $pdo->prepare('UPDATE users SET employee_id = ? WHERE id = ?')->execute([$e['id'], $target['id']]);
        }
        $pdo->commit();
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        if ($current) {
            audit($pdo, 'hr', 'update', 'users', (int) $current['id'], (string) $current['name'], "users.employee_id: {$e['id']} → (空); {$label}");
        }
        if ($target) {
            audit($pdo, 'hr', 'update', 'users', (int) $target['id'], (string) $target['name'], "users.employee_id: (空) → {$e['id']}; {$label}");
        }
        flash(t('hr_msg_linked'));
        redirect($back[0], $back[1]);
        break;

    case 'create':
        $requireEdit();
        view('hr.form', ['pageTitle' => t('hr_new_employee'), 'pageSub' => '', 'e' => null, 'viewer' => $viewer,
            'staff' => $pdo->query("SELECT id, full_name, employee_no FROM employees WHERE employment_status <> 'resigned' ORDER BY full_name")->fetchAll()]);
        break;

    case 'store':
    case 'update':
        Csrf::verify();
        $requireEdit();
        $isNew = $action === 'store';
        $before = $isNew ? null : $findEmployee((int) input('id', 0));
        $data = [];
        foreach (Hr::BASIC_FIELDS as $k) {
            $data[$k] = trim((string) input($k, ''));
            if ($data[$k] === '') {
                $data[$k] = null;
            }
        }
        $back = $isNew ? ['hr.create', []] : ['hr.edit', ['id' => $before['id']]];
        $errors = [];
        if (($data['full_name'] ?? '') === null || ($data['full_name'] ?? '') === '') {
            $errors[] = t('hr_err_name');
        }
        if ($data['nik'] !== null || $isNew) {
            if (!Hr::valid_nik((string) $data['nik'])) {
                $errors[] = t('hr_err_nik_format');
            } elseif ($taken = Hr::nik_taken($pdo, (string) $data['nik'], $isNew ? 0 : (int) $before['id'])) {
                $errors[] = sprintf(t('hr_err_nik_taken'), $taken);
            }
        }
        if ($data['birth_date'] !== null && !Hr::valid_date($data['birth_date'])) {
            $errors[] = t('hr_err_date');
        }
        if ($isNew) {
            // Required on creation (HR-01): name, NIK, birth date, department, position, location, join date.
            $job = [
                'department' => (string) input('department', ''), 'position' => trim((string) input('position', '')),
                'work_location' => (string) input('work_location', ''), 'join_date' => trim((string) input('join_date', '')),
                'supervisor_id' => ((int) input('supervisor_id', 0)) ?: null,
                'employment_status' => in_array(input('employment_status'), ['probation', 'active'], true) ? (string) input('employment_status') : 'active',
            ];
            if ($data['birth_date'] === null) {
                $errors[] = t('hr_err_birth_req');
            }
            if (!in_array($job['department'], Hr::DEPARTMENTS, true) || $job['position'] === '' || !in_array($job['work_location'], Hr::LOCATIONS, true)) {
                $errors[] = t('hr_err_job_req');
            }
            if (!Hr::valid_date($job['join_date'])) {
                $errors[] = t('hr_err_join_req');
            } elseif ($job['join_date'] > $today) {
                $errors[] = t('hr_err_join_future');
            }
        }
        foreach (['gender' => Hr::GENDERS, 'religion' => Hr::RELIGIONS, 'marital_status' => Hr::MARITAL, 'ptkp_status' => Hr::PTKP] as $k => $allowed) {
            if ($data[$k] !== null && !in_array($data[$k], $allowed, true)) {
                $data[$k] = null;
            }
        }
        if ($errors) {
            flash(implode(' ', $errors), 'error');
            $_SESSION['hr_form_old'] = $_POST;
            redirect($back[0], $back[1]);
        }
        if ($isNew) {
            $salary = trim((string) input('base_salary', ''));
            if ($salary !== '' && !Hr::crypto_ready()) {
                flash(t('hr_err_no_key'), 'error');
                $_SESSION['hr_form_old'] = $_POST;
                redirect('hr.create');
            }
            $no = Hr::next_employee_no($pdo);
            $cols = array_merge(['employee_no'], array_keys($data), array_keys($job), ['base_salary_enc', 'salary_effective_date', 'created_by', 'updated_by', 'updated_at']);
            $vals = array_merge([$no], array_values($data), array_values($job), [
                $salary === '' ? null : Hr::encrypt((string) (float) $salary), $salary === '' ? null : $job['join_date'], $operator, $operator, date('Y-m-d H:i:s'),
            ]);
            $pdo->prepare('INSERT INTO employees (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'hr', 'create', 'employees', $id, $no . ' ' . $data['full_name'],
                sprintf('employees: %s %s; %s; %s; %s', $no, $data['full_name'], $job['department'], $job['position'], $job['work_location']));
            flash(sprintf(t('hr_msg_created'), $no));
            redirect('hr.show', ['id' => $id]);
        }
        $data['updated_by'] = $operator;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $set = implode(',', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $pdo->prepare("UPDATE employees SET {$set} WHERE id = ?")->execute([...array_values($data), $before['id']]);
        Hr::audit_fields($pdo, 'update', 'employees', (int) $before['id'], $before['employee_no'] . ' ' . $data['full_name'],
            array_intersect_key($before, array_flip(Hr::BASIC_FIELDS)), array_intersect_key($data, array_flip(Hr::BASIC_FIELDS)));
        flash(t('hr_msg_saved'));
        redirect('hr.show', ['id' => $before['id']]);
        break;

    case 'edit':
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        view('hr.form', ['pageTitle' => t('btn_edit') . ' · ' . $e['full_name'], 'pageSub' => (string) $e['employee_no'], 'e' => $e, 'viewer' => $viewer, 'staff' => []]);
        break;

    // Job / salary change: effective date and note are mandatory; every changed
    // field is written to employee_changes (history) and audit_log.
    case 'job':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        $back = ['hr.show', ['id' => $e['id'], 'tab' => 'job']];
        $effective = trim((string) input('effective_date', ''));
        $note = trim((string) input('note', ''));
        if (!Hr::valid_date($effective) || $note === '') {
            flash(t('hr_err_effective_note'), 'error');
            redirect($back[0], $back[1]);
        }
        $new = [
            'department'        => in_array(input('department'), Hr::DEPARTMENTS, true) ? (string) input('department') : $e['department'],
            'position'          => trim((string) input('position', (string) $e['position'])),
            'work_location'     => in_array(input('work_location'), Hr::LOCATIONS, true) ? (string) input('work_location') : $e['work_location'],
            'supervisor_id'     => ((int) input('supervisor_id', 0)) ?: null,
            'employment_status' => in_array(input('employment_status'), ['probation', 'active'], true) ? (string) input('employment_status') : $e['employment_status'],
            'is_dept_head'      => input('is_dept_head') ? 1 : 0,
        ];
        if ($e['employment_status'] === 'resigned') {
            $new['employment_status'] = 'resigned';   // only the resignation flow changes this
        }
        if ($new['supervisor_id'] === (int) $e['id']) {
            $new['supervisor_id'] = $e['supervisor_id'];
        }
        $typeOf = ['department' => 'position', 'position' => 'position', 'is_dept_head' => 'position', 'work_location' => 'location', 'supervisor_id' => 'supervisor', 'employment_status' => 'status'];
        $changed = [];
        foreach ($new as $k => $v) {
            if ((string) $e[$k] !== (string) $v) {
                $changed[$k] = $v;
                Hr::record_change($pdo, (int) $e['id'], $typeOf[$k], $k, $e[$k] === null ? null : (string) $e[$k], $v === null ? null : (string) $v, $effective, $note, $operator);
            }
        }
        $salaryIn = trim((string) input('base_salary', ''));
        $oldSalary = Hr::decrypt($e['base_salary_enc']);
        $salaryChanged = $salaryIn !== '' && (string) (float) $salaryIn !== (string) ($oldSalary === null ? '' : (float) $oldSalary);
        if ($salaryChanged) {
            if (!Hr::crypto_ready()) {
                flash(t('hr_err_no_key'), 'error');
                redirect($back[0], $back[1]);
            }
            Hr::record_change($pdo, (int) $e['id'], 'salary', 'base_salary', $oldSalary, (string) (float) $salaryIn, $effective, $note, $operator);
        }
        if (!$changed && !$salaryChanged) {
            flash(t('hr_msg_nothing_changed'), 'error');
            redirect($back[0], $back[1]);
        }
        $set = $changed;
        if ($salaryChanged) {
            $set['base_salary_enc'] = Hr::encrypt((string) (float) $salaryIn);
            $set['salary_effective_date'] = $effective;
        }
        $set['updated_by'] = $operator;
        $set['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE employees SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), $e['id']]);
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        Hr::audit_fields($pdo, 'update', 'employees', (int) $e['id'], $label, array_intersect_key($e, $changed), $changed);
        if ($salaryChanged) {
            audit($pdo, 'hr', 'update', 'employees', (int) $e['id'], $label, "employees.base_salary: (已修改，金额不记入日志); 生效 {$effective}; {$note}");
        }
        flash(t('hr_msg_saved'));
        redirect($back[0], $back[1]);
        break;

    case 'upload':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        $back = ['hr.show', ['id' => $e['id'], 'tab' => 'files']];
        $type = in_array(input('doc_type'), Hr::DOC_TYPES, true) ? (string) input('doc_type') : '';
        $file = $_FILES['file'] ?? null;
        if ($type === '' || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash(t('hr_err_upload'), 'error');
            redirect($back[0], $back[1]);
        }
        $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
            flash(t('hr_err_too_big'), 'error');
            redirect($back[0], $back[1]);
        }
        // Extension AND content must both be pdf / jpg / png.
        if (!isset($allowed[$ext]) || Hr::sniff((string) $file['tmp_name']) !== $allowed[$ext]) {
            flash(t('hr_err_type'), 'error');
            redirect($back[0], $back[1]);
        }
        $dir = Hr::upload_dir((int) $e['id']);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $stored)
            : (PHP_SAPI === 'cli' && rename((string) $file['tmp_name'], $dir . '/' . $stored));   // test harness only
        if (!$moved) {
            flash(t('hr_err_upload'), 'error');
            redirect($back[0], $back[1]);
        }
        $pdo->prepare('INSERT INTO employee_attachments (employee_id, doc_type, file_path, file_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$e['id'], $type, $stored, (string) $file['name'], $allowed[$ext], (int) $file['size'], $operator]);
        audit($pdo, 'hr', 'upload', 'employee_attachments', (int) $pdo->lastInsertId(), $e['employee_no'] . ' ' . $e['full_name'],
            sprintf('employee_attachments: %s %s', strtoupper($type), (string) $file['name']));
        flash(t('hr_msg_uploaded'));
        redirect($back[0], $back[1]);
        break;

    case 'file':
        // Private file: only through this check, never a public URL.
        $st = $pdo->prepare('SELECT * FROM employee_attachments WHERE id = ?');
        $st->execute([(int) input('fid', 0)]);
        $a = $st->fetch();
        if (!$a) {
            http_response_code(404);
            exit('Not found');
        }
        $findEmployee((int) $a['employee_id']);
        $path = Hr::upload_dir((int) $a['employee_id']) . '/' . basename((string) $a['file_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) $a['file_name']));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;

    // Resignation: status → resigned, three todos, CRM account deactivated (never deleted).
    case 'resign':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        $back = ['hr.show', ['id' => $e['id'], 'tab' => 'job']];
        $date = trim((string) input('resign_date', ''));
        $reason = trim((string) input('resign_reason', ''));
        if ($e['employment_status'] === 'resigned') {
            flash(t('hr_err_already_resigned'), 'error');
            redirect($back[0], $back[1]);
        }
        if (!Hr::valid_date($date) || $reason === '') {
            flash(t('hr_err_resign_req'), 'error');
            redirect($back[0], $back[1]);
        }
        if ($e['join_date'] && $date < $e['join_date']) {
            flash(t('hr_err_resign_before_join'), 'error');
            redirect($back[0], $back[1]);
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE employees SET employment_status = 'resigned', resign_date = ?, resign_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([$date, $reason, $operator, date('Y-m-d H:i:s'), $e['id']]);
        Hr::record_change($pdo, (int) $e['id'], 'status', 'employment_status', (string) $e['employment_status'], 'resigned', $date, $reason, $operator);
        $ref = 'resign:' . $pdo->lastInsertId();   // one set of todos per resignation (it can be undone and redone)
        foreach (['contract_end', 'bpjs_stop', 'account_off'] as $type) {
            Hr::add_todo($pdo, $type, (int) $e['id'], $date, $ref);
        }
        $accounts = $pdo->prepare('SELECT id, name, active FROM users WHERE employee_id = ?');
        $accounts->execute([$e['id']]);
        $accts = $accounts->fetchAll();
        $pdo->prepare('UPDATE users SET active = 0 WHERE employee_id = ?')->execute([$e['id']]);
        $pdo->commit();
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        Hr::audit_fields($pdo, 'resign', 'employees', (int) $e['id'], $label,
            ['employment_status' => $e['employment_status'], 'resign_date' => $e['resign_date'], 'resign_reason' => $e['resign_reason']],
            ['employment_status' => 'resigned', 'resign_date' => $date, 'resign_reason' => $reason]);
        foreach ($accts as $u) {
            audit($pdo, 'hr', 'resign', 'users', (int) $u['id'], (string) $u['name'], 'users.active: ' . (int) $u['active'] . ' → 0 (离职停用)');
        }
        flash(t('hr_msg_resigned'));
        redirect($back[0], $back[1]);
        break;

    // Undo a resignation (owner decision 2026-10-21): status back to what it was,
    // open resignation todos cancelled, CRM account re-enabled. Todos already
    // handled (e.g. BPJS stopped) are not rolled back — HR follows up by hand.
    case 'unresign':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('id', 0));
        $back = ['hr.show', ['id' => $e['id'], 'tab' => 'job']];
        $note = trim((string) input('note', ''));
        if ($e['employment_status'] !== 'resigned') {
            flash(t('hr_err_not_resigned'), 'error');
            redirect($back[0], $back[1]);
        }
        if ($note === '') {
            flash(t('hr_err_unresign_note'), 'error');
            redirect($back[0], $back[1]);
        }
        $last = $pdo->prepare("SELECT old_value FROM employee_changes WHERE employee_id = ? AND field = 'employment_status' AND new_value = 'resigned' ORDER BY id DESC LIMIT 1");
        $last->execute([$e['id']]);
        $prev = (string) ($last->fetchColumn() ?: 'active');
        if (!in_array($prev, ['probation', 'active'], true)) {
            $prev = 'active';
        }
        $todos = $pdo->prepare("SELECT id, type FROM hr_todos WHERE employee_id = ? AND status = 'open' AND (ref = 'resign' OR ref LIKE 'resign:%')");
        $todos->execute([$e['id']]);
        $open = $todos->fetchAll();
        $accounts = $pdo->prepare('SELECT id, name FROM users WHERE employee_id = ? AND active = 0');
        $accounts->execute([$e['id']]);
        $accts = $accounts->fetchAll();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE employees SET employment_status = ?, resign_date = NULL, resign_reason = NULL, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$prev, $operator, date('Y-m-d H:i:s'), $e['id']]);
        Hr::record_change($pdo, (int) $e['id'], 'status', 'employment_status', 'resigned', $prev, $today, $note, $operator);
        foreach ($open as $td) {
            $pdo->prepare("UPDATE hr_todos SET status = 'cancelled', handled_by = ?, handled_at = datetime('now','localtime'), handled_note = ? WHERE id = ?")
                ->execute([$operator, $note, $td['id']]);
        }
        $pdo->prepare('UPDATE users SET active = 1 WHERE employee_id = ? AND active = 0')->execute([$e['id']]);
        $pdo->commit();
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        Hr::audit_fields($pdo, 'unresign', 'employees', (int) $e['id'], $label,
            ['employment_status' => $e['employment_status'], 'resign_date' => $e['resign_date'], 'resign_reason' => $e['resign_reason']],
            ['employment_status' => $prev, 'resign_date' => null, 'resign_reason' => null]);
        foreach ($open as $td) {
            audit($pdo, 'hr', 'unresign', 'hr_todos', (int) $td['id'], t('hrtodo_' . $td['type']), 'hr_todos.status: open → cancelled; ' . $note);
        }
        foreach ($accts as $u) {
            audit($pdo, 'hr', 'unresign', 'users', (int) $u['id'], (string) $u['name'], 'users.active: 0 → 1 (撤销离职)');
        }
        flash(t('hr_msg_unresigned'));
        redirect($back[0], $back[1]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
