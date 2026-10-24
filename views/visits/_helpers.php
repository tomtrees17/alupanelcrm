<?php
// Shared bits of the 拜访 pages (HR-04).
$outcomeTag = fn(string $o): string => '<span class="tag ' . (['berminat' => 'tag-green', 'penawaran' => 'tag-blue', 'tidak_butuh' => 'tag-gray', 'tidak_terhubung' => 'tag-orange'][$o] ?? 'tag-gray') . '">'
    . e(Visits::OUTCOMES[$o] ?? $o) . '</span>';
$targetLabel = fn(array $v): string => e(Visits::target_name($v)) . ' <span class="muted small">' . e(t('vistype_' . $v['target_type'])) . '</span>';
