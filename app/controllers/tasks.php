<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

switch ($action) {
    case 'index':
        $filter = (string) input('filter', 'all');
        $sql = 'SELECT t.*, c.name AS customer_name FROM tasks t LEFT JOIN customers c ON c.id = t.customer_id';
        $args = [];
        switch ($filter) {
            case 'today': $sql .= " WHERE t.due_date = '2026-05-26'"; break;
            case 'high':  $sql .= " WHERE t.priority = '高' AND t.done = 0"; break;
            case 'done':  $sql .= ' WHERE t.done = 1'; break;
            case 'pending': $sql .= ' WHERE t.done = 0'; break;
        }
        $sql .= ' ORDER BY t.done, t.due_date';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $total = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $done  = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE done = 1')->fetchColumn();
        $high  = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE priority='高' AND done=0")->fetchColumn();
        view('tasks.index', [
            'pageTitle' => '任务提醒', 'pageSub' => '待办事项与提醒',
            'tasks' => $stmt->fetchAll(), 'filter' => $filter,
            'stats' => ['total' => $total, 'done' => $done, 'high' => $high, 'rate' => $total ? round($done / $total * 100) : 0],
            'customers' => $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $title = trim((string) input('title', ''));
        if ($title === '') {
            flash('任务标题必填。', 'error');
            redirect('tasks.index');
        }
        $pdo->prepare('INSERT INTO tasks (title,due_date,priority,customer_id,note) VALUES (?,?,?,?,?)')
            ->execute([
                $title, (string) input('due_date', ''), (string) input('priority', '中'),
                ((int) input('customer_id', 0)) ?: null, trim((string) input('note', '')),
            ]);
        flash('任务已添加。');
        redirect('tasks.index');
        break;

    case 'toggle':
        Csrf::verify();
        $id = (int) input('id', 0);
        $pdo->prepare('UPDATE tasks SET done = 1 - done WHERE id = ?')->execute([$id]);
        redirect('tasks.index', ['filter' => input('filter', 'all')]);
        break;

    case 'delete':
        Csrf::verify();
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([(int) input('id', 0)]);
        flash('任务已删除。');
        redirect('tasks.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
