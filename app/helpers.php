<?php
declare(strict_types=1);

/** HTML-escape a value. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Build an in-app URL: url('customers.edit', ['id' => 5]). */
function url(string $route = 'dashboard.index', array $params = []): string
{
    $query = array_merge(['r' => $route], $params);
    return 'index.php?' . http_build_query($query);
}

/** Redirect to an in-app route and stop. */
function redirect(string $route, array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

/** Read a request value (GET or POST). */
function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Format money using the configured currency symbol. */
function money($amount): string
{
    $cur = $GLOBALS['config']['currency'] ?? '';
    return $cur . number_format((float) $amount, 2);
}

/** Set / pull a one-time flash message. */
function flash(?string $message = null, string $type = 'success')
{
    if ($message !== null) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        return null;
    }
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Render a view inside the main layout.
 * $template is relative to /views, e.g. 'customers.index'.
 */
function view(string $template, array $data = [], bool $useLayout = true): void
{
    $path = __DIR__ . '/../views/' . str_replace('.', '/', $template) . '.php';
    if (!is_file($path)) {
        http_response_code(500);
        echo "View not found: {$template}";
        return;
    }

    // Services every view/layout may reference.
    $auth   = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];

    extract($data, EXTR_SKIP);

    ob_start();
    include $path;
    $content = ob_get_clean();

    if (!$useLayout) {
        echo $content;
        return;
    }

    include __DIR__ . '/../views/layout.php';
}

/** Status label/colour map for sales documents. */
function status_label(string $status): string
{
    $map = [
        'draft'     => '草稿',
        'sent'      => '已发送',
        'accepted'  => '已确认',
        'ordered'   => '已下单',
        'completed' => '已完成',
        'cancelled' => '已取消',
    ];
    return $map[$status] ?? $status;
}

function status_list(): array
{
    return ['draft', 'sent', 'accepted', 'ordered', 'completed', 'cancelled'];
}
