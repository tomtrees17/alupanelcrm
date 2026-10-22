<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var array $config */

// 银行流水 / 回款对账 (FA-AI-01). Same people as 财务管理: the finance module.
// Every booking needs a person's click; the model only proposes (Bank.php).
if (!can_access('finance')) {
    http_response_code(403);
    flash(t('msg_no_module'), 'error');
    redirect('dashboard.index');
}
$by = own_name();

/** One line, or 404. */
$findTrx = function (int $id) use ($pdo): array {
    $t = Bank::find($pdo, $id);
    if ($t === null) {
        http_response_code(404);
        exit('Not found');
    }
    return $t;
};

/** Lines typed on the confirm form → [{invoice_id, amount}] (invoice by its number). */
$readLines = function () use ($pdo): array {
    $nos = (array) ($_POST['inv'] ?? []);
    $amts = (array) ($_POST['amt'] ?? []);
    $find = $pdo->prepare('SELECT id FROM invoices WHERE invoice_no = ? AND voided_at IS NULL');
    $lines = [];
    foreach ($nos as $i => $no) {
        $no = trim((string) $no);
        $amt = (float) str_replace(',', '', (string) ($amts[$i] ?? '0'));
        if ($no === '' && $amt <= 0) {
            continue;
        }
        $find->execute([$no]);
        $lines[] = ['invoice_id' => (int) $find->fetchColumn(), 'amount' => $amt, 'no' => $no];
    }
    return $lines;
};

$auditConfirm = function (array $t, array $res, string $diff, string $how) use ($pdo): void {
    $parts = [];
    foreach ($res['invoices'] as $inv) {
        $parts[] = sprintf('%s 累计已收 %s / %s', $inv['invoice_no'], idr((float) $inv['paid_after']), idr((float) $inv['total']));
    }
    audit($pdo, 'bank', 'confirm', 'bank_transaction', (int) $t['id'], (string) $t['trx_code'],
        sprintf('%s 入账 %s（%s，%s）；%s%s', $how, idr((float) $t['amount']), $t['trx_date'], (string) $t['counterparty_name'],
            implode('；', $parts), $diff !== 'none' ? '；差额：' . t('bank_diff_' . $diff) : ''));
};

switch ($action) {
    case 'index':
        $accounts = $pdo->query('SELECT * FROM bank_accounts ORDER BY bank, last4')->fetchAll();
        $acc = (int) input('acc', 0);
        if ($acc === 0 && $accounts) {
            $acc = (int) $accounts[0]['id'];
        }
        $month = (string) input('m', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $st = $pdo->prepare('SELECT MAX(substr(trx_date, 1, 7)) FROM bank_transactions WHERE account_id = ?');
            $st->execute([$acc]);
            $month = (string) ($st->fetchColumn() ?: date('Y-m'));
        }
        $status = in_array(input('st'), Bank::MATCH, true) ? (string) input('st') : '';
        $dir = in_array(input('dir'), ['in', 'out'], true) ? (string) input('dir') : '';
        $q = trim((string) input('q', ''));
        $cond = ['t.account_id = ?', 'substr(t.trx_date, 1, 7) = ?'];
        $args = [$acc, $month];
        if ($status !== '') {
            $cond[] = 't.match_status = ?';
            $args[] = $status;
        }
        if ($dir !== '') {
            $cond[] = 't.direction = ?';
            $args[] = $dir;
        }
        if ($q !== '') {
            $cond[] = '(t.description LIKE ? OR t.counterparty_name LIKE ? OR t.trx_code LIKE ?)';
            array_push($args, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $where = ' WHERE ' . implode(' AND ', $cond);
        $pg = paginate($pdo, 'SELECT COUNT(*) FROM bank_transactions t' . $where, $args, 100);
        $st = $pdo->prepare('SELECT t.*, (SELECT GROUP_CONCAT(i.invoice_no, \', \') FROM payment_allocations a JOIN invoices i ON i.id = a.invoice_id WHERE a.transaction_id = t.id) AS booked
                               FROM bank_transactions t' . $where . ' ORDER BY t.trx_date, t.id' . $pg['limit']);
        $st->execute($args);
        $counts = $pdo->prepare('SELECT match_status, COUNT(*) FROM bank_transactions WHERE account_id = ? AND substr(trx_date, 1, 7) = ? GROUP BY match_status');
        $counts->execute([$acc, $month]);
        $months = $pdo->prepare('SELECT DISTINCT substr(trx_date, 1, 7) FROM bank_transactions WHERE account_id = ? ORDER BY 1 DESC');
        $months->execute([$acc]);
        view('bank.index', [
            'pageTitle' => t('bank_title'), 'accounts' => $accounts, 'acc' => $acc, 'month' => $month, 'months' => $months->fetchAll(PDO::FETCH_COLUMN),
            'status' => $status, 'dir' => $dir, 'q' => $q, 'rows' => $st->fetchAll(), 'pg' => $pg,
            'counts' => $counts->fetchAll(PDO::FETCH_KEY_PAIR),
            'aiLeft' => (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE match_status = 'unmatched' AND direction = 'in' AND COALESCE(ai_status, 'failed') IN ('failed', 'over_budget')")->fetchColumn(),
            'bpOpen' => (int) $pdo->query("SELECT COUNT(*) FROM payment_allocations WHERE diff_reason = 'withholding' AND bukti_potong_at IS NULL")->fetchColumn(),
            'aiOn' => AiTask::enabled($config),
        ]);
        break;

    case 'import':
        view('bank.import', [
            'pageTitle' => t('bank_import'),
            'imports' => $pdo->query('SELECT b.*, a.bank, a.last4 FROM bank_imports b JOIN bank_accounts a ON a.id = b.account_id ORDER BY b.id DESC LIMIT 20')->fetchAll(),
            'last' => (int) input('done', 0),
        ]);
        break;

    case 'upload':
        Csrf::verify();
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) $f['size'] <= 0 || (int) $f['size'] > 5 * 1024 * 1024) {
            flash(t('bank_err_upload'), 'error');
            redirect('bank.import');
        }
        $content = (string) file_get_contents((string) $f['tmp_name']);
        $res = Bank::import($pdo, $config, $content, (string) $f['name'], $by);
        if (!$res['ok']) {
            flash(t('bank_err_' . $res['error']), 'error');
            redirect('bank.import');
        }
        $c = $res['check'];
        audit($pdo, 'bank', 'import', 'bank_import', $res['import_id'], (string) $f['name'],
            sprintf('%s …%s %s：新增 %d 笔，跳过 %d 笔，待入账 %d 笔；进账 %d 笔 %s，出账 %d 笔 %s；文件汇总%s；余额链%s；规则匹配 %d 笔',
                $res['parser'] === 'bca_csv' ? 'BCA' : 'ICBC', $res['last4'], $res['period'], $res['added'], $res['skipped'], $res['pending'],
                $c['credit_n'], idr($c['credit']), $c['debit_n'], idr($c['debit']),
                $c['totals_ok'] === null ? '无' : ($c['totals_ok'] ? '一致' : '不一致'), $c['balance_ok'] ? '连续' : '断开', $res['rule_matched']));
        flash(sprintf(t('bank_msg_imported'), $res['added'], $res['skipped']));
        redirect('bank.import', ['done' => $res['import_id']]);
        break;

    case 'show':
        $t = $findTrx((int) input('id', 0));
        $allocs = $pdo->prepare('SELECT a.*, i.invoice_no, i.total, i.customer, r.trx_code AS refund_code FROM payment_allocations a
                                   JOIN invoices i ON i.id = a.invoice_id LEFT JOIN bank_transactions r ON r.id = a.refund_transaction_id
                                  WHERE a.transaction_id = ? ORDER BY a.id');
        $allocs->execute([$t['id']]);
        $open = in_array($t['match_status'], ['unmatched', 'rule', 'ai'], true) ? Bank::open_invoices($pdo) : [];
        $proposal = $t['proposal'] ? (array) json_decode((string) $t['proposal'], true) : null;
        // Proposed lines carry invoice numbers for the form; a proposal for an invoice paid meanwhile is shown, not hidden.
        $byId = [];
        if ($proposal) {
            $ids = array_map(fn($l) => (int) $l['invoice_id'], $proposal['lines']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT id, invoice_no, customer, total, amount_paid, invoice_date FROM invoices WHERE id IN ({$in})");
            $s->execute($ids);
            foreach ($s->fetchAll() as $r) {
                $byId[(int) $r['id']] = $r;
            }
        }
        view('bank.show', [
            'pageTitle' => (string) $t['trx_code'], 't' => $t, 'allocs' => $allocs->fetchAll(), 'open' => $open,
            'proposal' => $proposal, 'propInv' => $byId, 'aiOn' => AiTask::enabled($config),
        ]);
        break;

    case 'confirm':
        Csrf::verify();
        $t = $findTrx((int) input('id', 0));
        $lines = $readLines();
        foreach ($lines as $l) {
            if ($l['invoice_id'] === 0) {
                flash(sprintf(t('bank_err_invoice'), $l['no']), 'error');
                redirect('bank.show', ['id' => $t['id']]);
            }
        }
        $diff = in_array(input('diff'), Bank::DIFF, true) ? (string) input('diff') : 'none';
        $res = Bank::confirm($pdo, $config, (int) $t['id'], $lines, $diff, $by);
        if (!$res['ok']) {
            flash(t('bank_err_' . $res['error']), 'error');
            redirect('bank.show', ['id' => $t['id']]);
        }
        $how = ['rule' => '规则匹配确认', 'ai' => 'AI 建议确认'][$t['match_status']] ?? '手工匹配';
        if ($res['ai'] !== null && !$res['ai']['accepted']) {
            $how .= '（改动：' . implode('/', $res['ai']['changed']) . '）';
        }
        $auditConfirm($t, $res, $diff, $how);
        flash(t('bank_msg_confirmed'));
        redirect('bank.index', ['acc' => $t['account_id'], 'm' => substr((string) $t['trx_date'], 0, 7), 'st' => (string) input('back_st', '')]);
        break;

    case 'confirm_rules':
        // 「全部确认」 for rule matches: each exactly as proposed.
        Csrf::verify();
        $acc = (int) input('acc', 0);
        $month = (string) input('m', '');
        $st = $pdo->prepare("SELECT id FROM bank_transactions WHERE match_status = 'rule' AND account_id = ? AND substr(trx_date, 1, 7) = ? ORDER BY trx_date, id");
        $st->execute([$acc, $month]);
        $ok = $failed = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $t = Bank::find($pdo, (int) $id);
            $p = (array) json_decode((string) $t['proposal'], true);
            // Balances may have moved since the proposal (another line paid the same invoice): only
            // book what the rules still propose today; anything else waits for a person.
            $fresh = Bank::rule_proposal($pdo, $config, $t, Bank::open_invoices($pdo));
            if ($fresh === null || $fresh['proposal']['lines'] != ($p['lines'] ?? null)) {
                $failed++;
                continue;
            }
            $res = Bank::confirm($pdo, $config, (int) $id, $p['lines'] ?? [], (string) ($p['diff_reason'] ?? 'none'), $by);
            if ($res['ok']) {
                $auditConfirm($t, $res, (string) ($p['diff_reason'] ?? 'none'), '规则匹配批量确认');
                $ok++;
            } else {
                $failed++;
            }
        }
        flash(sprintf(t('bank_msg_confirmed_n'), $ok) . ($failed ? ' · ' . sprintf(t('bank_msg_failed_n'), $failed) : ''), $failed ? 'error' : 'success');
        redirect('bank.index', ['acc' => $acc, 'm' => $month]);
        break;

    case 'ai_batch':
        // 「AI 匹配」: the lines rules could not place and the model has not seen yet, a batch per click.
        Csrf::verify();
        $acc = (int) input('acc', 0);
        $month = (string) input('m', '');
        if (!AiTask::enabled($config)) {
            flash(t('ai_usage_off'), 'error');
            redirect('bank.index', ['acc' => $acc, 'm' => $month]);
        }
        @set_time_limit(600);
        $limit = Bank::settings($config)['ai_batch'];
        // Never asked, or asked and failed / stopped by the budget. The per-line guard (2 calls in 24 h) stops any loop.
        $st = $pdo->query("SELECT id FROM bank_transactions WHERE match_status = 'unmatched' AND direction = 'in' AND COALESCE(ai_status, 'failed') IN ('failed', 'over_budget') ORDER BY ai_status IS NOT NULL, trx_date, id LIMIT {$limit}");
        $done = ['ok' => 0, 'none' => 0, 'failed' => 0];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = Bank::ai_match($pdo, $config, (int) $id);
            if ($r['status'] === 'over_budget') {
                flash(t('ai_budget_hit'), 'error');
                break;
            }
            $done[$r['status'] === 'ok' ? 'ok' : (in_array($r['status'], ['none', 'no_candidates'], true) ? 'none' : 'failed')]++;
        }
        flash(sprintf(t('bank_msg_ai_done'), $done['ok'], $done['none'], $done['failed']));
        redirect('bank.index', ['acc' => $acc, 'm' => $month, 'st' => 'ai']);
        break;

    case 'ai_one':
        Csrf::verify();
        $t = $findTrx((int) input('id', 0));
        if (!AiTask::enabled($config)) {
            flash(t('ai_usage_off'), 'error');
            redirect('bank.show', ['id' => $t['id']]);
        }
        $r = Bank::ai_match($pdo, $config, (int) $t['id']);
        $msg = ['ok' => 'bank_msg_ai_ok', 'none' => 'bank_msg_ai_none', 'no_candidates' => 'bank_msg_ai_none', 'over_budget' => 'ai_budget_hit'][$r['status']] ?? 'bank_msg_ai_failed';
        flash(t($msg), $r['status'] === 'ok' ? 'success' : 'error');
        redirect('bank.show', ['id' => $t['id']]);
        break;

    case 'mark':
        Csrf::verify();
        $t = $findTrx((int) input('id', 0));
        $reason = (string) input('reason', '');
        if (Bank::mark_non_ar($pdo, (int) $t['id'], $reason, $by)) {
            audit($pdo, 'bank', 'classify', 'bank_transaction', (int) $t['id'], (string) $t['trx_code'],
                sprintf('非货款：%s（%s %s）', t('bank_nonar_' . $reason), $t['trx_date'], idr((float) $t['amount'])));
            flash(t('bank_msg_marked'));
        }
        redirect('bank.show', ['id' => $t['id']]);
        break;

    case 'unmark':
        Csrf::verify();
        $t = $findTrx((int) input('id', 0));
        if (Bank::unmark_non_ar($pdo, (int) $t['id'])) {
            audit($pdo, 'bank', 'classify', 'bank_transaction', (int) $t['id'], (string) $t['trx_code'],
                sprintf('非货款（%s）→ 未匹配', t('bank_nonar_' . $t['non_ar_reason'])));
            Bank::rule_match($pdo, $config, (int) $t['id']);
        }
        redirect('bank.show', ['id' => $t['id']]);
        break;

    case 'refund':
        Csrf::verify();
        $alloc = (int) input('alloc', 0);
        $code = trim((string) input('code', ''));
        $tid = (int) input('id', 0);
        if (Bank::link_refund($pdo, $alloc, $code)) {
            audit($pdo, 'bank', 'link', 'payment_allocation', $alloc, $code, '多付退款关联出账流水 ' . $code);
            flash(t('bank_msg_saved'));
        } else {
            flash(t('bank_err_refund'), 'error');
        }
        redirect('bank.show', ['id' => $tid]);
        break;

    case 'bp':
        Csrf::verify();
        $alloc = (int) input('alloc', 0);
        if (Bank::bukti_potong_received($pdo, $alloc)) {
            audit($pdo, 'bank', 'handle', 'payment_allocation', $alloc, '', '已收到客户 Bukti Potong');
            flash(t('bank_msg_saved'));
        }
        redirect((string) input('back', '') === 'bp' ? 'bank.bukti_potong' : 'bank.show', ['id' => (int) input('id', 0)]);
        break;

    case 'bukti_potong':
        $rows = $pdo->query("SELECT a.*, i.invoice_no, i.customer, t.trx_code, t.trx_date FROM payment_allocations a
                               JOIN invoices i ON i.id = a.invoice_id JOIN bank_transactions t ON t.id = a.transaction_id
                              WHERE a.diff_reason = 'withholding' ORDER BY a.bukti_potong_at IS NOT NULL, t.trx_date DESC LIMIT 300")->fetchAll();
        view('bank.bukti_potong', ['pageTitle' => t('bank_bp_title'), 'rows' => $rows]);
        break;

    case 'receipts':
    case 'receipts_export':
        $month = (string) input('m', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        // A salesperson with the finance module still sees only their own invoices, as on the invoice list.
        $rows = Bank::receipts($pdo, $month, sees_only_own() ? own_name() : null);
        if ($action === 'receipts') {
            $months = $pdo->query("SELECT DISTINCT substr(invoice_date, 1, 7) FROM invoices WHERE invoice_date IS NOT NULL AND invoice_date <> '' ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
            view('bank.receipts', ['pageTitle' => t('bank_receipts'), 'rows' => $rows, 'month' => $month, 'months' => array_values(array_unique(array_merge([date('Y-m')], $months)))]);
            break;
        }
        if (!can_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('bank.receipts', ['m' => $month]);
        }
        // Same columns as the workbook's 「5. Catatan Penerimaan」, up to six payments per invoice.
        $head = ['Bulan', 'No. Invoice', 'Tanggal Invoice', 'Tanggal Duedate', 'PIC', 'Nama Client', 'Total Invoice (Include PPN)', 'Nominal Pembayaran',
                 'Balanced', 'STATUS Pembayaran', 'Tanggal Pembayaran Invoice', 'KODE TRANSAKSI (Kelebihan TF)'];
        for ($i = 1; $i <= 6; $i++) {
            array_push($head, "Tanggal {$i}", "Kode {$i}", "Ket {$i}", "Nominal {$i}");
        }
        $out = [];
        foreach ($rows as $r) {
            $line = [$month, $r['invoice_no'], $r['invoice_date'], $r['due_date'], $r['submitter'], $r['customer'], (float) $r['total'], $r['cash'],
                     $r['balance'], $r['status'], $r['last_paid'], implode(', ', $r['refunds'])];
            foreach (array_slice($r['payments'], 0, 6) as $p) {
                array_push($line, $p['pay_date'], $p['receipt_no'], trim($p['method'] . ' ' . $p['note']), (float) $p['amount']);
            }
            $out[] = $line;
        }
        audit($pdo, 'bank', 'export', 'receipts', 0, $month, sprintf('导出回款记录 %s（%d 张发票）', $month, count($out)));
        send_spreadsheet('catatan_penerimaan_' . $month, 'Catatan Penerimaan', $head, $out);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
