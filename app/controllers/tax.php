<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var array $config */

// 销项 Faktur (FA-TAX-01): classify invoices, keep buyers' tax data, generate
// the Coretax import file. Finance only (module 'finance'); no AI.
if (!can_access('finance')) {
    http_response_code(403);
    flash(t('msg_no_module'), 'error');
    redirect('dashboard.index');
}
$by = own_name();

/** The period on the page: from / to, default this month. */
$period = function (): array {
    $from = (string) input('from', date('Y-m-01'));
    $to = (string) input('to', date('Y-m-t'));
    $ok = fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$ok($from) || !$ok($to) || $from > $to) {
        return [date('Y-m-01'), date('Y-m-t')];
    }
    return [$from, $to];
};

/** A blocker key ("key" or "key:detail") as text. */
$blockerText = function (string $b): string {
    [$k, $d] = array_pad(explode(':', $b, 2), 2, '');
    return $d !== '' ? sprintf(t($k), $d) : t($k);
};

switch ($action) {
    case 'index':
        [$from, $to] = $period();
        $show = in_array(input('show'), ['FAKTUR', 'GUNGGUNG', 'TBC', 'blocked'], true) ? (string) input('show') : '';
        $rv = Faktur::review($pdo, $config, $from, $to);
        $rows = array_values(array_filter($rv['rows'], fn($r) => $show === '' || ($show === 'blocked' ? $r['blockers'] !== [] : $r['class'] === $show)));
        $exports = $pdo->prepare('SELECT * FROM faktur_exports WHERE period_from = ? AND period_to = ? ORDER BY id DESC');
        $exports->execute([$from, $to]);
        view('tax.index', [
            'pageTitle' => t('fk_title'), 'from' => $from, 'to' => $to, 'show' => $show, 'rv' => $rv, 'rows' => $rows,
            'exports' => $exports->fetchAll(),
            'recent' => $pdo->query('SELECT * FROM faktur_exports ORDER BY id DESC LIMIT 10')->fetchAll(),
            'blockerText' => $blockerText,
        ]);
        break;

    case 'classify':
        Csrf::verify();
        $id = (int) input('id', 0);
        $class = (string) input('class', '');
        $st = $pdo->prepare('SELECT invoice_no, faktur_class FROM invoices WHERE id = ?');
        $st->execute([$id]);
        $inv = $st->fetch();
        if ($inv && ($class === '' || in_array($class, Faktur::CLASSES, true)) && (string) $inv['faktur_class'] !== $class) {
            $pdo->prepare('UPDATE invoices SET faktur_class = ? WHERE id = ?')->execute([$class !== '' ? $class : null, $id]);
            audit($pdo, 'tax', 'classify', 'invoice', $id, (string) $inv['invoice_no'],
                sprintf('invoices.faktur_class: %s → %s', $inv['faktur_class'] ?: t('fk_auto'), $class !== '' ? $class : t('fk_auto')));
            flash(t('fk_msg_saved'));
        }
        redirect('tax.index', ['from' => (string) input('from', ''), 'to' => (string) input('to', ''), 'show' => (string) input('show', '')]);
        break;

    case 'customer':
        $id = (int) input('id', 0);
        $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            http_response_code(404);
            exit('Not found');
        }
        $inv = $pdo->prepare('SELECT i.invoice_no, i.invoice_date, i.address, i.total FROM invoices i JOIN orders o ON o.id = i.order_id
                               WHERE o.customer_id = ? AND i.voided_at IS NULL ORDER BY i.invoice_date DESC LIMIT 5');
        $inv->execute([$id]);
        view('tax.customer', ['pageTitle' => t('fk_customer_title'), 'c' => $c, 'invoices' => $inv->fetchAll(),
            'from' => (string) input('from', ''), 'to' => (string) input('to', '')]);
        break;

    case 'customer_save':
        Csrf::verify();
        $id = (int) input('id', 0);
        $res = Faktur::save_customer($pdo, $id, $_POST, $by);
        $back = ['id' => $id, 'from' => (string) input('from', ''), 'to' => (string) input('to', '')];
        if (!$res['ok']) {
            flash(t($res['error']), 'error');
            redirect('tax.customer', $back);
        }
        if ($res['changed']) {
            $name = (string) $pdo->query('SELECT name FROM customers WHERE id = ' . $id)->fetchColumn();
            $parts = [];
            foreach ($res['changed'] as $k => [$a, $b]) {
                // Ids are shown masked in the log: the full number stays on the customer record.
                $mask = fn(string $v): string => in_array($k, ['tax_npwp', 'tax_nik', 'tax_nitku'], true) && strlen($v) > 4 ? str_repeat('•', strlen($v) - 4) . substr($v, -4) : $v;
                $parts[] = sprintf('customers.%s: %s → %s', $k, $mask($a) ?: '∅', $mask($b) ?: '∅');
            }
            audit($pdo, 'tax', 'update', 'customer', $id, $name, implode('; ', $parts));
            flash(t('fk_msg_saved'));
        }
        if ((string) input('from', '') !== '') {
            redirect('tax.index', ['from' => (string) input('from'), 'to' => (string) input('to')]);
        }
        redirect('tax.customer', $back);
        break;

    case 'generate':
        Csrf::verify();
        [$from, $to] = $period();
        $res = Faktur::generate($pdo, $config, $from, $to, $by);
        if (!$res['ok']) {
            $shown = array_map(function (string $e) use ($blockerText): string {
                $p = strpos($e, ' · ');
                return $p === false ? $blockerText($e) : substr($e, 0, $p + 4) . $blockerText(substr($e, $p + 4));
            }, array_slice($res['errors'], 0, 8));
            flash(t('fk_msg_blocked') . ' ' . implode('；', $shown) . (count($res['errors']) > 8 ? ' …' : ''), 'error');
            redirect('tax.index', ['from' => $from, 'to' => $to]);
        }
        audit($pdo, 'tax', 'export', 'faktur_export', $res['id'], "{$from} – {$to}",
            sprintf('生成 Coretax 导入表：%d 张发票，%d 行明细，PPN 合计 %s', $res['invoices'], $res['lines'], idr($res['ppn'])));
        flash(sprintf(t('fk_msg_generated'), $res['invoices'], $res['lines']));
        redirect('tax.index', ['from' => $from, 'to' => $to]);
        break;

    case 'gunggung':
        Csrf::verify();
        [$from, $to] = $period();
        $res = Faktur::gunggung($pdo, $config, $from, $to, $by);
        audit($pdo, 'tax', 'export', 'faktur_export', $res['id'], "{$from} – {$to}", sprintf('生成 Digunggung 汇总：%d 张发票，PPN 合计 %s', $res['invoices'], idr($res['ppn'])));
        flash(sprintf(t('fk_msg_gunggung'), $res['invoices']));
        redirect('tax.index', ['from' => $from, 'to' => $to]);
        break;

    case 'download':
        $st = $pdo->prepare('SELECT * FROM faktur_exports WHERE id = ?');
        $st->execute([(int) input('id', 0)]);
        $e = $st->fetch();
        if (!$e) {
            http_response_code(404);
            exit('Not found');
        }
        Faktur::send($e);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
