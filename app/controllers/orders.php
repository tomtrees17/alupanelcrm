<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

switch ($action) {
    case 'index':
        $statusFilter = (string) input('status', '');
        $sql = 'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id=o.id)+o.shipping_cost AS amount FROM orders o';
        $args = [];
        if ($statusFilter !== '') {
            $sql .= ' WHERE o.status = ?';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY o.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $counts = [];
        foreach ($pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        view('orders.index', [
            'orders' => $stmt->fetchAll(), 'counts' => $counts, 'statusFilter' => $statusFilter,
        ]);
        break;

    case 'show':
        $order = find_order($pdo, (int) input('id', 0));
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$order['id']]);
        $totals = order_totals($pdo, (int) $order['id']);
        $invoice = null;
        if ($order['invoice_number']) {
            $iv = $pdo->prepare('SELECT * FROM invoices WHERE invoice_no = ?');
            $iv->execute([$order['invoice_number']]);
            $invoice = $iv->fetch() ?: null;
        }
        $deliveryId = null;
        if ($order['do_number']) {
            $d = $pdo->prepare('SELECT id FROM delivery_orders WHERE do_no = ?');
            $d->execute([$order['do_number']]);
            $deliveryId = $d->fetchColumn() ?: null;
        }
        view('orders.show', [
            'pageTitle' => $order['order_no'], 'pageSub' => $order['company'],
            'order' => $order, 'items' => $items->fetchAll(), 'totals' => $totals,
            'invoice' => $invoice, 'deliveryId' => $deliveryId,
        ]);
        break;

    case 'create':
        view('orders.form', [
            'pageTitle' => '新建销售订单', 'pageSub' => '',
            'customers' => $pdo->query('SELECT id, name, company, phone FROM customers ORDER BY name')->fetchAll(),
            'products'  => $pdo->query('SELECT id, sku, color_en, color_zh, spec, size, price, stock FROM products ORDER BY sku LIMIT 400')->fetchAll(),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $id = create_order($pdo, $auth);
        flash('订单已提交，进入主管审批。');
        redirect('orders.show', ['id' => $id]);
        break;

    case 'approve':
        Csrf::verify();
        $order = find_order($pdo, (int) input('id', 0));
        approve_order($pdo, $auth, $order, trim((string) input('note', '')));
        redirect('orders.show', ['id' => $order['id']]);
        break;

    case 'reject':
        Csrf::verify();
        $order = find_order($pdo, (int) input('id', 0));
        reject_order($pdo, $auth, $order, trim((string) input('note', '')));
        redirect('orders.show', ['id' => $order['id']]);
        break;

    case 'delete':
        Csrf::verify();
        if (!$auth->isAdmin()) {
            flash('只有管理员可以删除订单。', 'error');
            redirect('orders.index');
        }
        $order = find_order($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        flash('订单已删除。');
        redirect('orders.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function create_order(PDO $pdo, Auth $auth): int
{
    $customerId = ((int) input('customer_id', 0)) ?: null;
    $custName = trim((string) input('customer_name', ''));
    if ($customerId) {
        $c = $pdo->prepare('SELECT name, company FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        if ($row = $c->fetch()) {
            $custName = $custName ?: $row['name'];
        }
    }
    if ($custName === '') {
        flash('请填写客户。', 'error');
        redirect('orders.create');
    }

    $skus = (array) input('sku', []);
    $colors = (array) input('color', []);
    $specs = (array) input('spec', []);
    $sizes = (array) input('size', []);
    $qtys = (array) input('qty', []);
    $prices = (array) input('price', []);
    $items = [];
    foreach ($skus as $i => $sku) {
        $sku = trim((string) $sku);
        $qty = (float) ($qtys[$i] ?? 0);
        if ($sku === '' && $qty <= 0) {
            continue;
        }
        $items[] = [$sku, trim((string) ($colors[$i] ?? '')), trim((string) ($specs[$i] ?? '')), trim((string) ($sizes[$i] ?? '')), $qty, (float) ($prices[$i] ?? 0)];
    }
    if (!$items) {
        flash('请至少添加一项产品。', 'error');
        redirect('orders.create');
    }

    // Block orders that exceed available stock.
    $short = stock_shortages($pdo, array_map(fn($it) => ['sku' => $it[0], 'spec' => $it[2], 'qty' => $it[4]], $items));
    if ($short) {
        flash(shortage_message($short), 'error');
        redirect('orders.create');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (order_no,customer_id,customer_name,company,address,phone,client_type,delivery_service,delivery_address,submitter,shipping_cost,delivery_date,note,payment_term,custom_days,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            next_order_no($pdo), $customerId, $custName, trim((string) input('company', '')),
            trim((string) input('address', '')), trim((string) input('phone', '')),
            (string) input('client_type', 'End User'), (string) input('delivery_service', 'Self Pickup'),
            trim((string) input('delivery_address', '')), $auth->user()['name'] ?? '',
            (float) input('shipping_cost', 0), (string) input('delivery_date', ''),
            trim((string) input('note', '')), (string) input('payment_term', 'CBD'),
            (int) input('custom_days', 0), 'pending_sup',
        ]);
        $oid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO order_items (order_id,sku,color,spec,size,qty,unit,price) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([$oid, $it[0], $it[1], $it[2], $it[3], $it[4], 'Unit', $it[5]]);
        }
        $pdo->commit();
        return $oid;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Check the current user may act on this order's pending stage. */
function can_act(Auth $auth, array $order): bool
{
    if ($auth->isAdmin()) {
        return true;
    }
    $need = order_action_role($order['status']);
    return $need !== null && ($auth->user()['role'] ?? '') === $need;
}

function approve_order(PDO $pdo, Auth $auth, array $order, string $note): void
{
    if (!can_act($auth, $order)) {
        flash('当前阶段无权审批。', 'error');
        return;
    }

    // Re-check stock at every approval stage; the flow cannot pass if insufficient.
    $oi = $pdo->prepare('SELECT sku, spec, qty FROM order_items WHERE order_id = ?');
    $oi->execute([$order['id']]);
    $short = stock_shortages($pdo, $oi->fetchAll());
    if ($short) {
        flash(shortage_message($short), 'error');
        return;
    }

    $name = $auth->user()['name'] ?? '';
    $today = date('Y-m-d');

    switch ($order['status']) {
        case 'pending_sup':
            $pdo->prepare('UPDATE orders SET status=?, sup_note=?, sup_approver=?, sup_date=? WHERE id=?')
                ->execute(['pending_mgr', $note, $name, $today, $order['id']]);
            flash('主管已通过，进入经理审批。');
            break;
        case 'pending_mgr':
            $pdo->prepare('UPDATE orders SET status=?, mgr_note=?, mgr_approver=?, mgr_date=? WHERE id=?')
                ->execute(['pending_wh', $note, $name, $today, $order['id']]);
            flash('经理已通过，进入仓库出货。');
            break;
        case 'pending_wh':
            fulfill_order($pdo, $order, $name, $note, $today);
            flash('仓库已确认出货：已扣库存并生成送货单与发票。');
            break;
        default:
            flash('该订单当前无需审批。', 'error');
    }
}

function reject_order(PDO $pdo, Auth $auth, array $order, string $note): void
{
    if (!can_act($auth, $order)) {
        flash('当前阶段无权操作。', 'error');
        return;
    }
    $name = $auth->user()['name'] ?? '';
    $today = date('Y-m-d');
    $col = ['pending_sup' => 'sup', 'pending_mgr' => 'mgr', 'pending_wh' => 'wh'][$order['status']] ?? null;
    if (!$col) {
        flash('该订单无法驳回。', 'error');
        return;
    }
    $pdo->prepare("UPDATE orders SET status='rejected', {$col}_note=?, {$col}_approver=?, {$col}_date=? WHERE id=?")
        ->execute([$note, $name, $today, $order['id']]);
    flash('订单已驳回。');
}

/** Warehouse confirmation: deduct stock, create DO + invoice. */
function fulfill_order(PDO $pdo, array $order, string $name, string $note, string $today): void
{
    $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $items->execute([$order['id']]);
    $rows = $items->fetchAll();

    $pdo->beginTransaction();
    try {
        // 1) Auto-deduct stock
        foreach ($rows as $it) {
            $pid = match_product_id($pdo, (string) $it['sku'], (string) $it['spec']);
            if ($pid) {
                adjust_stock($pdo, $pid, 'out_auto', (int) $it['qty'], $order['order_no'], '订单批准自动扣减');
            }
        }

        // 2) Delivery order
        $doNo = next_do_no($pdo);
        $pdo->prepare(
            'INSERT INTO delivery_orders (do_no,order_id,customer,company,address,phone,delivery_address,delivery_service,pickup_date,issued_by,note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $doNo, $order['id'], $order['customer_name'], $order['company'], $order['address'], $order['phone'],
            $order['delivery_address'], $order['delivery_service'], $today, $name, $order['note'],
        ]);

        // 3) Invoice — order prices are TAX-INCLUSIVE, so back the tax out.
        //    pre-tax = inclusive / (1 + rate); Subtotal + VAT = inclusive total.
        $ppnRate = (float) ($GLOBALS['config']['ppn_rate'] ?? 11);
        $div = 1 + $ppnRate / 100;            // 1.11

        $itemsSum = 0.0;
        $lines = [];
        foreach ($rows as $it) {
            $pretaxUnit = round((float) $it['price'] / $div, 2);   // shown on invoice line
            $lineAmt = round((float) $it['qty'] * $pretaxUnit);
            $itemsSum += $lineAmt;
            $lines[] = [$it, $pretaxUnit];
        }
        $pretaxShip = round((float) $order['shipping_cost'] / $div, 2);
        $subtotal = $itemsSum + round($pretaxShip);
        $ppn = round($subtotal * $ppnRate / 100);
        $total = $subtotal + $ppn;            // ≈ original tax-inclusive order amount
        $days = $order['payment_term'] === 'custom' ? (int) $order['custom_days'] : 0;
        $due = date('Y-m-d', strtotime($today . " +{$days} days"));
        $invNo = next_invoice_no($pdo);

        $pdo->prepare(
            'INSERT INTO invoices (invoice_no,order_id,do_number,customer,bill_to_name,address,currency,shipping_cost,subtotal,ppn,total,invoice_date,due_date,issued_by,payment_term,custom_days,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invNo, $order['id'], $doNo, $order['company'] ?: $order['customer_name'], $order['customer_name'],
            $order['address'], 'IDR', $pretaxShip, $subtotal, $ppn, $total, $today, $due,
            $name, $order['payment_term'], $order['custom_days'], 'pending',
        ]);
        $invId = (int) $pdo->lastInsertId();
        $iis = $pdo->prepare('INSERT INTO invoice_items (invoice_id,sku,color,spec,size,qty,unit,price) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($lines as [$it, $pretaxUnit]) {
            $iis->execute([$invId, $it['sku'], $it['color'], $it['spec'], $it['size'], $it['qty'], $it['unit'], $pretaxUnit]);
        }

        // 4) Mark order approved
        $pdo->prepare('UPDATE orders SET status=?, wh_note=?, wh_approver=?, wh_date=?, do_number=?, invoice_number=? WHERE id=?')
            ->execute(['approved', $note, $name, $today, $doNo, $invNo, $order['id']]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function find_order(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('订单不存在');
    }
    return $row;
}
