<?php

// ── New order: save a half-filled draft (owner 2026-10-28) ──
// A draft may have no customer and no items; submitting for approval still
// needs both (from the form and from the draft's page). The action bar stays
// on screen; the form autosaves in this browser and offers to restore.

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'odraft');
    $f = new PDO('sqlite:' . $db);
    $f->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@x', 'x', 'admin')");
    $f->exec("INSERT INTO products (id, sku, name, spec, price, stock) VALUES (1, 'SJ8001', 'ACP', '4.0*0.21', 235000, 500)");
    $f = null;
    $admin = json_encode(['id' => 1, 'real' => true]);
    $run = function (string $action, array $get, ?array $post) use ($db, $harness, $admin): array {
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get ?: new stdClass()), $admin, $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $count = fn() => (int) $q('SELECT COUNT(*) n FROM orders')[0]['n'];

    [$form] = $run('create', [], null);
    ok('form: the draft button skips the browser\'s required check, the action bar sticks', str_contains($form, 'value="draft" formnovalidate') && str_contains($form, 'order-actions'));
    ok('form: autosave in this browser, restore offered', str_contains($form, "order-form:new") && str_contains($form, 'autosave-restore'));

    [, $fl] = $run('store', [], ['do' => 'draft', 'customer_name' => '', 'note' => 'Pak Denny, tunggu ukuran']);
    $d = $q("SELECT * FROM orders WHERE note = 'Pak Denny, tunggu ukuran'")[0] ?? null;
    ok('a draft without customer and items is saved', $d && $d['status'] === 'draft' && $d['customer_name'] === '' && (int) $q("SELECT COUNT(*) n FROM order_items WHERE order_id = {$d['id']}")[0]['n'] === 0, json_encode($fl));
    ok('…and no empty customer record is created', (int) $q('SELECT COUNT(*) n FROM customers')[0]['n'] === 0);
    [$list] = $run('index', [], null);
    ok('the list shows it with a placeholder name', str_contains($list, t('order_no_customer')));
    [, $fl] = $run('submit', [], ['id' => $d['id']]);
    ok('submitting that draft for approval: refused, customer first', ($fl[0]['type'] ?? '') === 'error' && $fl[0]['message'] === t('msg_order_cust_req')
        && $q("SELECT status FROM orders WHERE id = {$d['id']}")[0]['status'] === 'draft');
    $n = $count();
    [, $fl] = $run('store', [], ['do' => 'submit', 'customer_name' => '']);
    ok('submit from the form without a customer: refused, nothing saved', ($fl[0]['message'] ?? '') === t('msg_order_cust_req') && $count() === $n);
    [, $fl] = $run('store', [], ['do' => 'submit', 'customer_name' => 'PT Lengkap']);
    ok('submit with a customer but no items: refused', ($fl[0]['message'] ?? '') === t('msg_order_item_req') && $count() === $n);
    $run('update', [], ['id' => $d['id'], 'do' => 'draft', 'customer_name' => 'PT Denny Jaya', 'product_id' => ['1'], 'sku' => ['SJ8001'], 'color' => ['White'],
        'spec' => ['4.0*0.21'], 'size' => ['1.22x2.44'], 'qty' => ['5'], 'price' => ['235000'], 'price_excl' => ['0'], 'price_basis' => 'incl']);
    [, $fl] = $run('submit', [], ['id' => $d['id']]);
    ok('completed later, the same draft goes to approval', $q("SELECT status FROM orders WHERE id = {$d['id']}")[0]['status'] === 'pending_sup', json_encode($fl));
    @unlink($db);
}
