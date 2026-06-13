<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

switch ($action) {
    case 'index':
        $rows = $pdo->query(
            'SELECT d.*, o.order_no FROM delivery_orders d
          LEFT JOIN orders o ON o.id = d.order_id
          ORDER BY d.id DESC'
        )->fetchAll();
        view('delivery.index', ['dos' => $rows]);
        break;

    case 'print':
        $do = find_do($pdo, (int) input('id', 0));
        // DO items mirror the source order's items.
        $items = [];
        if ($do['order_id']) {
            $st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $st->execute([$do['order_id']]);
            $items = $st->fetchAll();
        }
        view('print.do', ['do' => $do, 'items' => $items], false);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function find_do(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('送货单不存在');
    }
    return $row;
}
