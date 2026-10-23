<?php
declare(strict_types=1);

/**
 * Paste-to-order (粘贴识别): the salesperson's WhatsApp text → order form fields.
 *
 * The sales assistant pastes the message on the new-order page; the model
 * extracts customer, delivery, payment and item lines; this class then matches
 * them against the platform's own data (catalogue, customers, salespeople) so
 * the form is filled with real records, and marks what still needs a look.
 * Nothing is saved here — the assistant checks the form and saves it herself.
 */
final class OrderParse
{
    public const TASK = 'order_parse';

    /** Compact catalogue for the prompt: one line per SKU, specs joined. */
    public static function catalogue(PDO $pdo): string
    {
        $lines = [];
        foreach ($pdo->query('SELECT sku, color_en, color_zh, spec FROM products ORDER BY sku, spec') as $p) {
            $k = (string) $p['sku'];
            $lines[$k] ??= ['names' => [], 'specs' => []];
            foreach ([(string) $p['color_en'], (string) $p['color_zh']] as $n) {
                if ($n !== '' && !in_array($n, $lines[$k]['names'], true)) {
                    $lines[$k]['names'][] = $n;
                }
            }
            if ((string) $p['spec'] !== '' && !in_array((string) $p['spec'], $lines[$k]['specs'], true)) {
                $lines[$k]['specs'][] = (string) $p['spec'];
            }
        }
        $out = [];
        foreach ($lines as $sku => $l) {
            $out[] = $sku . ': ' . implode(' / ', $l['names']) . ' — specs: ' . implode(' | ', $l['specs']);
        }
        return implode("\n", $out);
    }

    public static function schema(): array
    {
        $str = fn(string $d) => ['type' => 'string', 'description' => $d];
        return [
            'type' => 'object',
            'properties' => [
                'sales_name'       => $str('Salesperson named in the message, else "".'),
                'customer_name'    => $str('Customer / contact person name, else "".'),
                'company'          => $str('Customer company or shop name, else "".'),
                'phone'            => $str('Customer phone, digits as written, else "".'),
                'address'          => $str('Customer address if given separately from the delivery address, else "".'),
                'delivery_address' => $str('Where to deliver, one line, else "".'),
                'delivery_service' => ['type' => 'string', 'enum' => array_merge([''], delivery_services())],
                'delivery_date'    => $str('YYYY-MM-DD, else "".'),
                'payment_term'     => ['type' => 'string', 'enum' => ['', 'CBD', 'COD', 'custom']],
                'custom_days'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365],
                'note'             => $str('Short remark worth keeping, else "".'),
                'items'            => ['type' => 'array', 'maxItems' => 40, 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sku'   => $str('Catalogue SKU, e.g. SJ8019; "" if none recognisable.'),
                        'spec'  => $str('Catalogue spec, e.g. 3.0*0.04背涂; "" if unclear.'),
                        'color' => $str('Colour as written in the message.'),
                        'qty'   => ['type' => 'number', 'minimum' => 0],
                        'price' => ['type' => ['number', 'null'], 'description' => 'Unit price in rupiah if written, else null.'],
                        'raw'   => $str('The original line.'),
                    ],
                    'required' => ['sku', 'spec', 'color', 'qty', 'price', 'raw'],
                    'additionalProperties' => false,
                ]],
                'uncertain'        => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
            ],
            'required' => ['sales_name', 'customer_name', 'company', 'phone', 'address', 'delivery_address', 'delivery_service',
                           'delivery_date', 'payment_term', 'custom_days', 'note', 'items', 'uncertain'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Run the model and resolve its answer against the platform's records.
     * @return array{ok:bool, error:string, data:?array}
     */
    public static function parse(PDO $pdo, array $config, string $message, string $today = ''): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'error' => 'empty', 'data' => null];
        }
        $today = $today !== '' ? $today : date('Y-m-d');
        $content = json_encode(['message' => $message, 'today' => $today, 'catalogue' => self::catalogue($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = AiTask::run($pdo, $config, self::TASK, ['content' => (string) $content, 'schema' => self::schema()]);
        if ($r['status'] !== 'ok' || !is_array($r['data'])) {
            return ['ok' => false, 'error' => $r['status'] . ($r['error'] !== '' ? ': ' . $r['error'] : ''), 'data' => null];
        }
        return ['ok' => true, 'error' => '', 'data' => self::resolve($pdo, $r['data'])];
    }

    /** Match the extracted fields to catalogue / customers / users; pure, testable. */
    public static function resolve(PDO $pdo, array $d): array
    {
        $lk = SalesHistory::lookups($pdo);
        $uncertain = array_values(array_filter(array_map('strval', (array) ($d['uncertain'] ?? []))));

        // Customer: existing record wins, and fills what the message left out.
        $out = [
            'sales_name' => '', 'customer_id' => null, 'customer_name' => trim((string) $d['customer_name']), 'company' => trim((string) $d['company']),
            'phone' => trim((string) $d['phone']), 'address' => trim((string) $d['address']), 'delivery_address' => trim((string) $d['delivery_address']),
            'delivery_service' => (string) $d['delivery_service'], 'delivery_date' => (string) $d['delivery_date'],
            'payment_term' => (string) $d['payment_term'], 'custom_days' => (int) $d['custom_days'], 'note' => trim((string) $d['note']),
            'client_type' => '', 'items' => [], 'uncertain' => $uncertain, 'new_customer' => false,
        ];
        $cid = null;
        foreach ([$out['customer_name'], $out['company']] as $n) {
            if ($n !== '' && ($cid = SalesHistory::match_customer($lk, $n)) !== null) {
                break;
            }
        }
        if ($cid !== null) {
            $c = $pdo->prepare('SELECT id, name, company, phone, city, owner FROM customers WHERE id = ?');
            $c->execute([$cid]);
            if ($row = $c->fetch()) {
                $out['customer_id'] = (int) $row['id'];
                $out['customer_name'] = (string) $row['name'];
                $out['company'] = $out['company'] !== '' ? $out['company'] : (string) ($row['company'] ?? '');
                $out['phone'] = $out['phone'] !== '' ? $out['phone'] : (string) ($row['phone'] ?? '');
                if ($out['sales_name'] === '' && trim((string) $d['sales_name']) === '' && (string) ($row['owner'] ?? '') !== '') {
                    $out['sales_name'] = (string) $row['owner'];
                }
            }
        } elseif ($out['customer_name'] !== '' || $out['company'] !== '') {
            $out['new_customer'] = true;
            if ($out['customer_name'] === '') {
                $out['customer_name'] = $out['company'];
            }
        }
        $sales = self::strip_honorific(trim((string) $d['sales_name']));
        $out['customer_name'] = self::strip_honorific($out['customer_name']);
        if ($sales !== '') {
            $out['sales_name'] = SalesHistory::match_user($lk, $sales);
        }
        if ($out['delivery_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $out['delivery_date'])) {
            $out['delivery_date'] = '';
            $uncertain[] = 'delivery_date';
        }

        // Items: resolve to a product id; otherwise leave the row for the assistant to pick.
        $prodBySku = [];
        foreach ($pdo->query('SELECT id, sku, color_en, color_zh, spec, size, price FROM products ORDER BY sku, spec') as $p) {
            $prodBySku[strtoupper((string) $p['sku'])][] = $p;
        }
        foreach ((array) $d['items'] as $i => $it) {
            $sku = strtoupper(str_replace(' ', '', trim((string) ($it['sku'] ?? ''))));
            $spec = self::normalize_spec((string) ($it['spec'] ?? ''), (string) ($it['raw'] ?? ''));
            $qty = (float) ($it['qty'] ?? 0);
            $price = isset($it['price']) && $it['price'] !== null ? (float) $it['price'] : null;
            $row = ['product_id' => null, 'sku' => $sku, 'spec' => $spec, 'color' => trim((string) ($it['color'] ?? '')), 'size' => '',
                    'qty' => $qty, 'price' => $price, 'raw' => trim((string) ($it['raw'] ?? '')), 'matched' => false];
            $cands = $prodBySku[$sku] ?? [];
            $hit = null;
            foreach ($cands as $p) {
                if ((string) $p['spec'] === $spec) {
                    $hit = $p;
                    break;
                }
            }
            if ($hit === null && $spec !== '') {
                $base = preg_replace('/[^0-9.*].*$/u', '', $spec) ?? '';
                $same = array_values(array_filter($cands, fn($p) => str_starts_with((string) $p['spec'], $base)));
                if (count($same) === 1) {
                    $hit = $same[0];
                } elseif (count($same) > 1) {
                    $uncertain[] = "items.{$i}.spec";
                }
            }
            if ($hit === null && $spec === '' && count($cands) === 1) {
                $hit = $cands[0];
            }
            if ($hit !== null) {
                $row = array_merge($row, [
                    'product_id' => (int) $hit['id'], 'sku' => (string) $hit['sku'], 'spec' => (string) $hit['spec'],
                    'color' => (string) $hit['color_en'], 'size' => (string) $hit['size'], 'matched' => true,
                    'price' => $price ?? (float) $hit['price'],
                ]);
            } else {
                $uncertain[] = "items.{$i}";
            }
            $out['items'][] = $row;
        }
        $out['uncertain'] = array_values(array_unique($uncertain));
        return $out;
    }

    /** "Bp Ridwan" → "Ridwan"; the salespeople write honorifics the user records do not carry. */
    public static function strip_honorific(string $s): string
    {
        return trim(preg_replace('/^(?:bp|bpk|bapak|pak|ibu|bu|mr|mrs|ms)\.?\s+/iu', '', trim($s)) ?? $s);
    }

    /** "3mm x 0.04", "3 MM 0,06", "4.0*0.21" → "3.0*0.04" style base; keep a catalogue suffix if the model gave one. */
    public static function normalize_spec(string $spec, string $raw = ''): string
    {
        $s = trim($spec);
        if (preg_match('/^(\d(?:\.\d)?)\s*(?:mm)?\s*[x*×]\s*(\d[.,]\d{2})(.*)$/iu', $s, $m)) {
            $thick = number_format((float) $m[1], 1, '.', '');
            $skin = str_replace(',', '.', $m[2]);
            return $thick . '*' . $skin . trim($m[3]);
        }
        if (preg_match('/^(\d)\.(\d{2})([^\d].*)?$/u', $s, $m)) {   // "3.06印花" short form used in the catalogue
            return $s;
        }
        if ($s === '' && $raw !== '' && preg_match('/(\d(?:[.,]\d)?)\s*mm\s*[x*×]?\s*(\d[.,]\d{2})/iu', $raw, $m)) {
            return number_format((float) str_replace(',', '.', $m[1]), 1, '.', '') . '*' . str_replace(',', '.', $m[2]);
        }
        return $s;
    }
}
