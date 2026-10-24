<?php /** @var ?array $v */ /** @var string $today */
// The visit form = one row of the Excel 回收表: who → result → (note) → save.
$isNew = $v === null;
$old = $_SESSION['visit_form_old'] ?? [];
unset($_SESSION['visit_form_old']);
$val = fn(string $k, string $d = '') => (string) ($old[$k] ?? ($v[$k] ?? $d));
$outcome = $val('outcome');
$date = $isNew ? (string) ($old['visit_date'] ?? $today) : substr((string) $v['visited_at'], 0, 10);
?>
<div class="page-head">
    <h1><?= $isNew ? t('vis_new') : e($v['visit_no']) ?></h1>
    <a class="btn btn-ghost" href="<?= url('visits.index') ?>"><?= t('btn_back') ?></a>
</div>
<form method="post" action="<?= url($isNew ? 'visits.store' : 'visits.update') ?>" enctype="multipart/form-data" id="vf" class="vis-form">
    <?= Csrf::field() ?>
    <?php $repick = !$isNew && ($v['source'] ?? '') === 'wa_import'; ?>
    <?php if ($repick): ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><?php endif; ?>
    <?php if ($isNew || $repick): ?>
        <input type="hidden" name="target_type" id="vf-type" value="<?= e($isNew ? $val('target_type') : (in_array($v['target_type'], ['customer', 'prospect'], true) ? $v['target_type'] : '')) ?>">
        <input type="hidden" name="target_id" id="vf-id" value="<?= e($isNew ? $val('target_id') : (string) ($v['customer_id'] ?: $v['prospect_id'])) ?>">
        <input type="hidden" name="lat" id="vf-lat"><input type="hidden" name="lng" id="vf-lng"><input type="hidden" name="accuracy" id="vf-acc">
        <label class="form-label" for="vf-q"><?= t('vis_who') ?></label>
        <div class="vis-chosen" id="vf-chosen" hidden><span id="vf-chosen-name"></span> <button type="button" class="btn btn-ghost btn-sm" id="vf-clear">×</button></div>
        <input class="form-input vis-q" name="q" id="vf-q" value="<?= e($isNew ? $val('q') : Visits::target_name($v)) ?>" autocomplete="off" placeholder="<?= t('vis_who_ph') ?>">
        <div class="vis-results" id="vf-results"></div>
        <?php if ($repick): ?><p class="muted small"><?= t('vis_store_written') ?>: <?= e((string) $v['store_name_raw']) ?> · <?= t('vis_repick_hint') ?></p><?php endif; ?>
    <?php else: ?>
        <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
        <div class="form-label"><?= t('vis_who') ?></div>
        <p><strong><?= e(Visits::target_name($v)) ?></strong> <span class="muted small"><?= t('vistype_' . $v['target_type']) ?></span></p>
    <?php endif; ?>

    <div class="form-label"><?= t('vis_outcome') ?></div>
    <div class="vis-outcomes">
        <?php foreach (Visits::OUTCOMES as $k => $label): ?>
            <label class="vis-outcome vis-o-<?= $k ?>"><input type="radio" name="outcome" value="<?= $k ?>" <?= $outcome === $k ? 'checked' : '' ?> required><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
    </div>

    <div class="vis-tb" id="vf-tb" <?= $outcome === 'tidak_butuh' ? '' : 'hidden' ?>>
        <input class="form-input" name="current_supplier" value="<?= e($val('current_supplier')) ?>" placeholder="<?= t('vis_supplier') ?>">
        <input class="form-input" name="current_price" value="<?= e($val('current_price')) ?>" placeholder="<?= t('vis_price') ?>">
    </div>
    <textarea class="form-input" name="note" rows="2" placeholder="<?= t('vis_note_ph') ?>"><?= e($val('note')) ?></textarea>

    <details class="vis-more" <?= $date !== $today ? 'open' : '' ?>>
        <summary><?= t('vis_date') ?>: <span id="vf-date-label"><?= $date === $today ? t('vis_today') : e(Hr::dmy($date)) ?></span></summary>
        <input class="form-input" type="date" name="visit_date" value="<?= e($date) ?>" min="<?= e(HrContract::add_days($today, -Visits::BACKDATE_DAYS)) ?>" max="<?= e($today) ?>">
    </details>

    <button class="btn btn-primary vis-big" type="submit"><?= t('btn_save') ?></button>

    <label class="vis-photo"><?= t('vis_photo_opt') ?><?= !$isNew && $v['photo_path'] ? ' · <a href="' . url('visits.photo', ['id' => $v['id']]) . '" target="_blank" rel="noopener">' . e($v['photo_name']) . '</a>' : '' ?>
        <input type="file" name="photo" accept="image/jpeg,image/png" capture="environment"></label>
</form>
<script>
(function () {
    const form = document.getElementById('vf');
    // Tidak Butuh: two more (optional) boxes.
    form.querySelectorAll('input[name=outcome]').forEach(r => r.addEventListener('change', () => {
        document.getElementById('vf-tb').hidden = form.outcome.value !== 'tidak_butuh';
    }));
    const q = document.getElementById('vf-q');
    if (!q) return;
    // Location: only if the browser already allows it — never ask.
    if (navigator.permissions && navigator.geolocation) {
        navigator.permissions.query({name: 'geolocation'}).then(p => {
            if (p.state !== 'granted') return;
            navigator.geolocation.getCurrentPosition(pos => {
                document.getElementById('vf-lat').value = pos.coords.latitude;
                document.getElementById('vf-lng').value = pos.coords.longitude;
                document.getElementById('vf-acc').value = Math.round(pos.coords.accuracy);
            }, () => {}, {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000});
        }).catch(() => {});
    }
    // Pick from my customers / prospects; a typed name nobody matches is a new customer.
    const type = document.getElementById('vf-type'), id = document.getElementById('vf-id');
    const box = document.getElementById('vf-results'), chosen = document.getElementById('vf-chosen');
    const kinds = <?= json_encode(['customer' => t('vistype_customer'), 'prospect' => t('vistype_prospect')], JSON_UNESCAPED_UNICODE) ?>;
    const choose = (r) => {
        type.value = r.type; id.value = r.id; q.value = r.name;
        document.getElementById('vf-chosen-name').textContent = '✓ ' + r.name + ' · ' + kinds[r.type];
        chosen.hidden = false; q.hidden = true; box.innerHTML = '';
    };
    document.getElementById('vf-clear').addEventListener('click', () => {
        type.value = ''; id.value = ''; q.value = ''; chosen.hidden = true; q.hidden = false; q.focus(); load('');
    });
    let timer = null, seq = 0;
    const load = (term) => {
        const my = ++seq;
        fetch(<?= json_encode(url('visits.search')) ?> + '&q=' + encodeURIComponent(term), {credentials: 'same-origin'})
            .then(r => r.json()).then(list => {
                if (my !== seq) return;
                box.innerHTML = '';
                list.forEach(r => {
                    const b = document.createElement('button');
                    b.type = 'button'; b.className = 'vis-result';
                    b.innerHTML = '<strong></strong><span class="muted small"></span>';
                    b.querySelector('strong').textContent = r.name;
                    b.querySelector('span').textContent = ' ' + kinds[r.type] + (r.sub ? ' · ' + r.sub : '');
                    b.addEventListener('click', () => choose(r));
                    box.appendChild(b);
                });
                if (term !== '' && !list.length) {
                    box.innerHTML = '<div class="muted small vis-newhint"></div>';
                    box.firstChild.textContent = <?= json_encode(t('vis_new_customer_hint'), JSON_UNESCAPED_UNICODE) ?>;
                }
            }).catch(() => {});
    };
    q.addEventListener('input', () => { type.value = ''; id.value = ''; clearTimeout(timer); timer = setTimeout(() => load(q.value.trim()), 250); });
    q.addEventListener('focus', () => { if (!q.value.trim()) load(''); });
    if (type.value && id.value && q.value) choose({type: type.value, id: id.value, name: q.value});
})();
</script>
