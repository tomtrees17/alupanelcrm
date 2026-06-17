<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

$today = '2026-05-26';

switch ($action) {
    case 'index':
        // Refresh overdue flags on the fly
        foreach ($pdo->query('SELECT id FROM invoices') as $r) {
            refresh_invoice_status($pdo, (int) $r['id'], $today);
        }

        $statusFilter = (string) input('status', 'all');
        $sql = 'SELECT * FROM invoices';
        $args = [];
        if ($statusFilter !== 'all' && $statusFilter !== '') {
            $sql .= ' WHERE payment_status = ?';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY invoice_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $stats = [
            'received' => (float) $pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM invoices')->fetchColumn(),
            'pending'  => (float) $pdo->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE payment_status IN ('pending','partial')")->fetchColumn(),
            'overdue'  => (float) $pdo->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE payment_status='overdue'")->fetchColumn(),
        ];
        view('finance.index', [
            'invoices' => $stmt->fetchAll(), 'stats' => $stats, 'statusFilter' => $statusFilter,
        ]);
        break;

    case 'export':
        if (!can_export()) {
            http_response_code(403);
            flash('无导出权限 / Tidak punya akses ekspor.', 'error');
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

    case 'pay':
        Csrf::verify();
        $invoice = find_invoice($pdo, (int) input('id', 0));
        $amount = (float) input('amount', 0);
        if ($amount <= 0) {
            flash('请输入有效金额。', 'error');
            redirect('finance.show', ['id' => $invoice['id']]);
        }
        $payDate = (string) input('pay_date', date('Y-m-d'));
        $method = trim((string) input('method', ''));
        $receipt = trim((string) input('receipt_no', '')) ?: ('RC-' . date('Y') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT));
        $note = trim((string) input('note', ''));

        $newPaid = (float) $invoice['amount_paid'] + $amount;
        $pdo->prepare('UPDATE invoices SET amount_paid=?, paid_date=?, receipt_no=?, payment_method=?, payment_note=? WHERE id=?')
            ->execute([$newPaid, $payDate, $receipt, $method, $note, $invoice['id']]);
        $pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,pay_date,method,receipt_no,note) VALUES (?,?,?,?,?,?,?)')
            ->execute([$invoice['id'], $invoice['customer'], $amount, $payDate, $method, $receipt, $note]);
        refresh_invoice_status($pdo, (int) $invoice['id'], date('Y-m-d'));

        flash('收款已登记。');
        redirect('finance.show', ['id' => $invoice['id']]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
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
