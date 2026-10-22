<?php /** @var array $docs */ /** @var array $items */ /** @var string $q */ /** @var string $year */ /** @var array $pg */ /** @var array $years */ /** @var float $sum */ ?>
<div class="page-head">
    <div>
        <h1><?= t('hist_title') ?></h1>
        <p class="muted"><?= t('hist_sub') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('finance.yearly', $year !== '' ? ['year' => $year] : []) ?>"><?= t('yr_link_yearly') ?></a>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="finance.history">
    <select class="form-input" name="year" style="max-width:160px" onchange="this.form.submit()">
        <option value=""><?= t('yr_all_years') ?></option>
        <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= (string) $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
    </select>
    <input class="form-input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('hist_search') ?>">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= url('finance.history', array_filter(['year' => $year])) ?>"><?= t('btn_clear') ?></a><?php endif; ?>
</form>

<p class="muted small"><?= t('hist_sum') ?>: <strong><?= idr($sum) ?></strong></p>

<div class="card"><div class="table-wrap"><table>
    <thead><tr>
        <th><?= t('th_date') ?></th><th><?= t('hist_doc_no') ?></th><th><?= t('th_customer') ?></th>
        <th><?= t('hist_salesperson') ?></th><th><?= t('th_product') ?></th>
        <th class="right"><?= t('hist_sheets') ?></th><th class="right"><?= t('th_amount') ?></th>
    </tr></thead>
    <tbody>
    <?php if (!$docs): ?><tr><td colspan="7" class="empty"><?= t('hist_no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($docs as $d): ?>
        <tr>
            <td><?= e($d['doc_date']) ?></td>
            <td class="muted small"><?= e($d['doc_no']) ?></td>
            <td>
                <?php if ($d['customer_id'] !== null && can_access('customers')): ?>
                    <a href="<?= url('customers.show', ['id' => $d['customer_id']]) ?>"><?= e($d['customer_now'] ?? $d['customer_name']) ?></a>
                    <?php if (($d['customer_now'] ?? '') !== '' && $d['customer_now'] !== $d['customer_name']): ?><div class="muted small"><?= e($d['customer_name']) ?></div><?php endif; ?>
                <?php else: ?>
                    <?= e($d['customer_name']) ?>
                <?php endif; ?>
                <?php if (($d['note'] ?? '') !== ''): ?><div class="muted small"><?= e($d['note']) ?></div><?php endif; ?>
            </td>
            <td><?= e($d['submitter'] ?? $d['salesperson'] ?? '') ?><?php if (($d['submitter'] ?? '') !== ($d['salesperson'] ?? '') && ($d['salesperson'] ?? '') !== ''): ?> <span class="muted small">(<?= e($d['salesperson']) ?>)</span><?php endif; ?></td>
            <td class="small">
                <?php foreach ($items[(int) $d['id']] ?? [] as $it): ?>
                    <div><?= e(trim(($it['sku'] ?? '') . ' ' . ($it['color'] ?? '') . ' ' . ($it['spec'] ?? ''))) ?> × <?= num($it['qty']) ?><?php if (($it['note'] ?? '') !== ''): ?> <span class="muted">(<?= e($it['note']) ?>)</span><?php endif; ?></div>
                <?php endforeach; ?>
            </td>
            <td class="right"><?= num($d['sheets'] ?? 0) ?></td>
            <td class="right"><strong><?= idr($d['amount']) ?></strong><?php if ((float) $d['shipping'] > 0): ?><div class="muted small">+ <?= idr($d['shipping']) ?></div><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?= pager('finance.history', ['q' => $q, 'year' => $year], $pg) ?>
