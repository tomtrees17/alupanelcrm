<?php
// Status tag for a BPJS record (HR-03).
$bpjsTag = fn(?string $s): string => $s === null ? '<span class="muted">—</span>' : '<span class="tag ' . ([
    'pending' => 'tag-red', 'active' => 'tag-green', 'pending_stop' => 'tag-orange', 'stopped' => 'tag-gray',
][$s] ?? 'tag-gray') . '">' . e(t('hrbstatus_' . $s)) . '</span>';
