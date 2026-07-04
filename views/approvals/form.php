<?php
/** @var ?array $req */
$isEdit = $req !== null;
$type = $req['type'] ?? 'expense';
$act = $isEdit ? url('approvals.update') : url('approvals.store');
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') . ' ' . e($req['req_no']) : t('btn_add_request') ?></h1>
    <div class="head-actions"><a class="btn btn-ghost" href="<?= url('approvals.index') ?>"><?= t('btn_back') ?></a></div>
</div>

<div class="card" style="max-width:640px"><div class="card-body">
    <form method="post" action="<?= $act ?>" id="req-form">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $req['id'] ?>"><?php endif; ?>

        <div class="form-group">
            <label class="form-label"><?= t('f_req_type') ?></label>
            <select class="form-select" name="type" id="req-type">
                <?php foreach (request_types() as $rt): ?>
                    <option value="<?= e($rt) ?>" <?= $type === $rt ? 'selected' : '' ?>><?= e(request_type_label($rt)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label"><?= t('f_req_title') ?></label>
            <input class="form-input" name="title" required value="<?= e($req['title'] ?? '') ?>">
        </div>

        <div class="form-group" data-for="trip">
            <label class="form-label"><?= t('f_destination') ?></label>
            <input class="form-input" name="destination" value="<?= e($req['destination'] ?? '') ?>">
        </div>

        <div class="form-group" data-for="expense">
            <label class="form-label"><?= t('f_category') ?></label>
            <select class="form-select" name="category">
                <?php foreach (expense_categories() as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($req['category'] ?? '') === $c ? 'selected' : '' ?>><?= e(tr_req_cat($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" data-for="leave">
            <label class="form-label"><?= t('f_leave_type') ?></label>
            <select class="form-select" name="category">
                <?php foreach (leave_types() as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($req['category'] ?? '') === $c ? 'selected' : '' ?>><?= e(tr_req_cat($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" data-for="expense">
            <label class="form-label"><?= t('f_expense_date') ?></label>
            <input class="form-input" type="date" name="start_date" value="<?= e($req['start_date'] ?? '') ?>">
        </div>

        <div class="grid-2" data-for="trip leave" style="grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label class="form-label"><?= t('f_start') ?></label>
                <input class="form-input" type="date" name="start_date" value="<?= e($req['start_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('f_end') ?></label>
                <input class="form-input" type="date" name="end_date" value="<?= e($req['end_date'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group" data-for="expense">
            <label class="form-label"><?= t('f_amount') ?></label>
            <input class="form-input" type="number" step="1" min="0" name="amount" value="<?= e($req['amount'] ?? '') ?>">
        </div>
        <div class="form-group" data-for="trip">
            <label class="form-label"><?= t('f_budget') ?></label>
            <input class="form-input" type="number" step="1" min="0" name="amount" value="<?= e($req['amount'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label class="form-label"><?= t('f_reason') ?></label>
            <textarea class="form-textarea" name="reason" rows="3"><?= e($req['reason'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button class="btn btn-ghost" type="submit" name="do" value="draft"><?= t('btn_save_draft') ?></button>
            <button class="btn btn-primary" type="submit" name="do" value="submit"><?= t('btn_save_order') ?></button>
        </div>
    </form>
</div></div>

<script>
(function () {
    var sel = document.getElementById('req-type');
    function upd() {
        document.querySelectorAll('[data-for]').forEach(function (el) {
            var show = el.dataset.for.split(' ').indexOf(sel.value) !== -1;
            el.style.display = show ? '' : 'none';
            // Hidden inputs must not post (duplicate names across type sections).
            el.querySelectorAll('input,select,textarea').forEach(function (inp) { inp.disabled = !show; });
        });
    }
    sel.addEventListener('change', upd);
    upd();
})();
</script>
