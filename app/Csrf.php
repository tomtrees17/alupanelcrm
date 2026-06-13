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
        if (!hash_equals(self::token(), (string) $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch. 请刷新页面重试。');
        }
    }
}
