<?php
declare(strict_types=1);

/** Session-based authentication. */
final class Auth
{
    private PDO $pdo;
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function attempt(string $email, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $this->user = $user;
            $this->loaded = true;
            return true;
        }
        return false;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;

        $id = $_SESSION['user_id'] ?? null;
        if ($id) {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $this->user = $stmt->fetch() ?: null;
        }
        return $this->user;
    }

    public function isAdmin(): bool
    {
        $u = $this->user();
        return $u !== null && $u['role'] === 'admin';
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->user = null;
    }
}
