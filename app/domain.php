<?php
declare(strict_types=1);

/**
 * Shared business logic (loaded on every request via bootstrap).
 */

/** Apply a stock movement and log a transaction. */
function adjust_stock(PDO $pdo, int $productId, string $type, int $qty, string $ref, string $note = ''): void
{
    $delta = $type === 'in' ? $qty : -$qty;
    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $productId]);
    $pdo->prepare('INSERT INTO stock_txn (product_id,type,qty,ref,note) VALUES (?,?,?,?,?)')
        ->execute([$productId, $type, $qty, $ref, $note]);
}

/** Best-effort product match for an order line (sku + spec). */
function match_product_id(PDO $pdo, string $sku, string $spec): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND spec = ? LIMIT 1');
    $stmt->execute([$sku, $spec]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $stmt->execute([$sku]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Return items whose ordered qty exceeds current product stock.
 * $items: array of ['sku'=>, 'spec'=>, 'qty'=>]. Unknown products are skipped.
 * Each result: ['sku','spec','need','have'].
 */
function stock_shortages(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
    $short = [];
    foreach ($items as $it) {
        $pid = match_product_id($pdo, (string) ($it['sku'] ?? ''), (string) ($it['spec'] ?? ''));
        if (!$pid) {
            continue;
        }
        $stmt->execute([$pid]);
        $have = (int) $stmt->fetchColumn();
        $need = (int) ceil((float) ($it['qty'] ?? 0));
        if ($need > $have) {
            $short[] = ['sku' => $it['sku'] ?? '', 'spec' => $it['spec'] ?? '', 'need' => $need, 'have' => $have];
        }
    }
    return $short;
}

/**
 * Items whose qty exceeds AVAILABLE stock (= stock − reserved by other open orders).
 * Used when placing a new order. $items: ['product_id'?, 'sku', 'spec', 'qty'].
 */
function available_shortages(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare('SELECT stock, reserved FROM products WHERE id = ?');
    $short = [];
    foreach ($items as $it) {
        $pid = $it['product_id'] ?? null;
        if (!$pid) {
            $pid = match_product_id($pdo, (string) ($it['sku'] ?? ''), (string) ($it['spec'] ?? ''));
        }
        if (!$pid) {
            continue;
        }
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if (!$row) {
            continue;
        }
        $avail = (int) $row['stock'] - (int) $row['reserved'];
        $need = (int) ceil((float) ($it['qty'] ?? 0));
        if ($need > $avail) {
            $short[] = ['sku' => $it['sku'] ?? '', 'spec' => $it['spec'] ?? '', 'need' => $need, 'have' => max(0, $avail)];
        }
    }
    return $short;
}

/** Recompute every product's reserved qty from currently-open (pending) orders. */
function recompute_reservations(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE products SET reserved = COALESCE((
             SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = products.id AND o.status LIKE 'pending_%'
         ), 0)"
    );
}

/** Human-readable shortage message (bilingual prefix). */
function shortage_message(array $short): string
{
    $parts = array_map(
        fn($s) => "{$s['sku']} ({$s['spec']}) " . t('need') . " {$s['need']} / " . t('have') . " {$s['have']}",
        $short
    );
    return t('stock_insufficient') . '：' . implode('；', $parts);
}

/** Order subtotal (items) and grand total (with shipping). */
function order_totals(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $subtotal = (float) $stmt->fetchColumn();
    $ship = (float) $pdo->query("SELECT shipping_cost FROM orders WHERE id = $orderId")->fetchColumn();
    return ['subtotal' => $subtotal, 'shipping' => $ship, 'total' => $subtotal + $ship];
}

/** Next sequential sales-order number: 0477/AMI-CO/MM/YY */
function next_order_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT order_no FROM orders") as $r) {
        if (preg_match('/^(\d+)\//', (string) $r['order_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    $seq = str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    return sprintf('%s/AMI-CO/%s/%s', $seq, date('m'), date('y'));
}

/** Next delivery-order number: DO-YYYY-NNN */
function next_do_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT do_no FROM delivery_orders") as $r) {
        if (preg_match('/(\d+)$/', (string) $r['do_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    return sprintf('DO-%s-%03d', date('Y'), $max + 1);
}

/** Next invoice number, matching the company format: "480 - AMI - INV - 04 - 26". */
function next_invoice_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT invoice_no FROM invoices") as $r) {
        if (preg_match('/^(\d+)\s*-/', (string) $r['invoice_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    return sprintf('%d - AMI - INV - %s - %s', $max + 1, date('m'), date('y'));
}

/** Next administrative-request number per type: BT-YYYY-NNN / EX-YYYY-NNN / LV-YYYY-NNN */
function next_request_no(PDO $pdo, string $type): string
{
    $prefix = ['trip' => 'BT', 'expense' => 'EX', 'leave' => 'LV', 'payment' => 'PY'][$type] ?? 'AR';
    $max = 0;
    $stmt = $pdo->prepare("SELECT req_no FROM admin_requests WHERE req_no LIKE ?");
    $stmt->execute([$prefix . '-%']);
    foreach ($stmt as $r) {
        if (preg_match('/(\d+)$/', (string) $r['req_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    return sprintf('%s-%s-%03d', $prefix, date('Y'), $max + 1);
}

/** Indonesian "terbilang": spell a Rupiah amount in words. */
function terbilang($number): string
{
    $n = (int) round((float) $number);
    if ($n === 0) {
        return 'Nol Rupiah';
    }
    $words = trim(terbilang_helper($n));
    return ucwords($words) . ' Rupiah';
}

function terbilang_helper(int $n): string
{
    $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) {
        return ' ' . $satuan[$n];
    }
    if ($n < 20) {
        return terbilang_helper($n - 10) . ' belas';
    }
    if ($n < 100) {
        return terbilang_helper(intdiv($n, 10)) . ' puluh' . terbilang_helper($n % 10);
    }
    if ($n < 200) {
        return ' seratus' . terbilang_helper($n - 100);
    }
    if ($n < 1000) {
        return terbilang_helper(intdiv($n, 100)) . ' ratus' . terbilang_helper($n % 100);
    }
    if ($n < 2000) {
        return ' seribu' . terbilang_helper($n - 1000);
    }
    if ($n < 1000000) {
        return terbilang_helper(intdiv($n, 1000)) . ' ribu' . terbilang_helper($n % 1000);
    }
    if ($n < 1000000000) {
        return terbilang_helper(intdiv($n, 1000000)) . ' juta' . terbilang_helper($n % 1000000);
    }
    return terbilang_helper(intdiv($n, 1000000000)) . ' miliar' . terbilang_helper($n % 1000000000);
}

// ──────────────────────────────────────────────────────────
//  Login brute-force throttle (per client IP, sliding window)
// ──────────────────────────────────────────────────────────

const LOGIN_MAX_ATTEMPTS   = 8;     // failures allowed within the window
const LOGIN_WINDOW_SECONDS = 900;   // 15-minute window / lockout

function login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Remaining lockout for the current IP in minutes (0 = not locked). */
function login_lockout_minutes(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) c, MIN(attempt_time) m FROM login_attempts WHERE ip = ? AND attempt_time > ?'
    );
    $stmt->execute([login_client_ip(), time() - LOGIN_WINDOW_SECONDS]);
    $row = $stmt->fetch();
    if ((int) ($row['c'] ?? 0) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    // Locked until the oldest in-window failure ages out.
    $remain = ((int) $row['m'] + LOGIN_WINDOW_SECONDS) - time();
    return max(1, (int) ceil($remain / 60));
}

/** Record a failed login and prune attempts older than the window. */
function login_record_failure(PDO $pdo, string $email): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, email, attempt_time) VALUES (?,?,?)')
        ->execute([login_client_ip(), mb_substr($email, 0, 190), time()]);
    $pdo->prepare('DELETE FROM login_attempts WHERE attempt_time < ?')
        ->execute([time() - LOGIN_WINDOW_SECONDS]);
}

/** Clear an IP's failed attempts after a successful login. */
function login_clear_failures(PDO $pdo): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([login_client_ip()]);
}

// ──────────────────────────────────────────────────────────
//  AI inventory lookup
// ──────────────────────────────────────────────────────────

/** Queries this user has already made today (rate limit + cost control). */
function ai_used_today(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM ai_queries WHERE user_id = ? AND date(created_at) = date('now','localtime')");
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

/**
 * Live stock for the ids the model picked, in the model's order of confidence.
 * This is the only place a quantity is produced — never the model.
 */
function ai_resolve_products(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if ($ids === []) {
        return [];
    }
    $ids = array_slice($ids, 0, 8);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, sku, name, color_zh, color_en, spec, size, unit, price, stock, reserved, min_stock
                         FROM products WHERE id IN ({$in})");
    $st->execute($ids);

    $byId = [];
    foreach ($st->fetchAll() as $row) {
        $row['available'] = (int) $row['stock'] - (int) $row['reserved'];
        $byId[(int) $row['id']] = $row;
    }
    // Preserve the model's ranking; drop ids that no longer exist.
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
    }
    return $out;
}

/** Record every call: what was asked, what matched, what it cost. */
function ai_log(PDO $pdo, string $question, array $ids, bool $ok, string $error = '', array $usage = []): void
{
    $auth = $GLOBALS['auth'] ?? null;
    $user = ($auth !== null && $auth->check()) ? $auth->user() : null;
    try {
        $pdo->prepare(
            'INSERT INTO ai_queries (user_id,user_name,question,matched,ok,error,in_tokens,cached_tokens,out_tokens)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $user['id'] ?? null,
            $user['name'] ?? '',
            mb_substr($question, 0, 500),
            implode(',', array_map('intval', $ids)),
            $ok ? 1 : 0,
            mb_substr($error, 0, 300),
            (int) ($usage['in'] ?? 0),
            (int) ($usage['cached'] ?? 0),
            (int) ($usage['out'] ?? 0),
        ]);
    } catch (Throwable $e) {
        // Logging must not break the answer the user is waiting for.
    }
}

// ──────────────────────────────────────────────────────────
//  WhatsApp notifications — who to tell, and what to say
// ──────────────────────────────────────────────────────────

/** Users holding a role, as [id => name]. Admins are NOT auto-included: they
 *  can act at any stage, but notifying them about every order would be noise. */
function users_with_role(PDO $pdo, string $role): array
{
    $st = $pdo->prepare('SELECT id, name FROM users WHERE role = ? ORDER BY id');
    $st->execute([$role]);
    return $st->fetchAll();
}

/** Look a user up by display name (submitter / created_by are stored as names). */
function user_by_name(PDO $pdo, string $name): ?array
{
    if (trim($name) === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE name = ? LIMIT 1');
    $st->execute([trim($name)]);
    return $st->fetch() ?: null;
}

/** A user's preferred language, falling back to Indonesian (most of the staff). */
function user_lang(?array $user): string
{
    $l = (string) ($user['lang'] ?? '');
    return in_array($l, ['zh', 'id'], true) ? $l : 'id';
}

/** Translate a key in an explicit language (t() follows the *session*, which is
 *  the sender's, not the recipient's — wrong for notifications). */
function t_in(string $lang, string $key): string
{
    return I18N[$lang][$key] ?? I18N['zh'][$key] ?? $key;
}

/**
 * Queue a notification for one user, rendered in that user's own language.
 * $args fills the sprintf placeholders of the message template.
 */
function notify_user(PDO $pdo, ?array $user, string $event, string $msgKey, array $args, string $entity = '', ?int $entityId = null, string $label = ''): void
{
    if ($user === null) {
        return;
    }
    $body = vsprintf(t_in(user_lang($user), $msgKey), $args);
    Notify::queue($pdo, (int) $user['id'], $event, $body, $entity, $entityId, $label);
}

/** Same, for everyone holding a role (e.g. every supervisor). */
function notify_role(PDO $pdo, string $role, string $event, string $msgKey, array $args, string $entity = '', ?int $entityId = null, string $label = ''): void
{
    foreach (users_with_role($pdo, $role) as $u) {
        $full = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $full->execute([$u['id']]);
        notify_user($pdo, $full->fetch() ?: null, $event, $msgKey, $args, $entity, $entityId, $label);
    }
}

/**
 * An order moved to a stage that someone must act on: tell that role.
 * Mirrors order_action_role() so the notification and the permission can never
 * point at different people.
 */
function notify_order_stage(PDO $pdo, array $order, string $status): void
{
    $role = order_action_role($status);
    if ($role === null) {
        return;
    }
    notify_role(
        $pdo,
        $role,
        'order_' . $status,
        'wa_order_pending',
        [(string) $order['order_no'], (string) $order['customer_name']],
        'order',
        (int) $order['id'],
        (string) $order['order_no']
    );
}

/** People who need to know what happened to an order they are responsible for:
 *  the assistant who keyed it in, and the salesperson it belongs to. */
function order_stakeholders(PDO $pdo, array $order): array
{
    $out = [];
    foreach ([(string) ($order['created_by'] ?? ''), (string) ($order['submitter'] ?? '')] as $name) {
        $u = user_by_name($pdo, $name);
        if ($u !== null && !isset($out[(int) $u['id']])) {
            $out[(int) $u['id']] = $u;
        }
    }
    return array_values($out);
}

// ──────────────────────────────────────────────────────────
//  Audit trail (审计日志) — who changed what
// ──────────────────────────────────────────────────────────

/**
 * Append one entry to the audit trail.
 *
 * Never lets a logging failure break the business operation it records:
 * a missing table (DB not yet migrated) or a locked write must not turn
 * an approved order into a 500.
 */
function audit(
    PDO $pdo,
    string $module,
    string $action,
    string $entity = '',
    ?int $entityId = null,
    string $label = '',
    string $detail = ''
): void {
    $auth = $GLOBALS['auth'] ?? null;
    $user = ($auth !== null && $auth->check()) ? $auth->user() : null;

    try {
        $pdo->prepare(
            'INSERT INTO audit_log (user_id,user_name,user_role,module,action,entity,entity_id,label,detail,ip)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $user['id'] ?? null,
            $user['name'] ?? '',
            $user['role'] ?? '',
            $module,
            $action,
            $entity,
            $entityId,
            mb_substr($label, 0, 200),
            mb_substr($detail, 0, 2000),
            login_client_ip(),
        ]);
    } catch (Throwable $e) {
        // Swallow: the audited action already succeeded and matters more.
    }
}

/**
 * Summarise what changed between two rows: "单价: 100 → 120; 数量: 3 → 5".
 * $fields maps column name → human label; unchanged columns are skipped.
 */
function audit_diff(array $before, array $after, array $fields): string
{
    $parts = [];
    foreach ($fields as $col => $label) {
        $old = (string) ($before[$col] ?? '');
        $new = (string) ($after[$col] ?? '');
        if ($old === $new) {
            continue;
        }
        $parts[] = $label . ': ' . ($old === '' ? '(空)' : $old) . ' → ' . ($new === '' ? '(空)' : $new);
    }
    return implode('; ', $parts);
}

/** Fetch a row by id for before/after diffing; [] when missing. */
function audit_snapshot(PDO $pdo, string $table, int $id): array
{
    // $table is always a literal from call sites, never user input.
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

/**
 * Recompute invoices.amount_paid from the payment ledger and refresh the status.
 *
 * amount_paid is a cached sum, never incremented in place: reversals are stored
 * as negative rows, so summing is the only way the two stay consistent.
 */
function recompute_invoice_paid(PDO $pdo, int $invoiceId, ?string $today = null): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    $paid = (float) $stmt->fetchColumn();

    $pdo->prepare('UPDATE invoices SET amount_paid = ? WHERE id = ?')->execute([$paid, $invoiceId]);
    refresh_invoice_status($pdo, $invoiceId, $today ?? date('Y-m-d'));

    return $paid;
}

/** Has this invoice been voided? */
function invoice_is_void(array $invoice): bool
{
    return ($invoice['voided_at'] ?? null) !== null && (string) $invoice['voided_at'] !== '';
}

/**
 * Why this invoice cannot be voided, or null when it can be.
 *
 * Money first: an invoice that has collected cash must have that cash reversed
 * (see payment_reversal_block) before the document itself can be voided —
 * otherwise the ledger would hold a payment against a document that no longer
 * claims anything.
 */
function invoice_void_block(PDO $pdo, array $invoice): ?string
{
    if (invoice_is_void($invoice)) {
        return t('void_err_already');
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
    $st->execute([(int) $invoice['id']]);
    if (abs((float) $st->fetchColumn()) > 0.005) {
        return t('void_err_has_payment');
    }
    return null;
}

/**
 * Why this payment cannot be reversed, or null when it can be.
 * One place so the confirmation page and the POST handler can never disagree.
 */
function payment_reversal_block(PDO $pdo, array $payment): ?string
{
    if (($payment['reversal_of'] ?? null) !== null) {
        return t('reverse_err_is_reversal');   // a reversal cannot itself be reversed
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE reversal_of = ?');
    $st->execute([(int) $payment['id']]);
    if ((int) $st->fetchColumn() > 0) {
        return t('reverse_err_already');
    }
    return null;
}

/** Recompute an invoice's payment_status from amount_paid / due_date. */
function refresh_invoice_status(PDO $pdo, int $invoiceId, string $today): void
{
    $inv = $pdo->prepare('SELECT total, amount_paid, due_date, voided_at FROM invoices WHERE id = ?');
    $inv->execute([$invoiceId]);
    $row = $inv->fetch();
    if (!$row) {
        return;
    }
    // A voided invoice keeps whatever status it had; it is out of receivables,
    // so letting the clock turn it "overdue" would be misleading.
    if (($row['voided_at'] ?? null) !== null) {
        return;
    }
    $paid = (float) $row['amount_paid'];
    $total = (float) $row['total'];
    if ($paid >= $total && $total > 0) {
        $status = 'paid';
    } elseif (!empty($row['due_date']) && $row['due_date'] < $today) {
        $status = 'overdue';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    $pdo->prepare('UPDATE invoices SET payment_status = ? WHERE id = ?')->execute([$status, $invoiceId]);
}
