<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */

switch ($action) {
    case 'index':
        $deals = $pdo->query(
            'SELECT d.*, c.name AS customer_name, c.company
               FROM deals d LEFT JOIN customers c ON c.id = d.customer_id
           ORDER BY d.id DESC'
        )->fetchAll();
        $total = (float) $pdo->query('SELECT COALESCE(SUM(value),0) FROM deals')->fetchColumn();
        view('pipeline.index', ['deals' => $deals, 'total' => $total]);
        break;

    case 'create':
        view('pipeline.form', [
            'pageTitle' => '新增商机', 'pageSub' => '',
            'deal' => null,
            'customers' => $pdo->query('SELECT id, name, company FROM customers ORDER BY name')->fetchAll(),
            'stage' => (string) input('stage', '初步接触'),
        ]);
        break;

    case 'store':
        Csrf::verify();
        $data = collect_deal();
        if ($data['name'] === '') {
            flash('商机名称必填。', 'error');
            redirect('pipeline.create');
        }
        $pdo->prepare('INSERT INTO deals (name,customer_id,value,stage,close_date,note) VALUES (?,?,?,?,?,?)')
            ->execute([$data['name'], $data['customer_id'], $data['value'], $data['stage'], $data['close_date'], $data['note']]);
        flash('商机已创建。');
        redirect('pipeline.index');
        break;

    case 'edit':
        view('pipeline.form', [
            'pageTitle' => '编辑商机', 'pageSub' => '',
            'deal' => find_deal($pdo, (int) input('id', 0)),
            'customers' => $pdo->query('SELECT id, name, company FROM customers ORDER BY name')->fetchAll(),
            'stage' => null,
        ]);
        break;

    case 'update':
        Csrf::verify();
        $deal = find_deal($pdo, (int) input('id', 0));
        $data = collect_deal();
        $pdo->prepare('UPDATE deals SET name=?,customer_id=?,value=?,stage=?,close_date=?,note=? WHERE id=?')
            ->execute([$data['name'], $data['customer_id'], $data['value'], $data['stage'], $data['close_date'], $data['note'], $deal['id']]);
        flash('商机已更新。');
        redirect('pipeline.index');
        break;

    case 'move':
        Csrf::verify();
        $deal = find_deal($pdo, (int) input('id', 0));
        $stage = (string) input('stage', '');
        if (in_array($stage, deal_stages(), true)) {
            $pdo->prepare('UPDATE deals SET stage = ? WHERE id = ?')->execute([$stage, $deal['id']]);
        }
        redirect('pipeline.index');
        break;

    case 'delete':
        Csrf::verify();
        $deal = find_deal($pdo, (int) input('id', 0));
        $pdo->prepare('DELETE FROM deals WHERE id = ?')->execute([$deal['id']]);
        flash('商机已删除。');
        redirect('pipeline.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function collect_deal(): array
{
    return [
        'name'        => trim((string) input('name', '')),
        'customer_id' => ((int) input('customer_id', 0)) ?: null,
        'value'       => (float) input('value', 0),
        'stage'       => (string) input('stage', '初步接触'),
        'close_date'  => (string) input('close_date', ''),
        'note'        => trim((string) input('note', '')),
    ];
}

function find_deal(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('商机不存在');
    }
    return $row;
}
