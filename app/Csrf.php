<?php
declare(strict_types=1);

/** Minimal session-based CSRF protection. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (hash_equals(self::token(), (string) $sent)) {
            return;
        }
        // Almost always an expired form, not an attack: the page sat open past
        // the session lifetime (24 min idle), or the user logged in/out in
        // another tab. Reject the submission as before, but send the person
        // back to the page they came from with a message instead of a dead end.
        if (function_exists('flash') && function_exists('t')) {
            flash(t('msg_csrf_expired'), 'error');
        }
        header('Location: ' . self::back_url(), true, 303);
        http_response_code(303);
        exit;
    }

    /** Same-site page the form came from (path + query only), else the app root. */
    public static function back_url(): string
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $parts = $ref !== '' ? parse_url($ref) : false;
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (is_array($parts) && isset($parts['host']) && strtolower($parts['host']) === $host && $host !== '') {
            $path = (string) ($parts['path'] ?? '/');
            // Never bounce back to a POST-only endpoint; the page before it is gone.
            $q = (string) ($parts['query'] ?? '');
            if (!preg_match('/(^|&)r=auth\.authenticate(&|$)/', $q)) {
                return $path . ($q !== '' ? '?' . $q : '');
            }
        }
        return 'index.php';
    }
}
