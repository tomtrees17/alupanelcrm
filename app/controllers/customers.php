<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

$fields = ['name', 'company', 'phone', 'email', 'city', 'tag', 'value', 'note', 'last_contact'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        $tag = trim((string) input('tag', ''));
        $city = trim((string) input('city', ''));
        $owner = trim((string) input('owner', ''));
        $sort = (string) input('sort', '');

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
        if ($city !== '') {
            $cond[] = 'city = ?';
            $args[] = $city;
        }
        if (sees_only_own()) {
            $cond[] = 'owner = ?';
            $args[] = own_name();
        } elseif ($owner !== '') {
            $cond[] = 'owner = ?';
            $args[] = $owner;
        }
        if ($cond) {
            $sql .= ' WHERE ' . implode(' AND ', $cond);
        }
        // Whitelisted sort (never interpolate raw input into ORDER BY).
        $orderBy = [
            'value_desc' => 'value DESC, id DESC',
            'value_asc'  => 'value ASC, id DESC',
        ][$sort] ?? 'id DESC';
        $sql .= ' ORDER BY ' . $orderBy;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        // Distinct city / owner options for the filter dropdowns (scoped to visibility).
        if (sees_only_own()) {
            $cs = $pdo->prepare("SELECT DISTINCT city FROM customers WHERE COALESCE(city,'') <> '' AND owner = ? ORDER BY city");
            $cs->execute([own_name()]);
            $cities = array_column($cs->fetchAll(), 'city');
            $owners = [];
        } else {
            $cities = array_column($pdo->query("SELECT DISTINCT city FROM customers WHERE COALESCE(city,'') <> '' ORDER BY city")->fetchAll(), 'city');
            $owners = array_column($pdo->query("SELECT DISTINCT owner FROM customers WHERE COALESCE(owner,'') <> '' ORDER BY owner")->fetchAll(), 'owner');
        }

        view('customers.index', [
            'customers' => $stmt->fetchAll(), 'q' => $q, 'tag' => $tag,
            'city' => $city, 'owner' => $owner, 'sort' => $sort,
            'cities' => $cities, 'owners' => $owners,
        ]);
        break;

    case 'export':
        if (!can_export()) {
            http_response_code(403);
            flash(t('msg_no_export'), 'error');
            redirect('customers.index');
        }
        $rows = [];
        foreach ($pdo->query('SELECT * FROM customers ORDER BY id DESC') as $c) {
            $rows[] = [
                $c['name'], $c['company'], $c['phone'], $c['email'], $c['city'],
                $c['tag'], (float) $c['value'], $c['owner'], $c['last_contact'],
            ];
        }
        send_spreadsheet('customers_' . date('Ymd'), '客户列表',
            ['客户', '公司', '联系方式', '邮箱', '城市', '标签', '潜在价值', '负责销售', '最后跟进'],
            $rows);
        break;

    case 'create':
        view('customers.form', [
            'pageTitle' => t('btn_new') . ' · ' . t('nav_customers'), 'pageSub' => '', 'customer' => null,
            'canAssign' => !sees_only_own(),
            'staff' => assignable_staff($pdo),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_customer($fields);
        if ($data['name'] === '') {
            flash(t('msg_cust_name_req'), 'error');
            redirect('customers.create');
        }
        // Privileged users may assign the owner; sales always own what they create.
        $owner = own_name();
        if (!sees_only_own() && trim((string) input('owner', '')) !== '') {
            $owner = trim((string) input('owner'));
        }
        $cols = implode(',', $fields);
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO customers ($cols, owner) VALUES ($ph, ?)")
            ->execute([...array_values($data), $owner]);
        $newId = (int) $pdo->lastInsertId();
        audit(
            $pdo,
            'customers',
            'create',
            'customer',
            $newId,
            $data['name'],
            sprintf('公司: %s; 城市: %s; 负责销售: %s', $data['company'] ?: '(空)', $data['city'] ?: '(空)', $owner ?: '(空)')
        );
        flash(t('msg_cust_created'));
        redirect('customers.show', ['id' => $newId]);
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
        view('customers.form', [
            'pageTitle' => t('btn_edit') . ' · ' . t('nav_customers'), 'pageSub' => '', 'customer' => find_customer($pdo, (int) input('id', 0)),
            'canAssign' => !sees_only_own(),
            'staff' => assignable_staff($pdo),
        ]);
        break;

    case 'update':
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $data = collect_customer($fields);
        if ($data['name'] === '') {
            flash(t('msg_cust_name_req'), 'error');
            redirect('customers.edit', ['id' => $customer['id']]);
        }
        $set = implode(',', array_map(fn($f) => "$f = ?", $fields));
        $after = $data;
        if (!sees_only_own()) {
            // Privileged users may (re)assign the owner.
            $after['owner'] = trim((string) input('owner', ''));
            $pdo->prepare("UPDATE customers SET $set, owner = ? WHERE id = ?")
                ->execute([...array_values($data), $after['owner'], $customer['id']]);
        } else {
            $pdo->prepare("UPDATE customers SET $set WHERE id = ?")
                ->execute([...array_values($data), $customer['id']]);
        }
        $diff = audit_diff($customer, $after, [
            'name' => '姓名', 'company' => '公司', 'phone' => '电话', 'email' => '邮箱',
            'city' => '城市', 'tag' => '标签', 'value' => '潜在价值', 'owner' => '负责销售',
        ]);
        audit(
            $pdo,
            'customers',
            'update',
            'customer',
            (int) $customer['id'],
            (string) $data['name'],
            $diff !== '' ? $diff : '(无字段变化)'
        );
        flash(t('msg_cust_updated'));
        redirect('customers.show', ['id' => $customer['id']]);
        break;

    case 'delete':
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customer['id']]);
        audit(
            $pdo,
            'customers',
            'delete',
            'customer',
            (int) $customer['id'],
            (string) $customer['name'],
            sprintf('公司: %s; 负责销售: %s', (string) ($customer['company'] ?? '') ?: '(空)', (string) ($customer['owner'] ?? '') ?: '(空)')
        );
        flash(t('msg_cust_deleted'));
        redirect('customers.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

/** Staff who can be assigned as a customer owner. */
function assignable_staff(PDO $pdo): array
{
    return $pdo->query('SELECT name, role FROM users ORDER BY name')->fetchAll();
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
        flash(t('msg_cust_own_only'), 'error');
        redirect('customers.index');
    }
    return $row;
}
