<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

$fields = ['company', 'contact_name', 'email', 'phone', 'address', 'city', 'country', 'notes'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        if ($q !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM customers
                  WHERE company LIKE :kw OR contact_name LIKE :kw OR email LIKE :kw OR city LIKE :kw
               ORDER BY id DESC'
            );
            $stmt->execute([':kw' => "%{$q}%"]);
            $customers = $stmt->fetchAll();
        } else {
            $customers = $pdo->query('SELECT * FROM customers ORDER BY id DESC')->fetchAll();
        }
        view('customers.index', ['customers' => $customers, 'q' => $q]);
        break;

    case 'create':
        view('customers.form', ['customer' => null]);
        break;

    case 'store':
        Csrf::verify();
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = trim((string) input($f, ''));
        }
        if ($data['company'] === '') {
            flash('公司名称必填。', 'error');
            redirect('customers.create');
        }
        $cols = implode(',', $fields);
        $ph   = implode(',', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare("INSERT INTO customers ($cols) VALUES ($ph)");
        $stmt->execute(array_values($data));
        flash('客户已创建。');
        redirect('customers.show', ['id' => (int) $pdo->lastInsertId()]);
        break;

    case 'show':
        $customer = find_customer($pdo, (int) input('id', 0));
        $quotes = $pdo->prepare('SELECT * FROM quotes WHERE customer_id = ? ORDER BY id DESC');
        $quotes->execute([$customer['id']]);
        view('customers.show', ['customer' => $customer, 'quotes' => $quotes->fetchAll()]);
        break;

    case 'edit':
        view('customers.form', ['customer' => find_customer($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = trim((string) input($f, ''));
        }
        if ($data['company'] === '') {
            flash('公司名称必填。', 'error');
            redirect('customers.edit', ['id' => $customer['id']]);
        }
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $stmt = $pdo->prepare("UPDATE customers SET $set WHERE id = ?");
        $stmt->execute([...array_values($data), $customer['id']]);
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

function find_customer(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('客户不存在');
    }
    return $row;
}
