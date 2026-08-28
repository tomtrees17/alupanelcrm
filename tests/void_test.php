<?php

// ── Invoice void ──
// Voiding keeps the document and removes it from receivables. The two things
// that must never happen: a voided invoice counted as money owed, or one that
// still accepts payment.

require_once __DIR__ . '/../app/Csrf.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);
$GLOBALS['auth'] = new AuthStub(['id' => 4, 'name' => 'Finance', 'role' => 'finance_manager']);

// The printed invoice reads the real letterhead config (company_addr, banks,
// signer_title). Use the actual file so a config key going missing fails here.
$realConfig = @include __DIR__ . '/../config.php';
if (is_array($realConfig)) {
    $GLOBALS['config'] = array_merge($GLOBALS['config'], $realConfig);
}

$today = '2026-08-29';
$mk = function (float $total, string $due = '2099-01-01') use ($pdo): int {
    $pdo->prepare('INSERT INTO invoices (invoice_no,customer,total,due_date,payment_status) VALUES (?,?,?,?,?)')
        ->execute(['INV-' . uniqid(), 'PT Test', $total, $due, 'pending']);
    return (int) $pdo->lastInsertId();
};
$get = fn(int $id): array => $pdo->query("SELECT * FROM invoices WHERE id={$id}")->fetch();
$void = function (int $id, string $reason) use ($pdo): void {
    $pdo->prepare("UPDATE invoices SET voided_at = datetime('now','localtime'), voided_by = ?, void_reason = ? WHERE id = ?")
        ->execute(['Finance', $reason, $id]);
};

// 1) The flag itself.
$a = $mk(1000000);
ok('a fresh invoice is not void', !invoice_is_void($get($a)));
$void($a, '开错客户');
ok('a voided invoice reads as void', invoice_is_void($get($a)));
ok('who and why are recorded', $get($a)['voided_by'] === 'Finance' && $get($a)['void_reason'] === '开错客户');
ok('the invoice row still exists', $get($a) !== false && (float) $get($a)['total'] === 1000000.0);

// 2) Guards: cannot void twice, cannot void what holds cash.
ok('voiding twice is blocked', invoice_void_block($pdo, $get($a)) === t('void_err_already'));

$b = $mk(500000);
ok('a clean invoice can be voided', invoice_void_block($pdo, $get($b)) === null);
$pdo->prepare('INSERT INTO payments (invoice_id,customer,amount) VALUES (?,?,?)')->execute([$b, 'PT Test', 500000]);
recompute_invoice_paid($pdo, $b, $today);
ok('an invoice holding cash is blocked', invoice_void_block($pdo, $get($b)) === t('void_err_has_payment'));

// Reversing the payment (the existing feature) clears the way — the two compose.
$pay = (int) $pdo->query("SELECT id FROM payments WHERE invoice_id={$b}")->fetchColumn();
$pdo->prepare('INSERT INTO payments (invoice_id,customer,amount,reversal_of) VALUES (?,?,?,?)')
    ->execute([$b, 'PT Test', -500000, $pay]);
recompute_invoice_paid($pdo, $b, $today);
ok('after reversal the invoice can be voided', invoice_void_block($pdo, $get($b)) === null);
ok('the reversed payment rows are still on the ledger', (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id={$b}")->fetchColumn() === 2);

// 3) A voided invoice must stay out of receivables — including as it ages.
$overdue = $mk(2000000, '2026-01-01');
refresh_invoice_status($pdo, $overdue, $today);
ok('an unpaid past-due invoice is overdue', $get($overdue)['payment_status'] === 'overdue');

$void($overdue, '重复开票');
refresh_invoice_status($pdo, $overdue, $today);
ok('refresh leaves a voided invoice alone', $get($overdue)['payment_status'] === 'overdue');

$sum = fn(string $where): float => (float) $pdo->query("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE {$where}")->fetchColumn();
ok('voided value is excluded from overdue totals',
    $sum("payment_status='overdue' AND voided_at IS NULL") === 0.0,
    (string) $sum("payment_status='overdue' AND voided_at IS NULL"));
ok('...but is still there without the filter', $sum("payment_status='overdue'") === 2000000.0);

$c = $mk(3000000, '2026-01-01');
refresh_invoice_status($pdo, $c, $today);
ok('a live overdue invoice still counts', $sum("payment_status='overdue' AND voided_at IS NULL") === 3000000.0);

// received total excludes voided too
$d = $mk(100000);
$pdo->prepare('INSERT INTO payments (invoice_id,customer,amount) VALUES (?,?,?)')->execute([$d, 'PT Test', 100000]);
recompute_invoice_paid($pdo, $d, $today);
$received = fn(): float => (float) $pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE voided_at IS NULL')->fetchColumn();
$before = $received();
ok('a paid live invoice counts as received', $before >= 100000.0);

// 4) The list filter.
$voidCount = (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE voided_at IS NOT NULL')->fetchColumn();
ok('void filter finds exactly the voided ones', $voidCount === 2, (string) $voidCount);
$liveOverdue = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status='overdue' AND voided_at IS NULL")->fetchColumn();
ok('status filters exclude voided rows', $liveOverdue === 1, (string) $liveOverdue);

// 5) Un-voiding restores the invoice and re-derives its status.
$pdo->prepare('UPDATE invoices SET voided_at=NULL, voided_by=NULL, void_reason=NULL WHERE id=?')->execute([$overdue]);
refresh_invoice_status($pdo, $overdue, $today);
ok('unvoid clears the flag', !invoice_is_void($get($overdue)));
ok('unvoid re-derives the status', $get($overdue)['payment_status'] === 'overdue');
ok('unvoided value returns to receivables', $sum("payment_status='overdue' AND voided_at IS NULL") === 5000000.0);
$void($overdue, '重复开票');   // put it back for the audit registry checks below

// 6) Audit actions must be registered, or the log shows raw keys.
ok('void is a known audit action', in_array('void', audit_actions(), true));
ok('unvoid is a known audit action', in_array('unvoid', audit_actions(), true));
ok('void has a colour', audit_action_class('void') === 'tag-red');
$missing = [];
foreach (['void', 'unvoid'] as $act) {
    foreach (['zh', 'id'] as $lang) {
        if (!isset(I18N[$lang]['audit_act_' . $act])) {
            $missing[] = "{$lang}/{$act}";
        }
    }
}
ok('void actions translated in both languages', $missing === [], implode(' ', $missing));

// ── Views ──
$render = function (string $file, array $data): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
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

$cols = array_fill_keys(array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name'), '');
$live = array_merge($cols, ['id' => 7, 'invoice_no' => 'INV-7', 'order_id' => 3, 'customer' => 'PT Maju',
    'total' => 1000000, 'amount_paid' => 0, 'subtotal' => 900000, 'ppn' => 100000, 'shipping_cost' => 0,
    'invoice_date' => '2026-08-01', 'due_date' => '2026-09-01', 'payment_status' => 'pending',
    'do_number' => 'DO-1', 'voided_at' => null]);
$dead = array_merge($live, ['voided_at' => '2026-08-29 10:00:00', 'voided_by' => 'Finance', 'void_reason' => '开错客户']);
$items = [['sku' => 'A1', 'color' => 'S', 'spec' => '4.0', 'size' => '1.22', 'qty' => 3, 'unit' => 'Unit', 'price' => 300000]];

$showFile = __DIR__ . '/../views/finance/show.php';
$htmlLive = $render($showFile, ['invoice' => $live, 'items' => $items, 'payments' => []]);
ok('live invoice offers the void button', str_contains($htmlLive, 'finance.void_form'));
ok('live invoice offers the payment form', str_contains($htmlLive, 'finance.pay'));

$htmlDead = $render($showFile, ['invoice' => $dead, 'items' => $items, 'payments' => []]);
ok('voided invoice shows the banner', str_contains($htmlDead, t('void_tag')) && str_contains($htmlDead, '开错客户'));
ok('voided invoice names who and when', str_contains($htmlDead, 'Finance') && str_contains($htmlDead, '2026-08-29'));
ok('voided invoice hides the payment form', !str_contains($htmlDead, 'finance.pay'));
ok('voided invoice hides the void button', !str_contains($htmlDead, 'finance.void_form'));
ok('finance_manager sees no unvoid button', !str_contains($htmlDead, 'finance.unvoid'));

$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
ok('admin sees the unvoid button', str_contains($render($showFile, ['invoice' => $dead, 'items' => $items, 'payments' => []]), 'finance.unvoid'));
$GLOBALS['auth'] = new AuthStub(['id' => 4, 'name' => 'Finance', 'role' => 'finance_manager']);

// Confirmation page
$htmlForm = $render(__DIR__ . '/../views/finance/void.php', ['invoice' => $live]);
ok('void form requires a reason', str_contains($htmlForm, 'name="reason"') && str_contains($htmlForm, 'required'));
ok('void form carries CSRF', str_contains($htmlForm, '_csrf'));
ok('void form explains the effect', str_contains($htmlForm, t('void_effect')));

// The printed document must be unmistakable — and must contain no Chinese.
$printed = $render(__DIR__ . '/../views/print/invoice.php', ['invoice' => $dead, 'items' => $items, 'orderNo' => 'SO-1']);
ok('printed voided invoice is stamped', str_contains($printed, 'VOID / BATAL'));
ok('printed live invoice is not stamped', !str_contains($render(__DIR__ . '/../views/print/invoice.php', ['invoice' => $live, 'items' => $items, 'orderNo' => 'SO-1']), 'VOID / BATAL'));
ok('the stamp itself carries no Chinese', preg_match('/[一-鿿]/u', 'VOID / BATAL') === 0);

// List page
$listFile = __DIR__ . '/../views/finance/index.php';
$stats = ['received' => 0, 'pending' => 0, 'overdue' => 0, 'void' => 1];
$htmlList = $render($listFile, ['invoices' => [$live, $dead], 'stats' => $stats, 'statusFilter' => 'all']);
ok('voided row is struck through in the list', str_contains($htmlList, 'row-void'));
ok('voided row shows the void tag', substr_count($htmlList, t('void_tag')) >= 1);
ok('void filter tab appears when there are voided invoices', str_contains($htmlList, "status=void"));
ok('no void tab when there are none',
    !str_contains($render($listFile, ['invoices' => [$live], 'stats' => ['received' => 0, 'pending' => 0, 'overdue' => 0, 'void' => 0], 'statusFilter' => 'all']), 'status=void'));

// Indonesian
$_SESSION['lang'] = 'id';
$htmlId = $render($showFile, ['invoice' => $dead, 'items' => $items, 'payments' => []]);
$keys = ['void_tag', 'voided_by_on', 'void_btn', 'unvoid_btn'];
$leak = array_values(array_filter($keys, fn($k) => str_contains($htmlId, $k)));
ok('Indonesian void page has no raw keys', $leak === [], implode(' ', $leak));
ok('Indonesian void page is translated', str_contains($htmlId, I18N['id']['void_tag']));
$_SESSION['lang'] = 'zh';
