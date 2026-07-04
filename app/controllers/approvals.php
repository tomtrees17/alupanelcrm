<?php
declare(strict_types=1);

/**
 * Administrative requests: business trip / expense reimbursement / leave.
 * Flow: draft → pending_mgr → (expense: pending_fin) → approved.
 * Reject at any stage sends the request back to draft for revision.
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 */

switch ($action) {
    case 'index':
        $typeFilter   = in_array(input('type'), request_types(), true) ? (string) input('type') : '';
        $statusFilter = in_array(input('status'), request_statuses(), true) ? (string) input('status') : '';
        $cond = [];
        $args = [];
        if ($typeFilter !== '') {
            $cond[] = 'type = ?';
            $args[] = $typeFilter;
        }
        if ($statusFilter !== '') {
            $cond[] = 'status = ?';
            $args[] = $statusFilter;
        }
        if (!approvals_sees_all()) {
            $cond[] = 'applicant = ?';
            $args[] = own_name();
        }
        $sql = 'SELECT * FROM admin_requests' . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '') . ' ORDER BY id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        // Status counts (respecting the same visibility scope + type filter).
        $ccond = [];
        $cargs = [];
        if ($typeFilter !== '') {
            $ccond[] = 'type = ?';
            $cargs[] = $typeFilter;
        }
        if (!approvals_sees_all()) {
            $ccond[] = 'applicant = ?';
            $cargs[] = own_name();
        }
        $cs = $pdo->prepare('SELECT status, COUNT(*) c FROM admin_requests' . ($ccond ? ' WHERE ' . implode(' AND ', $ccond) : '') . ' GROUP BY status');
        $cs->execute($cargs);
        $counts = [];
        foreach ($cs->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }

        view('approvals.index', [
            'requests' => $stmt->fetchAll(), 'counts' => $counts,
            'typeFilter' => $typeFilter, 'statusFilter' => $statusFilter,
        ]);
        break;

    case 'show':
        $req = find_request($pdo, (int) input('id', 0));
        view('approvals.show', [
            'pageTitle' => $req['req_no'], 'pageSub' => request_type_label($req['type']),
            'req' => $req,
        ]);
        break;

    case 'create':
        view('approvals.form', [
            'pageTitle' => t('btn_add_request'), 'pageSub' => '', 'req' => null,
        ]);
        break;

    case 'store':
        Csrf::verify();
        $id = save_request($pdo, $auth, null);
        redirect('approvals.show', ['id' => $id]);
        break;

    case 'edit':
        $req = find_request($pdo, (int) input('id', 0));
        if (!request_editable($req)) {
            flash(t('req_not_editable'), 'error');
            redirect('approvals.show', ['id' => $req['id']]);
        }
        view('approvals.form', [
            'pageTitle' => t('btn_edit') . ' ' . $req['req_no'], 'pageSub' => '', 'req' => $req,
        ]);
        break;

    case 'update':
        Csrf::verify();
        $req = find_request($pdo, (int) input('id', 0));
        if (!request_editable($req)) {
            flash(t('req_not_editable'), 'error');
            redirect('approvals.show', ['id' => $req['id']]);
        }
        save_request($pdo, $auth, $req);
        redirect('approvals.show', ['id' => $req['id']]);
        break;

    case 'submit':
        Csrf::verify();
        $req = find_request($pdo, (int) input('id', 0));
        if (!request_editable($req)) {
            flash(t('req_not_editable'), 'error');
            redirect('approvals.show', ['id' => $req['id']]);
        }
        $pdo->prepare("UPDATE admin_requests SET status='pending_mgr', reject_note=NULL, reject_by=NULL, reject_date=NULL WHERE id=?")
            ->execute([$req['id']]);
        flash(t('req_submitted'));
        redirect('approvals.show', ['id' => $req['id']]);
        break;

    case 'approve':
        Csrf::verify();
        $req = find_request($pdo, (int) input('id', 0));
        approve_request($pdo, $auth, $req, trim((string) input('note', '')));
        redirect('approvals.show', ['id' => $req['id']]);
        break;

    case 'reject':
        Csrf::verify();
        $req = find_request($pdo, (int) input('id', 0));
        reject_request($pdo, $auth, $req, trim((string) input('note', '')));
        redirect('approvals.show', ['id' => $req['id']]);
        break;

    case 'delete':
        Csrf::verify();
        $req = find_request($pdo, (int) input('id', 0));
        if (!$auth->isAdmin() && !request_editable($req)) {
            flash(t('req_not_editable'), 'error');
            redirect('approvals.show', ['id' => $req['id']]);
        }
        $pdo->prepare('DELETE FROM admin_requests WHERE id = ?')->execute([$req['id']]);
        flash(t('req_deleted'));
        redirect('approvals.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

/**
 * Create or update a request from the form. "do" decides: submit → pending_mgr, else draft.
 * Returns the request id.
 */
function save_request(PDO $pdo, Auth $auth, ?array $existing): int
{
    $isEdit = $existing !== null;
    $back = $isEdit ? ['approvals.edit', ['id' => $existing['id']]] : ['approvals.create', []];
    $submit = (string) input('do', 'submit') === 'submit';

    $type = in_array(input('type'), request_types(), true)
        ? (string) input('type')
        : ($isEdit ? (string) $existing['type'] : 'expense');
    $title = trim((string) input('title', ''));
    $dest = trim((string) input('destination', ''));
    $reason = trim((string) input('reason', ''));
    $start = trim((string) input('start_date', ''));
    $end = trim((string) input('end_date', ''));
    $amount = (float) input('amount', 0);

    // Category doubles as expense category / leave type; keep only canonical values.
    $category = (string) input('category', '');
    if ($type === 'expense' && !in_array($category, expense_categories(), true)) {
        $category = '其他';
    } elseif ($type === 'leave' && !in_array($category, leave_types(), true)) {
        $category = '事假';
    } elseif ($type === 'trip') {
        $category = '';
    }

    // Per-type required fields.
    $missing = $title === ''
        || ($type === 'trip' && ($dest === '' || $start === ''))
        || ($type === 'expense' && ($amount <= 0 || $start === ''))
        || ($type === 'leave' && ($start === '' || $end === ''));
    if ($missing) {
        flash(t('req_fill_required'), 'error');
        redirect($back[0], $back[1]);
    }
    if ($type !== 'expense' && $end !== '' && $end < $start) {
        $end = $start;
    }
    if ($type === 'expense') {
        $end = '';
    }
    if ($type === 'leave') {
        $amount = 0;
    }

    $status = $submit ? 'pending_mgr' : 'draft';

    if ($isEdit) {
        // Applicant never changes on edit; a fresh submit clears the previous rejection.
        $sql = 'UPDATE admin_requests SET type=?, title=?, destination=?, category=?, start_date=?, end_date=?, amount=?, reason=?, status=?';
        $params = [$type, $title, $dest, $category, $start, $end, $amount, $reason, $status];
        if ($submit) {
            $sql .= ', reject_note=NULL, reject_by=NULL, reject_date=NULL, mgr_note=NULL, mgr_approver=NULL, mgr_date=NULL, fin_note=NULL, fin_approver=NULL, fin_date=NULL';
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int) $existing['id'];
        $pdo->prepare($sql)->execute($params);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO admin_requests (req_no,type,applicant,title,destination,category,start_date,end_date,amount,reason,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            next_request_no($pdo, $type), $type, own_name(), $title, $dest, $category,
            $start, $end, $amount, $reason, $status,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    flash($submit ? t('req_submitted') : t('req_saved_draft'));
    return $id;
}

/** Check the current user may act on this request's pending stage. */
function request_can_act(Auth $auth, array $req): bool
{
    if ($auth->isAdmin()) {
        return true;
    }
    $need = request_action_role($req['status']);
    return $need !== null && ($auth->user()['role'] ?? '') === $need;
}

function approve_request(PDO $pdo, Auth $auth, array $req, string $note): void
{
    if (!request_can_act($auth, $req)) {
        flash(t('wait_for') . ' ' . role_label(request_action_role($req['status']) ?? '') . t('no_permission_stage'), 'error');
        return;
    }
    $name = $auth->user()['name'] ?? '';
    $today = date('Y-m-d');

    switch ($req['status']) {
        case 'pending_mgr':
            // Expenses continue to finance for payment confirmation; others are done.
            $next = $req['type'] === 'expense' ? 'pending_fin' : 'approved';
            $pdo->prepare('UPDATE admin_requests SET status=?, mgr_note=?, mgr_approver=?, mgr_date=? WHERE id=?')
                ->execute([$next, $note, $name, $today, $req['id']]);
            flash($next === 'pending_fin' ? t('req_to_fin') : t('req_approved'));
            break;
        case 'pending_fin':
            $pdo->prepare("UPDATE admin_requests SET status='approved', fin_note=?, fin_approver=?, fin_date=? WHERE id=?")
                ->execute([$note, $name, $today, $req['id']]);
            flash(t('req_approved'));
            break;
        default:
            flash(t('req_not_editable'), 'error');
    }
}

function reject_request(PDO $pdo, Auth $auth, array $req, string $note): void
{
    if (!request_can_act($auth, $req)) {
        flash(t('wait_for') . ' ' . role_label(request_action_role($req['status']) ?? '') . t('no_permission_stage'), 'error');
        return;
    }
    if (!in_array($req['status'], ['pending_mgr', 'pending_fin'], true)) {
        flash(t('req_not_editable'), 'error');
        return;
    }
    $pdo->prepare(
        "UPDATE admin_requests SET status='draft', reject_note=?, reject_by=?, reject_date=?,
             mgr_note=NULL, mgr_approver=NULL, mgr_date=NULL,
             fin_note=NULL, fin_approver=NULL, fin_date=NULL
         WHERE id=?"
    )->execute([$note, $auth->user()['name'] ?? '', date('Y-m-d'), $req['id']]);
    flash(t('req_rejected'));
}

function find_request(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM admin_requests WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Not found');
    }
    if (!approvals_sees_all() && ($row['applicant'] ?? '') !== own_name()) {
        http_response_code(403);
        flash(t('my_only_hint'), 'error');
        redirect('approvals.index');
    }
    return $row;
}
