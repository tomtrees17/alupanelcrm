<?php

// ── Renders views/audit/index.php for real and inspects the HTML ──
// Catches template-level breakage (undefined helper, bad array key, escaping
// slips) that a pure data-layer test would miss.

$viewFile = __DIR__ . '/../views/audit/index.php';
ok('audit view file exists', is_file($viewFile));

/** Render the view in isolation and fail loudly on any notice/warning. */
$render = function (array $data) use ($viewFile): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool {
        $errors[] = $msg;
        return true;
    });
    // view() injects these into every template; mirror it or the render diverges.
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($data, EXTR_SKIP);
    ob_start();
    include $viewFile;
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};

$base = [
    'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1,
    'module' => '', 'act' => '', 'userId' => 0, 'from' => '', 'to' => '', 'q' => '',
    'users' => [['id' => 3, 'name' => 'Ahmad']],
];

// 1) Empty state.
$html = $render($base);
ok('empty state renders', str_contains($html, t('audit_none')));
ok('filter dropdowns rendered', str_contains($html, 'name="module"') && str_contains($html, 'name="action_f"') && str_contains($html, 'name="user_id"'));
ok('no pager when single page', !str_contains($html, 'class="pager"'));
ok('no reset button without filters', !str_contains($html, t('audit_reset')));

// 2) Populated, paginated, filtered.
$rows = [
    [
        'id' => 2, 'created_at' => '2026-08-28 10:00:00', 'user_id' => 3, 'user_name' => 'Ahmad',
        'user_role' => 'sales', 'module' => 'orders', 'action' => 'delete', 'entity' => 'order',
        'entity_id' => 5, 'label' => 'SO-0005', 'detail' => '客户: PT Maju; 状态: 草稿', 'ip' => '10.0.0.1',
    ],
    [
        'id' => 1, 'created_at' => '2026-08-28 09:00:00', 'user_id' => null, 'user_name' => '',
        'user_role' => '', 'module' => 'auth', 'action' => 'login_failed', 'entity' => 'user',
        'entity_id' => null, 'label' => 'attacker@example.com', 'detail' => '密码错误或账号不存在', 'ip' => '1.2.3.4',
    ],
];
$html = $render(array_merge($base, [
    'rows' => $rows, 'total' => 120, 'page' => 2, 'pages' => 3, 'module' => 'orders', 'q' => 'SO',
]));
ok('rows rendered', str_contains($html, 'SO-0005') && str_contains($html, 'attacker@example.com'));
ok('actor and role shown', str_contains($html, 'Ahmad') && str_contains($html, role_label('sales')));
ok('anonymous actor shown as dash', str_contains($html, '—'));
ok('action tag coloured', str_contains($html, 'tag-red'));
ok('total count shown', str_contains($html, sprintf(t('audit_total'), 120)));
ok('pager shown with prev+next', str_contains($html, 'class="pager"') && str_contains($html, t('audit_prev')) && str_contains($html, t('audit_next')));
ok('pager keeps active filters', str_contains($html, 'module=orders') && str_contains($html, 'page=3'));
ok('reset button shown when filtered', str_contains($html, t('audit_reset')));
ok('selected module preserved in dropdown', str_contains($html, 'value="orders" selected'));

// 3) Hostile content must be escaped, not executed.
$evil = array_merge($rows[0], [
    'label'     => '<script>alert(1)</script>',
    'detail'    => '负责销售: "a" → \'b\' & <b>c</b>',
    'user_name' => '<img src=x onerror=alert(2)>',
]);
$html = $render(array_merge($base, ['rows' => [$evil], 'total' => 1]));
ok('script tag escaped', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));
ok('img onerror escaped', !str_contains($html, '<img src=x'));
ok('quotes and ampersands escaped', str_contains($html, '&amp;') && str_contains($html, '&quot;'));

// 4) The keyword the user typed must survive round-trip into the input, escaped.
$html = $render(array_merge($base, ['q' => '"><script>x</script>']));
ok('search term escaped in input', !str_contains($html, '"><script>') && str_contains($html, '&quot;&gt;&lt;script&gt;'));

// 5) Both languages render (id has no fallback gaps for the audit keys).
$_SESSION['lang'] = 'id';
$html = $render(array_merge($base, ['rows' => $rows, 'total' => 2]));
ok('Indonesian renders without missing keys', !str_contains($html, 'audit_mod_') && !str_contains($html, 'audit_act_') && str_contains($html, 'Log Audit'));
$_SESSION['lang'] = 'zh';
