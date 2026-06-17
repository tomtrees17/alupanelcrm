<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

$fields = ['name', 'company', 'phone', 'email', 'city', 'tag', 'value', 'note', 'last_contact'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        $tag = trim((string) input('tag', ''));
        $sql = 'SELECT * FROM customers';
        $cond = [];
        $args = [];
        if ($q !== '') {
            $cond[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR city LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($tag !== '') {
            $cond[] = 'tag = ?';
            $args[] = $tag;
        }
        if (sees_only_own()) {
            $cond[] = 'owner = ?';
            $args[] = own_name();
        }
        if ($cond) {
            $sql .= ' WHERE ' . implode(' AND ', $cond);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        view('customers.index', ['customers' => $stmt->fetchAll(), 'q' => $q, 'tag' => $tag]);
        break;

    case 'create':
        view('customers.form', ['pageTitle' => '新建客户', 'pageSub' => '', 'customer' => null]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_customer($fields);
        if ($data['name'] === '') {
            flash('客户姓名必填。', 'error');
            redirect('customers.create');
        }
        $cols = implode(',', $fields);
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO customers ($cols, owner) VALUES ($ph, ?)")
            ->execute([...array_values($data), own_name()]);
        flash('客户已创建。');
        redirect('customers.show', ['id' => (int) $pdo->lastInsertId()]);
        break;

    case 'show':
        $customer = find_customer($pdo, (int) input('id', 0));
        $deals = $pdo->prepare('SELECT * FROM deals WHERE customer_id = ? ORDER BY id DESC');
        $deals->execute([$customer['id']]);
        $orders = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC');
        $orders->execute([$customer['id']]);
        view('customers.show', [
            'pageTitle' => $customer['name'], 'pageSub' => $customer['company'],
            'customer' => $customer, 'deals' => $deals->fetchAll(), 'orders' => $orders->fetchAll(),
        ]);
        break;

    case 'edit':
        view('customers.form', ['pageTitle' => '编辑客户', 'pageSub' => '', 'customer' => find_customer($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $data = collect_customer($fields);
        if ($data['name'] === '') {
            flash('客户姓名必填。', 'error');
            redirect('customers.edit', ['id' => $customer['id']]);
        }
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $pdo->prepare("UPDATE customers SET $set WHERE id = ?")->execute([...array_values($data), $customer['id']]);
        flash('客户已更新。');
        redirect('customers.show', ['id' => $customer['id']]);
        break;

    case 'delete':
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customer['id']]);
        flash('客户已删除。');
        redirect('customers.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function collect_customer(array $fields): array
{
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim((string) input($f, ''));
    }
    $data['value'] = (float) ($data['value'] ?: 0);
    $data['tag'] = $data['tag'] ?: '潜在';
    return $data;
}

function find_customer(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('客户不存在');
    }
    if (sees_only_own() && ($row['owner'] ?? '') !== own_name()) {
        http_response_code(403);
        flash('只能访问自己的客户 / Hanya pelanggan Anda.', 'error');
        redirect('customers.index');
    }
    return $row;
}
