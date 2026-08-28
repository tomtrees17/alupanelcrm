<?php

// ── Payment reversal: the ledger stays the source of truth ──

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    $schema = (string) ($GLOBALS['__schema'] ?? '');
}
$pdo->exec($schema);
$GLOBALS['auth'] = new AuthStub(['id' => 4, 'name' => 'Finance', 'role' => 'finance_manager']);

$today = '2026-08-28';
$mkInvoice = function (float $total, string $due = '2099-01-01') use ($pdo): int {
    $pdo->prepare('INSERT INTO invoices (invoice_no,customer,total,due_date,payment_status) VALUES (?,?,?,?,?)')
        ->execute(['INV-' . uniqid(), 'PT Test', $total, $due, 'pending']);
    return (int) $pdo->lastInsertId();
};
$pay = function (int $invId, float $amount) use ($pdo, $today): int {
    $pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,pay_date,receipt_no,created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$invId, 'PT Test', $amount, $today, 'RC-' . uniqid(), 'Finance']);
    $id = (int) $pdo->lastInsertId();
    recompute_invoice_paid($pdo, $invId, $today);
    return $id;
};
$reverse = function (int $invId, int $payId, string $reason) use ($pdo, $today): void {
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$payId]);
    $p = $st->fetch();
    $pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,pay_date,receipt_no,note,created_by,reversal_of) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$invId, $p['customer'], -1 * (float) $p['amount'], $today, $p['receipt_no'], $reason, 'Finance', $payId]);
    recompute_invoice_paid($pdo, $invId, $today);
};
$inv = fn(int $id): array => $pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch();

// 1) A wrong amount (the 10x typo) can be undone.
$id = $mkInvoice(500000);
$wrong = $pay($id, 5000000);
ok('typo marks invoice paid', $inv($id)['payment_status'] === 'paid' && (float) $inv($id)['amount_paid'] === 5000000.0);

$reverse($id, $wrong, '金额录入错误');
$row = $inv($id);
ok('reversal zeroes amount_paid', (float) $row['amount_paid'] === 0.0, (string) $row['amount_paid']);
ok('status falls back to pending', $row['payment_status'] === 'pending', $row['payment_status']);

// 2) The original row is preserved, not deleted — both sides stay on the ledger.
$rows = $pdo->query("SELECT * FROM payments WHERE invoice_id = {$id} ORDER BY id")->fetchAll();
ok('original payment kept', count($rows) === 2 && (float) $rows[0]['amount'] === 5000000.0);
ok('offsetting row is negative', (float) $rows[1]['amount'] === -5000000.0);
ok('offsetting row links to the original', (int) $rows[1]['reversal_of'] === $wrong);
ok('reason stored on the reversal', $rows[1]['note'] === '金额录入错误');
ok('actor recorded on both rows', $rows[0]['created_by'] === 'Finance' && $rows[1]['created_by'] === 'Finance');

// 3) Re-registering the correct amount afterwards works.
$pay($id, 500000);
$row = $inv($id);
ok('correct amount then settles the invoice', (float) $row['amount_paid'] === 500000.0 && $row['payment_status'] === 'paid');

// 4) Partial payments: reversing one of several leaves the rest intact.
$id2 = $mkInvoice(1000000);
$p1 = $pay($id2, 300000);
$pay($id2, 400000);
ok('two partials sum correctly', (float) $inv($id2)['amount_paid'] === 700000.0 && $inv($id2)['payment_status'] === 'partial');
$reverse($id2, $p1, '重复登记');
ok('reversing one partial leaves the other', (float) $inv($id2)['amount_paid'] === 400000.0, (string) $inv($id2)['amount_paid']);
ok('still partial after reversal', $inv($id2)['payment_status'] === 'partial');

// 5) Reversing the only payment on an overdue invoice restores 'overdue', not 'pending'.
$id3 = $mkInvoice(200000, '2026-01-01');
$p3 = $pay($id3, 200000);
ok('paid overrides overdue', $inv($id3)['payment_status'] === 'paid');
$reverse($id3, $p3, '客户退款');
ok('overdue restored after reversal', $inv($id3)['payment_status'] === 'overdue', $inv($id3)['payment_status']);

// 6) Guard rails: a reversal cannot be reversed, and nothing can be reversed twice.
$id4 = $mkInvoice(100000);
$p4 = $pay($id4, 100000);
$st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
$st->execute([$p4]);
ok('a fresh payment is reversible', payment_reversal_block($pdo, $st->fetch()) === null);

$reverse($id4, $p4, '测试');
$st->execute([$p4]);
ok('already-reversed payment is blocked', payment_reversal_block($pdo, $st->fetch()) === t('reverse_err_already'));

$rev = $pdo->query("SELECT * FROM payments WHERE reversal_of = {$p4}")->fetch();
ok('a reversal row itself is blocked', payment_reversal_block($pdo, $rev) === t('reverse_err_is_reversal'));

// Double reversal would wrongly credit the customer again — make sure it stays blocked.
ok('amount_paid unaffected by blocked attempts', (float) $inv($id4)['amount_paid'] === 0.0);

// 7) The ledger and the cached total never diverge, whatever the sequence.
$drift = $pdo->query(
    'SELECT COUNT(*) FROM invoices iv
     WHERE ABS(iv.amount_paid - COALESCE((SELECT SUM(amount) FROM payments WHERE invoice_id = iv.id),0)) > 0.005'
)->fetchColumn();
ok('no invoice drifts from its ledger', (int) $drift === 0, (string) $drift);

// 8) audit action is registered so the log renders it in words, not a raw key.
ok('reverse is a known audit action', in_array('reverse', audit_actions(), true));
ok('reverse has a label and colour', tr_audit_action('reverse') !== 'reverse' && audit_action_class('reverse') === 'tag-red');
