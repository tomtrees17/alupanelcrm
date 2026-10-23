<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

switch ($action) {
    case 'index':
        $statusFilter = (string) input('status', '');
        $q = trim((string) input('q', ''));
        $cond = [];
        $args = [];
        if ($statusFilter !== '') {
            $cond[] = 'o.status = ?';
            $args[] = $statusFilter;
        }
        if ($q !== '') {
            $cond[] = '(o.order_no LIKE ? OR o.co_no LIKE ? OR o.customer_name LIKE ? OR o.company LIKE ? OR o.submitter LIKE ? OR o.invoice_number LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (sees_only_own()) {
            $cond[] = 'o.submitter = ?';
            $args[] = own_name();
        }
        $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
        $pg = paginate($pdo, 'SELECT COUNT(*) FROM orders o' . $where, $args);
        // Line totals are summed for the visible page only.
        $stmt = $pdo->prepare(
            'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id=o.id)+o.shipping_cost AS amount FROM orders o'
            . $where . ' ORDER BY o.id DESC' . $pg['limit']
        );
        $stmt->execute($args);

        $counts = [];
        if (sees_only_own()) {
            $cs = $pdo->prepare('SELECT status, COUNT(*) c FROM orders WHERE submitter = ? GROUP BY status');
            $cs->execute([own_name()]);
            $countRows = $cs->fetchAll();
        } else {
            $countRows = $pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll();
        }
        foreach ($countRows as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        view('orders.index', [
            'orders' => $stmt->fetchAll(), 'counts' => $counts, 'statusFilter' => $statusFilter,
            'q' => $q, 'pg' => $pg,
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
            'canLinkCo' => co_can_link($auth, $order), 'coAvailable' => CoNumbers::available($pdo, 120, $order['co_no'] ?? null),
            'order' => $order, 'items' => $items->fetchAll(), 'totals' => $totals,
            'invoice' => $invoice, 'deliveryId' => $deliveryId,
        ]);
        break;

    case 'create':
        view('orders.form', [
            'pageTitle' => t('btn_add_order'), 'pageSub' => '', 'order' => null, 'items' => [],
            'customers' => order_customer_list($pdo), 'products' => order_product_list($pdo),
            'canAssignSales' => !sees_only_own(), 'salesStaff' => order_sales_list($pdo),
            'coAvailable' => CoNumbers::available($pdo), 'nextNo' => next_order_no($pdo),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $id = save_order($pdo, $auth, null);
        redirect('orders.show', ['id' => $id]);
        break;

    case 'edit':
        $order = find_order($pdo, (int) input('id', 0));
        if (!order_editable($order)) {
            flash(t('msg_order_locked_draft'), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        $its = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $its->execute([$order['id']]);
        view('orders.form', [
            'pageTitle' => t('btn_edit') . ' ' . $order['order_no'], 'pageSub' => '',
            'order' => $order, 'items' => $its->fetchAll(),
            'customers' => order_customer_list($pdo), 'products' => order_product_list($pdo),
            'canAssignSales' => !sees_only_own(), 'salesStaff' => order_sales_list($pdo, $order['submitter']),
            'coAvailable' => CoNumbers::available($pdo, 60, $order['co_no'] ?? null), 'nextNo' => null,
        ]);
        break;

    case 'update':
        Csrf::verify();
        $order = find_order($pdo, (int) input('id', 0));
        if (!order_editable($order)) {
            flash(t('msg_order_locked'), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        save_order($pdo, $auth, $order);
        redirect('orders.show', ['id' => $order['id']]);
        break;

    case 'submit':
        Csrf::verify();
        $order = find_order($pdo, (int) input('id', 0));
        if (!order_editable($order)) {
            flash(t('msg_order_no_submit'), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        submit_order($pdo, $order);
        redirect('orders.show', ['id' => $order['id']]);
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
        $order = find_order($pdo, (int) input('id', 0));
        if (!$auth->isAdmin() && !order_editable($order)) {
            flash(t('msg_order_del_own'), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        // A shipped order has an invoice and a delivery note against it. Deleting
        // the order would only null their order_id, leaving orphaned paperwork on
        // the finance list forever — refuse, and point at the cleanup tool.
        $linked = $pdo->prepare('SELECT invoice_no FROM invoices WHERE order_id = ?');
        $linked->execute([$order['id']]);
        $invNo = (string) ($linked->fetchColumn() ?: '');
        if ($invNo !== '') {
            flash(sprintf(t('msg_order_has_invoice'), $invNo), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        CoNumbers::unlink($pdo, (int) $order['id']);   // the form number goes back to the pool
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);
        recompute_reservations($pdo);   // release any reserved stock
        audit(
            $pdo,
            'orders',
            'delete',
            'order',
            (int) $order['id'],
            (string) $order['order_no'],
            sprintf('客户: %s; 状态: %s', (string) $order['customer_name'], order_status_label((string) $order['status']))
        );
        flash(t('msg_order_deleted'));
        redirect('orders.index');
        break;

    case 'print_co':
        // Confirmation Order for the customer — replaces the Google-Sheet template.
        $order = find_order($pdo, (int) input('id', 0));
        $its = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $its->execute([$order['id']]);
        $u = $pdo->prepare('SELECT name, title FROM users WHERE lower(name) = lower(?) LIMIT 1');
        $u->execute([(string) $order['submitter']]);
        view('print.co', ['order' => $order, 'items' => $its->fetchAll(), 'sales' => $u->fetch() ?: []], false);
        break;

    // ── CO numbers (Google-Form register) ──────────────────────────────────
    case 'co_index':
        $q = trim((string) input('q', ''));
        $only = (string) input('only', '');
        $cond = [];
        $args = [];
        if ($q !== '') {
            $cond[] = '(r.co_no LIKE ? OR r.sales_name LIKE ? OR r.company LIKE ? OR r.client_name LIKE ? OR r.phone LIKE ? OR o.order_no LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($only === 'open') {
            $cond[] = 'r.order_id IS NULL';
        } elseif ($only === 'linked') {
            $cond[] = 'r.order_id IS NOT NULL';
        }
        $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
        $base = ' FROM co_requests r LEFT JOIN orders o ON o.id = r.order_id' . $where;
        $pg = paginate($pdo, 'SELECT COUNT(*)' . $base, $args);
        $st = $pdo->prepare('SELECT r.*, o.order_no, o.status AS order_status' . $base . ' ORDER BY r.seq DESC' . $pg['limit']);
        $st->execute($args);
        $stats = $pdo->query('SELECT COUNT(*) total, SUM(order_id IS NULL) open, MAX(seq) max_seq, MAX(issued_at) last_issued FROM co_requests')->fetch() ?: [];
        view('orders.co_index', ['rows' => $st->fetchAll(), 'q' => $q, 'only' => $only, 'pg' => $pg, 'stats' => $stats, 'nextNo' => next_order_no($pdo),
                                 'canPickSales' => !sees_only_own(), 'salesStaff' => order_sales_list($pdo)]);
        break;

    case 'co_reserve':
        Csrf::verify();
        $company = trim((string) input('company', ''));
        $client = trim((string) input('client_name', ''));
        if ($company === '' && $client === '') {
            flash(t('msg_co_reserve_req'), 'error');
            redirect('orders.co_index');
        }
        $sales = sees_only_own() ? own_name() : (trim((string) input('sales_name', '')) ?: own_name());
        $r = CoNumbers::reserve($pdo, $sales, $company, $client, trim((string) input('phone', '')), own_name());
        audit($pdo, 'orders', 'create', 'co_request', $r['seq'], $r['co_no'], sprintf('领号: %s / %s (%s)', $company, $client, $sales));
        flash(sprintf(t('msg_co_reserved'), $r['co_no']));
        redirect('orders.co_index', ['q' => $r['co_no']]);
        break;

    case 'link_co':
        Csrf::verify();
        $order = find_order($pdo, (int) input('id', 0));
        if (!co_can_link($auth, $order)) {
            flash(t('msg_co_no_perm'), 'error');
            redirect('orders.show', ['id' => $order['id']]);
        }
        $coNo = trim((string) input('co_no', ''));
        if ($coNo !== '' && preg_match('/^\d{1,5}$/', $coNo)) {
            // Typing just the sequence is enough — the register knows the full number.
            $seqRow = $pdo->prepare('SELECT co_no FROM co_requests WHERE seq = ?');
            $seqRow->execute([(int) $coNo]);
            $coNo = (string) ($seqRow->fetchColumn() ?: $coNo);
        }
        try {
            if ($coNo === '') {
                $before = (string) ($order['co_no'] ?? '');
                CoNumbers::unlink($pdo, (int) $order['id']);
                audit($pdo, 'orders', 'update', 'order', (int) $order['id'], (string) $order['order_no'], 'CO 号解除: ' . $before);
                flash(t('msg_co_unlinked'));
            } else {
                // A draft has no paperwork yet, so it can take the CO number as its order number.
                $rename = order_editable($order);
                CoNumbers::link($pdo, (int) $order['id'], $coNo, own_name(), $rename, 'manual');
                audit($pdo, 'orders', 'update', 'order', (int) $order['id'], $rename ? $coNo : (string) $order['order_no'], 'CO 号挂接: ' . $coNo . ($rename ? '（订单号改为该号）' : ''));
                flash(t('msg_co_linked'));
            }
        } catch (RuntimeException $e) {
            flash(t($e->getMessage() === 'co_taken' ? 'msg_co_taken' : 'msg_co_not_found'), 'error');
        }
        redirect('orders.show', ['id' => $order['id']]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

/** Customer dropdown for the order form (sales: own customers only). */
function order_customer_list(PDO $pdo): array
{
    if (sees_only_own()) {
        $s = $pdo->prepare('SELECT id, name, company, phone FROM customers WHERE owner = ? ORDER BY name');
        $s->execute([own_name()]);
        return $s->fetchAll();
    }
    return $pdo->query('SELECT id, name, company, phone FROM customers ORDER BY name')->fetchAll();
}

function order_product_list(PDO $pdo): array
{
    return $pdo->query('SELECT id, sku, color_en, color_zh, spec, size, price, stock, reserved, min_stock FROM products ORDER BY sku LIMIT 400')->fetchAll();
}

/** Salespeople that an order can be assigned to (role=sales); include $keep if not listed. */
function order_sales_list(PDO $pdo, ?string $keep = null): array
{
    $names = array_column($pdo->query("SELECT name FROM users WHERE role = 'sales' ORDER BY name")->fetchAll(), 'name');
    // Also suggest names already used as submitters (even if not 'sales' users).
    foreach ($pdo->query("SELECT DISTINCT submitter FROM orders WHERE COALESCE(submitter,'') <> '' ORDER BY submitter") as $r) {
        if (!in_array($r['submitter'], $names, true)) {
            $names[] = $r['submitter'];
        }
    }
    if ($keep !== null && $keep !== '' && !in_array($keep, $names, true)) {
        array_unshift($names, $keep);
    }
    return $names;
}

/**
 * Create or update an order from the form. $existing = current row (update) or null (create).
 * The "do" field decides: "submit" → pending_sup (with stock check); else → draft.
 * Returns the order id.
 */
function save_order(PDO $pdo, object $auth, ?array $existing): int   // $auth unused; typed loosely so the test harness stub can drive this
{
    $isEdit = $existing !== null;
    $back = $isEdit ? ['orders.edit', ['id' => $existing['id']]] : ['orders.create', []];
    $submit = (string) input('do', 'submit') === 'submit';

    // Assigned salesperson (submitter): privileged roles may pick; sales = self.
    $submitter = own_name();
    if (!sees_only_own()) {
        $picked = trim((string) input('submitter', ''));
        $submitter = $picked !== '' ? $picked : ($isEdit ? (string) ($existing['submitter'] ?? own_name()) : own_name());
    }

    $customerId = ((int) input('customer_id', 0)) ?: null;
    $custName = trim((string) input('customer_name', ''));
    if ($customerId) {
        $c = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        $row = $c->fetch();
        if ($row) {
            $custName = $custName ?: $row['name'];
        } else {
            $customerId = null;
        }
    }
    if ($custName === '') {
        flash(t('msg_order_cust_req'), 'error');
        redirect($back[0], $back[1]);
    }
    // First-time customer: create it in the customers module, owned by the assigned sales.
    if (!$customerId && input('save_customer')) {
        $pdo->prepare('INSERT INTO customers (name, company, phone, owner, tag) VALUES (?,?,?,?,?)')
            ->execute([$custName, trim((string) input('company', '')), trim((string) input('phone', '')), $submitter ?: own_name(), '潜在']);
        $customerId = (int) $pdo->lastInsertId();
    }

    $skus = (array) input('sku', []);
    $colors = (array) input('color', []);
    $specs = (array) input('spec', []);
    $sizes = (array) input('size', []);
    $qtys = (array) input('qty', []);
    $prices = (array) input('price', []);
    $pricesExcl = (array) input('price_excl', []);
    $productIds = (array) input('product_id', []);
    // 含税 / 不含税: which price the customer was quoted. On a pre-tax order the
    // typed pre-tax price is authoritative (it is printed on the invoice as-is);
    // the inclusive price is derived from it, never the other way round.
    $basis = input('price_basis', 'incl') === 'excl' ? 'excl' : 'incl';
    $rate = 1 + ((float) ($GLOBALS['config']['ppn_rate'] ?? 11)) / 100;
    $items = [];
    foreach ($skus as $i => $sku) {
        $sku = trim((string) $sku);
        $qty = (float) ($qtys[$i] ?? 0);
        if ($sku === '' && $qty <= 0) {
            continue;
        }
        if ($basis === 'excl') {
            $excl = round((float) ($pricesExcl[$i] ?? 0), 2);
            $incl = round($excl * $rate, 2);
        } else {
            $incl = (float) ($prices[$i] ?? 0);
            $excl = null;
        }
        $items[] = [
            $sku, trim((string) ($colors[$i] ?? '')), trim((string) ($specs[$i] ?? '')),
            trim((string) ($sizes[$i] ?? '')), $qty, $incl,
            ((int) ($productIds[$i] ?? 0)) ?: null, $excl,
        ];
    }
    if (!$items) {
        flash(t('msg_order_item_req'), 'error');
        redirect($back[0], $back[1]);
    }

    // Only check stock when actually submitting for approval.
    if ($submit) {
        $short = available_shortages($pdo, array_map(
            fn($it) => ['product_id' => $it[6], 'sku' => $it[0], 'spec' => $it[2], 'qty' => $it[4]],
            $items
        ));
        if ($short) {
            flash(shortage_message($short), 'error');
            redirect($back[0], $back[1]);
        }
    }

    $status = $submit ? 'pending_sup' : 'draft';

    $pdo->beginTransaction();
    try {
        if ($isEdit) {
            $oid = (int) $existing['id'];
            $sql = 'UPDATE orders SET customer_id=?,customer_name=?,company=?,address=?,phone=?,client_type=?,delivery_service=?,delivery_address=?,shipping_cost=?,delivery_date=?,note=?,payment_term=?,custom_days=?,status=?,price_basis=?';
            $params = [
                $customerId, $custName, trim((string) input('company', '')), trim((string) input('address', '')),
                trim((string) input('phone', '')), (string) input('client_type', 'End User'),
                (string) input('delivery_service', 'Self Pickup'), trim((string) input('delivery_address', '')),
                (float) input('shipping_cost', 0), (string) input('delivery_date', ''), trim((string) input('note', '')),
                (string) input('payment_term', 'CBD'), (int) input('custom_days', 0), $status, $basis,
            ];
            if (!sees_only_own()) {
                $sql .= ', submitter=?';
                $params[] = $submitter;
            }
            if ($submit) {
                // Fresh approval cycle: clear rejection + prior approvals.
                $sql .= ', reject_note=NULL, reject_by=NULL, reject_date=NULL, sup_note=NULL, sup_approver=NULL, sup_date=NULL, mgr_note=NULL, mgr_approver=NULL, mgr_date=NULL, wh_note=NULL, wh_approver=NULL, wh_date=NULL';
            }
            $sql .= ' WHERE id = ?';
            $params[] = $oid;
            $pdo->prepare($sql)->execute($params);
            $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$oid]);
        } else {
            $newNo = next_order_no($pdo);
            $pdo->prepare(
                'INSERT INTO orders (order_no,customer_id,customer_name,company,address,phone,client_type,delivery_service,delivery_address,submitter,shipping_cost,delivery_date,note,payment_term,custom_days,status,created_by,price_basis)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $newNo, $customerId, $custName, trim((string) input('company', '')),
                trim((string) input('address', '')), trim((string) input('phone', '')),
                (string) input('client_type', 'End User'), (string) input('delivery_service', 'Self Pickup'),
                trim((string) input('delivery_address', '')), $submitter,
                (float) input('shipping_cost', 0), (string) input('delivery_date', ''),
                trim((string) input('note', '')), (string) input('payment_term', 'CBD'),
                (int) input('custom_days', 0), $status, own_name(), $basis,
            ]);
            $oid = (int) $pdo->lastInsertId();
            // From now on the order number is the CO number the customer sees.
            $pdo->prepare('UPDATE orders SET co_no = ? WHERE id = ?')->execute([$newNo, $oid]);
        }
        // A number already requested through the old Google Form: the order takes it
        // instead of a fresh one (drafts only — nothing printed yet carries the number).
        $pickCo = trim((string) input('co_no', ''));
        if ($pickCo !== '' && $pickCo !== (string) ($existing['co_no'] ?? '')) {
            CoNumbers::link($pdo, $oid, $pickCo, own_name(), true, 'picked on order form');
        }

        $ins = $pdo->prepare('INSERT INTO order_items (order_id,product_id,sku,color,spec,size,qty,unit,price,price_excl) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            $pid = $it[6] ?: match_product_id($pdo, $it[0], $it[2]);
            $ins->execute([$oid, $pid, $it[0], $it[1], $it[2], $it[3], $it[4], 'Unit', $it[5], $it[7]]);
        }
        recompute_reservations($pdo);
        $pdo->commit();
    } catch (RuntimeException $ex) {
        $pdo->rollBack();
        if (in_array($ex->getMessage(), ['co_taken', 'co_not_found'], true)) {
            flash(t($ex->getMessage() === 'co_taken' ? 'msg_co_taken' : 'msg_co_not_found'), 'error');
            redirect($back[0], $back[1]);
        }
        throw $ex;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $orderNo = (string) ($existing['order_no'] ?? '');
    if ($orderNo === '' || $pickCo !== '') {
        $noStmt = $pdo->prepare('SELECT order_no FROM orders WHERE id = ?');
        $noStmt->execute([$oid]);
        $orderNo = (string) $noStmt->fetchColumn();
    }
    $totals = order_totals($pdo, $oid);
    audit(
        $pdo,
        'orders',
        $isEdit ? 'update' : 'create',
        'order',
        $oid,
        $orderNo,
        sprintf('客户: %s; 明细 %d 项; 合计 %s; 状态: %s', $custName, count($items), idr($totals['total'] ?? 0), order_status_label($status))
    );
    if ($submit) {
        audit($pdo, 'orders', 'submit', 'order', $oid, $orderNo, '提交主管审批');
        $fresh = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $fresh->execute([$oid]);
        notify_order_stage($pdo, $fresh->fetch() ?: [], 'pending_sup');
    }

    flash($submit ? '订单已提交，进入主管审批。' : '草稿已保存。');
    return $oid;
}

/** Submit a draft/rejected order into the approval flow (no item edits). */
function submit_order(PDO $pdo, array $order): void
{
    $oi = $pdo->prepare('SELECT sku, spec, qty, product_id FROM order_items WHERE order_id = ?');
    $oi->execute([$order['id']]);
    $rows = $oi->fetchAll();
    if (!$rows) {
        flash(t('msg_order_no_items'), 'error');
        return;
    }
    $short = available_shortages($pdo, $rows);
    if ($short) {
        flash(shortage_message($short), 'error');
        return;
    }
    $pdo->prepare("UPDATE orders SET status='pending_sup', reject_note=NULL, reject_by=NULL, reject_date=NULL WHERE id=?")
        ->execute([$order['id']]);
    recompute_reservations($pdo);
    audit($pdo, 'orders', 'submit', 'order', (int) $order['id'], (string) $order['order_no'], '草稿提交，进入主管审批');
    notify_order_stage($pdo, $order, 'pending_sup');
    flash(t('msg_order_submitted'));
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
        flash(t('msg_order_no_appr'), 'error');
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

    $oid = (int) $order['id'];
    $ono = (string) $order['order_no'];
    $noteSuffix = $note !== '' ? '；批注: ' . $note : '';

    switch ($order['status']) {
        case 'pending_sup':
            $pdo->prepare('UPDATE orders SET status=?, sup_note=?, sup_approver=?, sup_date=? WHERE id=?')
                ->execute(['pending_mgr', $note, $name, $today, $oid]);
            audit($pdo, 'orders', 'approve', 'order', $oid, $ono, '主管通过 → 待经理审批' . $noteSuffix);
            notify_order_stage($pdo, $order, 'pending_mgr');
            flash(t('msg_order_sup_ok'));
            break;
        case 'pending_mgr':
            $pdo->prepare('UPDATE orders SET status=?, mgr_note=?, mgr_approver=?, mgr_date=? WHERE id=?')
                ->execute(['pending_wh', $note, $name, $today, $oid]);
            audit($pdo, 'orders', 'approve', 'order', $oid, $ono, '经理通过 → 待仓库出货' . $noteSuffix);
            notify_order_stage($pdo, $order, 'pending_wh');
            flash(t('msg_order_mgr_ok'));
            break;
        case 'pending_wh':
            fulfill_order($pdo, $order, $name, $note, $today);
            flash(t('msg_order_ship_ok'));
            break;
        default:
            flash(t('msg_order_no_stage'), 'error');
    }
}

function reject_order(PDO $pdo, Auth $auth, array $order, string $note): void
{
    if (!can_act($auth, $order)) {
        flash(t('msg_order_no_act'), 'error');
        return;
    }
    if (!in_array($order['status'], ['pending_sup', 'pending_mgr', 'pending_wh'], true)) {
        flash(t('msg_order_no_reject'), 'error');
        return;
    }
    $name = $auth->user()['name'] ?? '';
    $today = date('Y-m-d');
    // Send back to draft for revision; record who rejected & why, void prior approvals.
    $pdo->prepare(
        "UPDATE orders SET status='draft', reject_note=?, reject_by=?, reject_date=?,
             sup_note=NULL, sup_approver=NULL, sup_date=NULL,
             mgr_note=NULL, mgr_approver=NULL, mgr_date=NULL,
             wh_note=NULL, wh_approver=NULL, wh_date=NULL
         WHERE id=?"
    )->execute([$note, $name, $today, $order['id']]);
    recompute_reservations($pdo);   // release reserved stock
    audit(
        $pdo,
        'orders',
        'reject',
        'order',
        (int) $order['id'],
        (string) $order['order_no'],
        sprintf('在 %s 阶段驳回，退回草稿；理由: %s', order_status_label((string) $order['status']), $note !== '' ? $note : '(未填)')
    );
    foreach (order_stakeholders($pdo, $order) as $u) {
        notify_user(
            $pdo, $u, 'order_rejected', 'wa_order_rejected',
            [(string) $order['order_no'], $name, $note !== '' ? $note : '-'],
            'order', (int) $order['id'], (string) $order['order_no']
        );
    }
    flash(t('msg_order_rejected'));
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

        // 3) Invoice — order prices are TAX-INCLUSIVE; see invoice_amounts() for
        //    how the tax is backed out and why the total carries a Pembulatan.
        $cfgAll = $GLOBALS['config'];
        $calc = ($cfgAll['invoice_calc'] ?? 'coretax') === 'legacy' ? 'legacy' : 'coretax';
        $amt = $calc === 'coretax'
            ? invoice_amounts_coretax($rows, (float) $order['shipping_cost'], (string) ($cfgAll['coretax_decimals'] ?? 'truncate'), ($order['price_basis'] ?? 'incl') === 'excl')
            : invoice_amounts($rows, (float) $order['shipping_cost'], (float) ($cfgAll['ppn_rate'] ?? 11));
        $lines = [];
        foreach ($rows as $k => $it) {
            $lines[] = [$it, $amt['units'][$k]];
        }
        $pretaxShip = $amt['pretax_ship'];
        $subtotal = $amt['subtotal'];
        $ppn = $amt['ppn'];
        $rounding = $amt['rounding'];
        $total = $amt['total'];               // = the order's tax-inclusive amount, exactly
        $days = $order['payment_term'] === 'custom' ? (int) $order['custom_days'] : 0;
        $due = date('Y-m-d', strtotime($today . " +{$days} days"));
        $invNo = next_invoice_no($pdo);

        $pdo->prepare(
            'INSERT INTO invoices (invoice_no,order_id,do_number,customer,bill_to_name,address,currency,shipping_cost,subtotal,ppn,rounding,total,calc,dpp,invoice_date,due_date,issued_by,payment_term,custom_days,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invNo, $order['id'], $doNo, $order['company'] ?: $order['customer_name'], $order['customer_name'],
            $order['address'], 'IDR', $pretaxShip, $subtotal, $ppn, $rounding, $total, $calc, $amt['dpp'] ?? null, $today, $due,
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

        recompute_reservations($pdo);    // order no longer pending → release reservation (stock already deducted)
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    // Logged after commit: the trail must only record what actually persisted.
    audit(
        $pdo,
        'orders',
        'fulfill',
        'order',
        (int) $order['id'],
        (string) $order['order_no'],
        sprintf('确认出货：扣减 %d 项库存，生成送货单 %s、发票 %s（合计 %s）', count($rows), $doNo, $invNo, idr($total))
    );
    audit($pdo, 'finance', 'create', 'invoice', $invId, $invNo, sprintf('由订单 %s 自动生成；合计 %s', $order['order_no'], idr($total)));

    foreach (order_stakeholders($pdo, $order) as $u) {
        notify_user(
            $pdo, $u, 'order_shipped', 'wa_order_shipped',
            [(string) $order['order_no'], $doNo, $invNo],
            'order', (int) $order['id'], (string) $order['order_no']
        );
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
    if (sees_only_own() && ($row['submitter'] ?? '') !== own_name()) {
        http_response_code(403);
        flash(t('msg_order_own_only'), 'error');
        redirect('orders.index');
    }
    return $row;
}

/** Who may tie an order to a Google-Form CO number: anyone who sees all orders, or the order's own sales while it is still a draft. */
function co_can_link(object $auth, array $order): bool
{
    if ($auth->isAdmin() || !sees_only_own() || can_access('finance')) {
        return true;
    }
    return order_editable($order);
}
