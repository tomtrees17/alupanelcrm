<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */
/** @var array $config */

$fields = ['sku', 'name', 'color_zh', 'color_en', 'spec', 'size', 'category', 'unit', 'price', 'stock', 'min_stock'];

// Only admin / warehouse may modify; everyone else with inventory access is read-only.
if (in_array($action, ['create', 'store', 'edit', 'update', 'adjust', 'delete'], true) && !can_edit_inventory()) {
    http_response_code(403);
    flash(t('msg_stock_no_perm'), 'error');
    redirect('inventory.index');
}

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
            'low'   => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE (stock - reserved) <= min_stock')->fetchColumn(),
            'out'   => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE (stock - reserved) <= 0')->fetchColumn(),
        ];
        $cats = array_column($pdo->query('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(), 'category');

        view('inventory.index', [
            'products' => $stmt->fetchAll(), 'stats' => $stats, 'cats' => $cats,
            'q' => $q, 'cat' => $cat, 'low' => $low,
        ]);
        break;

    case 'export':
        if (!can_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('inventory.index');
        }
        $rows = [];
        foreach ($pdo->query('SELECT * FROM products ORDER BY category, sku') as $p) {
            $reserved = (int) $p['reserved'];
            $rows[] = [
                $p['sku'], $p['name'], $p['color_zh'], $p['color_en'], $p['spec'], $p['size'],
                $p['category'], $p['unit'], (float) $p['price'], (int) $p['stock'], $reserved,
                (int) $p['stock'] - $reserved, (int) $p['min_stock'],
            ];
        }
        send_spreadsheet('inventory_' . date('Ymd'), '库存',
            ['SKU', '名称', '颜色(中)', '颜色(英)', '规格', '尺寸', '分类', '单位', '单价', '库存', '预留', '可用', '安全库存'],
            $rows);
        break;

    case 'ask':
        // Natural-language stock lookup. Read-only: the model picks products,
        // ai_resolve_products() reads the live numbers. Available to anyone who
        // can already see inventory, on the same permission.
        $question = trim((string) input('q', ''));
        $answer = null;
        $error = '';
        $limit = (int) ($config['ai']['daily_limit'] ?? 60);
        $used = ai_used_today($pdo, (int) ($auth->user()['id'] ?? 0));

        if ($question !== '' && is_post()) {
            Csrf::verify();
            if ($used >= $limit) {
                $error = sprintf(t('ai_limit_hit'), $limit);
            } else {
                [$parsed, $err, $usage] = Ai::match($config, Ai::catalogue($pdo), $question);
                if ($parsed === null) {
                    $error = t('ai_failed');
                    ai_log($pdo, $question, [], false, $err, $usage);
                } else {
                    $ids = (array) ($parsed['product_ids'] ?? []);
                    $answer = [
                        'understood' => (string) ($parsed['understood'] ?? ''),
                        'clarify'    => $parsed['clarify'] ?? null,
                        'qty'        => $parsed['qty_asked'] ?? null,
                        'products'   => ai_resolve_products($pdo, $ids),
                    ];
                    ai_log($pdo, $question, $ids, true, '', $usage);
                    $used++;
                }
            }
        }

        view('inventory.ask', [
            'pageTitle' => t('page_ask'), 'pageSub' => t('sub_ask'),
            'question' => $question, 'answer' => $answer, 'error' => $error,
            'used' => $used, 'limit' => $limit,
            'stub' => (string) ($config['ai']['driver'] ?? 'stub') !== 'claude',
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
        view('inventory.form', ['pageTitle' => t('btn_new') . ' · ' . t('th_product'), 'pageSub' => '', 'product' => null]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_product($fields);
        if ($data['name'] === '') {
            flash(t('msg_prod_name_req'), 'error');
            redirect('inventory.create');
        }
        $cols = implode(',', $fields);
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO products ($cols) VALUES ($ph)")->execute(array_values($data));
        audit(
            $pdo,
            'inventory',
            'create',
            'product',
            (int) $pdo->lastInsertId(),
            trim($data['sku'] . ' ' . $data['name']),
            sprintf('规格: %s; 初始库存: %s; 单价: %s', $data['spec'], $data['stock'], idr((float) $data['price']))
        );
        flash(t('msg_prod_created'));
        redirect('inventory.index');
        break;

    case 'edit':
        view('inventory.form', ['pageTitle' => t('btn_edit') . ' · ' . t('th_product'), 'pageSub' => '', 'product' => find_product($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $data = collect_product($fields);
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $pdo->prepare("UPDATE products SET $set WHERE id = ?")->execute([...array_values($data), $product['id']]);
        $diff = audit_diff($product, $data, [
            'sku' => 'SKU', 'name' => '名称', 'spec' => '规格', 'size' => '尺寸',
            'category' => '分类', 'price' => '单价', 'stock' => '库存', 'min_stock' => '安全库存',
        ]);
        audit(
            $pdo,
            'inventory',
            'update',
            'product',
            (int) $product['id'],
            trim($data['sku'] . ' ' . $data['name']),
            $diff !== '' ? $diff : '(无字段变化)'
        );
        flash(t('msg_prod_updated'));
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
            audit(
                $pdo,
                'inventory',
                'adjust',
                'product',
                (int) $product['id'],
                trim((string) $product['sku'] . ' ' . (string) $product['name']),
                sprintf(
                    '%s %d；库存 %s → %s；事由: %s',
                    $type === 'in' ? '入库' : '出库',
                    $qty,
                    (string) $product['stock'],
                    (string) ((int) $product['stock'] + ($type === 'in' ? $qty : -$qty)),
                    $ref
                )
            );
            flash(t('msg_stock_adjusted'));
        }
        redirect('inventory.index');
        break;

    case 'delete':
        Csrf::verify();
        $product = find_product($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product['id']]);
        audit(
            $pdo,
            'inventory',
            'delete',
            'product',
            (int) $product['id'],
            trim((string) $product['sku'] . ' ' . (string) $product['name']),
            sprintf('删除时库存: %s; 规格: %s', (string) $product['stock'], (string) $product['spec'])
        );
        flash(t('msg_prod_deleted'));
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
