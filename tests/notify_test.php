<?php

// ── WhatsApp notification queue ──

require_once __DIR__ . '/../app/Notify.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

// 1) Indonesian phone numbers arrive in every shape staff will type.
$cases = [
    '081234567890'    => '6281234567890',
    '0812-3456-7890'  => '6281234567890',
    '+62 812 3456 7890' => '6281234567890',
    '62812 3456 7890' => '6281234567890',
    '812345678901'    => '62812345678901',   // bare local, no leading 0
    '  0812 3456 7890  ' => '6281234567890',
];
$bad = ['', 'abc', '0812', '12345', '1234567890123456789'];
$phoneOk = true;
foreach ($cases as $in => $want) {
    if (Notify::normalise_phone((string) $in) !== $want) {
        $phoneOk = false;
        echo "      {$in} → " . Notify::normalise_phone((string) $in) . " (want {$want})\n";
    }
}
ok('phone numbers normalise to 62xxx', $phoneOk);
ok('junk numbers are rejected', array_filter($bad, fn($b) => Notify::normalise_phone($b) !== '') === []);

// 2) Queue resolves the recipient's number at queue time.
$pdo->prepare("INSERT INTO users (name,email,password_hash,role,phone,lang) VALUES (?,?,?,?,?,?)")
    ->execute(['Budi', 'budi@x.id', 'x', 'supervisor', '0812-1111-2222', 'id']);
$budi = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (name,email,password_hash,role,phone,lang) VALUES (?,?,?,?,?,?)")
    ->execute(['老板', 'boss@x.id', 'x', 'manager', '', 'zh']);
$boss = (int) $pdo->lastInsertId();

Notify::queue($pdo, $budi, 'order_pending_sup', 'Order SO-1 menunggu persetujuan.', 'order', 1, 'SO-1');
$n = $pdo->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 1')->fetch();
ok('queued with a normalised phone', $n['phone'] === '6281211112222' && $n['status'] === 'queued', (string) $n['phone']);
ok('queue records what it is about', $n['entity'] === 'order' && (int) $n['entity_id'] === 1 && $n['label'] === 'SO-1');

// 3) A user with no phone is recorded as skipped, not silently dropped —
//    otherwise nobody notices that an approver never gets told anything.
Notify::queue($pdo, $boss, 'order_pending_mgr', '订单等待审批。', 'order', 1, 'SO-1');
$n = $pdo->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 1')->fetch();
ok('missing phone is visible, not dropped', $n['status'] === 'skipped' && str_contains((string) $n['error'], 'no phone'));

// 4) Queueing must never throw, even against a broken database.
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$threw = false;
try {
    Notify::queue($broken, 1, 'x', 'body');
} catch (Throwable $e) {
    $threw = true;
}
ok('queue swallows its own failure', !$threw);

// 5) The 'log' driver completes the round trip without any network call.
$cfg = ['wa' => ['driver' => 'log', 'throttle' => 0]];
[$sent, $failed] = Notify::flush($pdo, $cfg, 50);
ok('log driver marks queued messages sent', $sent === 1 && $failed === 0, "sent={$sent} failed={$failed}");
$n = $pdo->query("SELECT * FROM notifications WHERE status='sent'")->fetch();
ok('sent_at stamped', ($n['sent_at'] ?? '') !== '');
ok('skipped rows are not picked up', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE status='skipped'")->fetchColumn() === 1);
ok('nothing left queued', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE status='queued'")->fetchColumn() === 0);

// 6) A failing provider retries, then gives up at the cap instead of looping forever.
Notify::queue($pdo, $budi, 'order_pending_sup', 'retry me', 'order', 2, 'SO-2');
$cfgBad = ['wa' => ['driver' => 'cloud', 'token' => 'x', 'sender' => '', 'throttle' => 0]];  // no sender → always fails
for ($i = 1; $i <= Notify::MAX_ATTEMPTS + 2; $i++) {
    Notify::flush($pdo, $cfgBad, 50);
}
$n = $pdo->query("SELECT * FROM notifications WHERE label='SO-2'")->fetch();
ok('failed message stops at the attempt cap', (int) $n['attempts'] === Notify::MAX_ATTEMPTS, (string) $n['attempts']);
ok('failed message is marked failed', $n['status'] === 'failed', (string) $n['status']);
ok('failure reason recorded', str_contains((string) $n['error'], 'sender'));

// 7) test_to redirects everything to one number (safe first switch-on).
Notify::queue($pdo, $budi, 'order_pending_sup', 'to the test line', 'order', 3, 'SO-3');
$before = $pdo->query("SELECT phone FROM notifications WHERE label='SO-3'")->fetchColumn();
Notify::flush($pdo, ['wa' => ['driver' => 'log', 'test_to' => '0899-0000-0000', 'throttle' => 0]], 50);
ok('test_to does not rewrite the stored recipient', $pdo->query("SELECT phone FROM notifications WHERE label='SO-3'")->fetchColumn() === $before);

// ── Recipient resolution (domain layer) ──

// 8) Messages render in the RECIPIENT's language, not the sender's session.
$_SESSION['lang'] = 'zh';
$budiRow = $pdo->query("SELECT * FROM users WHERE id = {$budi}")->fetch();
$bossRow = $pdo->query("SELECT * FROM users WHERE id = {$boss}")->fetch();
ok('user_lang reads the column', user_lang($budiRow) === 'id' && user_lang($bossRow) === 'zh');
ok('user_lang defaults to Indonesian', user_lang(null) === 'id' && user_lang(['lang' => 'xx']) === 'id');

notify_user($pdo, $budiRow, 'order_pending_sup', 'wa_order_pending', ['SO-9', 'PT Maju'], 'order', 9, 'SO-9');
$n = $pdo->query("SELECT * FROM notifications WHERE label='SO-9'")->fetch();
ok('Indonesian staff get Indonesian text', str_contains((string) $n['body'], 'menunggu persetujuan'), (string) $n['body']);
ok('placeholders filled', str_contains((string) $n['body'], 'SO-9') && str_contains((string) $n['body'], 'PT Maju'));

$_SESSION['lang'] = 'id';
notify_user($pdo, $bossRow, 'order_pending_mgr', 'wa_order_pending', ['SO-10', 'PT Maju'], 'order', 10, 'SO-10');
$n = $pdo->query("SELECT * FROM notifications WHERE label='SO-10'")->fetch();
ok('Chinese staff get Chinese text regardless of session', str_contains((string) $n['body'], '等待你审批'), (string) $n['body']);
$_SESSION['lang'] = 'zh';

// 9) Stage routing must follow the same map as the permission check, or the
//    person who is told is not the person who is allowed to act.
$roleOk = true;
foreach (['pending_sup' => 'supervisor', 'pending_mgr' => 'manager', 'pending_wh' => 'warehouse'] as $status => $role) {
    if (order_action_role($status) !== $role) {
        $roleOk = false;
    }
}
ok('order stage → role map is the permission map', $roleOk);

$pdo->exec("DELETE FROM notifications");
$order = ['id' => 5, 'order_no' => 'SO-5', 'customer_name' => 'PT Maju', 'submitter' => 'Sales A', 'created_by' => 'Budi'];
notify_order_stage($pdo, $order, 'pending_sup');
$got = $pdo->query("SELECT * FROM notifications")->fetchAll();
ok('every supervisor is notified', count($got) === 1 && (int) $got[0]['user_id'] === $budi, (string) count($got));
ok('approved status notifies nobody', (function () use ($pdo, $order) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    notify_order_stage($pdo, $order, 'approved');
    return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $before;
})());

// 10) Stakeholders = the assistant who keyed it in AND the salesperson it belongs to.
$pdo->prepare("INSERT INTO users (name,email,password_hash,role,phone,lang) VALUES (?,?,?,?,?,?)")
    ->execute(['Sales A', 'sa@x.id', 'x', 'sales', '081300000001', 'id']);
$holders = order_stakeholders($pdo, $order);
ok('both assistant and salesperson notified', count($holders) === 2, implode(',', array_column($holders, 'name')));

$same = order_stakeholders($pdo, ['submitter' => 'Budi', 'created_by' => 'Budi']);
ok('same person is not notified twice', count($same) === 1);

$none = order_stakeholders($pdo, ['submitter' => 'Nobody Here', 'created_by' => '']);
ok('unknown names resolve to nobody', $none === []);

// ── The delivery-log page renders (it is the only window into the queue) ──
$viewFile = __DIR__ . '/../views/audit/notifications.php';
$render = function (array $data) use ($viewFile): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
    // view() injects these into every template; mirror it or the render diverges.
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($data, EXTR_SKIP);
    ob_start();
    include $viewFile;
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};
$base = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'st' => '', 'counts' => [], 'driver' => 'log'];
$html = $render($base);
ok('notification log empty state renders', str_contains($html, t('notif_none')));
ok('log driver is called out as not sending', str_contains($html, t('notif_driver_log')));

$rows = [
    ['id' => 2, 'created_at' => '2026-08-28 10:00:00', 'sent_at' => '2026-08-28 10:01:00', 'user_id' => 1,
     'user_name' => 'Budi', 'phone' => '6281211112222', 'event' => 'order_pending_sup', 'label' => 'SO-1',
     'body' => "Order SO-1\nmenunggu persetujuan.", 'status' => 'sent', 'error' => null],
    ['id' => 1, 'created_at' => '2026-08-28 09:00:00', 'sent_at' => null, 'user_id' => 2,
     'user_name' => '老板', 'phone' => '', 'event' => 'order_pending_mgr', 'label' => 'SO-1',
     'body' => '订单等待审批。', 'status' => 'skipped', 'error' => 'no phone number on file'],
];
$html = $render(array_merge($base, ['rows' => $rows, 'total' => 2, 'counts' => ['sent' => 1, 'skipped' => 1], 'driver' => 'fonnte']));
ok('rows render with status tags', str_contains($html, t('notif_st_sent')) && str_contains($html, t('notif_st_skipped')));
ok('unreachable recipient shows the reason', str_contains($html, 'no phone number on file'));
ok('multi-line body kept readable', str_contains($html, '<br'));
ok('real driver name shown', str_contains($html, 'fonnte') && !str_contains($html, t('notif_driver_log')));

$evil = [array_merge($rows[0], ['body' => '<script>alert(1)</script>', 'user_name' => '<img src=x onerror=y>', 'error' => '<b>x</b>'])];
$html = $render(array_merge($base, ['rows' => $evil, 'total' => 1]));
ok('message body escaped', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));
ok('recipient name escaped', !str_contains($html, '<img src=x'));
