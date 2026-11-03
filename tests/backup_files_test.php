<?php

// ── Nightly backup bundles every private folder, not just data/uploads (2026-11-03) ──
// HR attachments, visit photos, Coretax files and signature images were left out.

if (class_exists('ZipArchive')) {
    $root = sys_get_temp_dir() . '/bkf-' . bin2hex(random_bytes(4));
    $files = [
        'db/uploads/12/req.pdf' => 'request',
        'db/signatures/sig-2-1.png' => 'co-sig',
        'db/signatures/sig-inv-2-1.png' => 'inv-sig',
        'app/hr/7/ktp.jpg' => 'ktp',
        'app/visits/7/2026-11/a.jpg' => 'photo',
        'app/faktur/coretax-2026-10.xml' => 'xml',
    ];
    foreach ($files as $rel => $bytes) {
        @mkdir(dirname($root . '/' . $rel), 0700, true);
        file_put_contents($root . '/' . $rel, $bytes);
    }
    $folders = Backup::folders(['db_path' => $root . '/db/crm.sqlite'], $root . '/app');
    ok('the five private folders are listed', array_keys($folders) === ['uploads', 'signatures', 'hr', 'visits', 'faktur'], json_encode(array_keys($folders)));

    $zipPath = $root . '/b.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $counts = Backup::add_folders($zip, $folders + ['missing' => $root . '/nope']);
    $zip->close();
    ok('file counts per folder; a missing folder is 0, not an error',
        $counts === ['uploads' => 1, 'signatures' => 2, 'hr' => 1, 'visits' => 1, 'faktur' => 1, 'missing' => 0], json_encode($counts));

    $zip->open($zipPath);
    $want = ['uploads/12/req.pdf' => 'request', 'signatures/sig-2-1.png' => 'co-sig', 'signatures/sig-inv-2-1.png' => 'inv-sig',
             'hr/7/ktp.jpg' => 'ktp', 'visits/7/2026-11/a.jpg' => 'photo', 'faktur/coretax-2026-10.xml' => 'xml'];
    $got = [];
    foreach (array_keys($want) as $name) {
        $got[$name] = $zip->getFromName($name);
    }
    ok('the zip mirrors data/ (restore = unzip into data/), contents intact', $got === $want && $zip->numFiles === 6, json_encode($got));
    $zip->close();

    $default = Backup::folders(['db_path' => '/srv/x/data/crm.sqlite']);
    ok('defaults: uploads/signatures beside the DB; hr/visits/faktur where Hr/Visits/Faktur write',
        $default['signatures'] === '/srv/x/data/signatures' && $default['hr'] === dirname(__DIR__) . '/data/hr'
        && $default['visits'] === dirname(__DIR__) . '/data/visits' && $default['faktur'] === dirname(__DIR__) . '/data/faktur');

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) {
        $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
    }
    @rmdir($root);
}
