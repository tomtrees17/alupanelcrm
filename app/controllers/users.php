<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

// Signature images are printed on documents every logged-in user may print,
// so fetching one is not an admin action; everything else here is.
if ($action === 'signature') {
    $col = input('kind') === 'invoice' ? 'invoice_signature_path' : 'signature_path';   // CO vs invoice signature (owner 2026-11-03)
    $st = $pdo->prepare("SELECT {$col} FROM users WHERE id = ?");
    $st->execute([(int) input('id', 0)]);
    $file = (string) ($st->fetchColumn() ?: '');
    $path = signature_dir() . '/' . basename($file);
    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (!$auth->isAdmin()) {
    http_response_code(403);
    flash(t('msg_user_admin_only'), 'error');
    redirect('dashboard.index');
}

$roles = all_roles();

/**
 * Store an uploaded signature for a user; returns the new file name, '' when
 * nothing was uploaded, or null when the file is not an image we accept.
 */
function store_signature(int $userId, string $field = 'signature'): ?string
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int) $f['size'] > 1024 * 1024) {
        return null;
    }
    $info = @getimagesize((string) $f['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) {
        return null;
    }
    $name = ($field === 'invoice_signature' ? 'sig-inv-' : 'sig-') . $userId . '-' . time() . '.' . $ext;
    $moved = is_uploaded_file((string) $f['tmp_name'])
        ? move_uploaded_file((string) $f['tmp_name'], signature_dir() . '/' . $name)
        : copy((string) $f['tmp_name'], signature_dir() . '/' . $name);   // test harness
    return $moved ? $name : null;
}

switch ($action) {
    case 'index':
        $users = $pdo->query('SELECT id,name,email,role,title,created_at FROM users ORDER BY id')->fetchAll();
        view('users.index', ['users' => $users]);
        break;

    case 'create':
        view('users.form', ['pageTitle' => t('btn_new') . ' · ' . t('nav_users'), 'pageSub' => '', 'user' => null, 'roles' => $roles]);
        break;

    case 'store':
        Csrf::verify();
        $name = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role = in_array(input('role'), $roles, true) ? input('role') : 'sales';
        $title = trim((string) input('title', ''));
        $phone = Notify::normalise_phone((string) input('phone', ''));
        $lang = in_array(input('lang'), ['zh', 'id'], true) ? (string) input('lang') : 'id';
        $pass = (string) input('password', '');
        if ($name === '' || $email === '' || strlen($pass) < 6) {
            flash(t('msg_user_req_pwd'), 'error');
            redirect('users.create');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (name,email,password_hash,role,title,phone,lang) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $title, $phone, $lang]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash(t('msg_user_email_used'), 'error');
            redirect('users.create');
        }
        $uid = (int) $pdo->lastInsertId();
        audit(
            $pdo,
            'users',
            'create',
            'user',
            $uid,
            $name,
            sprintf('邮箱: %s; 角色: %s; 职位: %s; WhatsApp: %s', $email, role_label((string) $role), $title ?: '(空)', $phone ?: '(未填)')
        );
        $emp = Hr::employee_for_user($pdo, $uid, $name, (string) $role, own_name());
        $pdo->commit();
        flash(t('msg_user_created') . ($emp ? ' ' . sprintf(t($emp[2] ? 'msg_user_emp_linked' : 'msg_user_emp_created'), $emp[1]) : ''));
        redirect('users.index');
        break;

    case 'edit':
        $user = find_user($pdo, (int) input('id', 0));
        view('users.form', ['pageTitle' => t('btn_edit') . ' · ' . t('nav_users'), 'pageSub' => '', 'user' => $user, 'roles' => $roles,
            'isSigner' => strcasecmp(trim((string) $user['name']), trim((string) ($config['signer_name'] ?? ''))) === 0 || !empty($user['invoice_signature_path'])]);
        break;

    case 'update':
        Csrf::verify();
        $user = find_user($pdo, (int) input('id', 0));
        $name = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role = in_array(input('role'), $roles, true) ? input('role') : $user['role'];
        $title = trim((string) input('title', ''));
        $phone = Notify::normalise_phone((string) input('phone', ''));
        $lang = in_array(input('lang'), ['zh', 'id'], true) ? (string) input('lang') : (string) ($user['lang'] ?? 'id');
        $pass = (string) input('password', '');
        if ($name === '' || $email === '') {
            flash(t('msg_user_req'), 'error');
            redirect('users.edit', ['id' => $user['id']]);
        }
        if ($pass !== '') {
            $pdo->prepare('UPDATE users SET name=?,email=?,role=?,title=?,phone=?,lang=?,password_hash=? WHERE id=?')
                ->execute([$name, $email, $role, $title, $phone, $lang, password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name=?,email=?,role=?,title=?,phone=?,lang=? WHERE id=?')
                ->execute([$name, $email, $role, $title, $phone, $lang, $user['id']]);
        }
        // Signature images (CO, invoice): replace, remove, or keep.
        $sigNotes = [];
        foreach (['signature' => ['signature_path', '签名'], 'invoice_signature' => ['invoice_signature_path', '发票签名']] as $field => [$col, $label]) {
            if (input('remove_' . $field)) {
                $pdo->prepare("UPDATE users SET {$col} = NULL WHERE id = ?")->execute([$user['id']]);
                $sigNotes[] = '删除了' . $label;
                continue;
            }
            $sig = store_signature((int) $user['id'], $field);
            if ($sig === null) {
                flash(t('msg_signature_bad'), 'error');
            } elseif ($sig !== '') {
                $pdo->prepare("UPDATE users SET {$col} = ? WHERE id = ?")->execute([$sig, $user['id']]);
                $sigNotes[] = '上传了' . $label;
            }
        }
        $sigNote = implode('; ', $sigNotes);
        // Role changes are the security-relevant part — spell them out in words.
        $diff = audit_diff(
            ['name' => $user['name'], 'email' => $user['email'], 'title' => $user['title'],
             'role' => role_label((string) $user['role']), 'phone' => $user['phone'] ?? '', 'lang' => $user['lang'] ?? ''],
            ['name' => $name, 'email' => $email, 'title' => $title,
             'role' => role_label((string) $role), 'phone' => $phone, 'lang' => $lang],
            ['name' => '姓名', 'email' => '邮箱', 'role' => '角色', 'title' => '职位', 'phone' => 'WhatsApp', 'lang' => '语言']
        );
        if ($pass !== '') {
            $diff = ($diff !== '' ? $diff . '; ' : '') . '重置了密码';
        }
        if ($sigNote !== '') {
            $diff = ($diff !== '' ? $diff . '; ' : '') . $sigNote;
        }
        audit($pdo, 'users', 'update', 'user', (int) $user['id'], $name, $diff !== '' ? $diff : '(无字段变化)');
        flash(t('msg_user_updated'));
        redirect('users.index');
        break;

    case 'delete':
        Csrf::verify();
        $user = find_user($pdo, (int) input('id', 0));
        if ((int) $user['id'] === (int) ($auth->user()['id'] ?? 0)) {
            flash(t('msg_user_self_del'), 'error');
            redirect('users.index');
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
        audit(
            $pdo,
            'users',
            'delete',
            'user',
            (int) $user['id'],
            (string) $user['name'],
            sprintf('邮箱: %s; 角色: %s', (string) $user['email'], role_label((string) $user['role']))
        );
        flash(t('msg_user_deleted'));
        redirect('users.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function find_user(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('用户不存在');
    }
    return $row;
}
