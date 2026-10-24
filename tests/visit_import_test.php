<?php

// ── 拜访 HR-04 v3: WhatsApp group export → visits (app/WaChat.php, app/VisitImport.php) ──
// Fixture: samples_test/wa_chat_sample (de-identified, real export layout) plus
// a few fictional messages: more four-line reports, a late one with a date
// line, photo and text sent separately, an invisible U+200E. The model is
// stubbed (Claude::$http); photos are 1×1 JPEGs carrying a marker the stub reads.

$dir = __DIR__ . '/../samples_test/wa_chat_sample';
$ios = (string) @file_get_contents($dir . '/_chat.txt');
$android = (string) @file_get_contents($dir . '/_chat_android_variant.txt');
$cfgFull = (array) (@include __DIR__ . '/../config.php');
$vcfg = VisitImport::cfg($cfgFull);
$savedPhotoRoot = Visits::$photoRoot;
Visits::$photoRoot = sys_get_temp_dir() . '/vi-photos-' . bin2hex(random_bytes(4));   // never data/ on the server

// ── reading the export ──
$m = WaChat::messages($ios);
ok('iPhone export: every message read (57)', count($m) === 57, (string) count($m));
ok('iPhone: M/D/YY and 12-hour clock → ISO', $m[0]['ts'] === '2026-10-19 17:08:23' && $m[0]['sender'] === 'A _ N D I' && $m[3]['ts'] === '2026-10-19 11:55:59');
ok('iPhone: caption and continuation lines kept, media name pulled out', str_starts_with($m[0]['text'], 'Abadi Glass') && str_contains($m[0]['text'], 'di follow up') && $m[0]['media'] === ['00000000-PHOTO-2026-10-19-17-08-23.jpg']);
ok('album markers and deleted messages recognised', count(array_filter($m, fn($x) => $x['album'])) === 9 && count(array_filter($m, fn($x) => $x['deleted'])) === 1);
$a = WaChat::messages($android);
ok('Android export: DD/MM/YY HH.MM, IMG (file attached), caption on the next lines', count($a) === 3 && $a[0]['ts'] === '2026-10-19 16:50:00' && $a[0]['media'] === ['IMG-20261019-WA0001.jpg']
    && str_starts_with($a[0]['text'], 'Toko: Maju Jaya Aluminium') && $a[2]['media'] === [null]);
ok('Android English clock (HH:MM) and PM also read', WaChat::messages("19/10/26, 4:50 PM - Budi: <Media omitted>\nToko: A")[0]['ts'] === '2026-10-19 16:50:00');
ok('U+200E and a BOM are stripped', WaChat::messages("\u{FEFF}\u{200E}[10/19/26, 5:08:23 PM] \u{200E}Budi: <attached: x.jpg> Toko A")[0]['text'] === 'Toko A');
ok('day/month order read from the file (13/10 can only be D/M)', WaChat::messages("[13/10/26, 9:00:00 AM] X: hi\n[1/10/26, 9:00:00 AM] X: hi")[1]['ts'] === '2026-10-01 09:00:00');
$sv = WaChat::visits($m);
ok('sample: 24 visits (photo with caption), 23 extra photos joined, 10 lines ignored', count($sv['visits']) === 24 && $sv['ignored'] === 10
    && array_sum(array_map(fn($v) => count($v['photos']), $sv['visits'])) === 47, count($sv['visits']) . '/' . $sv['ignored']);
ok('extra photos only from the same sender and minute', count($sv['visits'][0]['photos']) === 2 && count(WaChat::visits($a)['visits']) === 2);
ok('sender keys: a phone number in any spelling, names folded', WaChat::sender_key('+62 812-0000-0002') === WaChat::sender_key('0812 0000 0002') && WaChat::sender_key('A _ N D I') === 'a _ n d i');
ok('dedup hash ignores the media marker (text-only and media exports agree)', WaChat::hash('X', '2026-10-19 10:00:00', 'Toko A') === WaChat::hash('X', '2026-10-19 10:00:00', " Toko A \n"));

// ── reading a report (rules) ──
$four = VisitImport::parse_report("Toko: Cahaya Advertising\nStatus: Baru\nHasil: Order\nCatatan: butuh 40 lbr 4mm bulan depan; pakai Seven 180rb", '2026-10-24 13:21:51', $vcfg);
ok('four-line: store, Baru, Order, note, format ok', $four['store'] === 'Cahaya Advertising' && $four['status'] === 'baru' && $four['outcome'] === 'order' && $four['format_ok'] === 1 && str_contains($four['note'], '40 lbr'));
ok('four-line: competitor brand and price "Seven 180rb" → 180.000', $four['brand'] === 'Seven' && $four['price'] === 180000);
ok('group short words map to the stored results', VisitImport::parse_report("Toko: A\nStatus: Lama\nHasil: Tutup", '2026-10-24 10:00:00', $vcfg)['outcome'] === 'tidak_terhubung'
    && VisitImport::parse_report("Toko: A\nStatus: Lama\nHasil: Minat", '2026-10-24 10:00:00', $vcfg)['outcome'] === 'berminat'
    && VisitImport::parse_report("Toko: A\nStatus: Lama\nHasil: Tidak", '2026-10-24 10:00:00', $vcfg)['outcome'] === 'tidak_butuh');
$late = VisitImport::parse_report("23/10\nToko: Toko Kaca Abadi Jaya\nStatus: Baru\nHasil: Minat", '2026-10-25 09:00:00', $vcfg);
ok('date line = late report: the visit day is that date', $late['date'] === '2026-10-23' && $late['store'] === 'Toko Kaca Abadi Jaya');
$old = VisitImport::parse_report("Abadi Glass \nToko \nPic : Bpk Lina \nPenawaran Alusign \nBiasa pakai ACP Marks, tertarik dengan Alusign, sdh berikan katalog dan sample, di follow up", '2026-10-19 17:08:23', $vcfg);
ok('old free text: store, type, PIC, brand, template sentence, result unknown', $old['store'] === 'Abadi Glass' && $old['type'] === 'Toko' && $old['pic'] === 'Lina' && $old['brand'] === 'Marks'
    && $old['template'] === 1 && $old['outcome'] === Visits::UNKNOWN && $old['format_ok'] === 0);
$visit = VisitImport::parse_report("Visit Advertising\nBerkah Reklame\nperkenalan acp Alusign memberikan katalog dan sampel, biasa memakai Seven 3mm dan 4mm", '2026-10-23 14:21:43', $vcfg);
ok('"Visit Advertising" heading: the store is the next line; 3mm is not a price', $visit['store'] === 'Berkah Reklame' && $visit['type'] === 'Advertising' && $visit['brand'] === 'Seven' && $visit['price'] === null);
ok('"- Mitra Laser" bullet stripped; our own brand is never a competitor', VisitImport::parse_report("- Mitra Laser \n- Menawarkan ACP alusignpanel", '2026-10-20 14:34:37', $vcfg)['store'] === 'Mitra Laser'
    && VisitImport::brands('pakai Alusign dan Tajima', $vcfg) === ['Tajima']);
ok('prices: Rp 175.000, 1,2jt', VisitImport::price_near('Alcopan Rp 175.000 per lembar', 'Alcopan') === 175000 && VisitImport::price_near('Marks 1,2jt', 'Marks') === 1200000);
ok('names compare without PT / Toko / punctuation', VisitImport::name_key('PT. Abadi Glass') === 'abadi glass' && VisitImport::similarity('Toko Abadi Glass', 'ABADI GLASS') === 1.0
    && VisitImport::similarity('Abadi Glass', 'Abadi Glass Bekasi') >= 0.9 && VisitImport::similarity('Nusa Alumunium', 'Nusa Aluminium') < 0.95);

// ── the fixture export ──
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$photo = fn(string $marker) => "\xFF\xD8" . "\xFF\xFE" . pack('n', strlen($marker) + 2) . $marker . substr($jpeg, 2);
$extra = "[10/25/26, 8:01:00 AM] Budi Santoso: <attached: 00000047-PHOTO-2026-10-25-08-01-00.jpg> Toko: Sinar Kaca Mandiri\nStatus: Lama\nHasil: Penawaran\n"
       . "[10/25/26, 8:31:00 AM] +62 813-0000-0003: <attached: 00000048-PHOTO-2026-10-25-08-31-00.jpg> Toko: Karya Plafon\nStatus: Baru\nHasil: Minat\nCatatan: tanya harga 3mm\n"
       . "[10/25/26, 9:02:00 AM] Rina Marlina: <attached: 00000049-PHOTO-2026-10-25-09-02-00.jpg> Toko: Abadi Glass\nStatus: Lama\nHasil: Order\nCatatan: 20 lbr Seven 175rb\n"
       . "\u{200E}[10/25/26, 9:40:00 AM] A _ N D I: <attached: 00000050-PHOTO-2026-10-25-09-40-00.jpg> Toko: Maju Jaya Aluminium\nStatus: Lama\nHasil: Tutup\n"
       . "[10/25/26, 10:15:00 AM] +62 811-0000-0001: <attached: 00000051-PHOTO-2026-10-25-10-15-00.jpg> Toko: Mitra Laser\nStatus: Lama\nHasil: Tidak\nCatatan: pakai Toin 165rb\n"
       . "[10/25/26, 11:00:00 AM] Budi Santoso: <attached: 00000052-PHOTO-2026-10-25-11-00-00.jpg> 23/10\nToko: Toko Kaca Abadi Jaya\nStatus: Baru\nHasil: Minat\n"
       . "[10/25/26, 11:30:00 AM] Rina Marlina: <attached: 00000053-PHOTO-2026-10-25-11-30-00.jpg>\n"
       . "[10/25/26, 11:30:40 AM] Rina Marlina: Toko Mulia Kaca\nToko\nBiasa pakai ACP Toin, untuk sementara belum ada kebutuhan\n";
$full = rtrim($ios) . "\n" . $extra;
$names = [];
foreach (WaChat::messages($full) as $msg) {
    foreach ($msg['media'] as $n) {
        if ($n !== null) {
            $names[] = $n;
        }
    }
}
$markers = ['00000036-PHOTO-2026-10-24-13-21-51.jpg' => 'WM:B', '00000038-PHOTO-2026-10-24-15-18-26.jpg' => 'WM:C',
            '00000041-PHOTO-2026-10-24-12-02-47.jpg' => 'WM:LATE', '00000044-PHOTO-2026-10-24-10-51-15.jpg' => 'WM:NONE'];
$files = [];
foreach ($names as $n) {
    $files[$n] = $photo($markers[$n] ?? 'WM:PLAIN');
}
$fv = WaChat::visits(WaChat::messages($full));
$fourCount = count(array_filter($fv['visits'], fn($v) => preg_match('/^(\d+\/\d+\s*\n)?toko\s*:/i', $v['caption'])));
ok('fixture: ≥ 10 four-line and ≥ 10 free-text reports, one sent as photo + separate text', $fourCount >= 10 && count($fv['visits']) - $fourCount >= 10
    && count(array_filter($fv['visits'], fn($v) => $v['merged'])) === 1, "{$fourCount} / " . count($fv['visits']));

// ── a company: customers and prospects the stores should meet ──
$mkDb = function (): PDO {
    $schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
    $p->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1,'Administrator','a@t','x','admin'),(4,'ridwan','r@t','x','sales'),(5,'angga','g@t','x','sales'),(6,'rian','i@t','x','sales')");
    Hr::migrate_users($p);
    $p->exec("INSERT INTO customers (id, name, owner, city) VALUES (10,'Abadi Glass','ridwan','Jakarta Utara'),(11,'Sinar Kaca','angga','Bekasi'),(12,'Sinar Kaca Jaya','angga','Bekasi'),(13,'Mitra Laser','rian','Jakarta Barat')");
    $p->exec("INSERT INTO prospects (id, name, category, region, source, source_key) VALUES (50,'Nusa Aluminium','Toko Aluminium & Kaca','Surabaya','daftar','k50'),(51,'Maju Jaya Aluminium','Toko Aluminium & Kaca','Jakarta Timur','daftar','k51')");
    return $p;
};

// ── AI off: rules only, nothing waits for the model ──
$off = ['ai' => ['key' => ''] + ($cfgFull['ai'] ?? []), 'visits' => $cfgFull['visits'] ?? [], 'insights' => $cfgFull['insights'] ?? []];
$off['ai']['key'] = '';
$po = $mkDb();
$textOnly = (string) preg_replace('/<attached: [^>]+>/', '<image omitted>', $full);
$r1 = VisitImport::import($po, $off, $textOnly, [], '_chat.txt', 'assistant');
ok('text-only export: every visit in, no photos, six senders unknown', $r1['new'] === count($fv['visits']) && $r1['photos'] === 0 && count($r1['unmapped']) === 6, json_encode(array_diff_key($r1, ['new_ids' => 1])));
$pr = VisitImport::process($po, $off, 100000, 'assistant');
ok('AI off: everything processed by the rules, nothing left waiting', $pr['left'] === 0 && (int) $po->query("SELECT COUNT(*) FROM visits WHERE match_status IS NULL")->fetchColumn() === 0);
$r2 = VisitImport::import($po, $off, $full, $files, 'export-media.zip', 'assistant');
ok('the with-media export later: no new rows, photos added to the existing ones', $r2['new'] === 0 && $r2['photos'] === count($names) && $r2['found'] === count($fv['visits']), "{$r2['new']} / {$r2['photos']}");
$r3 = VisitImport::import($po, $off, $full, $files, 'export-media.zip', 'assistant');
ok('same export again: everything skipped', $r3['new'] === 0 && $r3['photos'] === 0);
ok('photos stored privately under data/visits/{yyyymm}/', is_file(VisitImport::photo_file((string) $po->query("SELECT photo_path FROM visits WHERE photo_path IS NOT NULL LIMIT 1")->fetchColumn()))
    && str_starts_with((string) $po->query("SELECT photo_path FROM visits WHERE wa_message_ts LIKE '2026-10-25%' AND photo_path IS NOT NULL LIMIT 1")->fetchColumn(), '202610/'));
$rA = VisitImport::import($po, $off, $android, [], '_chat_android_variant.txt', 'assistant');
ok('the Android export reads the same way (2 visits)', $rA['new'] === 2 && $rA['found'] === 2);
ok('AI off: candidates wait for the supervisor, no AI fields', (int) $po->query("SELECT COUNT(*) FROM visits WHERE match_status = 'pending' AND ai_match_status IS NULL")->fetchColumn() > 0
    && (int) $po->query('SELECT COUNT(*) FROM visits WHERE ai_photo_status IS NOT NULL OR ai_parse_status IS NOT NULL')->fetchColumn() === 0);
$po = null;

// ── AI on (stubbed) ──
$cfg = ['ai' => $cfgFull['ai'] ?? [], 'visits' => $cfgFull['visits'] ?? [], 'insights' => $cfgFull['insights'] ?? []];
$cfg['ai']['key'] = 'sk-test';
$cfg['ai']['retries'] = 0;
$calls = ['parse' => 0, 'photo' => 0, 'match' => 0, 'digest' => 0];
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$calls): array {
    $b = json_decode($body, true);
    $sys = json_encode($b['system'] ?? '');
    $content = $b['messages'][0]['content'];
    if (str_contains($sys, 'timestamp watermark')) {
        $calls['photo']++;
        $img = base64_decode((string) ($content[0]['source']['data'] ?? ''));
        preg_match('/WM:[A-Z]+/', $img, $mm);
        $ans = match ($mm[0] ?? '') {
            'WM:B' => ['datetime' => '2026-10-24 13:20:40', 'address_lines' => ['8 Jalan Raya Jakarta - Bogor', 'Kecamatan Ciracas', 'Kota Jakarta Timur', 'Daerah Khusus Ibukota Jakarta'], 'is_store' => 'yes', 'brands_seen' => []],
            'WM:C' => ['datetime' => '2026-10-24 15:17:05', 'address_lines' => ['Jl. Meruya Utara', 'Kecamatan Kembangan', 'Kota Jakarta Barat', 'DKI Jakarta'], 'is_store' => 'yes', 'brands_seen' => ['Seven']],
            'WM:LATE' => ['datetime' => '2026-10-24 08:30:00', 'address_lines' => ['Jl. Danau Sunter', 'Kecamatan Tanjung Priok', 'Kota Jakarta Utara', 'DKI Jakarta'], 'is_store' => 'yes', 'brands_seen' => []],
            'WM:NONE' => ['datetime' => null, 'address_lines' => [], 'is_store' => 'unsure', 'brands_seen' => []],
            default => ['datetime' => null, 'address_lines' => [], 'is_store' => 'yes', 'brands_seen' => []],
        };
    } elseif (str_contains($sys, 'Pick which existing record')) {
        $calls['match']++;
        $u = json_decode((string) $content, true);
        $ans = ['choice' => $u['candidates'][0]['key'] ?? null, 'confidence' => 'medium', 'reason_zh' => '名称接近，区域一致。'];
    } elseif (str_contains($sys, 'weekly sales-visit review')) {
        $calls['digest']++;
        $ans = ['zh' => '本周拜访集中在雅加达北部。', 'id' => 'Minggu ini kunjungan terpusat di Jakarta Utara.'];
    } else {
        $calls['parse']++;
        $u = json_decode((string) $content, true);
        $lines = array_values(array_filter(array_map(fn($l) => trim((string) preg_replace('/^[\-•]+\s*/', '', trim($l))), explode("\n", (string) $u['message']))));
        $store = preg_match('/^visit\s/i', $lines[0] ?? '') ? ($lines[1] ?? '') : ($lines[0] ?? '');
        $t = mb_strtolower((string) $u['message']);
        $ans = ['store_name' => $store, 'store_type' => in_array($lines[1] ?? '', ['Toko', 'Workshop', 'Advertising', 'Distributor'], true) ? $lines[1] : null, 'pic_name' => null,
                'brands' => preg_match('/\b(marks|tajima|seven|toin|alcopan)\b/i', $t, $bm) ? [ucfirst($bm[1])] : [],
                'outcome_guess' => str_contains($t, 'belum ada kebutuhan') ? 'tidak_butuh' : (str_contains($t, 'tertarik') ? 'berminat' : 'unknown'),
                'needs' => null];
    }
    return [200, json_encode(['model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => json_encode($ans)]], 'stop_reason' => 'end_turn',
                              'usage' => ['input_tokens' => 120, 'output_tokens' => 40]]), []];
};
$pdo = $mkDb();
$res = VisitImport::import($pdo, $cfg, $full, $files, 'Absensi-25-10.zip', 'assistant');
ok('ACCEPTANCE: visits counted, photos stored, unknown senders listed', $res['new'] === count($fv['visits']) && $res['photos'] === count($names) && count($res['unmapped']) === 6
    && $res['ignored'] === $fv['ignored']);
$again = VisitImport::import($pdo, $cfg, $full, $files, 'Absensi-25-10.zip', 'assistant');
ok('ACCEPTANCE: the same export again: all skipped', $again['new'] === 0 && $again['photos'] === 0);
VisitImport::process($pdo, $cfg, 1000, 'assistant');
$byStore = function (string $store, string $ts = '') use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM visits WHERE store_name_raw = ?' . ($ts !== '' ? ' AND wa_message_ts = ?' : '') . ' ORDER BY id');
    $st->execute($ts !== '' ? [$store, $ts] : [$store]);
    return $st->fetchAll();
};
ok('AI read the free text only (four-line reports need no model)', $calls['parse'] === count($fv['visits']) - $fourCount && (int) $pdo->query('SELECT COUNT(*) FROM visits WHERE format_ok = 1 AND ai_parse_status IS NOT NULL')->fetchColumn() === 0);

// matching: exact, alias, similar, none
$ab = $byStore('Abadi Glass');
ok('ACCEPTANCE match — exact: Abadi Glass → the customer, automatically, as 老客', count($ab) >= 5 && count(array_filter($ab, fn($v) => $v['match_status'] === 'auto' && (int) $v['customer_id'] === 10 && $v['system_lama_baru'] === 'lama')) === count($ab));
ok('…and each such visit is a follow-up on the customer', (int) $pdo->query('SELECT COUNT(*) FROM customer_followups WHERE customer_id = 10')->fetchColumn() === count($ab));
$cah = $byStore('Cahaya Advertising');
$cp = $pdo->query("SELECT * FROM prospects WHERE source = 'visit' AND name = 'Cahaya Advertising'")->fetchAll();
ok('ACCEPTANCE match — none: a new store goes into the prospect pool (source visit, with its type); the second visit finds it', count($cp) === 1 && $cp[0]['store_type'] === 'Advertising'
    && count(array_filter($cah, fn($v) => (int) $v['prospect_id'] === (int) $cp[0]['id'])) === count($cah) && $cah[0]['match_status'] === 'new' && $cah[1]['match_status'] === 'auto');
$nusa = $byStore('Nusa Alumunium');
ok('ACCEPTANCE match — similar: spelling differs → waits for the supervisor with an AI suggestion', $nusa && $nusa[0]['match_status'] === 'pending' && $nusa[0]['ai_match_status'] === 'ok'
    && (json_decode((string) $nusa[0]['ai_match'], true)['choice'] ?? '') === 'prospect:50');
$sinar = $byStore('Sinar Kaca Mandiri');
ok('two close customers (Sinar Kaca, Sinar Kaca Jaya) → pending, both offered', $sinar && $sinar[0]['match_status'] === 'pending'
    && array_column((array) json_decode((string) $sinar[0]['match_candidates'], true), 'key') === ['customer:11', 'customer:12']);
ok('supervisor confirms: one click', VisitImport::confirm($pdo, (int) $sinar[0]['id'], 'customer:11', 'tania'));
$alias = $pdo->query("SELECT * FROM customer_name_aliases WHERE alias = 'Sinar Kaca Mandiri'")->fetch();
ok('…the written name becomes an alias of that customer', $alias && (int) $alias['customer_id'] === 11 && $alias['confirmed_by'] === 'tania');
ok('…and the other visits with the same name are settled with it', (int) $pdo->query("SELECT COUNT(*) FROM visits WHERE store_name_raw = 'Sinar Kaca Mandiri' AND match_status = 'pending'")->fetchColumn() === 0);
$next = "[10/26/26, 9:00:00 AM] Budi Santoso: <attached: 00000060-PHOTO-2026-10-26-09-00-00.jpg> Toko: Sinar Kaca Mandiri\nStatus: Lama\nHasil: Order\n";
VisitImport::import($pdo, $cfg, $next, ['00000060-PHOTO-2026-10-26-09-00-00.jpg' => $photo('WM:PLAIN')], 'next.zip', 'assistant');
VisitImport::process($pdo, $cfg, 1000, 'assistant');
$s2 = $byStore('Sinar Kaca Mandiri', '2026-10-26 09:00:00')[0] ?? [];
ok('ACCEPTANCE match — alias: the next time the same name matches by itself', ($s2['match_status'] ?? '') === 'auto' && (int) $s2['customer_id'] === 11);
ok('confirm "new store" puts it into the pool', VisitImport::confirm($pdo, (int) $nusa[0]['id'], 'new', 'tania') && $pdo->query("SELECT source FROM prospects WHERE name = 'Nusa Alumunium'")->fetchColumn() === 'visit');
ok('confirm refuses an unknown choice', !VisitImport::confirm($pdo, (int) $nusa[0]['id'], 'customer:999', 'tania') && !VisitImport::confirm($pdo, (int) $nusa[0]['id'], 'drop table', 'tania'));

// watermark
$cahO = $byStore('Cahaya Advertising', '2026-10-24 13:21:51')[0] ?? [];
ok('ACCEPTANCE watermark: time and address read (1)', ($cahO['watermark_at'] ?? '') === '2026-10-24 13:20:40' && str_contains((string) $cahO['address_text'], 'Kecamatan Ciracas') && $cahO['photo_is_store'] === 'yes' && (int) $cahO['late_flag'] === 0);
$mit = $byStore('Mitra Laser', '2026-10-24 15:18:26')[0] ?? [];
ok('ACCEPTANCE watermark: time and address read (2)', ($mit['watermark_at'] ?? '') === '2026-10-24 15:17:05' && str_contains((string) $mit['address_text'], 'Kota Jakarta Barat'));
$lateV = $byStore('Abadi Glass', '2026-10-24 12:02:47')[0] ?? [];
ok('ACCEPTANCE watermark: (3) taken 3.5 h before sending → 补发, the watermark time is the visit time', (int) ($lateV['late_flag'] ?? 0) === 1 && $lateV['visited_at'] === '2026-10-24 08:30:00');
$none = $byStore('Maju Jaya Aluminium', '2026-10-24 10:51:15')[0] ?? [];
ok('ACCEPTANCE watermark: no watermark → "unsure", visit time stays the message time', ($none['photo_is_store'] ?? '') === 'unsure' && $none['watermark_at'] === null && $none['visited_at'] === '2026-10-24 10:51:15');
$bf = $byStore('Toko Kaca Abadi Jaya')[0] ?? [];
ok('late report by date line: visit day 23-10, flagged', substr((string) ($bf['visited_at'] ?? ''), 0, 10) === '2026-10-23' && (int) $bf['late_flag'] === 1);
$mul = $byStore('Toko Mulia Kaca')[0] ?? [];
ok('photo + separate text = one visit, read by the AI: Toin, no need now (a guess, not the result)', $mul && $mul['competitor_brand'] === 'Toin' && $mul['outcome'] === Visits::UNKNOWN && $mul['ai_outcome_guess'] === 'tidak_butuh');

// outcome guesses, senders
$guessIds = $pdo->query("SELECT id FROM visits WHERE outcome = 'unknown' AND ai_outcome_guess = 'berminat'")->fetchAll(PDO::FETCH_COLUMN);
ok('free-text "tertarik … di follow up" → AI guesses Berminat, kept apart from the result', count($guessIds) >= 5);
ok('supervisor adopts a guess', VisitImport::adopt_outcome($pdo, (int) $guessIds[0], 'tania') && $pdo->query('SELECT outcome FROM visits WHERE id = ' . (int) $guessIds[0])->fetchColumn() === 'berminat');
$angga = (int) $pdo->query("SELECT employee_id FROM users WHERE name = 'angga'")->fetchColumn();
$filled = VisitImport::map_sender($pdo, '+62 812-0000-0002', $angga, 'Hana HR');
ok('HR maps a sender once: the waiting visits get the salesperson', $filled > 5 && (int) $pdo->query("SELECT COUNT(*) FROM visits WHERE wa_sender = '+62 812-0000-0002' AND employee_id IS NULL")->fetchColumn() === 0
    && count(VisitImport::unmapped($pdo)) === 5);
$moreNext = "[10/27/26, 9:00:00 AM] 0812-0000-0002: <attached: 00000061-PHOTO.jpg> Toko: Abadi Glass\nStatus: Lama\nHasil: Order\n";
VisitImport::import($pdo, $cfg, $moreNext, [], 'n2.txt', 'assistant');
ok('…the same number written differently next time is recognised', (int) $pdo->query("SELECT employee_id FROM visits WHERE wa_message_ts = '2026-10-27 09:00:00'")->fetchColumn() === $angga);
ok('Status written vs matched both kept (Mitra Laser 24-10: Lama, customer → lama)', ($mit['status_lama_baru'] ?? '') === 'lama' && $mit['system_lama_baru'] === 'lama');

// analysis
$comp = VisitImport::competitors($pdo, '2026-10-01', '2026-10-31', false);
$brandsSeen = array_unique(array_column($comp, 'brand'));
ok('competitor board: brands × area, prices from the notes', in_array('Seven', $brandsSeen, true) && in_array('Marks', $brandsSeen, true)
    && in_array(165000, array_map('intval', array_column(VisitImport::competitors($pdo, '2026-10-01', '2026-10-31', true), 'min_price')), true));
$q = VisitImport::quality($pdo, '2026-10-19', '2026-10-27');
$tpl = array_sum(array_column($q, 'template'));
ok('quality: template sentences, no result, photos read, late', $tpl >= 10 && array_sum(array_column($q, 'late')) >= 2 && array_sum(array_column($q, 'photo_read')) > 0);
$r = VisitImport::digest($pdo, $cfg, '2026-W43', 'tania');
ok('AI weekly digest: Chinese + Indonesian saved for the week', $r['status'] === 'ok' && $pdo->query("SELECT text_id FROM visit_digests WHERE week = '2026-W43'")->fetchColumn() === 'Minggu ini kunjungan terpusat di Jakarta Utara.');
$facts = VisitImport::week_facts($pdo, '2026-10-19', '2026-10-25');
ok('digest facts: no phone numbers for senders not yet mapped', !preg_match('/\+62|0812|0813|0811/', json_encode($facts)) && isset($facts['per_sales']['Pengirim belum dikenali']));
ok('everything via the AI layer, logged in ai_calls', (int) $pdo->query("SELECT COUNT(*) FROM ai_calls WHERE task_type LIKE 'visit_%'")->fetchColumn() === array_sum($calls));
Claude::$http = null;
Claude::$sleep = null;

// ── through the real controller (AI off in the test copy: no key) ──
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness) && class_exists('ZipArchive')) {
    $db = tempnam(sys_get_temp_dir(), 'vih');
    $hp = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $hp->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $hp->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1,'Administrator','a@t','x','admin'),(3,'tania','t@t','x','manager'),(4,'ridwan','r@t','x','sales'),(7,'Fera','f@t','x','clerk')");
    foreach (default_permissions() as $role => $mods) {
        foreach ($mods as $mod) {
            $hp->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
        }
    }
    Hr::migrate_users($hp);
    $hp->exec("INSERT INTO customers (id, name, owner) VALUES (10,'Abadi Glass','ridwan')");
    $ridwanEmp = (int) $hp->query("SELECT employee_id FROM users WHERE name = 'ridwan'")->fetchColumn();
    $hp = null;
    $zipPath = tempnam(sys_get_temp_dir(), 'wa') . '.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('chat.txt', $full);
    $z->addFromString('chat.md', '# ignored');
    foreach ($files as $n => $bytes) {
        $z->addFromString($n, $bytes);
    }
    $z->close();
    $photoTmp = sys_get_temp_dir() . '/vih-photos-' . bin2hex(random_bytes(4));
    $run = function (string $action, array $user, array $get = [], ?array $post = null, array $env = []) use ($db, $harness, $photoTmp): array {
        $env += ['TEST_VISIT_PHOTO_ROOT' => $photoTmp];
        $args = [PHP_BINARY, $harness, $db, 'visits', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $envs = '';
        foreach ($env as $k => $val) {
            $envs .= $k . '=' . escapeshellarg($val) . ' ';
        }
        $out = [];
        exec($envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $hq = fn() => new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tania = ['id' => 3, 'name' => 'tania', 'role' => 'manager', 'employee_id' => null];
    $admin = ['id' => 1, 'name' => 'Administrator', 'role' => 'admin', 'employee_id' => null];
    $ridwan = ['id' => 4, 'name' => 'ridwan', 'role' => 'sales', 'employee_id' => $ridwanEmp];
    $fera = ['id' => 7, 'name' => 'Fera', 'role' => 'clerk', 'employee_id' => null];
    $up = ['TEST_FILES' => json_encode(['file' => ['name' => 'Absensi Team Sales 25-10.zip', 'tmp_name' => $zipPath, 'size' => filesize($zipPath), 'error' => 0]])];
    [, $fl] = $run('import', $fera, [], [], $up);
    ok('page: import needs the 拜访：导入群聊 permission (granted per role in 权限设置)', ($fl[0]['type'] ?? '') === 'error' && (int) $hq()->query('SELECT COUNT(*) FROM visits')->fetchColumn() === 0);
    [, $fl] = $run('import', $tania, [], [], $up);
    ok('page: upload the zip → visits in, photos stored, chat.md ignored', (int) $hq()->query('SELECT COUNT(*) FROM visits')->fetchColumn() === count($fv['visits'])
        && (int) $hq()->query('SELECT photos_added FROM wa_imports ORDER BY id DESC')->fetchColumn() === count($names), json_encode($fl));
    [$h] = $run('import', $tania);
    ok('page: import history and unknown senders shown', str_contains($h, 'Absensi Team Sales 25-10.zip') && str_contains($h, t('vis_imp_unmapped')));
    [, $fl] = $run('senders', $tania, [], ['sender' => 'A _ N D I', 'employee_id' => $ridwanEmp]);
    ok('page: map a sender', (int) $hq()->query("SELECT COUNT(*) FROM visits WHERE wa_sender = 'A _ N D I' AND employee_id = {$ridwanEmp}")->fetchColumn() >= 3);
    [$h] = $run('queue', $tania);
    ok('page: queue lists stores to confirm, each candidate one click', str_contains($h, 'name="choice"') && str_contains($h, t('vis_queue_new_store')));
    $pid = (int) $hq()->query("SELECT id FROM visits WHERE match_status = 'pending' ORDER BY id LIMIT 1")->fetchColumn();
    [, $fl] = $run('confirm', $tania, [], ['id' => $pid, 'choice' => 'new']);
    ok('page: confirm "new store"', $hq()->query("SELECT match_status FROM visits WHERE id = {$pid}")->fetchColumn() === 'confirmed');
    [$h] = $run('day', $tania, ['date' => '2026-10-24']);
    ok('page: day view per salesperson, results, late tag', str_contains($h, 'Lama / Baru') && str_contains($h, 'Abadi Glass') && str_contains($h, '>Order<'));
    [$h] = $run('customers', $tania, ['q' => 'Abadi']);
    ok('page: customer view — visit history of a customer', substr_count($h, 'Abadi Glass') >= 3);
    [$h] = $run('competitors', $tania, ['from' => '2026-10-01', 'to' => '2026-10-31', 'all' => '1']);
    ok('page: competitor board', str_contains($h, 'Seven') && str_contains($h, 'Marks'));
    [, $fl] = $run('quality', $tania);
    [$h] = $run('quality', $admin, ['week' => '2026-W43']);
    ok('page: quality for the boss only', ($fl[0]['type'] ?? '') === 'error' && str_contains($h, t('vis_q_template')));
    [$h] = $run('report', $tania, ['week' => '2026-W43']);
    ok('page: weekly report with Baru and new prospects; digest needs AI', str_contains($h, '>Baru<') && str_contains($h, t('vis_new_prospects')) && str_contains($h, t('vis_digest_ai_off')));
    [$h] = $run('index', $ridwan);
    ok('page: the salesperson sees their imported visits and the matched name; the form is 补录', str_contains($h, 'Abadi Glass') && str_contains($h, t('vis_new')));
    @unlink($zipPath);
    foreach (glob($photoTmp . '/*/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($photoTmp . '/*') ?: [] as $f) {
        @rmdir($f);
    }
    @rmdir($photoTmp);
    @unlink($db);
}
foreach (glob(Visits::$photoRoot . '/*/*') ?: [] as $f) {
    @unlink($f);
}
foreach (glob(Visits::$photoRoot . '/*') ?: [] as $f) {
    @rmdir($f);
}
@rmdir(Visits::$photoRoot);
Visits::$photoRoot = $savedPhotoRoot;
$pdo = null;
