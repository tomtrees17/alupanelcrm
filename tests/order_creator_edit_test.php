<?php

// ── Rejected / draft orders: the person who keyed the order in may fix it (owner 2026-10-29) ──
// A sales assistant enters orders for a salesperson (submitter = the salesperson,
// created_by = the assistant). After a rejection the assistant must be able to
// edit and resubmit; another assistant must not.

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'ocreator');
    $f = new PDO('sqlite:' . $db);
    $f->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES
        (1, 'Admin', 'a@x', 'x', 'admin'), (2, 'ridwan', 'r@x', 'x', 'sales'),
        (3, 'nasya', 'n@x', 'x', 'sales_assistant'), (4, 'sinta', 's@x', 'x', 'sales_assistant')");
    $f->exec("INSERT INTO products (id, sku, name, spec, price, stock) VALUES (1, 'SJ8025', 'ACP', '3.0*0.10', 238000, 500)");
    $f->exec("INSERT INTO orders (id, order_no, customer_name, company, submitter, created_by, status, reject_note, reject_by, shipping_cost, payment_term, price_basis)
        VALUES (1, '1405/AMI-CO/10/26', 'CV Anugrah Cipta Karya', 'CV Anugrah Cipta Karya', 'ridwan', 'nasya', 'rejected', 'ongkir salah', 'tania', 250000, 'CBD', 'incl'),
               (2, '1406/AMI-CO/10/26', 'PT Lama', '', 'ridwan', '', 'rejected', 'x', 'tania', 0, 'CBD', 'incl')");
    $f->exec("INSERT INTO order_items (order_id, product_id, sku, color, spec, size, qty, price) VALUES (1, 1, 'SJ8025', 'Light Grey', '3.0*0.10', '1.22x2.44', 4, 238000)");
    $f = null;
    $run = function (int $uid, string $action, array $get, ?array $post) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get ?: new stdClass()), json_encode(['id' => $uid, 'real' => true]), $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $editLink = 'r=orders.edit&id=1';

    [$show] = $run(3, 'show', ['id' => 1], null);
    ok('assistant who keyed it in: the rejected order shows the Edit button', str_contains($show, $editLink));
    [$form, $fl] = $run(3, 'edit', ['id' => 1], null);
    ok('…and the edit form opens', str_contains($form, 'name="customer_name"') && $fl === [], json_encode($fl));
    $run(3, 'update', [], ['id' => 1, 'do' => 'submit', 'customer_name' => 'CV Anugrah Cipta Karya', 'sales_name' => 'ridwan', 'shipping_cost' => '200000',
        'product_id' => ['1'], 'sku' => ['SJ8025'], 'color' => ['Light Grey'], 'spec' => ['3.0*0.10'], 'size' => ['1.22x2.44'], 'qty' => ['4'], 'price' => ['238000'], 'price_excl' => ['0'], 'price_basis' => 'incl']);
    $o = $q('SELECT * FROM orders WHERE id = 1')[0];
    ok('…fixes it and resubmits: back to approval, rejection cleared, still ridwan\'s order, still keyed in by nasya',
        $o['status'] === 'pending_sup' && (float) $o['shipping_cost'] === 200000.0 && $o['reject_note'] === null && $o['submitter'] === 'ridwan' && $o['created_by'] === 'nasya', json_encode($o));
    ok('…once submitted it is locked again for her', !str_contains($run(3, 'show', ['id' => 1], null)[0], $editLink));

    (new PDO('sqlite:' . $db))->exec("UPDATE orders SET status = 'rejected' WHERE id = 1");
    [$show4] = $run(4, 'show', ['id' => 1], null);
    ok('another assistant: no Edit button', !str_contains($show4, $editLink));
    [, $fl] = $run(4, 'update', [], ['id' => 1, 'do' => 'draft', 'customer_name' => 'X']);
    ok('…and a direct update is refused', ($fl[0]['type'] ?? '') === 'error' && $q('SELECT customer_name FROM orders WHERE id = 1')[0]['customer_name'] === 'CV Anugrah Cipta Karya');
    ok('the salesperson (submitter) can still edit', str_contains($run(2, 'show', ['id' => 1], null)[0], $editLink));
    ok('an old order with no recorded keyer: not editable by an assistant', !str_contains($run(3, 'show', ['id' => 2], null)[0], 'r=orders.edit&id=2'));
    @unlink($db);
}
