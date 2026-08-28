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

    case 'word':
        // Editable Word copy — finance staff / warehouse admin only.
        if (!can_word_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('delivery.index');
        }
        $do = find_do($pdo, (int) input('id', 0));
        $items = [];
        if ($do['order_id']) {
            $st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $st->execute([$do['order_id']]);
            $items = $st->fetchAll();
        }
        if ($do['order_id']) {
            $o = $pdo->prepare('SELECT order_no FROM orders WHERE id = ?');
            $o->execute([$do['order_id']]);
            $do['order_no'] = (string) ($o->fetchColumn() ?: '');
        }
        $cfg = $GLOBALS['config'];
        ob_start();
        include __DIR__ . '/../../views/word/do.php';
        Word::download('SuratJalan_' . $do['do_no'], (string) ob_get_clean());
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
