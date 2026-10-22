<?php

// ── FA-AI-01: bank statements and collection matching (app/Bank.php) ──
// Files: samples_test/ only (de-identified). Invoices below are fictional and
// built to fit the sample lines. The model is stubbed (Claude::$http).

$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$cfgFull = (array) (@include __DIR__ . '/../config.php');
$cfg = ['ai' => $cfgFull['ai'], 'bank' => $cfgFull['bank']];
$cfg['ai']['key'] = 'sk-test';
$cfg['ai']['retries'] = 0;
$bcaCsv = (string) file_get_contents(__DIR__ . '/../samples_test/BCA_CorpAcctTrxn_SAMPLE.csv');
$icbcCsv = (string) file_get_contents(__DIR__ . '/../samples_test/ICBC_currentaccounthistory_SAMPLE.csv');

// Fixture: customers, orders (CO numbers) and invoices.
$cust = function (string $name, string $company = '') use ($pdo): int {
    $pdo->prepare('INSERT INTO customers (name, company, owner) VALUES (?,?,?)')->execute([$name, $company, 'Anton']);
    return (int) $pdo->lastInsertId();
};
$invNo = 1500;
$inv = function (int $cid, string $orderNo, float $total, string $date = '2026-09-25', string $customer = '') use ($pdo, &$invNo): int {
    $pdo->prepare("INSERT INTO orders (order_no, customer_id, customer_name, status, submitter, created_at) VALUES (?,?,?,'approved','Anton',?)")
        ->execute([$orderNo, $cid, $customer, $date . ' 10:00:00']);
    $oid = (int) $pdo->lastInsertId();
    $invNo++;
    $pdo->prepare("INSERT INTO invoices (invoice_no, order_id, customer, bill_to_name, total, invoice_date, due_date, payment_status, amount_paid)
                   VALUES (?,?,?,?,?,?,?,'pending',0)")
        ->execute([sprintf('%d - AMI - INV - %s - 26', $invNo, substr($date, 5, 2)), $oid, $customer, $customer, $total, $date, date('Y-m-d', strtotime($date . ' +14 days'))]);
    return (int) $pdo->lastInsertId();
};
$cRudi = $cust('RUDI HARTONO', 'Toko Rudi Jaya');
$cDewi = $cust('Dewi Lestari', 'DEWI LESTARI / Lestari Reklame');
$cBudi = $cust('Budi Santoso', 'CV Santoso Abadi');
$cNusa = $cust('PT Nusa Kreasi', 'PT NUSA KREASI');
$cBerkah = $cust('Toko Berkah', 'TOKO BERKAH');
$cHendra = $cust('Hendra Gunawan', '');
$cGlass = $cust('Cahaya Glass', 'CV Cahaya Glass');    // the owner pays from a personal account: LINA KUSUMA
$cYanto = $cust('Yanto Saputra', 'Saputra Interior');
$iRudi = $inv($cRudi, '1306/AMI-CO/10/26', 4649700, '2026-09-28', 'Toko Rudi Jaya');
$iDewi = $inv($cDewi, '1307/AMI-CO/10/26', 4651000, '2026-09-28', 'Lestari Reklame');             // pays 4,650,999.87: rounding
$iBudi = $inv($cBudi, '1319/AMI-CO/10/26', 30000000, '2026-09-30', 'CV Santoso Abadi');           // pays 26,245,000: partial
$iNusa = $inv($cNusa, '1290/AMI-CO/09/26', 12399700, '2026-09-20', 'PT NUSA KREASI');            // "sisa 4.jt": partial via AI
$iBerkah1 = $inv($cBerkah, '1291/AMI-CO/09/26', 1000000, '2026-09-21', 'TOKO BERKAH');
$iBerkah2 = $inv($cBerkah, '1292/AMI-CO/09/26', 501000, '2026-09-22', 'TOKO BERKAH');              // "lunas" 1,500,999.87: combined
$iHendra = $inv($cHendra, '1293/AMI-CO/09/26', 752000, '2026-09-23', 'Hendra Gunawan');           // unique amount + same name: rule 3
$iGlass = $inv($cGlass, '1294/AMI-CO/09/26', 8399700, '2026-09-24', 'CV Cahaya Glass');
$iGlass2 = $inv($cGlass, '1295/AMI-CO/09/26', 1500000, '2026-09-24', 'CV Cahaya Glass');
$iYanto = $inv($cYanto, '1296/AMI-CO/09/26', 16000000, '2026-09-26', 'Saputra Interior');         // pays 16,095,999.87: overpaid
$iVoid = $inv($cBudi, '1297/AMI-CO/09/26', 999000, '2026-09-26', 'CV Santoso Abadi');
$pdo->exec("UPDATE invoices SET voided_at = '2026-09-27 10:00:00' WHERE id = {$iVoid}");

// 1) Parsers and import (acceptance ①).
ok('detect: BCA by its first line, ICBC by its first line', Bank::detect($bcaCsv) === 'bca_csv' && Bank::detect($icbcCsv) === 'icbc_csv' && Bank::detect("a,b\n1,2") === '');
$res = Bank::import($pdo, $cfg, $bcaCsv, 'BCA_CorpAcctTrxn_SAMPLE.csv', 'Finance');
$rowsInFile = count(Bank::parse_bca($bcaCsv)['rows']);
ok('BCA: every booked line stored', $res['ok'] && $res['added'] === 57 && $rowsInFile === 57, json_encode($res));
ok('BCA: the PEND line is skipped, not stored', $res['pending'] === 1 && (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE description LIKE '%2210/FTSCY%'")->fetchColumn() === 0);
$sumIn = (float) $pdo->query("SELECT ROUND(SUM(amount), 2) FROM bank_transactions WHERE direction = 'in'")->fetchColumn();
ok('BCA: stored credits = parsed credits (41 lines)', abs($sumIn - $res['check']['credit']) < 0.005 && $res['check']['credit_n'] === 41);
ok('BCA: running balance holds from Saldo Awal to Saldo Akhir', $res['check']['balance_ok'] === true);
ok('BCA: the sample footer (zeroed in the fixture) is reported as not matching', $res['check']['totals_ok'] === false);
ok('only the last 4 digits of the account are kept', $pdo->query('SELECT bank || last4 FROM bank_accounts')->fetchColumn() === 'BCA7890'
    && (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE description LIKE '%1234567890%'")->fetchColumn() === 0);
$again = Bank::import($pdo, $cfg, $bcaCsv, 'again.csv', 'Finance');
ok('same file twice: everything skipped the second time', $again['added'] === 0 && $again['skipped'] === 57);
$ic = Bank::import($pdo, $cfg, $icbcCsv, 'ICBC_currentaccounthistory_SAMPLE.csv', 'Finance');
ok('ICBC: 12 lines, credits equal the Total line', $ic['ok'] && $ic['added'] === 12 && $ic['check']['credit_n'] === 2 && abs($ic['check']['credit'] - 250607335.61) < 0.005);
ok('ICBC: Indonesian numbers read (1.005.321,88 style)', Bank::num_id('1.005.321,88') === 1005321.88 && Bank::num_id('') === 0.0);
$tags = $pdo->query("SELECT t.tag, COUNT(*) FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE a.bank = 'ICBC' GROUP BY t.tag")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('ICBC tags by rule: salary / tax / internal / interest', ($tags['salary'] ?? 0) === 6 && ($tags['tax'] ?? 0) === 2 && ($tags['internal'] ?? 0) === 1 && ($tags['interest'] ?? 0) === 1, json_encode($tags));
ok('ICBC: Pindah Dana from our own BCA account = internal; nothing on ICBC is matched', (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE a.bank = 'ICBC' AND t.match_status <> 'na'")->fetchColumn() === 0);
ok('BCA outgoing lines are tagged, not matched', (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE a.bank = 'BCA' AND direction = 'out' AND match_status <> 'na'")->fetchColumn() === 0
    && $pdo->query("SELECT tag FROM bank_transactions WHERE description LIKE 'BIAYA ADM%' LIMIT 1")->fetchColumn() === 'fee');
$codes = $pdo->query("SELECT trx_code FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE a.bank = 'BCA' AND direction = 'in' ORDER BY t.id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
ok('Buku Bank numbering: BCA-IN-2610-0001… in date order; OUT counts on its own', $codes === ['BCA-IN-2610-0001', 'BCA-IN-2610-0002', 'BCA-IN-2610-0003']
    && $pdo->query("SELECT trx_code FROM bank_transactions WHERE direction = 'out' AND description LIKE '%AGRICULTURAL%' ORDER BY id LIMIT 1")->fetchColumn() === 'BCA-OUT-2610-0001');
ok('ICBC numbering: ICBC-IN / ICBC-OUT by month', $pdo->query("SELECT trx_code FROM bank_transactions WHERE description = 'Pindah Dana'")->fetchColumn() === 'ICBC-IN-2608-0002');

// 2) Reading a line.
$p = Bank::bca_payer('TRSF E-BANKING CR 0310/FTSCY/WS95031 8399700.00  sisa 4.jt PT NUSA KREASI  ');
ok('payer after a colloquial note', $p['payer'] === 'PT NUSA KREASI' && $p['note'] === 'sisa 4.jt');
ok('payer after a double-spaced note', Bank::bca_payer('TRSF E-BANKING CR 0510/FTSCY/WS95051 26245000.00  PAYMENT INV 1320/AMI-CO/09/26  DEWI LESTARI  ')['payer'] === 'DEWI LESTARI');
ok('note words are not names ("DP" is not part of the payer)', Bank::bca_payer('TRSF E-BANKING CR 0110/FTSCY/WS95271 500000.00  DP BUDI SANTOSO')['payer'] === 'BUDI SANTOSO');
ok('BI-FAST / SWITCHING / CDM payers', Bank::bca_payer('BI-FAST CR TRANSFER   DR 535 LINA KUSUMA    ')['payer'] === 'LINA KUSUMA'
    && Bank::bca_payer('SWITCHING CR TRF YANTO SAPUTRA  002  NEW BRI MOB  ')['payer'] === 'YANTO SAPUTRA'
    && Bank::bca_payer('SETORAN VIA CDM 03/10  WSID:ZM131 SITI RAHAYU   ')['payer'] === 'SITI RAHAYU');
ok('platform collections flagged', Bank::bca_payer('TRSF E-BANKING CR 0110/FTSCY/WS95051 752000.00  GoPay Bank Transfe ID26280000000000  DOMPET ANAK BANGSA ')['platform'] === true);
$refs = fn(string $s): array => array_map(fn($r) => $r['serial'] . '/' . $r['kind'], Bank::refs($s));
ok('refs: CO numbers, AMICO, invoice numbers, lists, CO / Inv words', $refs('1229/AMICO/09/26') === ['1229/co'] && $refs('PAYMENT INV 1330/AMI-CO/09/26') === ['1330/co']
    && $refs('1302,1303,1309-AMI') === ['1302/any', '1303/any', '1309/any'] && $refs('Fee CO 1240') === ['1240/any'] && $refs('Kelebihan Tf Inv 1309') === ['1309/any']
    && $refs('1301 - AMI - INV - 10 - 26') === ['1301/inv'] && $refs('sisa 4.jt') === []);
ok('text for the model: bank codes, wallet ids and long numbers removed', Bank::clean_for_ai('TRSF E-BANKING CR 0110/FTSCY/WS95051 752000.00  GoPay Bank Transfe ID26280000000000  DOMPET ANAK BANGSA') === 'TRSF E-BANKING CR 752000.00 GoPay Bank Transfe DOMPET ANAK BANGSA');

// 3) Rule matching at import.
$line = fn(string $like) => $pdo->query("SELECT * FROM bank_transactions WHERE description LIKE " . $pdo->quote($like) . " ORDER BY id LIMIT 1")->fetch();
$tRudi = $line('%1306/AMI-CO/10/26 RUDI%');
$tDewi = $line('%1307/AMI-CO/10/26 DEWI%');
$tBudi = $line('%1319/AMI-CO/10/26 BUDI%');
$tHendra = $line('%HENDRA GUNAWAN%');
$prop = fn(array $t): array => (array) json_decode((string) $t['proposal'], true);
ok('rule 1: CO number in the note → that order\'s invoice', $tRudi['match_status'] === 'rule' && $prop($tRudi)['lines'] === [['invoice_id' => $iRudi, 'amount' => 4649700.0]] && $prop($tRudi)['diff_reason'] === 'none', (string) $tRudi['proposal'] . ' ' . $tRudi['match_status']);
ok('rule 1: 0.13 short → rounding proposed', $tDewi['match_status'] === 'rule' && $prop($tDewi)['diff_reason'] === 'rounding' && abs($prop($tDewi)['diff_amount'] + 0.13) < 0.001);
ok('rule 1: amount below the balance → partial', $tBudi['match_status'] === 'rule' && $prop($tBudi)['diff_reason'] === 'partial');
ok('rule 3: amount fits one open invoice and the name agrees', $tHendra['match_status'] === 'rule' && str_starts_with((string) $tHendra['match_note'], 'amount+name'));
ok('a CO number of an invoice that does not exist stays unmatched', $line('%1311/AMI-CO/10/26 TOKO BERKAH%')['match_status'] === 'unmatched');
ok('platform lines are never matched by name', $line('%GoPay%')['match_status'] === 'unmatched' && $line('%GoPay%')['tag'] === 'platform');
ok('rule 1 ignores voided invoices', Bank::open_invoices($pdo)[$iVoid] ?? null === null);
ok('ambiguous serial (two invoices could be meant) → not a rule match', (function () use ($pdo) {
    $open = [1 => ['id' => 1, 'balance' => 100.0, 'inv_ref' => [1500, 9, 26], 'co_ref' => null, 'customer_id' => 0, 'customer' => '', 'bill_to_name' => '', 'cname' => '', 'ccompany' => '', 'invoice_no' => 'A'],
             2 => ['id' => 2, 'balance' => 100.0, 'inv_ref' => null, 'co_ref' => [1500, 9, 26], 'customer_id' => 0, 'customer' => '', 'bill_to_name' => '', 'cname' => '', 'ccompany' => '', 'invoice_no' => 'B']];
    return Bank::rule_proposal($pdo, ['bank' => ['tolerance' => 1000]], ['amount' => 100, 'refs' => json_encode(Bank::refs('1500-AMI')), 'tag' => 'goods', 'counterparty_name' => ''], $open) === null;
})());

// 4) Model calls (stubbed): partial, combined, a personal account, an overpayment (acceptance ③).
$sent = [];
$aiAnswer = [];   // payer => [invoice ids, diff_type]
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$sent, &$aiAnswer): array {
    $b = json_decode($body, true);
    $sent[] = $b;
    $u = json_decode($b['messages'][0]['content'], true);
    [$ids, $type] = $aiAnswer[(string) ($u['transfer']['payer'] ?? 'platform')] ?? [[], 'unknown'];
    $text = json_encode(['invoice_ids' => $ids, 'confidence' => $ids ? 'high' : 'low', 'diff_type' => $type,
                         'reason_id' => 'Nama pemilik toko cocok dengan pelanggan.', 'reason_zh' => '付款人是该客户店主，金额与余额相符。']);
    return [200, json_encode(['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn',
                              'usage' => ['input_tokens' => 3000, 'output_tokens' => 200]]), []];
};
$aiAnswer = [
    'PT NUSA KREASI' => [[$iNusa], 'partial'],
    'TOKO BERKAH'    => [[$iBerkah1, $iBerkah2], 'combined'],
    'LINA KUSUMA'    => [[$iGlass], 'exact'],
    'YANTO SAPUTRA'  => [[$iYanto], 'overpaid'],
];
$tNusa = $line('%sisa 4.jt PT NUSA KREASI%');
$tBerkah = $line('%lunas TOKO BERKAH%');
$tLina = $line('%BI-FAST CR TRANSFER   DR 535 LINA KUSUMA%');
$tYanto = $line('%BI-FAST CR TRANSFER   DR 535 YANTO SAPUTRA%');
foreach ([$tNusa, $tBerkah, $tLina, $tYanto] as $t) {
    $r = Bank::ai_match($pdo, $cfg, (int) $t['id']);
    ok("AI proposal for {$t['counterparty_name']}", $r['status'] === 'ok', json_encode($r));
}
$reload = fn(array $t): array => Bank::find($pdo, (int) $t['id']);
$pNusa = $prop($reload($tNusa));
ok('partial: the whole transfer on the one invoice, 4 jt still open', $pNusa['lines'] === [['invoice_id' => $iNusa, 'amount' => 8399700.0]] && $pNusa['diff_reason'] === 'partial' && abs($pNusa['diff_amount'] + 4000000) < 0.005, json_encode($pNusa));
$pBerkah = $prop($reload($tBerkah));
ok('combined: split by balance, amounts computed by rule (not by the model)', $pBerkah['lines'] === [['invoice_id' => $iBerkah1, 'amount' => 1000000.0], ['invoice_id' => $iBerkah2, 'amount' => 500999.87]] && $pBerkah['diff_reason'] === 'rounding', json_encode($pBerkah));
ok('personal account: proposal for the shop the owner pays for', $prop($reload($tLina))['lines'][0]['invoice_id'] === $iGlass);
$pYanto = $prop($reload($tYanto));
ok('overpaid: the extra stays on the invoice, flagged for refund', $pYanto['lines'] === [['invoice_id' => $iYanto, 'amount' => 16095999.87]] && $pYanto['diff_reason'] === 'overpaid');
$rl = $reload($tLina);
ok('the reason is kept in both languages, with the confidence', $rl['match_status'] === 'ai' && $rl['ai_confidence'] === 'high' && str_contains((string) $rl['ai_reason'], '店主') && str_contains((string) $rl['ai_reason'], 'pelanggan'));
$u = json_decode($sent[0]['messages'][0]['content'], true);
ok('model input: date, amount, cleaned text, payer and ≤ 30 candidates with balances', $u['transfer']['amount'] === 8399700 || $u['transfer']['amount'] === 8399700.0
    && count($u['candidates']) <= 30 && isset($u['candidates'][0]['balance'], $u['candidates'][0]['invoice_date'], $u['candidates'][0]['pic']));
$all = json_encode($sent);
ok('model input: no account numbers, no bank-internal codes', !str_contains($all, '1234567890') && !str_contains($all, 'WS95') && !str_contains($all, 'FTSCY') && !str_contains($all, '0120'));
ok('model call goes through the task layer (ai_calls row, bank_match v1)', (int) $pdo->query("SELECT COUNT(*) FROM ai_calls WHERE task_type = 'bank_match' AND status = 'ok' AND prompt_version = 1")->fetchColumn() === 4);
$gopay = $line('%GoPay%');
$n0 = count($sent);
Bank::ai_match($pdo, $cfg, (int) $gopay['id']);
$gu = json_decode($sent[$n0]['messages'][0]['content'], true);
ok('platform: the model is told the payer is unusable; no fit → stays for a person', $gu['transfer']['payer'] === null && str_contains((string) $gu['transfer']['note'], 'platform')
    && $reload($gopay)['match_status'] === 'unmatched' && $reload($gopay)['ai_status'] === 'none');
$aiAnswer['SITI RAHAYU'] = [[999999], 'exact'];   // an id that was never offered
$tSiti = $line('%SITI RAHAYU%');
$r = Bank::ai_match($pdo, $cfg, (int) $tSiti['id']);
ok('an invoice the model invented is rejected → "AI 未处理", line left for a person', $r['status'] === 'invalid' && $reload($tSiti)['ai_status'] === 'failed' && $reload($tSiti)['match_status'] === 'unmatched');

// 5) Confirmation books into the existing payments ledger.
$paid = fn(int $id): array => $pdo->query("SELECT amount_paid, payment_status FROM invoices WHERE id = {$id}")->fetch();
$dashBefore = Dashboard::data($pdo, Dashboard::period('month', '2026-10-15', '2026-10-15'), '2026-10-15', null, true, true)['receivable'];
$c = Bank::confirm($pdo, $cfg, (int) $tRudi['id'], $prop($tRudi)['lines'], 'none', 'Finance');
ok('confirm: invoice paid in full, status paid', $c['ok'] && abs((float) $paid($iRudi)['amount_paid'] - 4649700) < 0.005 && $paid($iRudi)['payment_status'] === 'paid');
$p = $pdo->query("SELECT * FROM payments WHERE invoice_id = {$iRudi}")->fetch();
ok('confirm: the payment date is the bank date; receipt = the line code', $p['pay_date'] === '2026-10-02' && $p['receipt_no'] === $tRudi['trx_code'] && $p['method'] === 'BCA' && (int) $p['noncash'] === 0);
$c = Bank::confirm($pdo, $cfg, (int) $tDewi['id'], $prop($tDewi)['lines'], 'rounding', 'Finance');
$settle = $pdo->query("SELECT * FROM payments WHERE invoice_id = {$iDewi} AND noncash = 1")->fetch();
ok('rounding: a non-cash settlement row closes the 0.13 gap', $c['ok'] && $settle && abs((float) $settle['amount'] - 0.13) < 0.001 && $paid($iDewi)['payment_status'] === 'paid');
$c = Bank::confirm($pdo, $cfg, (int) $tBudi['id'], $prop($tBudi)['lines'], 'partial', 'Finance');
ok('partial: nothing settled, the rest stays owed', $c['ok'] && (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = {$iBudi} AND noncash = 1")->fetchColumn() === 0
    && abs((float) $paid($iBudi)['amount_paid'] - 26245000) < 0.005 && $paid($iBudi)['payment_status'] !== 'paid');
$c = Bank::confirm($pdo, $cfg, (int) $tBerkah['id'], $pBerkah['lines'], 'rounding', 'Finance');
ok('combined: both invoices closed by one transfer', $c['ok'] && $paid($iBerkah1)['payment_status'] === 'paid' && $paid($iBerkah2)['payment_status'] === 'paid');
$c = Bank::confirm($pdo, $cfg, (int) $tYanto['id'], $pYanto['lines'], 'overpaid', 'Finance');
$alloc = $pdo->query("SELECT * FROM payment_allocations WHERE invoice_id = {$iYanto}")->fetch();
ok('overpaid: allocation records +95,999.87 as overpaid', $c['ok'] && $alloc['diff_reason'] === 'overpaid' && abs((float) $alloc['diff_amount'] - 95999.87) < 0.005);
ok('refund link needs a real outgoing line code', !Bank::link_refund($pdo, (int) $alloc['id'], 'BCA-OUT-2699-9999') && Bank::link_refund($pdo, (int) $alloc['id'], 'BCA-OUT-2610-0002'));

// Withholding tax (PPh 23): the invoice counts as paid; the slip is awaited.
$cNpwp = $cust('PT Contoh Pemotong', 'PT CONTOH PEMOTONG');
$iWht = $inv($cNpwp, '1298/AMI-CO/09/26', 26780000, '2026-09-26', 'PT CONTOH PEMOTONG');
$tWht = $line('%1326/AMI-CO/10/26 BUDI%');   // 26,245,999.87 on a 26,780,000 invoice: 2% withheld
$c = Bank::confirm($pdo, $cfg, (int) $tWht['id'], [['invoice_id' => $iWht, 'amount' => (float) $tWht['amount']]], 'withholding', 'Finance');
$a = $pdo->query("SELECT * FROM payment_allocations WHERE invoice_id = {$iWht}")->fetch();
ok('withholding: invoice paid, settlement row "PPh (Bukti Potong)", slip pending', $c['ok'] && $paid($iWht)['payment_status'] === 'paid' && $a['diff_reason'] === 'withholding'
    && $a['bukti_potong_at'] === null && $pdo->query("SELECT method FROM payments WHERE id = {$a['settle_payment_id']}")->fetchColumn() === 'PPh (Bukti Potong)');
ok('withholding: slip received once', Bank::bukti_potong_received($pdo, (int) $a['id']) && !Bank::bukti_potong_received($pdo, (int) $a['id']));

// AR and dashboard read the new balances; settlement rows are not cash.
$dashAfter = Dashboard::data($pdo, Dashboard::period('month', '2026-10-15', '2026-10-15'), '2026-10-15', null, true, true);
ok('AR aging (dashboard receivables) drops by what was booked', $dashAfter['receivable']['total'] < $dashBefore['total'] - 30000000, $dashBefore['total'] . ' → ' . $dashAfter['receivable']['total']);
$cashOct = (float) $pdo->query("SELECT SUM(amount) FROM payments WHERE noncash = 0 AND pay_date LIKE '2026-10%'")->fetchColumn();
ok("dashboard 回款 counts cash only (settlement rows excluded)", abs($dashAfter['kpi']['collected'] - $cashOct) < 0.01, $dashAfter['kpi']['collected'] . ' vs ' . $cashOct);

// Confirm guards.
ok('confirm: the lines must add up to the transfer', Bank::confirm($pdo, $cfg, (int) $tHendra['id'], [['invoice_id' => $iHendra, 'amount' => 1]], 'none', 'F')['error'] === 'sum_mismatch');
ok('confirm: a voided invoice is refused', Bank::confirm($pdo, $cfg, (int) $tHendra['id'], [['invoice_id' => $iVoid, 'amount' => 752000]], 'none', 'F')['error'] === 'invoice_not_found');
ok('confirm: a booked line cannot be booked twice', Bank::confirm($pdo, $cfg, (int) $tRudi['id'], $prop($tRudi)['lines'], 'none', 'F')['error'] === 'not_confirmable');
ok('confirm: outgoing lines cannot be booked as receipts', Bank::confirm($pdo, $cfg, (int) $line('%AGRICULTURAL%')['id'], [['invoice_id' => $iHendra, 'amount' => 447825000]], 'none', 'F')['error'] === 'not_confirmable');

// 6) Adoption, and the payer learned (acceptance ④).
$lina = $reload($tLina);
$edited = [['invoice_id' => $iGlass, 'amount' => 8399700.0]];
$c = Bank::confirm($pdo, $cfg, (int) $lina['id'], $edited, 'none', 'Finance');
ok('AI suggestion confirmed as proposed → accepted', $c['ok'] && $c['ai'] === ['accepted' => true, 'changed' => []]);
ok('the payer name is learned for that customer', (int) $pdo->query("SELECT customer_id FROM customer_payer_aliases WHERE payer_key = 'LINA KUSUMA'")->fetchColumn() === $cGlass);
$secondLina = $line('%DR 535 LINA KUSUMA%1500%') ?: $pdo->query("SELECT * FROM bank_transactions WHERE counterparty_name = 'LINA KUSUMA' AND id <> {$lina['id']}")->fetch();
ok('second line from the same payer → rule match (alias), no model call', $reload($secondLina)['match_status'] === 'rule' && str_starts_with((string) $reload($secondLina)['match_note'], 'alias:')
    && $prop($reload($secondLina))['lines'][0]['invoice_id'] === $iGlass2);
$n0 = count($sent);
Bank::ai_match($pdo, $cfg, (int) $secondLina['id']);
ok('…and asking the model for it is a no-op', count($sent) === $n0);
$nusa = $reload($tNusa);
$c = Bank::confirm($pdo, $cfg, (int) $nusa['id'], [['invoice_id' => $iNusa, 'amount' => 8399700.0]], 'fee', 'Finance');
ok('finance changes the AI suggestion → not accepted, the changed field recorded', $c['ok'] && $c['ai']['accepted'] === false && $c['ai']['changed'] === ['diff_reason']);
$u2 = AiTask::usage($pdo, $cfg, date('Y-m'));
ok('usage page: bank_match adoption = 3 of 4 (Berkah, Yanto, Lina as proposed; Nusa edited)', $u2['tasks']['bank_match']['confirmed'] === 4 && $u2['tasks']['bank_match']['accepted'] === 3, json_encode($u2['tasks']['bank_match']));

// Non-AR lines.
$t5 = $line('%SETORAN VIA CDM 04/10%');
ok('mark as non-AR (e.g. capital): status only, nothing booked', Bank::mark_non_ar($pdo, (int) $t5['id'], 'capital', 'Finance') && $reload($t5)['match_status'] === 'non_ar'
    && (int) $pdo->query("SELECT COUNT(*) FROM payment_allocations WHERE transaction_id = {$t5['id']}")->fetchColumn() === 0);
ok('a reason outside the list is refused; undo works', !Bank::mark_non_ar($pdo, (int) $tSiti['id'], 'gift', 'F') && Bank::unmark_non_ar($pdo, (int) $t5['id']) && $reload($t5)['match_status'] === 'unmatched');

// 7) Collection status names (finance workbook, card 0.4).
ok('status: PAID - Balance / Lebih Bayar / Kurang Bayar / Sebagian / UNPAID', Bank::collection_status(100, 100, 0, 'BCA') === 'PAID - Balance'
    && Bank::collection_status(100, 100.31, 0, 'BCA') === 'PAID - Lebih Bayar' && Bank::collection_status(100, 99.87, 0.13, 'BCA Pembulatan') === 'PAID - Kurang Bayar'
    && Bank::collection_status(100, 40, 0, 'BCA') === 'Sebagian - Kurang Bayar' && Bank::collection_status(100, 0, 0, '') === 'UNPAID - Kurang Bayar');
ok('status: PAID - Shoppe / Gudang / Cash', Bank::collection_status(100, 100, 0, 'BCA · SHOPEE') === 'PAID - Shoppe'
    && Bank::collection_status(100, 100, 0, 'Gudang') === 'PAID - Gudang' && Bank::collection_status(100, 100, 0, 'Cash') === 'PAID - Cash');
$rc = [];
foreach (Bank::receipts($pdo, '2026-09') as $r) {
    $rc[$r['id']] = $r;
}
ok('receipts: one row per invoice with the workbook status', $rc[$iRudi]['status'] === 'PAID - Balance' && $rc[$iDewi]['status'] === 'PAID - Kurang Bayar'
    && $rc[$iBudi]['status'] === 'Sebagian - Kurang Bayar' && $rc[$iYanto]['status'] === 'PAID - Lebih Bayar' && $rc[$iHendra]['status'] === 'UNPAID - Kurang Bayar'
    && $rc[$iYanto]['refunds'] === ['BCA-OUT-2610-0002'] && !isset($rc[$iVoid]));
ok('receipts: payments listed with date, code and amount', $rc[$iBerkah2]['payments'][0]['receipt_no'] === $tBerkah['trx_code'] && $rc[$iRudi]['last_paid'] === '2026-10-02');

// 8) Upgrade path: a production DB from before FA-AI-01.
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$old->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
foreach (['bank_book', 'customer_payer_aliases', 'payment_allocations', 'bank_transactions', 'bank_imports', 'bank_accounts'] as $tb) {
    $old->exec("DROP TABLE {$tb}");
}
$old->exec('ALTER TABLE payments DROP COLUMN noncash');
$old->exec("INSERT INTO app_meta (k, v) VALUES ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),('pwd_policy_v1','1'),('perm_approvals','1'),('perm_insights','1'),('perm_insights_v2','1'),('perm_hr_v1','1'),('hr_employees_v1','1'),('perm_ai_usage_v1','1')");
$old->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Admin', 'admin@alupanel.local', 'x', 'admin')");
$old->exec("INSERT INTO invoices (invoice_no, customer, total, amount_paid, payment_status) VALUES ('INV-LAMA', 'PT Lama', 5000, 2000, 'partial')");
$old->exec("INSERT INTO payments (invoice_id, customer, amount, pay_date, method) VALUES (1, 'PT Lama', 2000, '2026-07-01', 'BCA')");
Database::migrate($old);
$tables = $old->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('bank_accounts','bank_imports','bank_transactions','payment_allocations','customer_payer_aliases','bank_book') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
ok('migrate: the six bank tables are created', count($tables) === 6);
ok('migrate: payments.noncash added, existing payments count as cash', $old->query('SELECT noncash FROM payments')->fetchColumn() == 0
    && (float) $old->query("SELECT amount_paid FROM invoices WHERE invoice_no = 'INV-LAMA'")->fetchColumn() === 2000.0);
Database::migrate($old);
ok('migrate: re-run is harmless', count($old->query('PRAGMA table_info(payments)')->fetchAll()) === count(array_unique(array_column($old->query('PRAGMA table_info(payments)')->fetchAll(), 'name'))));

Claude::$http = null;
Claude::$sleep = null;

// 9) The pages for real: upload, list, one line, confirm, batch confirm, receipts, permissions.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = sys_get_temp_dir() . '/bank-pages-' . bin2hex(random_bytes(4)) . '.sqlite';
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Database::migrate($p);   // fresh install with seed data and default permissions
    $p->exec("INSERT INTO customers (name, company) VALUES ('RUDI HARTONO', 'Toko Rudi Jaya')");
    $rc = (int) $p->lastInsertId();
    $p->exec("INSERT INTO orders (order_no, customer_id, customer_name, status, submitter) VALUES ('1306/AMI-CO/10/26', {$rc}, 'Toko Rudi Jaya', 'approved', 'Anton')");
    $ro = (int) $p->lastInsertId();
    $p->exec("INSERT INTO invoices (invoice_no, order_id, customer, total, invoice_date, due_date, payment_status, amount_paid)
              VALUES ('1600 - AMI - INV - 09 - 26', {$ro}, 'Toko Rudi Jaya', 4649700, '2026-09-28', '2026-10-12', 'pending', 0)");
    $ri = (int) $p->lastInsertId();
    $p = null;
    $tmp = tempnam(sys_get_temp_dir(), 'bca');
    copy(__DIR__ . '/../samples_test/BCA_CorpAcctTrxn_SAMPLE.csv', $tmp);
    $run = function (string $controller, string $action, array $user, array $get = [], ?array $post = null, array $env = []) use ($db, $harness): string {
        $args = [PHP_BINARY, $harness, $db, $controller, $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $envs = '';
        foreach ($env as $k => $v) {
            $envs .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $out = [];
        exec($envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $fin = ['id' => 7, 'name' => 'Fin', 'role' => 'finance_manager'];
    $sales = ['id' => 3, 'name' => 'Rina', 'role' => 'sales'];
    $files = json_encode(['file' => ['name' => 'BCA_CorpAcctTrxn_SAMPLE.csv', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => 0]]);
    $up = $run('bank', 'upload', $fin, [], ['x' => 1], ['TEST_FILES' => $files]);
    ok('upload page: imported, flash says added / skipped', str_contains($up, sprintf(t('bank_msg_imported'), 57, 0)) && !str_contains($up, 'PHP:'), mb_substr($up, -300));
    $imp = $run('bank', 'import', $fin);
    ok('import page shows the result row: added, totals check, balance check', (bool) preg_match('/data-added>57</', $imp) && str_contains($imp, 'data-totals><span class="tag tag-red">')
        && str_contains($imp, 'data-balance><span class="tag tag-green">') && !str_contains($imp, 'PHP:'));
    $up2 = $run('bank', 'upload', $fin, [], ['x' => 1], ['TEST_FILES' => $files]);
    ok('upload twice: all 57 skipped', str_contains($up2, sprintf(t('bank_msg_imported'), 0, 57)));
    $bad = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($bad, "a,b,c\n1,2,3\n");
    $up3 = $run('bank', 'upload', $fin, [], ['x' => 1], ['TEST_FILES' => json_encode(['file' => ['name' => 'x.csv', 'tmp_name' => $bad, 'size' => 12, 'error' => 0]])]);
    ok('an unknown file is refused with a clear message', str_contains($up3, t('bank_err_unknown_format')));
    $list = $run('bank', 'index', $fin);
    ok('list renders: statuses, rule match count, no notices', str_contains($list, t('bank_st_rule')) && str_contains($list, sprintf(t('bank_btn_confirm_rules'), 1)) && !str_contains($list, 'PHP:'), mb_substr($list, 0, 200));
    $chk = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $rid = (int) $chk->query("SELECT id FROM bank_transactions WHERE match_status = 'rule'")->fetchColumn();
    $uid = (int) $chk->query("SELECT id FROM bank_transactions WHERE match_status = 'unmatched' AND description LIKE '%HENDRA%'")->fetchColumn();
    $show = $run('bank', 'show', $fin, ['id' => $rid]);
    ok('one line: proposal prefilled in the confirm form', str_contains($show, 'value="1600 - AMI - INV - 09 - 26"') && str_contains($show, 'value="4649700"') && !str_contains($show, 'PHP:'));
    ok('one line: unmatched shows the manual form and the non-AR choice', str_contains($run('bank', 'show', $fin, ['id' => $uid]), t('bank_btn_mark')));
    $bad = $run('bank', 'confirm', $fin, [], ['id' => $uid, 'inv' => ['1600 - AMI - INV - 09 - 26'], 'amt' => ['1'], 'diff' => 'none']);
    ok('confirm form: wrong total refused with a message, nothing booked', str_contains($bad, t('bank_err_sum_mismatch')) && (int) $chk->query('SELECT COUNT(*) FROM payment_allocations')->fetchColumn() === 0);
    $all = $run('bank', 'confirm_rules', $fin, [], ['acc' => (int) $chk->query('SELECT account_id FROM bank_transactions LIMIT 1')->fetchColumn(), 'm' => '2026-10']);
    ok('「全部确认」 books every rule match', str_contains($all, sprintf(t('bank_msg_confirmed_n'), 1)) && $chk->query("SELECT payment_status FROM invoices WHERE id = {$ri}")->fetchColumn() === 'paid');
    ok('…and it is audited with the invoice and new balance', str_contains((string) $chk->query("SELECT detail FROM audit_log WHERE module = 'bank' AND action = 'confirm'")->fetchColumn(), '1600 - AMI - INV - 09 - 26'));
    ok('the import is audited too', (int) $chk->query("SELECT COUNT(*) FROM audit_log WHERE module = 'bank' AND action = 'import'")->fetchColumn() === 2);
    // Two lines proposed for one invoice: the batch books the first, leaves the second for a person.
    $chk->exec("UPDATE invoices SET amount_paid = 0, payment_status = 'pending' WHERE id = {$ri}");
    $chk->exec("INSERT INTO payments (invoice_id, customer, amount, pay_date, method) VALUES ({$ri}, 'x', -4649700, '2026-10-02', 'test reset')");
    $chk->exec("UPDATE bank_transactions SET match_status = 'rule', proposal = (SELECT proposal FROM bank_transactions WHERE id = {$rid}), confirmed_by = NULL WHERE id IN ({$rid}, {$uid})");
    $chk->exec("UPDATE bank_transactions SET amount = 4649700 WHERE id = {$uid}");
    $chk->exec("DELETE FROM payment_allocations");   // test fixture only: back to two open proposals
    $two = $run('bank', 'confirm_rules', $fin, [], ['acc' => (int) $chk->query('SELECT account_id FROM bank_transactions LIMIT 1')->fetchColumn(), 'm' => '2026-10']);
    ok('batch confirm: a second line for an invoice already paid is not booked as overpayment', str_contains($two, sprintf(t('bank_msg_failed_n'), 1))
        && (int) $chk->query("SELECT COUNT(*) FROM payment_allocations WHERE diff_reason = 'overpaid'")->fetchColumn() === 0);
    $chk->exec("UPDATE bank_transactions SET match_status = 'unmatched', proposal = NULL WHERE id = {$uid}");
    $mk = $run('bank', 'mark', $fin, [], ['id' => $uid, 'reason' => 'refund']);
    ok('mark non-AR from the page', $chk->query("SELECT match_status || '/' || non_ar_reason FROM bank_transactions WHERE id = {$uid}")->fetchColumn() === 'non_ar/refund');
    $rcp = $run('bank', 'receipts', $fin, ['m' => '2026-09']);
    ok('receipts page: the invoice with its workbook status', str_contains($rcp, 'data-invoice="1600 - AMI - INV - 09 - 26"') && str_contains($rcp, 'PAID - Balance') && !str_contains($rcp, 'PHP:'));
    ok('Bukti Potong page renders', !str_contains($run('bank', 'bukti_potong', $fin), 'PHP:'));
    $denied = $run('bank', 'index', $sales);
    ok('no finance module → no bank pages', str_contains($denied, t('msg_no_module')) && !str_contains($denied, t('bank_sub')));
    ok('finance page links to the bank pages', str_contains($run('finance', 'index', $fin), 'r=bank.index'));
    $chk = null;
    @unlink($db);
    @unlink($tmp);
}

// 10) History import (Buku Bank) and the blind benchmark — samples_test/Finance_Workbook_SAMPLE.xlsx.
$h = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$h->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$mk = function (string $name, string $invoiceNo, string $orderNo, float $total, string $date) use ($h): array {
    $h->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$name]);
    $c = (int) $h->lastInsertId();
    $h->prepare("INSERT INTO orders (order_no, customer_id, customer_name, status, submitter) VALUES (?,?,?,'approved','Anton')")->execute([$orderNo, $c, $name]);
    $o = (int) $h->lastInsertId();
    $h->prepare("INSERT INTO invoices (invoice_no, order_id, customer, total, invoice_date, payment_status, amount_paid) VALUES (?,?,?,?,?,'pending',0)")->execute([$invoiceNo, $o, $name, $total, $date]);
    return [$c, (int) $h->lastInsertId()];
};
[$hBudi, $hInv] = $mk('BUDI SANTOSO / Maju Jaya', '1301 - AMI - INV - 10 - 26', '1401/AMI-CO/10/26', 4648000, '2026-10-01');
[$hShop, $hInvShop] = $mk('Toko Sinar Kaca', '1302 - AMI - INV - 10 - 26', '1402/AMI-CO/10/26', 2750000, '2026-10-01');
$book = Bank::book_rows(SalesHistory::read_xlsx(__DIR__ . '/../samples_test/Finance_Workbook_SAMPLE.xlsx', '1. Mutasi Bank BCA'));
ok('workbook: IN lines read by header, OUT lines ignored', count($book['rows']) === 1 && $book['rows'][0]['code'] === 'BCA-IN-2610-0003' && $book['rows'][0]['date'] === '2026-10-02'
    && $book['rows'][0]['invoice_raw'] === '1301 - AMI - 10 - 26' && $book['errors'] === []);
$dry = Bank::book_import($h, $book['rows'], false, 'test');
ok('history dry run: invoice "1301 - AMI - 10 - 26" found as 1301 - AMI - INV - 10 - 26, nothing written', $dry['resolved'] === 1 && $dry['aliases'] === ["BUDI SANTOSO → customer #{$hBudi}"]
    && (int) $h->query('SELECT COUNT(*) FROM bank_book')->fetchColumn() === 0 && (int) $h->query('SELECT COUNT(*) FROM customer_payer_aliases')->fetchColumn() === 0);
$app = Bank::book_import($h, $book['rows'], true, 'test');
ok('history --apply: line kept, payer learned as a history alias', $app['stored'] === 1 && $app['aliases_added'] === 1
    && $h->query("SELECT source FROM customer_payer_aliases WHERE payer_key = 'BUDI SANTOSO'")->fetchColumn() === 'history');
$again = Bank::book_import($h, $book['rows'], true, 'test');
ok('history re-run: nothing duplicated', $again['stored'] === 0 && $again['aliases_added'] === 0);
ok('workbook dates as Excel day numbers are read too', Bank::book_rows([['Kode Transaksi', 'Tanggal Transaksi', 'CR', 'Petty Cash', 'Nomor Invoice'], ['BCA-IN-2609-0001', 46297.0, 1000.0, 'x', '']])['rows'][0]['date'] === '2026-10-02');

// A September line from a payer seen before, under a shop name nothing like theirs.
$h->exec("INSERT INTO bank_book (trx_code, trx_date, description, amount, invoice_raw, invoice_ids, customer_id, payer_name) VALUES
          ('BCA-IN-2609-0001', '2026-09-05', 'BI-FAST CR TRANSFER   DR 535 WATI SUSANTI', 1000000, 'old', '{$hInvShop}', {$hShop}, 'WATI SUSANTI'),
          ('BCA-IN-2610-0090', '2026-10-06', 'BI-FAST CR TRANSFER   DR 535 WATI SUSANTI', 2750000, '1302 - AMI - 10 - 26', '{$hInvShop}', {$hShop}, 'WATI SUSANTI')");
$bm = Bank::benchmark($h, $cfg, '2026-10', false);
ok('benchmark (rules only): both October lines matched correctly', $bm['lines'] === 2 && $bm['rule'] === 2 && $bm['rule_ok'] === 2 && $bm['accuracy'] === 1.0, json_encode($bm['detail']));
ok('benchmark: the payer learned in September matched by alias (rule 2)', count(array_filter($bm['detail'], fn($d) => $d['code'] === 'BCA-IN-2610-0090' && $d['ok'])) === 1);
$h->exec("DELETE FROM bank_book WHERE trx_code = 'BCA-IN-2609-0001'");
$bm2 = Bank::benchmark($h, $cfg, '2026-10', false);
ok('benchmark is blind: without September the same line is not a rule match', $bm2['rule'] === 1 && $bm2['none'] === 1);
$sent = [];
$aiAnswer = ['WATI SUSANTI' => [[$hInvShop], 'exact']];
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$sent, &$aiAnswer): array {
    $b = json_decode($body, true);
    $sent[] = $b;
    $u = json_decode($b['messages'][0]['content'], true);
    [$ids, $type] = $aiAnswer[(string) ($u['transfer']['payer'] ?? '')] ?? [[], 'unknown'];
    $text = json_encode(['invoice_ids' => $ids, 'confidence' => 'high', 'diff_type' => $type, 'reason_id' => 'x', 'reason_zh' => 'x']);
    return [200, json_encode(['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]), []];
};
$bm3 = Bank::benchmark($h, $cfg, '2026-10', true);
ok('benchmark with the model: the line rules missed goes to the model, scored', $bm3['ai'] === 1 && $bm3['ai_ok'] === 1 && $bm3['ai_high'] === 1 && $bm3['ai_high_wrong'] === 0 && $bm3['accuracy'] === 1.0);
ok('benchmark: the hidden invoice number never reaches the model', !str_contains(json_encode($sent), '1302 - AMI - 10 - 26') && $h->query("SELECT ref_table FROM ai_calls ORDER BY id DESC LIMIT 1")->fetchColumn() === 'bank_book');
Claude::$http = null;
Claude::$sleep = null;
