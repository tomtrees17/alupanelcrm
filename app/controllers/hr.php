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

// HR-02: expiry, sync and reminders are brought up to date on every 人力 page (no cron, owner 2026-10-21).
HrContract::scan($pdo, $today);

/** A contract the viewer may read, with its employee. @return array{0:array,1:array} */
$findContract = function (int $id) use ($pdo, $findEmployee): array {
    $c = HrContract::find($pdo, $id);
    if (!$c) {
        http_response_code(404);
        exit('Not found');
    }
    return [$c, $findEmployee((int) $c['employee_id'])];
};
/**
 * Contract fields from the form. Errors are i18n keys.
 * @return array{0:array,1:list<string>}
 */
$contractInput = function (array $e, ?array $existing) use ($pdo): array {
    $d = [
        'contract_type'        => in_array(input('contract_type'), HrContract::TYPES, true) ? (string) input('contract_type') : '',
        'start_date'           => trim((string) input('start_date', '')),
        'end_date'             => trim((string) input('end_date', '')) ?: null,
        'is_probation'         => input('is_probation') ? 1 : 0,
        'probation_months'     => ((int) input('probation_months', 0)) ?: null,
        'position'             => trim((string) input('position', '')),
        'work_location'        => (string) input('work_location', ''),
        'signed_date'          => trim((string) input('signed_date', '')) ?: null,
        'previous_contract_id' => ((int) input('previous_contract_id', 0)) ?: null,
    ];
    $err = [];
    if ($d['contract_type'] === '' || !Hr::valid_date($d['start_date'])) {
        $err[] = 'hrc_err_type_start';
    }
    if ($d['contract_type'] === 'PKWT') {
        $d['is_probation'] = 0;
        $d['probation_months'] = null;
        if ($d['end_date'] === null || !Hr::valid_date($d['end_date']) || $d['end_date'] < $d['start_date']) {
            $err[] = 'hrc_err_end';
        }
    } else {
        $d['end_date'] = null;
        if (!$d['is_probation']) {
            $d['probation_months'] = null;
        } elseif ($d['probation_months'] === null || $d['probation_months'] < 1 || $d['probation_months'] > 3) {
            $err[] = 'hrc_err_probation';
        }
    }
    if ($d['position'] === '' || !in_array($d['work_location'], Hr::LOCATIONS, true)) {
        $err[] = 'hrc_err_job';
    }
    if ($d['signed_date'] !== null && !Hr::valid_date($d['signed_date'])) {
        $err[] = 'hr_err_date';
    }
    if ($d['previous_contract_id'] !== null) {
        $prev = HrContract::find($pdo, $d['previous_contract_id']);
        if (!$prev || (int) $prev['employee_id'] !== (int) $e['id'] || ($existing && (int) $prev['id'] === (int) $existing['id'])) {
            $d['previous_contract_id'] = null;
        }
    }
    return [$d, $err];
};

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
        $rows = $st->fetchAll();
        $current = [];
        foreach ($rows as $r) {
            $current[(int) $r['id']] = HrContract::current($pdo, (int) $r['id'], $today);
        }
        view('hr.employees', ['pageTitle' => t('hr_employees'), 'pageSub' => '', 'rows' => $rows, 'f' => $f, 'viewer' => $viewer, 'current' => $current, 'today' => $today]);
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
            'contracts' => (function () use ($pdo, $e): array {
                $st = $pdo->prepare('SELECT * FROM employment_contracts WHERE employee_id = ? ORDER BY start_date DESC, id DESC');
                $st->execute([$e['id']]);
                return $st->fetchAll();
            })(),
            'currentContract' => HrContract::current($pdo, (int) $e['id'], $today),
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
        $up = $type === '' ? ['error' => 'hr_err_upload'] : Hr::accept_upload($_FILES['file'] ?? null, (int) $e['id']);
        if (isset($up['error'])) {
            flash(t($up['error']), 'error');
            redirect($back[0], $back[1]);
        }
        $pdo->prepare('INSERT INTO employee_attachments (employee_id, doc_type, file_path, file_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$e['id'], $type, $up['stored'], $up['name'], $up['mime'], $up['size'], $operator]);
        audit($pdo, 'hr', 'upload', 'employee_attachments', (int) $pdo->lastInsertId(), $e['employee_no'] . ' ' . $e['full_name'],
            sprintf('employee_attachments: %s %s', strtoupper($type), $up['name']));
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
        Hr::send_file((int) $a['employee_id'], (string) $a['file_path'], $a['mime'], (string) $a['file_name']);
        break;

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
        Hr::resign($pdo, $e, $date, $reason, $operator);
        flash(t('hr_msg_resigned'));
        redirect($back[0], $back[1]);
        break;

    // ── HR-02: contracts ──
    case 'contracts':
        $f = [
            'type'   => in_array(input('type'), HrContract::TYPES, true) ? (string) input('type') : '',
            'status' => in_array(input('status'), HrContract::STATUSES, true) ? (string) input('status') : '',
            'within' => in_array((int) input('within', 0), [30, 60], true) ? (int) input('within') : 0,
        ];
        [$w, $args] = Hr::scope_sql($viewer);
        $cond = [$w];
        if ($f['type'] !== '') {
            $cond[] = 'c.contract_type = ?';
            $args[] = $f['type'];
        }
        if ($f['status'] !== '') {
            $cond[] = 'c.status = ?';
            $args[] = $f['status'];
        }
        if ($f['within'] > 0) {
            $cond[] = "c.status = 'active' AND c.contract_type = 'PKWT' AND c.end_date BETWEEN ? AND ?";
            array_push($args, $today, HrContract::add_days($today, $f['within']));
        }
        $st = $pdo->prepare('SELECT c.*, e.full_name, e.employee_no FROM employment_contracts c JOIN employees e ON e.id = c.employee_id
                              WHERE ' . implode(' AND ', $cond) . ' ORDER BY c.status = \'active\' DESC, c.end_date IS NULL, c.end_date, c.id DESC');
        $st->execute($args);
        view('hr.contracts', ['pageTitle' => t('hr_contracts'), 'pageSub' => '', 'rows' => $st->fetchAll(), 'f' => $f, 'viewer' => $viewer, 'today' => $today]);
        break;

    case 'contract':
        [$c, $e] = $findContract((int) input('id', 0));
        $prev = $c['previous_contract_id'] ? HrContract::find($pdo, (int) $c['previous_contract_id']) : null;
        $next = $pdo->prepare('SELECT id, contract_no, contract_type, status FROM employment_contracts WHERE previous_contract_id = ? ORDER BY id');
        $next->execute([$c['id']]);
        view('hr.contract', [
            'pageTitle' => (string) $c['contract_no'], 'pageSub' => $e['employee_no'] . ' ' . $e['full_name'],
            'c' => $c, 'e' => $e, 'viewer' => $viewer, 'today' => $today, 'prev' => $prev, 'next' => $next->fetchAll(),
            'salaryVisible' => Hr::can_see_salary($viewer, $e),
            'salary' => Hr::can_see_salary($viewer, $e) ? Hr::decrypt($c['salary_enc']) : null,
            'decidable' => $c['contract_type'] === 'PKWT' && in_array($c['status'], ['active', 'expired'], true) && !HrContract::has_successor($pdo, (int) $c['id']),
        ]);
        break;

    // New contract: from the employee page, employee locked.
    case 'contract_new':
    case 'contract_edit':
        $requireEdit();
        if ($action === 'contract_new') {
            $c = null;
            $e = $findEmployee((int) input('employee_id', 0));
        } else {
            [$c, $e] = $findContract((int) input('id', 0));
            if (!in_array($c['status'], ['draft', 'active'], true)) {
                flash(t('hrc_err_locked'), 'error');
                redirect('hr.contract', ['id' => $c['id']]);
            }
        }
        $others = $pdo->prepare('SELECT id, contract_no, contract_type, start_date, end_date, status FROM employment_contracts WHERE employee_id = ? AND id <> ? ORDER BY start_date DESC');
        $others->execute([$e['id'], $c['id'] ?? 0]);
        view('hr.contract_form', [
            'pageTitle' => $c ? t('btn_edit') . ' · ' . $c['contract_no'] : t('hrc_new'), 'pageSub' => $e['employee_no'] . ' ' . $e['full_name'],
            'c' => $c, 'e' => $e, 'viewer' => $viewer, 'others' => $others->fetchAll(),
            'salary' => $c ? Hr::decrypt($c['salary_enc']) : Hr::decrypt($e['base_salary_enc']),
        ]);
        break;

    case 'contract_store':
        Csrf::verify();
        $requireEdit();
        $e = $findEmployee((int) input('employee_id', 0));
        [$d, $err] = $contractInput($e, null);
        $salary = trim((string) input('salary', ''));
        if ($salary !== '' && !Hr::crypto_ready()) {
            $err[] = 'hr_err_no_key';
        }
        if ($err) {
            flash(implode(' ', array_map('t', array_unique($err))), 'error');
            $_SESSION['hr_form_old'] = $_POST;
            redirect('hr.contract_new', ['employee_id' => $e['id']]);
        }
        $d['contract_no'] = HrContract::next_no($pdo, $d['start_date']);
        $d['employee_id'] = $e['id'];
        $d['renewal_count'] = HrContract::renewal_count($pdo, $d['contract_type'], $d['previous_contract_id']);
        $d['salary_enc'] = $salary === '' ? null : Hr::encrypt((string) (float) $salary);
        $d['status'] = 'draft';
        $d['created_by'] = $d['updated_by'] = $operator;
        $d['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO employment_contracts (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')
            ->execute(array_values($d));
        $cid = (int) $pdo->lastInsertId();
        audit($pdo, 'hr', 'create', 'employment_contracts', $cid, $d['contract_no'],
            sprintf('employment_contracts: %s %s %s %s~%s; %s', $d['contract_no'], $e['employee_no'], $d['contract_type'], Hr::dmy($d['start_date']), Hr::dmy($d['end_date']) ?: '—', 'draft'));
        flash(sprintf(t('hrc_msg_created'), $d['contract_no']));
        redirect('hr.contract', ['id' => $cid]);
        break;

    // Drafts: every field. Active contracts: only corrections of end date and signing date.
    case 'contract_update':
        Csrf::verify();
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        $back = ['hr.contract_edit', ['id' => $c['id']]];
        if ($c['status'] === 'active') {
            $d = ['end_date' => trim((string) input('end_date', '')) ?: null, 'signed_date' => trim((string) input('signed_date', '')) ?: null];
            $err = [];
            if ($c['contract_type'] === 'PKWT' && ($d['end_date'] === null || !Hr::valid_date($d['end_date']) || $d['end_date'] < $c['start_date'])) {
                $err[] = 'hrc_err_end';
            }
            if ($c['contract_type'] === 'PKWTT') {
                $d['end_date'] = null;
            }
            if ($d['signed_date'] !== null && !Hr::valid_date($d['signed_date'])) {
                $err[] = 'hr_err_date';
            }
            if (!$err && ($o = HrContract::overlapping($pdo, (int) $e['id'], (string) $c['start_date'], $d['end_date'], (int) $c['id']))) {
                $err[] = 'hrc_err_overlap';
            }
        } elseif ($c['status'] === 'draft') {
            [$d, $err] = $contractInput($e, $c);
            $salary = trim((string) input('salary', ''));
            if ($salary !== '' && !Hr::crypto_ready()) {
                $err[] = 'hr_err_no_key';
            }
            if (!$err) {
                if (substr((string) $d['start_date'], 0, 4) !== substr((string) $c['start_date'], 0, 4) || substr((string) $d['start_date'], 5, 2) !== substr((string) $c['start_date'], 5, 2)) {
                    $d['contract_no'] = HrContract::next_no($pdo, $d['start_date'], (int) $c['id']);   // still a draft: number follows the start date
                }
                $d['renewal_count'] = HrContract::renewal_count($pdo, $d['contract_type'], $d['previous_contract_id']);
                $old = Hr::decrypt($c['salary_enc']);
                if ($salary !== '' && (string) (float) $salary !== (string) ($old === null ? '' : (float) $old)) {
                    $d['salary_enc'] = Hr::encrypt((string) (float) $salary);
                }
            }
        } else {
            $err = ['hrc_err_locked'];
            $d = [];
        }
        if ($err) {
            flash(implode(' ', array_map('t', array_unique($err))), 'error');
            redirect($back[0], $back[1]);
        }
        $d['updated_by'] = $operator;
        $d['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE employment_contracts SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($d))) . ' WHERE id = ?')
            ->execute([...array_values($d), $c['id']]);
        $audited = array_diff_key($d, array_flip(['updated_by', 'updated_at']));
        Hr::audit_fields($pdo, 'update', 'employment_contracts', (int) $c['id'], (string) ($d['contract_no'] ?? $c['contract_no']),
            array_intersect_key($c, $audited), $audited);
        if ($c['status'] === 'active') {
            HrContract::scan($pdo, $today);   // a shortened end date may raise a reminder right away
        }
        flash(t('hr_msg_saved'));
        redirect('hr.contract', ['id' => $c['id']]);
        break;

    case 'contract_upload':
        Csrf::verify();
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        $back = ['hr.contract', ['id' => $c['id']]];
        if ($c['status'] !== 'draft') {
            flash(t('hrc_err_locked'), 'error');
            redirect($back[0], $back[1]);
        }
        $up = Hr::accept_upload($_FILES['file'] ?? null, (int) $e['id']);
        if (isset($up['error'])) {
            flash(t($up['error']), 'error');
            redirect($back[0], $back[1]);
        }
        $pdo->prepare('UPDATE employment_contracts SET file_path = ?, file_name = ?, file_mime = ?, file_size = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$up['stored'], $up['name'], $up['mime'], $up['size'], $operator, date('Y-m-d H:i:s'), $c['id']]);
        audit($pdo, 'hr', 'upload', 'employment_contracts', (int) $c['id'], (string) $c['contract_no'],
            'employment_contracts.file: ' . ($c['file_name'] ? $c['file_name'] . ' → ' : '') . $up['name']);
        flash(t('hr_msg_uploaded'));
        redirect($back[0], $back[1]);
        break;

    case 'contract_file':
        [$c] = $findContract((int) input('id', 0));
        if (!$c['file_path']) {
            http_response_code(404);
            exit('Not found');
        }
        Hr::send_file((int) $c['employee_id'], (string) $c['file_path'], $c['file_mime'], (string) $c['file_name']);
        break;

    // Draft → active (or straight to expired for a past, historical contract). Needs the scan.
    case 'contract_activate':
        Csrf::verify();
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        $back = ['hr.contract', ['id' => $c['id']]];
        if ($c['status'] !== 'draft') {
            flash(t('hrc_err_locked'), 'error');
            redirect($back[0], $back[1]);
        }
        if (!$c['file_path']) {
            flash(t('hrc_err_need_scan'), 'error');
            redirect($back[0], $back[1]);
        }
        $historical = $c['end_date'] !== null && $c['end_date'] < $today;
        if (!$historical && ($o = HrContract::overlapping($pdo, (int) $e['id'], (string) $c['start_date'], $c['end_date'], (int) $c['id']))) {
            flash(sprintf(t('hrc_err_overlap_with'), $o['contract_no']), 'error');
            redirect($back[0], $back[1]);
        }
        $to = $historical ? 'expired' : 'active';
        $pdo->prepare('UPDATE employment_contracts SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$to, $operator, date('Y-m-d H:i:s'), $c['id']]);
        audit($pdo, 'hr', 'activate', 'employment_contracts', (int) $c['id'], (string) $c['contract_no'], "employment_contracts.status: draft → {$to}");
        HrContract::scan($pdo, $today);   // syncs the employee record if it has started; raises reminders
        flash(t($historical ? 'hrc_msg_historical' : 'hrc_msg_activated'));
        redirect($back[0], $back[1]);
        break;

    // Expiry page: renew PKWT / convert to PKWTT / terminate.
    case 'contract_decide':
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        if (!($c['contract_type'] === 'PKWT' && in_array($c['status'], ['active', 'expired'], true) && !HrContract::has_successor($pdo, (int) $c['id']))) {
            flash(t('hrc_err_not_decidable'), 'error');
            redirect('hr.contract', ['id' => $c['id']]);
        }
        view('hr.contract_decide', [
            'pageTitle' => t('hrc_decide') . ' · ' . $c['contract_no'], 'pageSub' => $e['employee_no'] . ' ' . $e['full_name'],
            'c' => $c, 'e' => $e, 'viewer' => $viewer, 'today' => $today,
            'salary' => Hr::decrypt($c['salary_enc']),
            'overLimit' => HrContract::over_limit($pdo, $c),
            'pkwtDays' => HrContract::pkwt_days($pdo, $c),
        ]);
        break;

    case 'contract_renew':
    case 'contract_convert':
        Csrf::verify();
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        $back = ['hr.contract_decide', ['id' => $c['id']]];
        if (!($c['contract_type'] === 'PKWT' && in_array($c['status'], ['active', 'expired'], true) && !HrContract::has_successor($pdo, (int) $c['id']))) {
            flash(t('hrc_err_not_decidable'), 'error');
            redirect('hr.contract', ['id' => $c['id']]);
        }
        $renew = $action === 'contract_renew';
        $start = $renew ? HrContract::add_days((string) $c['end_date'], 1) : trim((string) input('start_date', ''));
        $end = $renew ? trim((string) input('end_date', '')) : null;
        $err = [];
        if (!$renew && (!Hr::valid_date($start) || $start <= $c['start_date'])) {
            $err[] = 'hrc_err_type_start';
        }
        if ($renew && (!Hr::valid_date((string) $end) || $end < $start)) {
            $err[] = 'hrc_err_end';
        }
        $position = trim((string) input('position', (string) $c['position']));
        $location = in_array(input('work_location'), Hr::LOCATIONS, true) ? (string) input('work_location') : (string) $c['work_location'];
        $salary = trim((string) input('salary', ''));
        if ($salary !== '' && !Hr::crypto_ready()) {
            $err[] = 'hr_err_no_key';
        }
        if ($err) {
            flash(implode(' ', array_map('t', $err)), 'error');
            redirect($back[0], $back[1]);
        }
        $warn = $renew && HrContract::over_limit($pdo, $c, $end);
        $d = [
            'contract_no' => HrContract::next_no($pdo, $start), 'employee_id' => $e['id'], 'contract_type' => $renew ? 'PKWT' : 'PKWTT',
            'is_probation' => 0, 'probation_months' => null, 'start_date' => $start, 'end_date' => $end,
            'position' => $position, 'work_location' => $location,
            'salary_enc' => $salary === '' ? $c['salary_enc'] : Hr::encrypt((string) (float) $salary),
            'status' => 'draft', 'previous_contract_id' => $c['id'], 'renewal_count' => $renew ? (int) $c['renewal_count'] + 1 : 0,
            'created_by' => $operator, 'updated_by' => $operator, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO employment_contracts (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')
            ->execute(array_values($d));
        $nid = (int) $pdo->lastInsertId();
        if (!$renew) {
            $pdo->prepare("UPDATE employment_contracts SET status = 'converted', updated_by = ?, updated_at = ? WHERE id = ?")->execute([$operator, date('Y-m-d H:i:s'), $c['id']]);
        }
        HrContract::close_todos($pdo, (int) $c['id'], ($renew ? t('hrc_renew') : t('hrc_convert')) . ' → ' . $d['contract_no'], $operator);
        $pdo->commit();
        audit($pdo, 'hr', $renew ? 'renew' : 'convert', 'employment_contracts', $nid, $d['contract_no'],
            sprintf('employment_contracts: %s → %s %s %s~%s; renewal_count %d', $c['contract_no'], $d['contract_no'], $d['contract_type'], Hr::dmy($start), Hr::dmy($end) ?: '—', $d['renewal_count']));
        if (!$renew) {
            audit($pdo, 'hr', 'convert', 'employment_contracts', (int) $c['id'], (string) $c['contract_no'], 'employment_contracts.status: ' . $c['status'] . ' → converted');
        }
        flash(sprintf(t($renew ? 'hrc_msg_renewed' : 'hrc_msg_converted'), $d['contract_no']));
        if ($warn) {
            flash(t('hrc_limit_warning'), 'error');
        }
        redirect('hr.contract', ['id' => $nid]);
        break;

    // Terminate: a draft is simply closed; an active / expired contract also
    // runs the HR-01 resignation (unless the employee has already left).
    case 'contract_terminate':
        Csrf::verify();
        $requireEdit();
        [$c, $e] = $findContract((int) input('id', 0));
        $back = ['hr.contract', ['id' => $c['id']]];
        $date = trim((string) input('termination_date', ''));
        $reason = trim((string) input('termination_reason', ''));
        if (!in_array($c['status'], ['draft', 'active', 'expired'], true)) {
            flash(t('hrc_err_locked'), 'error');
            redirect($back[0], $back[1]);
        }
        if (!Hr::valid_date($date) || $reason === '') {
            flash(t('hrc_err_terminate_req'), 'error');
            redirect($back[0], $back[1]);
        }
        if ($c['status'] !== 'draft' && $date < $c['start_date']) {
            flash(t('hrc_err_terminate_before'), 'error');
            redirect($back[0], $back[1]);
        }
        $pdo->prepare("UPDATE employment_contracts SET status = 'terminated', termination_date = ?, termination_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([$date, $reason, $operator, date('Y-m-d H:i:s'), $c['id']]);
        HrContract::close_todos($pdo, (int) $c['id'], t('hrc_terminate'), $operator);
        Hr::audit_fields($pdo, 'terminate', 'employment_contracts', (int) $c['id'], (string) $c['contract_no'],
            ['status' => $c['status'], 'termination_date' => $c['termination_date'], 'termination_reason' => $c['termination_reason']],
            ['status' => 'terminated', 'termination_date' => $date, 'termination_reason' => $reason]);
        if ($c['status'] !== 'draft') {
            if ($e['employment_status'] !== 'resigned') {
                Hr::resign($pdo, $e, $date, $reason, $operator);
            }
            // The resignation's "terminate the contract" todo is now done.
            $pdo->prepare("UPDATE hr_todos SET status = 'done', handled_by = ?, handled_at = datetime('now','localtime'), handled_note = ?
                           WHERE employee_id = ? AND type = 'contract_end' AND status = 'open'")
                ->execute([$operator, (string) $c['contract_no'], $e['id']]);
        }
        flash(t($c['status'] === 'draft' ? 'hrc_msg_draft_closed' : 'hrc_msg_terminated'));
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
