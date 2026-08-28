<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var array $config */

/**
 * Audit trail viewer. The log itself is append-only — this module is read-only
 * by design (no edit/delete actions), so a compromised account cannot erase
 * its own tracks through the UI.
 *
 * Route access is guarded in public/index.php via can_access('audit'),
 * which nobody but admin has until granted in 权限设置.
 */

const AUDIT_PER_PAGE = 50;

switch ($action) {
    case 'index':
        $module = trim((string) input('module', ''));
        $act    = trim((string) input('action_f', ''));
        $userId = (int) input('user_id', 0);
        $from   = trim((string) input('from', ''));
        $to     = trim((string) input('to', ''));
        $q      = trim((string) input('q', ''));
        $page   = max(1, (int) input('page', 1));

        $cond = [];
        $args = [];
        if ($module !== '' && in_array($module, audit_modules(), true)) {
            $cond[] = 'module = ?';
            $args[] = $module;
        }
        if ($act !== '' && in_array($act, audit_actions(), true)) {
            $cond[] = 'action = ?';
            $args[] = $act;
        }
        if ($userId > 0) {
            $cond[] = 'user_id = ?';
            $args[] = $userId;
        }
        if ($from !== '') {
            $cond[] = 'created_at >= ?';
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $cond[] = 'created_at <= ?';
            $args[] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $cond[] = '(label LIKE ? OR detail LIKE ? OR user_name LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log' . $where);
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $pages  = max(1, (int) ceil($total / AUDIT_PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * AUDIT_PER_PAGE;

        // LIMIT/OFFSET are ints derived above, never raw input.
        $stmt = $pdo->prepare('SELECT * FROM audit_log' . $where . ' ORDER BY id DESC LIMIT ' . AUDIT_PER_PAGE . ' OFFSET ' . $offset);
        $stmt->execute($args);

        view('audit.index', [
            'rows'    => $stmt->fetchAll(),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'module'  => $module,
            'act'     => $act,
            'userId'  => $userId,
            'from'    => $from,
            'to'      => $to,
            'q'       => $q,
            'users'   => $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll(),
        ]);
        break;

    case 'notifications':
        // Delivery log for the WhatsApp queue. Read-only, same admin-only guard
        // as the audit trail: it shows staff phone numbers and message contents.
        $st = (string) input('st', '');
        $cond = in_array($st, ['queued', 'sent', 'failed', 'skipped'], true) ? ' WHERE n.status = ?' : '';
        $args = $cond !== '' ? [$st] : [];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications n' . $cond);
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, (int) input('page', 1));
        $pages = max(1, (int) ceil($total / AUDIT_PER_PAGE));
        $page = min($page, $pages);

        $rows = $pdo->prepare(
            'SELECT n.*, u.name AS user_name FROM notifications n
             LEFT JOIN users u ON u.id = n.user_id' . $cond .
            ' ORDER BY n.id DESC LIMIT ' . AUDIT_PER_PAGE . ' OFFSET ' . (($page - 1) * AUDIT_PER_PAGE)
        );
        $rows->execute($args);

        $counts = [];
        foreach ($pdo->query('SELECT status, COUNT(*) c FROM notifications GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }

        view('audit.notifications', [
            'rows'   => $rows->fetchAll(),
            'total'  => $total,
            'page'   => $page,
            'pages'  => $pages,
            'st'     => $st,
            'counts' => $counts,
            'driver' => (string) ($config['wa']['driver'] ?? 'log'),
        ]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
