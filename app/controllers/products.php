<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

$fields = ['sku', 'name', 'description', 'spec', 'unit', 'price'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        if ($q !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM products WHERE name LIKE :kw OR sku LIKE :kw OR spec LIKE :kw ORDER BY id DESC'
            );
            $stmt->execute([':kw' => "%{$q}%"]);
            $products = $stmt->fetchAll();
        } else {
            $products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
        }
        view('products.index', ['products' => $products, 'q' => $q]);
        break;

    case 'create':
        view('products.form', ['product' => null]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_product($fields);
        if ($data['name'] === '') {
            flash('产品名称必填。', 'error');
            redirect('products.create');
        }
        $cols = implode(',', $fields);
        $ph   = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO products ($cols) VALUES ($ph)")->execute(array_values($data));
        flash('产品已创建。');
        redirect('products.index');
        break;

    case 'edit':
        view('products.form', ['product' => find_product($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $data = collect_product($fields);
        if ($data['name'] === '') {
            flash('产品名称必填。', 'error');
            redirect('products.edit', ['id' => $product['id']]);
        }
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $pdo->prepare("UPDATE products SET $set WHERE id = ?")
            ->execute([...array_values($data), $product['id']]);
        flash('产品已更新。');
        redirect('products.index');
        break;

    case 'delete':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product['id']]);
        flash('产品已删除。');
        redirect('products.index');
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
    $data['unit']  = $data['unit'] ?: 'pc';
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
