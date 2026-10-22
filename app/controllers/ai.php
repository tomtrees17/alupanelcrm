<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var array $config */

// 「AI 使用情况」 (FA-AI-00): read-only, for the boss and whoever holds the
// ai_usage permission (default: finance manager). Figures: AiTask::usage().
if (!can_access('ai_usage')) {
    http_response_code(403);
    flash(t('msg_no_module'), 'error');
    redirect('dashboard.index');
}

switch ($action) {
    case 'index':
    case 'usage':
        $month = (string) input('m', date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $months = $pdo->query("SELECT DISTINCT substr(created_at, 1, 7) FROM ai_calls ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
        $months = array_values(array_unique(array_merge([date('Y-m'), $month], $months)));
        rsort($months);
        view('ai.usage', [
            'pageTitle' => t('ai_usage_title'),
            'u'         => AiTask::usage($pdo, $config, $month),
            'months'    => $months,
            'enabled'   => AiTask::enabled($config),
        ]);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
