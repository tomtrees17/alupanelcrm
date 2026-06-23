<?php
/**
 * CLI password reset for an AluPanel CRM account.
 *
 * Usage (run from the project root):
 *     php tools/reset_password.php                       # list all accounts
 *     php tools/reset_password.php <email> <new-password>
 *
 * On the Baota server, fix file ownership afterwards:
 *     chown -R www:www data && chmod -R 755 data
 *
 * Resets the password hash and clears the must-change-password flag so the
 * chosen password works immediately.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$config = require __DIR__ . '/../config.php';
$dbPath = $config['db_path'] ?? (__DIR__ . '/../data/crm.sqlite');

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found at: {$dbPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$email = $argv[1] ?? '';
$pass  = $argv[2] ?? '';

// No usable arguments → list accounts so the user can pick an email.
if ($email === '' || $pass === '') {
    fwrite(STDERR, "Usage: php tools/reset_password.php <email> <new-password>\n\n");
    fwrite(STDERR, "Existing accounts:\n");
    foreach ($pdo->query('SELECT email, name, role FROM users ORDER BY id') as $r) {
        fwrite(STDERR, "  - {$r['email']}  ({$r['role']}, {$r['name']})\n");
    }
    exit(1);
}

if (mb_strlen($pass) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "No account with email: {$email}\n");
    fwrite(STDERR, "Run without arguments to list available accounts.\n");
    exit(1);
}

$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
    ->execute([password_hash($pass, PASSWORD_DEFAULT), (int) $user['id']]);

echo "Password reset for {$user['name']} <{$email}>. You can log in now.\n";
