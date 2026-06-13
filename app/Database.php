<?php
declare(strict_types=1);

/**
 * SQLite connection + schema bootstrap.
 */
final class Database
{
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Create tables + seed data on first run (idempotent).
     */
    public static function migrate(PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn();

        if ($exists) {
            return;
        }

        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
        $pdo->exec($schema);

        self::seed($pdo);
    }

    private static function seed(PDO $pdo): void
    {
        // Default admin account.
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Administrator',
            'admin@alupanel.local',
            password_hash('admin123', PASSWORD_DEFAULT),
            'admin',
        ]);

        // Sample products (aluminum sign panels).
        $products = [
            ['ACP-3MM-SILVER', '3mm 铝塑复合板 - 银色', '标准 ACP 板材，PE 涂层', '1220×2440mm, 0.3mm 铝层', 'sheet', 86.00],
            ['ACP-4MM-WHITE',  '4mm 铝塑复合板 - 白色', '广告级 ACP 板材，PVDF 涂层', '1220×2440mm, 0.4mm 铝层', 'sheet', 128.00],
            ['ALU-SIGN-A4',    '铝标牌 A4 空白', '阳极氧化铝标牌坯料', '210×297mm, 1.0mm', 'pc', 12.50],
            ['BRUSHED-ALU',    '拉丝铝板', '装饰拉丝铝面板', '1000×2000mm, 2.0mm', 'sheet', 210.00],
        ];
        $ps = $pdo->prepare(
            'INSERT INTO products (sku, name, description, spec, unit, price) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($products as $p) {
            $ps->execute($p);
        }

        // Sample customer.
        $cs = $pdo->prepare(
            'INSERT INTO customers (company, contact_name, email, phone, city, country, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $cs->execute([
            '城市广告标识有限公司', '李经理', 'li@example.com', '13800000000',
            '深圳', '中国', '老客户，主营户外广告招牌。',
        ]);
    }
}
