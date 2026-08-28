<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

$today = date('Y-m-d');

switch ($action) {
    case 'index':
        // Refresh overdue flags on the fly
        foreach ($pdo->query('SELECT id FROM invoices') as $r) {
            refresh_invoice_status($pdo, (int) $r['id'], $today);
        }

        $statusFilter = (string) input('status', 'all');
        $sql = 'SELECT * FROM invoices';
        $args = [];
        if ($statusFilter === 'void') {
            $sql .= ' WHERE voided_at IS NOT NULL';
        } elseif ($statusFilter !== 'all' && $statusFilter !== '') {
            $sql .= ' WHERE payment_status = ? AND voided_at IS NULL';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY invoice_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        // Voided invoices are not receivables and must not inflate any total.
        $stats = [
            'received' => (float) $pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE voided_at IS NULL')->fetchColumn(),
            'pending'  => (float) $pdo->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE payment_status IN ('pending','partial') AND voided_at IS NULL")->fetchColumn(),
            'overdue'  => (float) $pdo->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE payment_status='overdue' AND voided_at IS NULL")->fetchColumn(),
            'void'     => (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE voided_at IS NOT NULL')->fetchColumn(),
        ];
        view('finance.index', [
            'invoices' => $stmt->fetchAll(), 'stats' => $stats, 'statusFilter' => $statusFilter,
        ]);
        break;

    case 'export':
        if (!can_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('finance.index');
        }
        foreach ($pdo->query('SELECT id FROM invoices') as $r) {
            refresh_invoice_status($pdo, (int) $r['id'], $today);
        }
        $rows = [];
        $sql = 'SELECT iv.*, (SELECT order_no FROM orders WHERE id = iv.order_id) AS order_no FROM invoices iv ORDER BY iv.invoice_date DESC';
        foreach ($pdo->query($sql) as $iv) {
            $rows[] = [
                $iv['invoice_no'], $iv['order_no'], $iv['customer'], $iv['bill_to_name'], $iv['npwp'],
                $iv['invoice_date'], $iv['due_date'], invoice_status_label($iv['payment_status']),
                (float) $iv['subtotal'], (float) $iv['ppn'], (float) $iv['total'], (float) $iv['amount_paid'],
                (float) $iv['total'] - (float) $iv['amount_paid'], $iv['payment_method'], $iv['receipt_no'],
            ];
        }
        send_spreadsheet('finance_' . date('Ymd'), '财务报表',
            ['发票号', '订单号', '客户', '开票对象', 'NPWP', '开票日', '到期日', '状态', '小计', 'PPN', '合计', '已收', '未收', '收款方式', '收据号'],
            $rows);
        break;

    case 'show':
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
        $items->execute([$invoice['id']]);
        $pays = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id DESC');
        $pays->execute([$invoice['id']]);
        view('finance.show', [
            'pageTitle' => t('invoice') . ' ' . $invoice['invoice_no'], 'pageSub' => $invoice['customer'],
            'invoice' => $invoice, 'items' => $items->fetchAll(), 'payments' => $pays->fetchAll(),
        ]);
        break;

    case 'print':
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
        $items->execute([$invoice['id']]);
        $orderNo = '';
        if ($invoice['order_id']) {
            $o = $pdo->prepare('SELECT order_no FROM orders WHERE id = ?');
            $o->execute([$invoice['order_id']]);
            $orderNo = (string) ($o->fetchColumn() ?: '');
        }
        view('print.invoice', ['invoice' => $invoice, 'items' => $items->fetchAll(), 'orderNo' => $orderNo], false);
        break;

    case 'word':
        // Editable Word copy — finance staff / warehouse admin only.
        if (!can_word_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('finance.index');
        }
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $st = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
        $st->execute([$invoice['id']]);
        $items = $st->fetchAll();
        $orderNo = '';
        if ($invoice['order_id']) {
            $o = $pdo->prepare('SELECT order_no FROM orders WHERE id = ?');
            $o->execute([$invoice['order_id']]);
            $orderNo = (string) ($o->fetchColumn() ?: '');
        }
        $cfg = $GLOBALS['config'];
        ob_start();
        include __DIR__ . '/../../views/word/invoice.php';
        Word::download('Invoice_' . $invoice['invoice_no'], (string) ob_get_clean());
        break;

    case 'update_no':
        // Finance can renumber an invoice (e.g. to match the physical/tax sequence).
        Csrf::verify();
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $no = trim((string) input('invoice_no', ''));
        if ($no === '') {
            flash(t('inv_no_required'), 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        if ($no !== $invoice['invoice_no']) {
            try {
                $pdo->prepare('UPDATE invoices SET invoice_no = ? WHERE id = ?')->execute([$no, $invoice['id']]);
            } catch (PDOException $e) {
                flash(t('inv_no_taken'), 'error');
                redirect('finance.show', ['id' => $invoice['id']]);
            }
            // Keep the denormalized copy on the linked order in sync.
            if ($invoice['order_id']) {
                $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?')->execute([$no, $invoice['order_id']]);
            }
            audit(
                $pdo,
                'finance',
                'update',
                'invoice',
                (int) $invoice['id'],
                $no,
                sprintf('发票号: %s → %s', (string) $invoice['invoice_no'], $no)
            );
            flash(t('inv_no_updated'));
        }
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    case 'pay':
        Csrf::verify();
        $invoice = find_invoice($pdo, (int) input('id', 0));
        if (invoice_is_void($invoice)) {
            flash(t('void_err_no_pay'), 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $amount = (float) input('amount', 0);
        if ($amount <= 0) {
            flash(t('msg_amount_invalid'), 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $payDate = (string) input('pay_date', date('Y-m-d'));
        $method = trim((string) input('method', ''));
        $receipt = trim((string) input('receipt_no', '')) ?: ('RC-' . date('Y') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT));
        $note = trim((string) input('note', ''));

        $pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,pay_date,method,receipt_no,note,created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$invoice['id'], $invoice['customer'], $amount, $payDate, $method, $receipt, $note, own_name()]);
        $pdo->prepare('UPDATE invoices SET paid_date=?, receipt_no=?, payment_method=?, payment_note=? WHERE id=?')
            ->execute([$payDate, $receipt, $method, $note, $invoice['id']]);
        // amount_paid is always re-derived from the ledger, never incremented.
        $newPaid = recompute_invoice_paid($pdo, (int) $invoice['id'], date('Y-m-d'));

        audit(
            $pdo,
            'finance',
            'pay',
            'invoice',
            (int) $invoice['id'],
            (string) $invoice['invoice_no'],
            sprintf(
                '收款 %s（%s）；累计已收 %s / %s；收据 %s%s',
                idr($amount),
                $method !== '' ? $method : '未填方式',
                idr($newPaid),
                idr((float) $invoice['total']),
                $receipt,
                $note !== '' ? '；备注: ' . $note : ''
            )
        );
        flash(t('msg_payment_saved'));
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    case 'reverse_form':
        // Confirmation step: a reversal moves money on the books, so make it deliberate.
        $payment = find_payment($pdo, (int) input('pid', 0));
        $invoice = find_invoice($pdo, (int) $payment['invoice_id']);
        if (payment_reversal_block($pdo, $payment) !== null) {
            flash(payment_reversal_block($pdo, $payment), 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        view('finance.reverse', [
            'pageTitle' => t('reverse_payment'), 'pageSub' => $invoice['invoice_no'],
            'invoice' => $invoice, 'payment' => $payment,
        ]);
        break;

    case 'reverse':
        Csrf::verify();
        $payment = find_payment($pdo, (int) input('pid', 0));
        $invoice = find_invoice($pdo, (int) $payment['invoice_id']);

        $block = payment_reversal_block($pdo, $payment);
        if ($block !== null) {
            flash($block, 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $reason = trim((string) input('reason', ''));
        if ($reason === '') {
            flash(t('reverse_need_reason'), 'error');
            redirect('finance.reverse_form', ['pid' => $payment['id']]);
        }

        // Append an offsetting row; the original is never edited or deleted.
        $pdo->prepare(
            'INSERT INTO payments (invoice_id,customer,amount,pay_date,method,receipt_no,note,created_by,reversal_of)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoice['id'], $invoice['customer'], -1 * (float) $payment['amount'], date('Y-m-d'),
            $payment['method'], $payment['receipt_no'], $reason, own_name(), (int) $payment['id'],
        ]);
        $newPaid = recompute_invoice_paid($pdo, (int) $invoice['id'], date('Y-m-d'));

        audit(
            $pdo,
            'finance',
            'reverse',
            'invoice',
            (int) $invoice['id'],
            (string) $invoice['invoice_no'],
            sprintf(
                '冲销收款 %s（原收据 %s，收款日 %s）；累计已收 %s / %s；原因: %s',
                idr((float) $payment['amount']),
                (string) ($payment['receipt_no'] ?: '—'),
                (string) $payment['pay_date'],
                idr($newPaid),
                idr((float) $invoice['total']),
                $reason
            )
        );
        flash(t('reverse_done'));
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    case 'void_form':
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $block = invoice_void_block($pdo, $invoice);
        if ($block !== null) {
            flash($block, 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        view('finance.void', [
            'pageTitle' => t('void_invoice'), 'pageSub' => $invoice['invoice_no'],
            'invoice' => $invoice,
        ]);
        break;

    case 'void':
        Csrf::verify();
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $block = invoice_void_block($pdo, $invoice);
        if ($block !== null) {
            flash($block, 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $reason = trim((string) input('reason', ''));
        if ($reason === '') {
            flash(t('void_need_reason'), 'error');
            redirect('finance.void_form', ['id' => $invoice['id']]);
        }
        $pdo->prepare("UPDATE invoices SET voided_at = datetime('now','localtime'), voided_by = ?, void_reason = ? WHERE id = ?")
            ->execute([own_name(), $reason, $invoice['id']]);
        audit(
            $pdo,
            'finance',
            'void',
            'invoice',
            (int) $invoice['id'],
            (string) $invoice['invoice_no'],
            sprintf('作废发票（%s，客户 %s）；原因: %s', idr((float) $invoice['total']), (string) $invoice['customer'], $reason)
        );
        flash(t('void_done'));
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    case 'unvoid':
        // Undoing a void is an admin-only correction: voiding is meant to be final.
        Csrf::verify();
        if (!$auth->isAdmin()) {
            flash(t('void_err_unvoid_admin'), 'error');
            redirect('finance.index');
        }
        $invoice = find_invoice($pdo, (int) input('id', 0));
        if (!invoice_is_void($invoice)) {
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $pdo->prepare('UPDATE invoices SET voided_at = NULL, voided_by = NULL, void_reason = NULL WHERE id = ?')
            ->execute([$invoice['id']]);
        refresh_invoice_status($pdo, (int) $invoice['id'], date('Y-m-d'));
        audit(
            $pdo,
            'finance',
            'unvoid',
            'invoice',
            (int) $invoice['id'],
            (string) $invoice['invoice_no'],
            sprintf('撤销作废（原作废人 %s，原因 %s）', (string) $invoice['voided_by'], (string) $invoice['void_reason'])
        );
        flash(t('unvoid_done'));
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function find_payment(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('收款记录不存在');
    }
    return $row;
}

function find_invoice(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('发票不存在');
    }
    return $row;
}
