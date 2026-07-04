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
            'req' => $req, 'refLink' => resolve_ref_link($pdo, (string) ($req['ref_no'] ?? '')),
            'files' => request_files($pdo, (int) $req['id']),
        ]);
        break;

    case 'file':
        // Stream an attachment; visibility enforced via find_request on its parent.
        $st = $pdo->prepare('SELECT * FROM request_files WHERE id = ?');
        $st->execute([(int) input('fid', 0)]);
        $f = $st->fetch();
        if (!$f) {
            http_response_code(404);
            exit('Not found');
        }
        find_request($pdo, (int) $f['request_id']);
        $path = request_upload_dir((int) $f['request_id']) . '/' . $f['stored'];
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) $f['orig_name']));
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;

    case 'delfile':
        Csrf::verify();
        $st = $pdo->prepare('SELECT * FROM request_files WHERE id = ?');
        $st->execute([(int) input('fid', 0)]);
        $f = $st->fetch();
        if (!$f) {
            http_response_code(404);
            exit('Not found');
        }
        $req = find_request($pdo, (int) $f['request_id']);
        if (!request_editable($req)) {
            flash(t('req_not_editable'), 'error');
            redirect('approvals.show', ['id' => $req['id']]);
        }
        @unlink(request_upload_dir((int) $f['request_id']) . '/' . $f['stored']);
        $pdo->prepare('DELETE FROM request_files WHERE id = ?')->execute([$f['id']]);
        flash(t('att_deleted'));
        redirect('approvals.edit', ['id' => $req['id']]);
        break;

    case 'create':
        view('approvals.form', [
            'pageTitle' => t('btn_add_request'), 'pageSub' => '', 'req' => null,
            'refOptions' => ref_options($pdo),
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
            'refOptions' => ref_options($pdo), 'files' => request_files($pdo, (int) $req['id']),
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
    $dest = trim((string) input('destination', ''));   // trip destination / payment payee
    $reason = trim((string) input('reason', ''));
    $start = trim((string) input('start_date', ''));
    $end = trim((string) input('end_date', ''));
    $amount = (float) input('amount', 0);
    // Linked order / invoice / request number (payment & expense only).
    $ref = in_array($type, ['payment', 'expense'], true) ? trim((string) input('ref_no', '')) : '';

    // Category doubles as expense/payment category / leave type; keep only canonical values.
    $category = (string) input('category', '');
    if ($type === 'expense' && !in_array($category, expense_categories(), true)) {
        $category = '其他';
    } elseif ($type === 'payment' && !in_array($category, payment_categories(), true)) {
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
        || ($type === 'payment' && ($amount <= 0 || $dest === ''))
        || ($type === 'leave' && ($start === '' || $end === ''));
    if ($missing) {
        flash(t('req_fill_required'), 'error');
        redirect($back[0], $back[1]);
    }
    if (in_array($type, ['trip', 'leave'], true) && $end !== '' && $end < $start) {
        $end = $start;
    }
    if (in_array($type, ['expense', 'payment'], true)) {
        $end = '';
    }
    if ($type === 'leave') {
        $amount = 0;
    }

    $status = $submit ? 'pending_mgr' : 'draft';

    if ($isEdit) {
        // Applicant never changes on edit; a fresh submit clears the previous rejection.
        $sql = 'UPDATE admin_requests SET type=?, title=?, destination=?, category=?, ref_no=?, start_date=?, end_date=?, amount=?, reason=?, status=?';
        $params = [$type, $title, $dest, $category, $ref, $start, $end, $amount, $reason, $status];
        if ($submit) {
            $sql .= ', reject_note=NULL, reject_by=NULL, reject_date=NULL, mgr_note=NULL, mgr_approver=NULL, mgr_date=NULL, fin_note=NULL, fin_approver=NULL, fin_date=NULL';
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int) $existing['id'];
        $pdo->prepare($sql)->execute($params);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO admin_requests (req_no,type,applicant,title,destination,category,ref_no,start_date,end_date,amount,reason,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            next_request_no($pdo, $type), $type, own_name(), $title, $dest, $category, $ref,
            $start, $end, $amount, $reason, $status,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    handle_request_uploads($pdo, $id);

    flash($submit ? t('req_submitted') : t('req_saved_draft'));
    return $id;
}

/** Absolute directory holding a request's attachments (outside the web root). */
function request_upload_dir(int $reqId): string
{
    return dirname(__DIR__, 2) . '/data/uploads/req/' . $reqId;
}

function request_files(PDO $pdo, int $reqId): array
{
    $st = $pdo->prepare('SELECT * FROM request_files WHERE request_id = ? ORDER BY id');
    $st->execute([$reqId]);
    return $st->fetchAll();
}

/** Magic-byte content sniffing for the allowed set (no ext-fileinfo dependency). */
function sniff_upload_mime(string $path): ?string
{
    $h = (string) @file_get_contents($path, false, null, 0, 16);
    if (strncmp($h, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'image/png';
    }
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (strncmp($h, '%PDF', 4) === 0) {
        return 'application/pdf';
    }
    return null;
}

/** Save validated uploads (images / PDF, ≤8MB each) for a request. */
function handle_request_uploads(PDO $pdo, int $reqId): void
{
    if (empty($_FILES['files']['name']) || !is_array($_FILES['files']['name'])) {
        return;
    }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $dir = request_upload_dir($reqId);

    foreach ($_FILES['files']['name'] as $i => $name) {
        if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $size = (int) ($_FILES['files']['size'][$i] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            flash(t('att_too_big'), 'error');
            continue;
        }
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        $tmp = (string) $_FILES['files']['tmp_name'][$i];
        // Extension AND actual file content must both be an allowed type.
        if (!isset($allowed[$ext]) || sniff_upload_mime($tmp) !== $allowed[$ext]) {
            flash(t('att_bad_type'), 'error');
            continue;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stored = bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($tmp, $dir . '/' . $stored)) {
            $pdo->prepare('INSERT INTO request_files (request_id, stored, orig_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?)')
                ->execute([$reqId, $stored, (string) $name, $allowed[$ext], $size, own_name()]);
        }
    }
}

/** Check the current user may act on this request's pending stage. */
function request_can_act(Auth $auth, array $req): bool
{
    if ($auth->isAdmin()) {
        return true;
    }
    // Separation of duties: you may not approve/reject your own request.
    if (($req['applicant'] ?? '') === ($auth->user()['name'] ?? '')) {
        return false;
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
            // Expenses & payment requests continue to finance; others are done.
            $next = request_needs_finance((string) $req['type']) ? 'pending_fin' : 'approved';
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

/** Recent document numbers offered as suggestions for the 关联单号 field. */
function ref_options(PDO $pdo): array
{
    $opts = [];
    foreach ([
        "SELECT req_no AS n FROM admin_requests WHERE COALESCE(req_no,'') <> '' ORDER BY id DESC LIMIT 30",
        "SELECT order_no AS n FROM orders WHERE COALESCE(order_no,'') <> '' ORDER BY id DESC LIMIT 30",
        "SELECT invoice_no AS n FROM invoices WHERE COALESCE(invoice_no,'') <> '' ORDER BY id DESC LIMIT 30",
    ] as $q) {
        foreach ($pdo->query($q) as $r) {
            $opts[] = (string) $r['n'];
        }
    }
    return array_values(array_unique($opts));
}

/** Resolve a linked number to an in-app URL (request / order / invoice), or null. */
function resolve_ref_link(PDO $pdo, string $ref): ?array
{
    if ($ref === '') {
        return null;
    }
    foreach ([
        ['SELECT id FROM admin_requests WHERE req_no = ?', 'approvals.show'],
        ['SELECT id FROM orders WHERE order_no = ?', 'orders.show'],
        ['SELECT id FROM invoices WHERE invoice_no = ?', 'finance.show'],
    ] as [$sql, $route]) {
        $st = $pdo->prepare($sql);
        $st->execute([$ref]);
        $id = $st->fetchColumn();
        if ($id) {
            return ['url' => url($route, ['id' => (int) $id]), 'label' => $ref];
        }
    }
    return null;
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
