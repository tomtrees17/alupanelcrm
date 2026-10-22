<?php /** @var array $t */ /** @var array $allocs */ /** @var array $open */ /** @var ?array $proposal */ /** @var array $propInv */ /** @var bool $aiOn */ ?>
<?php
$money = fn(float $v): string => number_format($v, 2, ',', '.');
$plain = fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$pending = in_array($t['match_status'], ['unmatched', 'rule', 'ai'], true) && $t['direction'] === 'in';
$noteLabel = function (?string $n): string {
    if ($n === null || $n === '') {
        return '';
    }
    [$k, $v] = array_pad(explode(':', $n, 2), 2, '');
    return t('bank_note_' . str_replace('+', '_', $k)) . ($v !== '' ? '：' . $v : '');
};
$lines = $proposal['lines'] ?? [];
?>
<div class="page-head">
    <div>
        <h1><?= e((string) $t['trx_code']) ?> · <?= e($t['bank'] . ' …' . $t['last4']) ?></h1>
        <p class="muted"><?= e(Hr::dmy($t['trx_date'])) ?> · <?= t($t['direction'] === 'in' ? 'bank_th_in' : 'bank_th_out') ?> <strong>Rp <?= $money((float) $t['amount']) ?></strong> · <?= t('bank_tag_' . $t['tag']) ?> · <span class="tag tag-gray"><?= t('bank_st_' . $t['match_status']) ?></span></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('bank.index', ['acc' => $t['account_id'], 'm' => substr((string) $t['trx_date'], 0, 7)]) ?>"><?= t('bank_title') ?></a>
</div>

<div class="card" style="padding:16px">
    <div class="small muted"><?= t('bank_th_desc') ?></div>
    <div style="font-family:monospace;white-space:pre-wrap;word-break:break-word"><?= e($t['description']) ?></div>
    <div class="small" style="margin-top:8px"><?= t('bank_th_payer') ?>：<strong><?= e((string) $t['counterparty_name']) ?: '—' ?></strong>
        <?php if ($t['balance'] !== null): ?> · <?= t('bank_th_balance') ?> <?= $money((float) $t['balance']) ?><?php endif; ?></div>
</div>

<?php if ($allocs): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('bank_booked') ?></span><span class="muted small"><?= e((string) $t['confirmed_by']) ?> · <?= e((string) $t['confirmed_at']) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('bank_th_invoices') ?></th><th><?= t('th_customer') ?></th><th class="right"><?= t('bank_th_amount') ?></th><th class="right"><?= t('bank_th_diff') ?></th><th><?= t('bank_diff_label') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($allocs as $a): ?>
            <tr>
                <td><a href="<?= url('finance.show', ['id' => $a['invoice_id']]) ?>"><?= e($a['invoice_no']) ?></a></td>
                <td><?= e((string) $a['customer']) ?></td>
                <td class="right num"><?= $money((float) $a['amount']) ?></td>
                <td class="right num"><?= abs((float) $a['diff_amount']) < 0.005 ? '—' : $money((float) $a['diff_amount']) ?></td>
                <td><?= t('bank_diff_' . $a['diff_reason']) ?></td>
                <td class="small">
                    <?php if ($a['diff_reason'] === 'overpaid'): ?>
                        <?php if ($a['refund_code']): ?><?= t('bank_refund') ?>：<?= e($a['refund_code']) ?>
                        <?php else: ?>
                            <form method="post" action="<?= url('bank.refund') ?>" class="inline-form">
                                <?= Csrf::field() ?><input type="hidden" name="alloc" value="<?= (int) $a['id'] ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <input class="form-input" name="code" placeholder="BCA-OUT-…" style="max-width:150px">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= t('bank_btn_refund') ?></button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($a['diff_reason'] === 'withholding'): ?>
                        <?php if ($a['bukti_potong_at']): ?><?= t('bank_bp') ?> ✓ <?= e(substr((string) $a['bukti_potong_at'], 0, 10)) ?>
                        <?php else: ?>
                            <form method="post" action="<?= url('bank.bp') ?>" class="inline-form">
                                <?= Csrf::field() ?><input type="hidden" name="alloc" value="<?= (int) $a['id'] ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <span class="tag tag-orange"><?= t('bank_bp_pending') ?></span> <button class="btn btn-ghost btn-sm" type="submit"><?= t('bank_btn_bp') ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($t['match_status'] === 'non_ar'): ?>
<div class="card" style="padding:16px">
    <?= t('bank_st_non_ar') ?>：<strong><?= t('bank_nonar_' . ($t['non_ar_reason'] ?: 'interest')) ?></strong>
    <span class="muted small"><?= e((string) $t['confirmed_by']) ?> <?= e((string) $t['confirmed_at']) ?></span>
    <?php if ($t['tag'] !== 'interest'): ?>
        <form method="post" action="<?= url('bank.unmark') ?>" style="display:inline;margin-left:8px">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= t('bank_btn_unmark') ?></button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pending): ?>
    <?php if ($proposal): ?>
    <div class="card" style="padding:16px">
        <div><strong><?= t($proposal['source'] === 'ai' ? 'bank_src_ai' : 'bank_src_rule') ?></strong>
            <?php if ($proposal['source'] === 'ai' && $t['ai_confidence']): ?> · <?= t('bank_confidence') ?>：<span class="tag <?= $t['ai_confidence'] === 'high' ? 'tag-green' : ($t['ai_confidence'] === 'low' ? 'tag-red' : 'tag-orange') ?>"><?= t('bank_conf_' . $t['ai_confidence']) ?></span><?php endif; ?>
            <?php if ($t['match_note']): ?> · <span class="muted"><?= e($noteLabel($t['match_note'])) ?></span><?php endif; ?></div>
        <?php if ($proposal['source'] === 'ai' && $t['ai_reason']): ?><div class="small" style="margin-top:6px"><?= t('bank_reason') ?>：<?= e((string) $t['ai_reason']) ?></div><?php endif; ?>
        <div class="small" style="margin-top:6px"><?= t('bank_diff_label') ?>：<?= t('bank_diff_' . $proposal['diff_reason']) ?><?php if (abs((float) $proposal['diff_amount']) >= 0.005): ?>（<?= $money((float) $proposal['diff_amount']) ?>）<?php endif; ?></div>
    </div>
    <?php elseif ($t['ai_status'] && $t['ai_status'] !== 'ok'): ?>
    <div class="card" style="padding:16px"><span class="muted"><?= t('bank_ai_' . $t['ai_status']) ?></span><?php if ($t['ai_reason']): ?> · <span class="small"><?= e((string) $t['ai_reason']) ?></span><?php endif; ?></div>
    <?php endif; ?>

    <div class="card" style="padding:16px">
        <div class="card-title" style="margin-bottom:8px"><?= t($proposal ? 'bank_confirm_title' : 'bank_match_manual') ?></div>
        <form method="post" action="<?= url('bank.confirm') ?>" id="confirmForm">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <table style="width:auto">
                <thead><tr><th><?= t('bank_th_invoices') ?></th><th><?= t('bank_th_amount') ?> (Rp)</th><th class="muted small"></th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <?php $l = $lines[$i] ?? null; $pi = $l ? ($propInv[(int) $l['invoice_id']] ?? null) : null; ?>
                    <tr<?= $i >= max(2, count($lines) + 1) ? ' class="extra-line" style="display:none"' : '' ?>>
                        <td><input class="form-input" name="inv[]" list="openInv" value="<?= e($pi['invoice_no'] ?? '') ?>" style="min-width:230px"></td>
                        <td><input class="form-input amt" name="amt[]" type="number" step="0.01" min="0" value="<?= $l ? e($plain((float) $l['amount'])) : '' ?>" style="max-width:170px"></td>
                        <td class="small muted"><?php if ($pi): ?><?= e((string) $pi['customer']) ?> · <?= t('bank_owed') ?> <?= $money((float) $pi['total'] - (float) $pi['amount_paid']) ?><?php endif; ?></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <a href="#" class="small" onclick="var r=document.querySelector('.extra-line[style]');if(r){r.removeAttribute('style');}return false;"><?= t('bank_add_line') ?></a>
            <div class="small" style="margin:8px 0"><?= t('bank_sum_hint') ?> <strong>Rp <?= $money((float) $t['amount']) ?></strong> · <span id="sumLeft"></span></div>
            <div class="form-group" style="max-width:420px">
                <label class="form-label"><?= t('bank_diff_label') ?></label>
                <select class="form-input" name="diff">
                    <?php foreach (Bank::DIFF as $d): ?><option value="<?= $d ?>"<?= ($proposal['diff_reason'] ?? 'none') === $d ? ' selected' : '' ?>><?= t('bank_diff_' . $d) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" type="submit"><?= t('bank_btn_confirm') ?></button>
        </form>
        <datalist id="openInv">
            <?php foreach ($open as $o): ?><option value="<?= e($o['invoice_no']) ?>"><?= e(trim($o['customer'] . ' · ' . number_format((float) $o['balance'], 2, ',', '.'))) ?></option><?php endforeach; ?>
        </datalist>
    </div>

    <div class="card" style="padding:16px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
        <form method="post" action="<?= url('bank.mark') ?>" class="inline-form">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <select class="form-input" name="reason"><?php foreach (Bank::NON_AR as $r): ?><option value="<?= $r ?>"><?= t('bank_nonar_' . $r) ?></option><?php endforeach; ?></select>
            <button class="btn btn-ghost" type="submit"><?= t('bank_btn_mark') ?></button>
        </form>
        <?php if ($aiOn): ?>
        <form method="post" action="<?= url('bank.ai_one') ?>" class="inline-form">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-ghost" type="submit"<?= $t['match_status'] !== 'unmatched' ? ' disabled' : '' ?>><?= t('bank_btn_ai_one') ?></button>
        </form>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        var total = <?= json_encode((float) $t['amount']) ?>, out = document.getElementById('sumLeft');
        function upd() {
            var s = 0;
            document.querySelectorAll('#confirmForm .amt').forEach(function (i) { s += parseFloat(i.value || '0') || 0; });
            var d = Math.round((total - s) * 100) / 100;
            out.textContent = d === 0 ? '✓' : (<?= json_encode(t('bank_sum_left')) ?> + ' ' + d.toLocaleString('id-ID', {minimumFractionDigits: 2}));
        }
        document.querySelectorAll('#confirmForm .amt').forEach(function (i) { i.addEventListener('input', upd); });
        upd();
    })();
    </script>
<?php endif; ?>
