<?php
/** @var array $rows */ /** @var int $total */ /** @var int $page */ /** @var int $pages */
/** @var string $module */ /** @var string $act */ /** @var int $userId */
/** @var string $from */ /** @var string $to */ /** @var string $q */ /** @var array $users */
$filters = array_filter([
    'module' => $module, 'action_f' => $act, 'user_id' => $userId ?: '',
    'from' => $from, 'to' => $to, 'q' => $q,
], fn($v) => $v !== '' && $v !== 0);
$hasFilter = $filters !== [];
?>
<div class="page-head">
    <h1><?= t('page_audit') ?></h1>
    <div class="head-actions muted"><?= sprintf(t('audit_total'), $total) ?></div>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="audit.index">
    <input class="form-input" type="text" name="q" value="<?= e($q) ?>" placeholder="<?= t('audit_keyword') ?>...">
    <select class="form-select filter-select" name="module" onchange="this.form.submit()">
        <option value=""><?= t('audit_all_modules') ?></option>
        <?php foreach (audit_modules() as $m): ?>
            <option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e(tr_audit_module($m)) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="action_f" onchange="this.form.submit()">
        <option value=""><?= t('audit_all_actions') ?></option>
        <?php foreach (audit_actions() as $a): ?>
            <option value="<?= e($a) ?>" <?= $act === $a ? 'selected' : '' ?>><?= e(tr_audit_action($a)) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="user_id" onchange="this.form.submit()">
        <option value=""><?= t('audit_all_users') ?></option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <input class="form-input filter-select" type="date" name="from" value="<?= e($from) ?>" title="<?= t('audit_from') ?>">
    <input class="form-input filter-select" type="date" name="to" value="<?= e($to) ?>" title="<?= t('audit_to') ?>">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
    <?php if ($hasFilter): ?>
        <a class="btn btn-ghost" href="<?= url('audit.index') ?>"><?= t('audit_reset') ?></a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-wrap"><table>
        <thead>
        <tr>
            <th><?= t('audit_time') ?></th>
            <th><?= t('audit_user') ?></th>
            <th><?= t('audit_module') ?></th>
            <th><?= t('audit_action') ?></th>
            <th><?= t('audit_target') ?></th>
            <th><?= t('audit_detail') ?></th>
            <th><?= t('audit_ip') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7"><div class="empty"><?= t('audit_none') ?></div></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e($r['created_at']) ?></td>
                <td>
                    <?= e($r['user_name'] !== '' ? $r['user_name'] : '—') ?>
                    <?php if (!empty($r['user_role'])): ?>
                        <div class="muted small"><?= e(role_label((string) $r['user_role'])) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e(tr_audit_module((string) $r['module'])) ?></td>
                <td><span class="tag <?= audit_action_class((string) $r['action']) ?>"><?= e(tr_audit_action((string) $r['action'])) ?></span></td>
                <td><?= e($r['label']) ?></td>
                <td class="audit-detail"><?= e($r['detail']) ?></td>
                <td class="muted small"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?>
            <a class="btn btn-ghost" href="<?= url('audit.index', array_merge($filters, ['page' => $page - 1])) ?>"><?= t('audit_prev') ?></a>
        <?php endif; ?>
        <span class="muted"><?= sprintf(t('audit_page'), $page, $pages) ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn-ghost" href="<?= url('audit.index', array_merge($filters, ['page' => $page + 1])) ?>"><?= t('audit_next') ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>
