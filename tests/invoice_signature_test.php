<?php

// ── Invoice signature (owner 2026-11-03) ──
// The documents' signer (config signer_name, Mutia) signs the CO and the invoice
// with two different images (e.g. the invoice one carries the company stamp).
// Her account holds both; the CO prints the first, invoices (print + Word) the second.

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('shell_exec') && is_file($harness)) {
    $dir = sys_get_temp_dir() . '/invsig-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0700, true);
    $db = $dir . '/fixture.db';
    $signerName = (string) ($GLOBALS['config']['signer_name'] ?? 'MUTIARA FARIANDA');
    $f = new PDO('sqlite:' . $db);
    $f->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->prepare("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@x', 'x', 'admin'), (2, ?, 'm@x', 'x', 'finance_manager'), (3, 'ridwan', 'r@x', 'x', 'sales')")
        ->execute([ucwords(strtolower($signerName))]);
    $f->exec("INSERT INTO invoices (id, invoice_no, customer, bill_to_name, subtotal, ppn, total, invoice_date, due_date, calc)
              VALUES (1, '11801 - AMI - INV - 11 - 26', 'Ibu Dewi', 'Ibu Dewi', 1351351.35, 148648.65, 1500000, '2026-11-03', '2026-11-03', 'coretax')");
    $f->exec("INSERT INTO invoice_items (invoice_id, sku, spec, size, qty, unit, price) VALUES (1, 'SJ8033', '3.0*0.06', '1.220 x 2.440', 10, 'LBR', 135135.14)");
    $f = null;

    // Two tiny, different PNGs (no GD needed).
    $png = function (int $r, int $g, int $b): string {
        $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress("\0" . chr($r) . chr($g) . chr($b))) . $chunk('IEND', '');
    };
    $coPng = $png(0, 0, 200);
    $invPng = $png(200, 0, 0);
    file_put_contents($dir . '/co.png', $coPng);
    file_put_contents($dir . '/inv.png', $invPng);
    $file = fn(string $name) => ['name' => basename($name), 'tmp_name' => $name, 'size' => filesize($name), 'error' => 0];

    $run = function (string $ctl, string $action, array $get, ?array $post = null, array $files = []) use ($db, $dir, $harness): array {
        $env = 'TEST_DATA_DIR=' . escapeshellarg($dir) . ($files ? ' TEST_FILES=' . escapeshellarg(json_encode($files)) : '');
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode($get ?: new stdClass()), json_encode(['id' => 1, 'real' => true]), $post === null ? '' : json_encode($post)];
        $out = (string) shell_exec($env . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
        preg_match('/<!--FLASH:(.*?)-->/s', $out, $fm);
        return [$out, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $userPost = fn(int $id, array $u) => ['id' => $id, 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'title' => '', 'phone' => '', 'lang' => 'id', 'password' => ''];

    [$form] = $run('users', 'edit', ['id' => 2]);
    [$formR] = $run('users', 'edit', ['id' => 3]);
    ok('the signer\'s account form has a second upload, 发票签名; other accounts do not',
        str_contains($form, 'name="invoice_signature"') && str_contains($form, 'name="signature"') && !str_contains($formR, 'name="invoice_signature"') && str_contains($formR, 'name="signature"'));

    $m = $q('SELECT * FROM users WHERE id = 2')[0];
    $run('users', 'update', [], $userPost(2, $m), ['signature' => $file($dir . '/co.png'), 'invoice_signature' => $file($dir . '/inv.png')]);
    $m = $q('SELECT signature_path, invoice_signature_path FROM users WHERE id = 2')[0];
    ok('both images stored separately in the private folder',
        str_starts_with((string) $m['signature_path'], 'sig-2-') && str_starts_with((string) $m['invoice_signature_path'], 'sig-inv-2-')
        && file_get_contents($dir . '/signatures/' . $m['signature_path']) === $coPng && file_get_contents($dir . '/signatures/' . $m['invoice_signature_path']) === $invPng, json_encode($m));
    $aud = $q("SELECT detail FROM audit_log WHERE module = 'users' AND entity_id = 2 ORDER BY id DESC LIMIT 1")[0]['detail'] ?? '';
    ok('…audit names both uploads', str_contains($aud, '上传了签名') && str_contains($aud, '上传了发票签名'), $aud);

    ok('fetch: default = CO signature, kind=invoice = invoice signature',
        str_starts_with($run('users', 'signature', ['id' => 2])[0], $coPng) && str_starts_with($run('users', 'signature', ['id' => 2, 'kind' => 'invoice'])[0], $invPng));

    [$print] = $run('finance', 'print', ['id' => 1]);
    preg_match('/<td class="inv-sign">(.*?)<\/td>/s', $print, $sm);
    ok('invoice print: the invoice signature above Finance Manager, not the CO one',
        str_contains($sm[1] ?? '', 'kind=invoice') && str_contains($sm[1] ?? '', 'id=2') && str_contains($sm[1] ?? '', 'Finance Manager'), $sm[1] ?? substr($print, 0, 300));

    [$word] = $run('finance', 'word', ['id' => 1]);
    file_put_contents($dir . '/out.docx', $word);
    $zip = new ZipArchive();
    $media = [];
    if ($zip->open($dir . '/out.docx') === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $media[] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
    }
    ok('Word invoice: the invoice signature is embedded (and not the CO one)', in_array($invPng, $media, true) && !in_array($coPng, $media, true), count($media) . ' media');

    $run('users', 'update', [], $userPost(2, $q('SELECT * FROM users WHERE id = 2')[0]) + ['remove_invoice_signature' => '1']);
    $m = $q('SELECT signature_path, invoice_signature_path FROM users WHERE id = 2')[0];
    [$print] = $run('finance', 'print', ['id' => 1]);
    ok('remove the invoice signature: the CO one stays; the invoice prints with a blank space again',
        $m['invoice_signature_path'] === null && $m['signature_path'] !== null && !str_contains($print, 'kind=invoice'), json_encode($m));

    foreach (array_merge(glob($dir . '/signatures/*') ?: [], glob($dir . '/*') ?: []) as $p) {
        is_file($p) && @unlink($p);
    }
    @rmdir($dir . '/signatures');
    @rmdir($dir);
}
