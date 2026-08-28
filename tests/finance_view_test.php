<?php

// ── Renders the invoice detail + reversal confirmation templates for real ──

require_once __DIR__ . '/../app/Csrf.php';

/** Render a view file in isolation; any notice/warning fails the test. */
$renderFile = function (string $file, array $data): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool {
        $errors[] = $msg;
        return true;
    });
    // view() injects these into every template; mirror it or the render diverges.
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($data, EXTR_SKIP);
    ob_start();
    include $file;
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};

$showFile    = __DIR__ . '/../views/finance/show.php';
$reverseFile = __DIR__ . '/../views/finance/reverse.php';
ok('finance views exist', is_file($showFile) && is_file($reverseFile));

$GLOBALS['auth'] = new AuthStub(['id' => 4, 'name' => 'Finance', 'role' => 'finance_manager']);
$GLOBALS['permissions'] = ['finance_manager' => ['finance']];

// Build the fixture from the real column list so a schema change surfaces here
// as a failing assertion rather than an "undefined array key" in production.
$tmp = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$sql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$tmp->exec($sql === false ? (string) ($GLOBALS['__schema'] ?? '') : $sql);
$invoice = array_fill_keys(array_column($tmp->query('PRAGMA table_info(invoices)')->fetchAll(), 'name'), '');
$invoice = array_merge($invoice, [
    'id' => 7, 'invoice_no' => 'INV-7', 'order_id' => 3, 'customer' => 'PT Maju', 'bill_to_name' => 'PT Maju',
    'address' => 'Jakarta', 'do_number' => 'DO-1',
    'subtotal' => 900000, 'ppn' => 100000, 'total' => 1000000, 'shipping_cost' => 0,
    'invoice_date' => '2026-08-01', 'due_date' => '2026-09-01', 'payment_term' => 'custom', 'custom_days' => 30,
    'payment_status' => 'partial', 'amount_paid' => 400000, 'paid_date' => '2026-08-10',
    'receipt_no' => 'RC-2', 'payment_method' => 'BCA',
]);
$items = [['sku' => 'A1', 'color' => 'Silver', 'spec' => '4.0', 'size' => '1.22x2.44', 'qty' => 3, 'unit' => 'Unit', 'price' => 300000]];

// A ledger with one live payment, one reversed payment, and its offsetting row.
$payments = [
    ['id' => 3, 'pay_date' => '2026-08-12', 'method' => 'BCA', 'receipt_no' => 'RC-1', 'amount' => -300000,
     'note' => '金额录入错误', 'created_by' => 'Finance', 'reversal_of' => 1],
    ['id' => 2, 'pay_date' => '2026-08-10', 'method' => 'BCA', 'receipt_no' => 'RC-2', 'amount' => 400000,
     'note' => '', 'created_by' => 'Finance', 'reversal_of' => null],
    ['id' => 1, 'pay_date' => '2026-08-05', 'method' => 'Cash', 'receipt_no' => 'RC-1', 'amount' => 300000,
     'note' => '', 'created_by' => 'Finance', 'reversal_of' => null],
];

$html = $renderFile($showFile, ['invoice' => $invoice, 'items' => $items, 'payments' => $payments]);

ok('all three ledger rows render', substr_count($html, 'RC-1') >= 2 && str_contains($html, 'RC-2'));
ok('reversal row tagged', str_contains($html, t('reverse_tag')));
ok('reversed original tagged', str_contains($html, t('reversed_tag')));
ok('voided rows get the strike-through class', substr_count($html, 'row-void') === 2, (string) substr_count($html, 'row-void'));
ok('reversal reason shown', str_contains($html, '金额录入错误'));
ok('registering user shown', str_contains($html, 'Finance'));

// Only the untouched payment (id=2) may offer a reverse button.
ok('reverse button offered once', substr_count($html, 'finance.reverse_form') === 1, (string) substr_count($html, 'finance.reverse_form'));
ok('reverse button targets the live payment', str_contains($html, 'pid=2'));

// A ledger with no reversals: every row is reversible, nothing is struck through.
$plain = [
    ['id' => 2, 'pay_date' => '2026-08-10', 'method' => 'BCA', 'receipt_no' => 'RC-2', 'amount' => 400000, 'note' => '', 'created_by' => 'A', 'reversal_of' => null],
    ['id' => 1, 'pay_date' => '2026-08-05', 'method' => 'Cash', 'receipt_no' => 'RC-1', 'amount' => 300000, 'note' => '', 'created_by' => 'A', 'reversal_of' => null],
];
$html2 = $renderFile($showFile, ['invoice' => $invoice, 'items' => $items, 'payments' => $plain]);
ok('no strike-through without reversals', !str_contains($html2, 'row-void'));
ok('both payments reversible', substr_count($html2, 'finance.reverse_form') === 2);

// Empty ledger must not break the row-scanning logic.
$html3 = $renderFile($showFile, ['invoice' => array_merge($invoice, ['amount_paid' => 0]), 'items' => $items, 'payments' => []]);
ok('empty ledger renders', str_contains($html3, t('no_payment')) && !str_contains($html3, 'row-void'));

// ── Reversal confirmation page ──
$payment = ['id' => 2, 'invoice_id' => 7, 'pay_date' => '2026-08-10', 'method' => 'BCA',
            'receipt_no' => 'RC-2', 'amount' => 400000, 'created_by' => 'Finance', 'reversal_of' => null];
$html4 = $renderFile($reverseFile, ['invoice' => $invoice, 'payment' => $payment]);

ok('confirmation shows the payment', str_contains($html4, 'RC-2') && str_contains($html4, idr(400000)));
ok('confirmation explains the before/after', str_contains($html4, idr(400000)) && str_contains($html4, idr(0)));
ok('reason field is required', str_contains($html4, 'name="reason"') && str_contains($html4, 'required'));
ok('posts to finance.reverse with the payment id', str_contains($html4, 'finance.reverse') && str_contains($html4, 'value="2"'));
ok('carries a CSRF token', str_contains($html4, '_csrf'));
ok('offers a way out', str_contains($html4, t('btn_cancel')));

// Hostile note text must be escaped in the ledger.
$evil = [['id' => 3, 'pay_date' => '2026-08-12', 'method' => '<b>x</b>', 'receipt_no' => 'R',
          'amount' => -1, 'note' => '<script>alert(1)</script>', 'created_by' => '<img src=x onerror=y>', 'reversal_of' => 1]];
$html5 = $renderFile($showFile, ['invoice' => $invoice, 'items' => $items, 'payments' => $evil]);
ok('reversal note escaped', !str_contains($html5, '<script>alert(1)</script>') && str_contains($html5, '&lt;script&gt;'));
ok('created_by escaped', !str_contains($html5, '<img src=x'));

// Indonesian must have every reversal string.
$_SESSION['lang'] = 'id';
$html6 = $renderFile($showFile, ['invoice' => $invoice, 'items' => $items, 'payments' => $payments]);
$html7 = $renderFile($reverseFile, ['invoice' => $invoice, 'payment' => $payment]);
// Check the i18n keys themselves are absent (the URL legitimately contains
// "reverse_form", so a bare 'reverse_' substring test would false-positive).
$leak = fn(string $html, array $keys): array => array_values(array_filter($keys, fn($k) => str_contains($html, $k)));
$ledgerKeys = ['reverse_tag', 'reversed_tag', 'reverse_btn'];
ok('Indonesian ledger has no raw keys', $leak($html6, $ledgerKeys) === [], implode(' ', $leak($html6, $ledgerKeys)));
ok('Indonesian ledger is actually translated', str_contains($html6, I18N['id']['reverse_tag']) && str_contains($html6, I18N['id']['reversed_tag']));

$confirmKeys = ['reverse_hint', 'reverse_effect', 'reverse_reason', 'reverse_confirm', 'reverse_registered_by', 'reverse_payment'];
ok('Indonesian confirmation has no raw keys', $leak($html7, $confirmKeys) === [], implode(' ', $leak($html7, $confirmKeys)));
ok('Indonesian confirmation is actually translated', str_contains($html7, I18N['id']['reverse_confirm']) && str_contains($html7, I18N['id']['reverse_hint']));

// And the same for Chinese, so a missing zh entry cannot slip through either.
$_SESSION['lang'] = 'zh';
$zhLedger = $renderFile($showFile, ['invoice' => $invoice, 'items' => $items, 'payments' => $payments]);
$zhConfirm = $renderFile($reverseFile, ['invoice' => $invoice, 'payment' => $payment]);
ok('Chinese ledger has no raw keys', $leak($zhLedger, $ledgerKeys) === [], implode(' ', $leak($zhLedger, $ledgerKeys)));
ok('Chinese confirmation has no raw keys', $leak($zhConfirm, $confirmKeys) === [], implode(' ', $leak($zhConfirm, $confirmKeys)));
$_SESSION['lang'] = 'zh';
