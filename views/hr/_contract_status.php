<?php
// Status tag for a contract (HR-02).
$contractTag = fn(string $s): string => '<span class="tag ' . ([
    'draft' => 'tag-gray', 'active' => 'tag-green', 'expired' => 'tag-orange', 'terminated' => 'tag-red', 'converted' => 'tag-blue',
][$s] ?? 'tag-gray') . '">' . e(t('hrcstatus_' . $s)) . '</span>';
