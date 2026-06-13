<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

switch ($action) {
    case 'index':
        $rows = $pdo->query(
            "SELECT q.*, c.company
               FROM quotes q
               JOIN customers c ON c.id = q.customer_id
           ORDER BY q.id DESC"
        )->fetchAll();
        view('quotes.index', ['quotes' => $rows]);
        break;

    case 'create':
        view('quotes.form', [
            'quote'     => null,
            'items'     => [],
            'customers' => $pdo->query('SELECT id, company FROM customers ORDER BY company')->fetchAll(),
            'products'  => $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll(),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $id = save_quote($pdo, null);
        flash('报价单已创建。');
        redirect('quotes.show', ['id' => $id]);
        break;

    case 'show':
        $quote = find_quote($pdo, (int) input('id', 0));
        $customer = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $customer->execute([$quote['customer_id']]);
        $items = $pdo->prepare(
            'SELECT qi.*, p.name AS product_name
               FROM quote_items qi
          LEFT JOIN products p ON p.id = qi.product_id
              WHERE qi.quote_id = ?'
        );
        $items->execute([$quote['id']]);
        view('quotes.show', [
            'quote'    => $quote,
            'customer' => $customer->fetch(),
            'items'    => $items->fetchAll(),
        ]);
        break;

    case 'edit':
        $quote = find_quote($pdo, (int) input('id', 0));
        $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ?');
        $items->execute([$quote['id']]);
        view('quotes.form', [
            'quote'     => $quote,
            'items'     => $items->fetchAll(),
            'customers' => $pdo->query('SELECT id, company FROM customers ORDER BY company')->fetchAll(),
            'products'  => $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll(),
        ]);
        break;

    case 'update':
        Csrf::verify();
        $quote = find_quote($pdo, (int) input('id', 0));
        save_quote($pdo, $quote['id']);
        flash('报价单已更新。');
        redirect('quotes.show', ['id' => $quote['id']]);
        break;

    case 'status':
        Csrf::verify();
        $quote  = find_quote($pdo, (int) input('id', 0));
        $status = (string) input('status', 'draft');
        if (in_array($status, status_list(), true)) {
            $pdo->prepare('UPDATE quotes SET status = ? WHERE id = ?')
                ->execute([$status, $quote['id']]);
            flash('状态已更新为「' . status_label($status) . '」。');
        }
        redirect('quotes.show', ['id' => $quote['id']]);
        break;

    case 'delete':
        Csrf::verify();
        $quote = find_quote($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$quote['id']]);
        flash('报价单已删除。');
        redirect('quotes.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

/**
 * Insert or update a quote together with its line items.
 * Returns the quote id.
 */
function save_quote(PDO $pdo, ?int $id): int
{
    $customerId = (int) input('customer_id', 0);
    if ($customerId <= 0) {
        flash('请选择客户。', 'error');
        redirect($id ? 'quotes.edit' : 'quotes.create', $id ? ['id' => $id] : []);
    }

    $taxRate     = (float) input('tax_rate', 0);
    $status      = (string) input('status', 'draft');
    $quoteDate   = (string) input('quote_date', date('Y-m-d'));
    $validUntil  = (string) input('valid_until', '');
    $notes       = trim((string) input('notes', ''));

    // Build line items from parallel arrays.
    $productIds  = (array) input('product_id', []);
    $descs       = (array) input('item_desc', []);
    $qtys        = (array) input('qty', []);
    $prices      = (array) input('unit_price', []);

    $items = [];
    $subtotal = 0.0;
    foreach ($descs as $i => $desc) {
        $desc  = trim((string) $desc);
        $qty   = (float) ($qtys[$i] ?? 0);
        $price = (float) ($prices[$i] ?? 0);
        $pid   = (int) ($productIds[$i] ?? 0) ?: null;
        if ($desc === '' && $qty <= 0) {
            continue;
        }
        $lineTotal = round($qty * $price, 2);
        $subtotal += $lineTotal;
        $items[] = [$pid, $desc, $qty, $price, $lineTotal];
    }

    $taxAmount = round($subtotal * $taxRate / 100, 2);
    $total     = round($subtotal + $taxAmount, 2);

    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO quotes (customer_id, status, quote_date, valid_until, notes, tax_rate, subtotal, tax_amount, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$customerId, $status, $quoteDate, $validUntil, $notes, $taxRate, $subtotal, $taxAmount, $total]);
            $id = (int) $pdo->lastInsertId();
            $quoteNo = 'Q' . date('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE quotes SET quote_no = ? WHERE id = ?')->execute([$quoteNo, $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE quotes SET customer_id=?, status=?, quote_date=?, valid_until=?, notes=?, tax_rate=?, subtotal=?, tax_amount=?, total=?
                 WHERE id = ?'
            );
            $stmt->execute([$customerId, $status, $quoteDate, $validUntil, $notes, $taxRate, $subtotal, $taxAmount, $total, $id]);
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO quote_items (quote_id, product_id, description, qty, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $it) {
            $ins->execute([$id, $it[0], $it[1], $it[2], $it[3], $it[4]]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return $id;
}

function find_quote(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('报价单不存在');
    }
    return $row;
}
