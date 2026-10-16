<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */
/** @var array $config */

$fields = ['name', 'company', 'phone', 'email', 'city', 'tag', 'value', 'note', 'last_contact'];

switch ($action) {
    case 'index':
        $q = trim((string) input('q', ''));
        $tag = trim((string) input('tag', ''));
        $city = trim((string) input('city', ''));
        $owner = trim((string) input('owner', ''));
        $sort = (string) input('sort', '');

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
        $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
        // Whitelisted sort (never interpolate raw input into ORDER BY).
        $orderBy = [
            'value_desc' => 'value DESC, id DESC',
            'value_asc'  => 'value ASC, id DESC',
        ][$sort] ?? 'id DESC';
        $pg = paginate($pdo, 'SELECT COUNT(*) FROM customers' . $where, $args);
        $stmt = $pdo->prepare('SELECT * FROM customers' . $where . ' ORDER BY ' . $orderBy . $pg['limit']);
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
            'cities' => $cities, 'owners' => $owners, 'pg' => $pg,
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
        $fu = $pdo->prepare('SELECT * FROM customer_followups WHERE customer_id = ? ORDER BY id DESC LIMIT 30');
        $fu->execute([$customer['id']]);
        $ins = $pdo->prepare('SELECT * FROM customer_insights WHERE customer_id = ?');
        $ins->execute([$customer['id']]);
        view('customers.show', [
            'pageTitle' => $customer['name'], 'pageSub' => $customer['company'],
            'customer' => $customer, 'deals' => $deals->fetchAll(), 'orders' => $orders->fetchAll(),
            'followups' => $fu->fetchAll(), 'insight' => $ins->fetch() ?: null,
            'snooze' => Insights::snoozed($pdo, $config, date('Y-m-d'))[(int) $customer['id']] ?? null,
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

    case 'set_industry':
        // Confirm or correct the inferred industry. A manual answer is final:
        // the nightly enrichment never overwrites it.
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $summary = CustomerData::set_industry($pdo, $customer, (string) input('industry', ''), own_name());
        if ($summary === null) {
            flash(t('msg_bad_industry'), 'error');
            redirect('customers.show', ['id' => $customer['id']]);
        }
        audit($pdo, 'customers', 'update', 'customer', (int) $customer['id'], (string) $customer['name'], $summary);
        flash(t('msg_industry_saved'));
        redirect('customers.show', ['id' => $customer['id']]);
        break;

    case 'followup':
    case 'no_need':
        // 记跟进 / 无需求. Either hides the customer from the call lists (7 / 30
        // days, read live). Not written to audit_log: high-frequency, and the
        // follow-up table is itself the record of who did what when.
        Csrf::verify();
        $customer = find_customer($pdo, (int) input('id', 0));
        $note = trim((string) input('note', ''));
        $back = match ((string) input('back', '')) {
            'insights'       => ['insights.index', array_filter(['tab' => (string) input('tab', '')])],
            'insights_admin' => ['insights.admin', array_filter(['tab' => (string) input('tab', ''), 'owner' => (string) input('owner', '')])],
            default          => ['customers.show', ['id' => $customer['id']]],
        };
        if ($note === '') {
            flash(t($action === 'no_need' ? 'msg_no_need_reason' : 'msg_followup_note'), 'error');
            redirect($back[0], $back[1]);
        }
        Insights::record($pdo, (int) $customer['id'], $action === 'no_need' ? 'no_need' : 'followup', mb_substr($note, 0, 1000), $auth->user(), date('Y-m-d'));
        flash(t($action === 'no_need' ? 'msg_no_need_saved' : 'msg_followup_saved'));
        redirect($back[0], $back[1]);
        break;

    // ── 数据整理 (admin): salesperson-name variants, owner (re)assignment ──
    case 'cleanup':
        require_admin();
        $variants = CustomerData::name_variants($pdo);
        $usage = [];
        foreach ($variants as $raw => $n) {
            $k = trim((string) $raw);
            $usage[$k] = ($usage[$k] ?? 0) + $n;
        }
        $accounts = $pdo->query('SELECT name, role FROM users ORDER BY name')->fetchAll();
        $pairs = CustomerData::similar_pairs(array_keys($usage), ignored_name_pairs($pdo));

        $ownerFilter = (string) input('owner', '');
        $st = $pdo->prepare(
            "SELECT c.id, c.name, c.company, c.owner,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS orders,
                    (SELECT MAX(date(created_at)) FROM orders o WHERE o.customer_id = c.id) AS last_order
               FROM customers c WHERE TRIM(COALESCE(c.owner, '')) = ? ORDER BY orders DESC, c.id DESC"
        );
        $st->execute([trim($ownerFilter)]);
        $owners = $pdo->query("SELECT TRIM(COALESCE(owner, '')) o, COUNT(*) c FROM customers GROUP BY o ORDER BY c DESC")->fetchAll(PDO::FETCH_KEY_PAIR);

        view('customers.cleanup', [
            'pageTitle' => t('cleanup_title'), 'pageSub' => t('cleanup_sub'),
            'pairs' => $pairs, 'usage' => $usage, 'accounts' => $accounts,
            'rows' => $st->fetchAll(), 'ownerFilter' => trim($ownerFilter), 'owners' => $owners,
            'suggest' => CustomerData::suggested_owners($pdo),
            'enrichLast' => (string) $pdo->query("SELECT v FROM app_meta WHERE k = 'enrich_last_run'")->fetchColumn(),
        ]);
        break;

    case 'merge_owner':
        require_admin();
        Csrf::verify();
        $from = trim((string) input('from', ''));
        $to = trim((string) input('to', ''));
        if ($from === '' || $to === '' || mb_strtolower($from) === mb_strtolower($to)) {
            flash(t('msg_merge_invalid'), 'error');
            redirect('customers.cleanup');
        }
        $pdo->beginTransaction();
        $n = CustomerData::rename_salesperson($pdo, $from, $to);
        $pdo->commit();
        audit($pdo, 'customers', 'merge', 'salesperson', null, $from . ' → ' . $to,
            sprintf('销售名合并: "%s" → "%s"; 客户 %d, 订单 %d', $from, $to, $n['customers'], $n['orders']));
        flash(sprintf(t('msg_merged'), $from, $to, $n['customers'], $n['orders']));
        redirect('customers.cleanup');
        break;

    case 'ignore_pair':
        require_admin();
        Csrf::verify();
        $a = trim((string) input('a', ''));
        $b = trim((string) input('b', ''));
        if ($a !== '' && $b !== '') {
            $list = ignored_name_pairs($pdo);
            $list[] = CustomerData::pair_key($a, $b);
            $pdo->prepare("INSERT OR REPLACE INTO app_meta (k, v) VALUES ('salesname_ignored', ?)")
                ->execute([json_encode(array_values(array_unique($list)), JSON_UNESCAPED_UNICODE)]);
        }
        redirect('customers.cleanup');
        break;

    case 'assign':
        require_admin();
        Csrf::verify();
        $ids = array_values(array_filter(array_map('intval', (array) input('ids', [])), fn($i) => $i > 0));
        $useSuggest = input('mode', '') === 'suggest';
        $target = trim((string) input('owner_to', ''));
        $back = ['owner' => (string) input('owner', '')];
        if ($ids === [] || (!$useSuggest && $target === '')) {
            flash(t('msg_assign_none'), 'error');
            redirect('customers.cleanup', $back);
        }
        $suggest = $useSuggest ? CustomerData::suggested_owners($pdo) : [];
        $get = $pdo->prepare('SELECT id, name, owner FROM customers WHERE id = ?');
        $set = $pdo->prepare('UPDATE customers SET owner = ? WHERE id = ?');
        $done = [];
        $skipped = 0;
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $get->execute([$id]);
            $c = $get->fetch();
            $to = $useSuggest ? ($suggest[$id] ?? '') : $target;
            if (!$c || $to === '' || trim((string) $c['owner']) === $to) {
                $skipped++;
                continue;
            }
            $set->execute([$to, $id]);
            $done[] = [$c, $to];
        }
        $pdo->commit();
        foreach ($done as [$c, $to]) {
            audit($pdo, 'customers', 'assign', 'customer', (int) $c['id'], (string) $c['name'],
                sprintf('负责销售: %s → %s', trim((string) $c['owner']) ?: '(空)', $to));
        }
        flash(sprintf(t('msg_assigned'), count($done), $skipped));
        redirect('customers.cleanup', $back);
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function require_admin(): void
{
    if (!$GLOBALS['auth']->isAdmin()) {
        http_response_code(403);
        flash(t('msg_admin_only'), 'error');
        redirect('customers.index');
    }
}

/** @return string[] "a|b" pairs an admin marked as different people */
function ignored_name_pairs(PDO $pdo): array
{
    $v = $pdo->query("SELECT v FROM app_meta WHERE k = 'salesname_ignored'")->fetchColumn();
    return is_string($v) ? (json_decode($v, true) ?: []) : [];
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
