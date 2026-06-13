<?php /** @var array $deals */ /** @var float $total */
$byStage = [];
foreach (deal_stages() as $s) { $byStage[$s] = []; }
foreach ($deals as $d) { $byStage[$d['stage']][] = $d; }
$stages = deal_stages();
?>
<div class="page-head">
    <h1>销售漏斗 <span class="muted" style="font-size:13px">总商机价值：<?= idr($total) ?></span></h1>
    <a class="btn btn-primary" href="<?= url('pipeline.create') ?>">＋ 新增商机</a>
</div>

<div class="pipeline-board">
    <?php foreach ($stages as $i => $stage): $color = deal_stage_color($stage); $cards = $byStage[$stage]; ?>
        <div class="pipeline-col">
            <div class="pipeline-col-header">
                <span class="pipeline-col-dot" style="background:<?= $color ?>"></span>
                <span class="pipeline-col-name"><?= e($stage) ?></span>
                <span class="pipeline-col-count"><?= count($cards) ?></span>
            </div>
            <div class="pipeline-cards">
                <?php foreach ($cards as $d): ?>
                    <div class="pipeline-card">
                        <div class="pipeline-card-name"><?= e($d['name']) ?></div>
                        <div class="pipeline-card-company"><?= e($d['customer_name'] ?? '') ?><?= $d['company'] ? ' · ' . e($d['company']) : '' ?></div>
                        <div class="pipeline-card-value"><?= idr($d['value']) ?></div>
                        <div class="pipeline-card-bottom">
                            <span class="pipeline-card-date"><?= e($d['close_date']) ?></span>
                            <a class="muted" href="<?= url('pipeline.edit', ['id' => $d['id']]) ?>" style="font-size:11px">编辑</a>
                        </div>
                        <div class="stage-move">
                            <?php if ($i > 0): ?>
                                <form method="post" action="<?= url('pipeline.move') ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>"><input type="hidden" name="stage" value="<?= e($stages[$i - 1]) ?>"><button type="submit" title="上一阶段">←</button></form>
                            <?php endif; ?>
                            <?php if ($i < count($stages) - 1): ?>
                                <form method="post" action="<?= url('pipeline.move') ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>"><input type="hidden" name="stage" value="<?= e($stages[$i + 1]) ?>"><button type="submit" title="下一阶段">→</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
