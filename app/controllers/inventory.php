<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

$fields = ['sku', 'name', 'color_zh', 'color_en', 'spec', 'size', 'category', 'unit', 'price', 'stock', 'min_stock'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        $cat = trim((string) input('cat', ''));
        $low = (string) input('low', '') === '1';
        $sql = 'SELECT * FROM products';
        $cond = [];
        $args = [];
        if ($q !== '') {
            $cond[] = '(sku LIKE ? OR name LIKE ? OR color_en LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        if ($cat !== '') {
            $cond[] = 'category = ?';
            $args[] = $cat;
        }
        if ($low) {
            $cond[] = 'stock <= min_stock';
        }
        if ($cond) {
            $sql .= ' WHERE ' . implode(' AND ', $cond);
        }
        $sql .= ' ORDER BY category, sku LIMIT 400';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $stats = [
            'skus'  => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
            'stock' => (int) $pdo->query('SELECT COALESCE(SUM(stock),0) FROM products')->fetchColumn(),
            'low'   => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock <= min_stock')->fetchColumn(),
            'out'   => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE stock = 0')->fetchColumn(),
        ];
        $cats = array_column($pdo->query('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(), 'category');

        view('inventory.index', [
            'pageTitle' => '库存管理', 'pageSub' => '产品库存与自动扣减',
            'products' => $stmt->fetchAll(), 'stats' => $stats, 'cats' => $cats,
            'q' => $q, 'cat' => $cat, 'low' => $low,
        ]);
        break;

    case 'txns':
        $rows = $pdo->query(
            'SELECT x.*, p.sku, p.name FROM stock_txn x JOIN products p ON p.id = x.product_id
           ORDER BY x.id DESC LIMIT 100'
        )->fetchAll();
        view('inventory.txns', ['pageTitle' => '出入库流水', 'pageSub' => '', 'txns' => $rows]);
        break;

    case 'create':
        view('inventory.form', ['pageTitle' => '新建产品', 'pageSub' => '', 'product' => null]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_product($fields);
        if ($data['name'] === '') {
            flash('产品名称必填。', 'error');
            redirect('inventory.create');
        }
        $cols = implode(',', $fields);
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO products ($cols) VALUES ($ph)")->execute(array_values($data));
        flash('产品已创建。');
        redirect('inventory.index');
        break;

    case 'edit':
        view('inventory.form', ['pageTitle' => '编辑产品', 'pageSub' => '', 'product' => find_product($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $data = collect_product($fields);
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $pdo->prepare("UPDATE products SET $set WHERE id = ?")->execute([...array_values($data), $product['id']]);
        flash('产品已更新。');
        redirect('inventory.index');
        break;

    case 'adjust':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $type = (string) input('type', 'in') === 'out' ? 'out' : 'in';
        $qty = max(0, (int) input('qty', 0));
        $ref = trim((string) input('ref', $type === 'in' ? '手动入库' : '手动出库'));
        if ($qty > 0) {
            adjust_stock($pdo, (int) $product['id'], $type, $qty, $ref, trim((string) input('note', '')));
            flash('库存已调整。');
        }
        redirect('inventory.index');
        break;

    case 'delete':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product['id']]);
        flash('产品已删除。');
        redirect('inventory.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function collect_product(array $fields): array
{
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim((string) input($f, ''));
    }
    $data['price'] = (float) ($data['price'] ?: 0);
    $data['stock'] = (int) ($data['stock'] ?: 0);
    $data['min_stock'] = (int) ($data['min_stock'] ?: 0);
    $data['unit'] = $data['unit'] ?: '张';
    $data['size'] = $data['size'] ?: '1.220 x 2.440';
    return $data;
}

function find_product(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('产品不存在');
    }
    return $row;
}
