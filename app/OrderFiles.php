<?php
declare(strict_types=1);

/**
 * CO-02 customer confirmation screenshots on an order (docs/任务卡-客户确认截图.md).
 * The sales assistant attaches the WhatsApp chat where the customer confirmed
 * the CO; the CO then prints ATTACHED in the client's signature box. Optional:
 * the approver only sees a reminder when there is none (owner 2026-11-03).
 *
 * Files live in data/orders/<order id>/ (private, served through
 * orders.attachment after the order's own access check), never deleted:
 * "remove" only hides a row while the order is still editable.
 */
final class OrderFiles
{
    public const MAX_PER_ORDER = 5;

    /** Tests point this at a temp dir so a run on the server never writes into data/. */
    public static ?string $root = null;

    public static function dir(int $orderId): string
    {
        return (self::$root ?? dirname(__DIR__) . '/data/orders') . '/' . $orderId;
    }

    public static function ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, stored TEXT NOT NULL, name TEXT NOT NULL, mime TEXT NOT NULL,
            size INTEGER NOT NULL, uploaded_by TEXT NOT NULL, uploaded_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            removed_by TEXT, removed_at TEXT)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_attach ON order_attachments(order_id)');
    }

    /** Shown (not removed) screenshots of an order. */
    public static function list(PDO $pdo, int $orderId): array
    {
        $st = $pdo->prepare('SELECT * FROM order_attachments WHERE order_id = ? AND removed_at IS NULL ORDER BY id');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function count(PDO $pdo, int $orderId): int
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM order_attachments WHERE order_id = ? AND removed_at IS NULL');
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Store the images from a multi-file input ($_FILES[...] with name[] etc.).
     * Returns [added count, error keys]. Images only (JPG / PNG, by content), up to
     * MAX_PER_ORDER shown per order; each upload is audited.
     */
    public static function accept(PDO $pdo, array $order, ?array $field, string $by): array
    {
        $files = [];
        if ($field !== null && is_array($field['name'] ?? null)) {
            foreach (array_keys($field['name']) as $i) {
                $files[] = ['name' => $field['name'][$i], 'tmp_name' => $field['tmp_name'][$i] ?? '', 'size' => $field['size'][$i] ?? 0, 'error' => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE];
            }
        } elseif ($field !== null && isset($field['name'])) {
            $files[] = $field;
        }
        $added = 0;
        $errors = [];
        $have = self::count($pdo, (int) $order['id']);
        $ins = $pdo->prepare('INSERT INTO order_attachments (order_id, stored, name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?)');
        foreach ($files as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($have >= self::MAX_PER_ORDER) {
                $errors['oa_err_max'] = true;
                continue;
            }
            $r = Hr::accept_upload($f, 0, self::dir((int) $order['id']), ['jpg', 'jpeg', 'png']);
            if (isset($r['error'])) {
                $errors[$r['error'] === 'hr_err_too_big' ? 'oa_err_big' : 'oa_err_type'] = true;
                continue;
            }
            $ins->execute([(int) $order['id'], $r['stored'], $r['name'], $r['mime'], $r['size'], $by]);
            audit($pdo, 'orders', 'attach', 'order', (int) $order['id'], (string) $order['order_no'], '上传客户确认截图: ' . $r['name']);
            $have++;
            $added++;
        }
        return [$added, array_keys($errors)];
    }

    /** Hide one screenshot (the file and row stay); true when something was hidden. */
    public static function remove(PDO $pdo, array $order, int $attId, string $by): bool
    {
        $st = $pdo->prepare("UPDATE order_attachments SET removed_at = datetime('now','localtime'), removed_by = ? WHERE id = ? AND order_id = ? AND removed_at IS NULL");
        $st->execute([$by, $attId, (int) $order['id']]);
        if ($st->rowCount() === 0) {
            return false;
        }
        $n = $pdo->prepare('SELECT name FROM order_attachments WHERE id = ?');
        $n->execute([$attId]);
        audit($pdo, 'orders', 'detach', 'order', (int) $order['id'], (string) $order['order_no'], '撤下客户确认截图: ' . (string) $n->fetchColumn() . " (#{$attId})");
        return true;
    }

    /** One row of this order (removed ones too: still viewable by id for the record). */
    public static function find(PDO $pdo, int $orderId, int $attId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM order_attachments WHERE id = ? AND order_id = ?');
        $st->execute([$attId, $orderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
