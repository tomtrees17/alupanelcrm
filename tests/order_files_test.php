<?php

// ── CO-02 customer confirmation screenshots (docs/任务卡-客户确认截图.md) ──
// The assistant who keyed the order attaches the WhatsApp chat where the customer
// confirmed the CO; the CO prints ATTACHED; optional, the approver sees a reminder.

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('shell_exec') && is_file($harness)) {
    $dir = sys_get_temp_dir() . '/ofiles-' . bin2hex(random_bytes(4));
    $root = $dir . '/orders';
    @mkdir($dir, 0700, true);
    $db = $dir . '/fixture.db';
    $f = new PDO('sqlite:' . $db);
    $f->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES
        (1, 'Admin', 'a@x', 'x', 'admin'), (2, 'ridwan', 'r@x', 'x', 'sales_manager'), (3, 'nasya', 'n@x', 'x', 'sales_assistant'),
        (4, 'sinta', 's@x', 'x', 'sales_assistant'), (5, 'rian', 'ri@x', 'x', 'sales')");
    $f->exec("INSERT INTO products (id, sku, name, spec, price, stock) VALUES (1, 'SJ8001', 'ACP', '4.0*0.30', 679279.28, 500)");
    $f->exec("INSERT INTO orders (id, order_no, customer_name, company, submitter, created_by, status, payment_term, price_basis) VALUES
        (1, '1430/AMI-CO/10/26', 'WAHANA NUANSA INDONESIA', 'WAHANA NUANSA INDONESIA', 'Mr Fang', 'nasya', 'draft', 'CBD', 'incl'),
        (2, '1431/AMI-CO/10/26', 'Toko Lain', '', 'rian', 'nasya', 'pending_mgr', 'CBD', 'incl')");
    $f->exec("INSERT INTO order_items (order_id, product_id, sku, color, spec, size, qty, price) VALUES (1, 1, 'SJ8001', 'Pure White', '4.0*0.30', '1220X2440', 5, 679279.28), (2, 1, 'SJ8001', 'Pure White', '4.0*0.30', '1220X2440', 1, 679279.28)");
    $f = null;

    $png = function (int $r): string {
        $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress("\0" . chr($r) . "\0\0")) . $chunk('IEND', '');
    };
    $n = 0;
    // A fresh temp copy per file: the CLI upload path moves it into place.
    $files = function (array $contents, array $names = []) use ($dir, &$n): array {
        $out = ['name' => [], 'tmp_name' => [], 'size' => [], 'error' => []];
        foreach ($contents as $i => $bytes) {
            $tmp = $dir . '/up' . (++$n);
            file_put_contents($tmp, $bytes);
            $out['name'][] = $names[$i] ?? "chat{$n}.png";
            $out['tmp_name'][] = $tmp;
            $out['size'][] = strlen($bytes);
            $out['error'][] = 0;
        }
        return ['confirm_shots' => $out];
    };
    $run = function (int $uid, string $action, array $get, ?array $post = null, array $upload = []) use ($db, $root, $harness): array {
        $env = 'TEST_ORDER_FILES_ROOT=' . escapeshellarg($root) . ($upload ? ' TEST_FILES=' . escapeshellarg(json_encode($upload)) : '');
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get ?: new stdClass()), json_encode(['id' => $uid, 'real' => true]), $post === null ? '' : json_encode($post)];
        $out = (string) shell_exec($env . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
        preg_match('/<!--FLASH:(.*?)-->/s', $out, $fm);
        return [$out, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $live = fn(int $oid) => (int) $q("SELECT COUNT(*) n FROM order_attachments WHERE order_id = {$oid} AND removed_at IS NULL")[0]['n'];
    $zh = fn(string $k) => I18N['zh'][$k];
    $a = $png(10);
    $b = $png(20);

    [, $fl] = $run(3, 'attach', [], ['id' => 1], $files([$a, $b]));
    $rows = $q('SELECT * FROM order_attachments WHERE order_id = 1 ORDER BY id');
    ok('the assistant who keyed the draft attaches two screenshots: stored privately, recorded, audited',
        count($rows) === 2 && $rows[0]['uploaded_by'] === 'nasya' && file_get_contents($root . '/1/' . $rows[0]['stored']) === $a
        && (int) $q("SELECT COUNT(*) n FROM audit_log WHERE module = 'orders' AND action = 'attach' AND entity_id = 1")[0]['n'] === 2
        && ($fl[0]['message'] ?? '') === sprintf($zh('oa_msg_added'), 2), json_encode($fl));

    [, $fl] = $run(4, 'attach', [], ['id' => 1], $files([$a]));
    ok('another assistant cannot attach to it', ($fl[0]['type'] ?? '') === 'error' && $live(1) === 2, json_encode($fl));

    [, $fl] = $run(3, 'attach', [], ['id' => 1], $files(["not an image at all"], ['chat.png']));
    ok('a non-image renamed .png is refused', ($fl[0]['message'] ?? '') === $zh('oa_err_type') && $live(1) === 2, json_encode($fl));

    [, $fl] = $run(3, 'attach', [], ['id' => 1], $files([$a, $a, $a, $a]));
    ok('at most 5 per order: 3 more taken, the 6th refused', $live(1) === 5 && in_array($zh('oa_err_max'), array_column($fl, 'message'), true), json_encode($fl));

    $att = (int) $rows[0]['id'];
    [$img] = $run(1, 'attachment', ['id' => 1, 'att' => $att]);
    [$wrong] = $run(1, 'attachment', ['id' => 2, 'att' => $att]);
    ok('viewing goes through the order: the image comes back; the same id under another order is 404',
        str_starts_with($img, $a) && str_starts_with($wrong, 'Not found'));
    [$own] = $run(5, 'attachment', ['id' => 1, 'att' => $att]);
    ok('a salesperson who only sees own orders cannot open another salesperson\'s screenshot', !str_starts_with($own, $a));

    [$show] = $run(3, 'show', ['id' => 1]);
    ok('order page: thumbnails, remove buttons, no upload form once 5 are there',
        substr_count($show, 'r=orders.attachment&') >= 5 && str_contains($show, 'r=orders.detach') && !str_contains($show, 'action="' . 'index.php?r=orders.attach"'));

    [$co] = $run(3, 'print_co', ['id' => 1]);
    preg_match('/Acknowledge by Client,(.*?)<\/div>\s*<div>/s', $co, $cb);
    [$co2] = $run(1, 'print_co', ['id' => 2]);
    ok('CO: ATTACHED in the client\'s signature box when there is a screenshot, blank when not',
        str_contains($cb[1] ?? '', 'ATTACHED') && !str_contains($co2, '>ATTACHED<'), $cb[1] ?? '');

    [, $fl] = $run(3, 'detach', [], ['id' => 1, 'att' => $att]);
    $r = $q("SELECT * FROM order_attachments WHERE id = {$att}")[0];
    ok('remove = hidden, not deleted: row and file stay, audited',
        $r['removed_at'] !== null && $r['removed_by'] === 'nasya' && is_file($root . '/1/' . $r['stored']) && $live(1) === 4
        && (int) $q("SELECT COUNT(*) n FROM audit_log WHERE action = 'detach' AND entity_id = 1")[0]['n'] === 1, json_encode($fl));
    ok('…and it is gone from the order page', !str_contains($run(3, 'show', ['id' => 1])[0], 'att=' . $att . '"'));

    (new PDO('sqlite:' . $db))->exec("UPDATE orders SET status = 'pending_mgr' WHERE id = 1");
    [, $fl] = $run(3, 'attach', [], ['id' => 1], $files([$b]));
    [, $fl2] = $run(3, 'detach', [], ['id' => 1, 'att' => (int) $rows[1]['id']]);
    ok('once submitted: no more adding or removing', ($fl[0]['message'] ?? '') === $zh('oa_err_locked') && ($fl2[0]['message'] ?? '') === $zh('oa_err_locked') && $live(1) === 4);

    [$appr2] = $run(2, 'show', ['id' => 2]);
    [$appr1] = $run(2, 'show', ['id' => 1]);
    ok('optional: the sales manager approving an order without a screenshot sees the reminder; with one, none',
        str_contains($appr2, 'oa-missing') && str_contains($appr2, $zh('oa_missing')) && !str_contains($appr1, 'oa-missing'));

    $run(3, 'store', [], ['do' => 'draft', 'customer_name' => 'CV Baru', 'sales_name' => 'rian', 'submitter' => 'rian', 'product_id' => ['1'], 'sku' => ['SJ8001'], 'color' => ['Pure White'],
        'spec' => ['4.0*0.30'], 'size' => ['1220X2440'], 'qty' => ['2'], 'price' => ['679279.28'], 'price_excl' => ['0'], 'price_basis' => 'incl'], $files([$b]));
    $new = $q("SELECT id FROM orders WHERE customer_name = 'CV Baru'")[0]['id'] ?? 0;
    ok('a screenshot picked on the new-order form is attached to the new order', $new > 0 && $live((int) $new) === 1);

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) {
        $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
    }
    @rmdir($dir);
}
