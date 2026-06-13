<?php
/** @var ?array $customer */
$isEdit = $customer !== null;
$v = fn(string $k) => e($customer[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑客户' : '新建客户' ?></h1>
    <a class="btn btn-ghost" href="<?= url('customers.index') ?>">返回列表</a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'customers.update' : 'customers.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label">客户姓名 *</label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
            <div class="form-group"><label class="form-label">公司名称</label><input class="form-input" name="company" value="<?= $v('company') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">手机号</label><input class="form-input" name="phone" value="<?= $v('phone') ?>" placeholder="08xx 或 +62 8xx"></div>
            <div class="form-group"><label class="form-label">邮箱</label><input class="form-input" type="email" name="email" value="<?= $v('email') ?>"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label">城市</label><input class="form-input" name="city" value="<?= $v('city') ?>"></div>
            <div class="form-group"><label class="form-label">客户标签</label>
                <select class="form-select" name="tag">
                    <?php foreach (['潜在', '重点', '成交', '流失'] as $tg): ?>
                        <option value="<?= $tg ?>" <?= ($customer['tag'] ?? '潜在') === $tg ? 'selected' : '' ?>><?= $tg ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">潜在价值 (Rp)</label><input class="form-input" type="number" name="value" value="<?= $isEdit ? e($customer['value']) : '0' ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">最后跟进日期</label><input class="form-input" type="date" name="last_contact" value="<?= $v('last_contact') ?>"></div>
        <div class="form-group"><label class="form-label">备注</label><textarea class="form-textarea" name="note"><?= $v('note') ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存客户</button></div>
    </form>
</div></div>
