<?php
declare(strict_types=1);

/**
 * HR-04 拜访记录 / Catatan Kunjungan (docs/任务卡-拜访签到.md v2): the weekly
 * Excel 回收表 moved to the phone, same fields. Decisions taken with the owner
 * (2026-10-24):
 *   - outcomes are the four of the Excel and the Petunjuk (Berminat / Sudah
 *     Penawaran / Tidak Butuh / Tidak Terhubung) plus Order (owner, v3); old
 *     group messages without a result are 'unknown'.
 *   - the weekly report is computed live (no generation, no todo); its export
 *     has exactly the columns of the Daftar Calon Pelanggan sheet, one visit
 *     per row, plus a summary sheet.
 *   - a visit to a CRM customer counts as a follow-up in the call lists:
 *     Tidak Butuh = "no need" (hidden 30 days), the rest = follow-up (7 days).
 *   - prospects are assigned by management on the prospect page (or by the
 *     Excel's Sales PIC column); rows already customers ("Sudah Pelanggan")
 *     are kept but never offered as prospects.
 *   - sales see their own visits; the boss and holders of 'visits_all'
 *     (default: manager) see everything.
 *   - "far" = more than 1 km from the target; back-filled visits (up to 7
 *     days) carry no distance — the phone was not there that day.
 *   - visit_no KV-{YYMM}-{NNNN}, serial per month of saving.
 * Location is read only if the browser already allows it; nothing prompts.
 */
final class Visits
{
    public const OUTCOMES = ['berminat' => 'Berminat', 'penawaran' => 'Sudah Penawaran', 'order' => 'Order', 'tidak_butuh' => 'Tidak Butuh', 'tidak_terhubung' => 'Tidak Terhubung'];
    /** Old-format group messages carry no result (v3): stored as unknown, never offered in the form. */
    public const UNKNOWN = 'unknown';
    public const TARGETS = ['customer', 'prospect', 'new'];
    public const FAR_METERS = 1000;
    public const BACKDATE_DAYS = 7;
    public const EDIT_HOURS = 24;
    /** Exactly the 回收表 columns (Daftar Calon Pelanggan, without the "Cek Data (sistem)" column). */
    public const REPORT_HEADERS = ['Nama Perusahaan', 'Kategori', 'Wilayah', 'Skor Prioritas', 'Telepon', 'Rating', 'Jml Ulasan', 'Sudah Pelanggan',
                                   'Alamat', 'Link Maps', 'Lat', 'Lng', 'Sales PIC', 'Hasil Follow-up', 'Tanggal Kunjungan', 'Catatan'];

    /** Tests point this at a temp dir. */
    public static ?string $photoRoot = null;

    public static function photo_dir(int $empId): string
    {
        return (self::$photoRoot ?? dirname(__DIR__) . '/data/visits') . '/' . $empId;
    }

    // ───────────────────────────── who ─────────────────────────────

    /** The employee behind a CRM user, or null. */
    public static function employee_of(PDO $pdo, ?array $user): ?array
    {
        $empId = (int) ($user['employee_id'] ?? 0);
        if (!$empId) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
        $st->execute([$empId]);
        return $st->fetch() ?: null;
    }

    /** Records visits: an employee in 拜访 mode who has not left. */
    public static function can_record(?array $employee): bool
    {
        return $employee !== null && ($employee['attendance_mode'] ?? '') === 'visit' && $employee['employment_status'] !== 'resigned';
    }

    public static function sees_all(): bool
    {
        $auth = $GLOBALS['auth'] ?? null;
        return $auth !== null && ($auth->isAdmin() || can_access('visits_all'));
    }

    // ───────────────────────────── numbers & geometry ─────────────────────────────

    public static function next_no(PDO $pdo, string $date): string
    {
        $ym = substr($date, 2, 2) . substr($date, 5, 2);
        $st = $pdo->prepare('SELECT visit_no FROM visits WHERE visit_no LIKE ?');
        $st->execute(["KV-{$ym}-%"]);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('/^KV-\d{4}-(\d+)$/', (string) $no, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('KV-%s-%04d', $ym, $max + 1);
    }

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round(2 * $r * asin(min(1.0, sqrt($a))));
    }

    /** Where the visit target is: a prospect's own point; a customer's latest delivery point. */
    public static function target_point(PDO $pdo, string $type, ?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        if ($type === 'prospect') {
            $st = $pdo->prepare('SELECT lat, lng FROM prospects WHERE id = ? AND lat IS NOT NULL AND lng IS NOT NULL');
        } elseif ($type === 'customer') {
            $st = $pdo->prepare('SELECT delivery_lat lat, delivery_lng lng FROM orders WHERE customer_id = ? AND delivery_lat IS NOT NULL AND delivery_lng IS NOT NULL
                                 ORDER BY created_at DESC, id DESC LIMIT 1');
        } else {
            return null;
        }
        $st->execute([$id]);
        $p = $st->fetch();
        return $p ? ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']] : null;
    }

    /** @return array{0:?int,1:string} distance in metres (or null) and ok|far|none */
    public static function distance(?array $here, ?array $target): array
    {
        if (!$here || !$target) {
            return [null, 'none'];
        }
        $m = self::meters($here['lat'], $here['lng'], $target['lat'], $target['lng']);
        return [$m, $m > self::FAR_METERS ? 'far' : 'ok'];
    }

    public static function editable(array $visit, ?array $employee, ?int $now = null): bool
    {
        return $employee !== null && (int) $visit['employee_id'] === (int) $employee['id']
            && strtotime((string) $visit['created_at']) + self::EDIT_HOURS * 3600 > ($now ?? time());
    }

    public static function outcome_label(string $o): string
    {
        return self::OUTCOMES[$o] ?? ($o === self::UNKNOWN ? 'Tidak diketahui' : $o);
    }

    public static function target_name(array $v): string
    {
        return (string) ($v['customer_name'] ?? $v['prospect_name'] ?? $v['new_customer_name'] ?? '');
    }

    // ───────────────────────────── saving ─────────────────────────────

    /** The follow-up note a visit leaves on a customer (and the 回收表's Catatan). */
    public static function catatan(array $v): string
    {
        $parts = [];
        if (trim((string) $v['note']) !== '') {
            $parts[] = trim((string) $v['note']);
        }
        if (trim((string) ($v['current_supplier'] ?? '')) !== '') {
            $parts[] = 'Supplier: ' . trim((string) $v['current_supplier']);
        }
        if (trim((string) ($v['current_price'] ?? '')) !== '') {
            $parts[] = 'Harga: ' . trim((string) $v['current_price']);
        }
        return implode(' · ', $parts);
    }

    /**
     * A visit to a CRM customer = one follow-up (owner decision): Tidak Butuh is a
     * "no need", the rest a follow-up; dated on the visit day; last contact moves.
     */
    public static function link_customer(PDO $pdo, array $v, ?array $user): ?int
    {
        if ($v['target_type'] !== 'customer' || !$v['customer_id']) {
            return null;
        }
        $kind = $v['outcome'] === 'tidak_butuh' ? 'no_need' : 'followup';
        $note = trim('Kunjungan ' . $v['visit_no'] . ' · ' . self::outcome_label((string) $v['outcome']) . ' · ' . self::catatan($v), ' ·');
        $at = substr((string) $v['visited_at'], 0, 19);
        if (!empty($v['followup_id'])) {
            $pdo->prepare('UPDATE customer_followups SET kind = ?, note = ?, created_at = ? WHERE id = ?')->execute([$kind, $note, $at, $v['followup_id']]);
            $id = (int) $v['followup_id'];
        } else {
            $pdo->prepare('INSERT INTO customer_followups (customer_id, user_id, user_name, kind, note, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$v['customer_id'], $user['id'] ?? null, (string) ($user['name'] ?? ''), $kind, $note, $at]);
            $id = (int) $pdo->lastInsertId();
        }
        $day = substr($at, 0, 10);
        $pdo->prepare('UPDATE customers SET last_contact = ? WHERE id = ? AND (last_contact IS NULL OR last_contact < ?)')->execute([$day, $v['customer_id'], $day]);
        return $id;
    }

    /** last_visit_at / last_outcome / visit_count of a prospect, from its visits. */
    public static function refresh_prospect(PDO $pdo, ?int $prospectId): void
    {
        if (!$prospectId) {
            return;
        }
        $st = $pdo->prepare('SELECT visited_at, outcome FROM visits WHERE prospect_id = ? ORDER BY visited_at DESC, id DESC');
        $st->execute([$prospectId]);
        $rows = $st->fetchAll();
        $pdo->prepare('UPDATE prospects SET last_visit_at = ?, last_outcome = ?, visit_count = ? WHERE id = ?')
            ->execute([$rows[0]['visited_at'] ?? null, $rows[0]['outcome'] ?? null, count($rows), $prospectId]);
    }

    // ───────────────────────────── picking a target ─────────────────────────────

    /**
     * Search for the visit form: my customers, then prospects (mine first); an
     * empty query lists my assigned prospects, least recently visited first.
     * Prospects that are already customers are never offered.
     * @return list<array{type:string,id:int,name:string,sub:string,mine:bool}>
     */
    public static function search(PDO $pdo, array $user, ?array $employee, string $q, int $limit = 25): array
    {
        $q = trim($q);
        $empId = (int) ($employee['id'] ?? 0);
        $me = mb_strtolower(trim((string) ($user['name'] ?? '')));
        $out = [];
        if ($q === '') {
            $st = $pdo->prepare('SELECT id, name, region, category FROM prospects WHERE assigned_to = ? AND is_customer = 0
                                  ORDER BY last_visit_at IS NOT NULL, last_visit_at, priority DESC, name LIMIT ?');
            $st->execute([$empId, $limit]);
            foreach ($st->fetchAll() as $p) {
                $out[] = ['type' => 'prospect', 'id' => (int) $p['id'], 'name' => (string) $p['name'], 'sub' => trim($p['region'] . ' · ' . $p['category'], ' ·'), 'mine' => true];
            }
            return $out;
        }
        $like = '%' . $q . '%';
        $st = $pdo->prepare('SELECT id, name, company, city FROM customers WHERE LOWER(TRIM(COALESCE(owner, \'\'))) = ? AND (name LIKE ? OR company LIKE ?) ORDER BY name LIMIT ?');
        $st->execute([$me, $like, $like, $limit]);
        foreach ($st->fetchAll() as $c) {
            $out[] = ['type' => 'customer', 'id' => (int) $c['id'], 'name' => (string) $c['name'], 'sub' => trim(($c['company'] ?? '') . ' · ' . ($c['city'] ?? ''), ' ·'), 'mine' => true];
        }
        $st = $pdo->prepare('SELECT id, name, region, category, assigned_to FROM prospects WHERE is_customer = 0 AND (name LIKE ? OR address LIKE ? OR phone LIKE ?)
                              ORDER BY (assigned_to = ?) DESC, priority DESC, name LIMIT ?');
        $st->execute([$like, $like, $like, $empId, $limit]);
        foreach ($st->fetchAll() as $p) {
            $out[] = ['type' => 'prospect', 'id' => (int) $p['id'], 'name' => (string) $p['name'], 'sub' => trim($p['region'] . ' · ' . $p['category'], ' ·'), 'mine' => (int) $p['assigned_to'] === $empId];
        }
        return array_slice($out, 0, $limit);
    }

    // ───────────────────────────── weeks & report ─────────────────────────────

    /** ISO week of a date, e.g. 2026-W43. */
    public static function week_of(string $date): string
    {
        return (new DateTimeImmutable($date))->format('o-\WW');
    }

    /** Monday and Sunday of an ISO week (YYYY-Www); null if malformed. */
    public static function week_bounds(string $week): ?array
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m) || (int) $m[2] < 1 || (int) $m[2] > 53) {
            return null;
        }
        $mon = (new DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1);
        return [$mon->format('Y-m-d'), $mon->modify('+6 day')->format('Y-m-d')];
    }

    /** Visits in a date range with their targets, for lists, report and map. */
    public static function fetch(PDO $pdo, string $from, string $to, array $filter = []): array
    {
        $where = ['date(v.visited_at) BETWEEN ? AND ?'];
        $args = [$from, $to];
        if (!empty($filter['employee_id'])) {
            $where[] = 'v.employee_id = ?';
            $args[] = (int) $filter['employee_id'];
        }
        if (!empty($filter['outcome'])) {
            $where[] = 'v.outcome = ?';
            $args[] = $filter['outcome'];
        }
        $st = $pdo->prepare('SELECT v.*, COALESCE(e.full_name, v.wa_sender) sales, e.employee_no, c.name customer_name, c.phone customer_phone, c.city customer_city,
                                    p.name prospect_name, p.category, p.region, p.priority, p.phone prospect_phone, p.rating, p.reviews, p.is_customer,
                                    p.address, p.lat p_lat, p.lng p_lng
                               FROM visits v LEFT JOIN employees e ON e.id = v.employee_id
                               LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN prospects p ON p.id = v.prospect_id
                              WHERE ' . implode(' AND ', $where) . ' ORDER BY v.visited_at DESC, v.id DESC');
        $st->execute($args);
        return $st->fetchAll();
    }

    /** One 回收表 row per visit, columns = REPORT_HEADERS. */
    public static function report_row(array $v): array
    {
        $isProspect = $v['target_type'] === 'prospect';
        $lat = $isProspect && $v['p_lat'] !== null ? (float) $v['p_lat'] : '';
        $lng = $isProspect && $v['p_lng'] !== null ? (float) $v['p_lng'] : '';
        return [
            self::target_name($v),
            $isProspect ? (string) $v['category'] : '',
            $isProspect ? (string) $v['region'] : '',
            $isProspect && $v['priority'] !== null ? (float) $v['priority'] : '',
            (string) ($isProspect ? $v['prospect_phone'] : ($v['customer_phone'] ?? '')),
            $isProspect && $v['rating'] !== null ? (float) $v['rating'] : '',
            $isProspect && $v['reviews'] !== null ? (int) $v['reviews'] : '',
            $v['target_type'] === 'customer' || ($isProspect && (int) $v['is_customer'] === 1) ? 'Ya' : '',
            $isProspect ? (string) $v['address'] : '',
            $lat !== '' && $lng !== '' ? "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}" : '',
            $lat,
            $lng,
            (string) $v['sales'],
            $v['outcome'] === self::UNKNOWN ? '' : self::outcome_label((string) $v['outcome']),
            Hr::dmy(substr((string) $v['visited_at'], 0, 10)),
            self::catatan($v),
        ];
    }

    /**
     * Per salesperson: visits, each outcome and its share, new customers.
     * @return list<array{sales:string,total:int,counts:array<string,int>,new:int}>
     */
    public static function summary(array $visits): array
    {
        $by = [];
        foreach ($visits as $v) {
            $k = (string) $v['sales'];
            $by[$k] ??= ['sales' => $k, 'total' => 0, 'counts' => array_fill_keys([...array_keys(self::OUTCOMES), self::UNKNOWN], 0), 'new' => [], 'eid' => (int) $v['employee_id']];
            $by[$k]['total']++;
            $by[$k]['baru'] = ($by[$k]['baru'] ?? 0) + ((($v['status_lama_baru'] ?? null) ?: ($v['system_lama_baru'] ?? null)) === 'baru' ? 1 : 0);
            $by[$k]['counts'][$v['outcome']] = ($by[$k]['counts'][$v['outcome']] ?? 0) + 1;
            if ($v['target_type'] === 'new') {
                $by[$k]['new'][mb_strtolower(trim((string) $v['new_customer_name']))] = true;
            }
        }
        ksort($by, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_map(fn($r) => ['new' => count($r['new'])] + $r, $by));
    }

    /** @param array<int,int> $newProspects employee id => prospects that came from their visits this week */
    public static function summary_rows(array $summary, array $newProspects = []): array
    {
        $rows = [];
        foreach ($summary as $s) {
            $row = [$s['sales'], $s['total'], $s['baru'] ?? 0];
            foreach ([...array_keys(self::OUTCOMES), self::UNKNOWN] as $o) {
                $row[] = $s['counts'][$o];
                $row[] = $s['total'] ? round(100 * $s['counts'][$o] / $s['total'], 1) : 0;
            }
            $row[] = $s['new'];
            $row[] = (int) ($newProspects[$s['eid']] ?? 0);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function summary_headers(): array
    {
        $h = ['Sales', 'Kunjungan', 'Baru'];
        foreach ([...array_values(self::OUTCOMES), self::outcome_label(self::UNKNOWN)] as $label) {
            $h[] = $label;
            $h[] = $label . ' %';
        }
        $h[] = 'Pelanggan Baru';
        $h[] = 'Calon Baru';
        return $h;
    }

    // ───────────────────────────── prospects import ─────────────────────────────

    /** Header aliases of the Daftar Calon Pelanggan workbook (lower-case). */
    private const IMPORT_COLUMNS = [
        'name' => ['nama perusahaan', 'nama', 'name', 'nama usaha'], 'category' => ['kategori', 'category', 'tipe', 'type'],
        'region' => ['wilayah', 'region', 'area'], 'priority' => ['skor prioritas', 'skor', 'priority'], 'phone' => ['telepon', 'phone', 'telp', 'no hp'],
        'rating' => ['rating'], 'reviews' => ['jml ulasan', 'ulasan', 'reviews'], 'is_customer' => ['sudah pelanggan'], 'address' => ['alamat', 'address'],
        'lat' => ['lat', 'latitude'], 'lng' => ['lng', 'lon', 'long', 'longitude'], 'pic' => ['sales pic', 'pic', 'sales'],
        'result' => ['hasil follow-up', 'hasil follow up', 'hasil'], 'visit_date' => ['tanggal kunjungan'], 'note' => ['catatan'],
    ];

    public static function prospect_key(string $name, $lat, $lng): string
    {
        $n = preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';
        $ll = ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') ? sprintf('%.4f,%.4f', (float) $lat, (float) $lng) : '';
        return sha1($n . '|' . $ll);
    }

    /**
     * Import (or preview) prospect rows: first row = headers. Same key (name +
     * point) → update attributes; Sales PIC fills an empty assignment only.
     * Filled 回收表 columns (Hasil / Tanggal / Catatan) are counted, not imported.
     * @return array{rows:int,new:int,updated:int,skipped:int,customers:int,unknown_pic:array,filled_results:int,regions:array,categories:array,missing_columns:array}
     */
    public static function import_prospects(PDO $pdo, array $rows, bool $apply, string $source, string $operator, array $picAliases = []): array
    {
        $res = ['rows' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'customers' => 0, 'unknown_pic' => [], 'filled_results' => 0, 'regions' => [], 'categories' => [], 'missing_columns' => []];
        $head = array_map(fn($h) => mb_strtolower(trim((string) $h)), (array) array_shift($rows));
        $idx = [];
        foreach (self::IMPORT_COLUMNS as $field => $aliases) {
            foreach ($aliases as $a) {
                $i = array_search($a, $head, true);
                if ($i !== false) {
                    $idx[$field] = $i;
                    break;
                }
            }
        }
        foreach (['name', 'lat', 'lng'] as $must) {
            if (!isset($idx[$must])) {
                $res['missing_columns'][] = $must;
            }
        }
        if (!isset($idx['name'])) {
            return $res;
        }
        $staff = [];
        foreach ($pdo->query('SELECT e.id, e.full_name, u.name uname FROM employees e LEFT JOIN users u ON u.employee_id = e.id') as $s) {
            foreach ([$s['full_name'], $s['uname']] as $n) {
                if (trim((string) $n) !== '') {
                    $staff[mb_strtolower(trim((string) $n))] = (int) $s['id'];
                }
            }
        }
        $get = fn(array $r, string $f) => isset($idx[$f]) ? $r[$idx[$f]] ?? null : null;
        $find = $pdo->prepare('SELECT id, assigned_to FROM prospects WHERE source_key = ?');
        $ins = $pdo->prepare('INSERT INTO prospects (name, category, region, priority, phone, rating, reviews, is_customer, address, lat, lng, assigned_to, assigned_at, assigned_by, source, source_key, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $upd = $pdo->prepare('UPDATE prospects SET name = ?, category = ?, region = ?, priority = ?, phone = ?, rating = ?, reviews = ?, is_customer = ?, address = ?, lat = ?, lng = ?,
                                     assigned_to = COALESCE(assigned_to, ?), source = ?, updated_by = ?, updated_at = ? WHERE id = ?');
        $seen = [];
        if ($apply) {
            $pdo->beginTransaction();
        }
        foreach ($rows as $r) {
            $name = trim((string) $get($r, 'name'));
            if ($name === '') {
                continue;
            }
            $res['rows']++;
            $lat = is_numeric($get($r, 'lat')) ? (float) $get($r, 'lat') : null;
            $lng = is_numeric($get($r, 'lng')) ? (float) $get($r, 'lng') : null;
            $key = self::prospect_key($name, $lat, $lng);
            if (isset($seen[$key])) {   // the same place listed twice (e.g. two region sheets)
                $res['skipped']++;
                continue;
            }
            $seen[$key] = true;
            $isCustomer = in_array(mb_strtolower(trim((string) $get($r, 'is_customer'))), ['ya', 'yes', 'y', '1', 'sudah'], true) ? 1 : 0;
            $res['customers'] += $isCustomer;
            $pic = trim((string) $get($r, 'pic'));
            $pic = $picAliases[mb_strtolower($pic)] ?? $pic;   // --alias "Excel name=employee name"
            $assigned = null;
            if ($pic !== '') {
                $assigned = $staff[mb_strtolower($pic)] ?? null;
                if ($assigned === null) {
                    $res['unknown_pic'][$pic] = true;
                }
            }
            if (trim((string) $get($r, 'result')) !== '' || trim((string) $get($r, 'visit_date')) !== '') {
                $res['filled_results']++;
            }
            $region = trim((string) $get($r, 'region'));
            $category = trim((string) $get($r, 'category'));
            $res['regions'][$region ?: '—'] = ($res['regions'][$region ?: '—'] ?? 0) + 1;
            $res['categories'][$category ?: '—'] = ($res['categories'][$category ?: '—'] ?? 0) + 1;
            $vals = [$name, $category ?: null, $region ?: null, is_numeric($get($r, 'priority')) ? (float) $get($r, 'priority') : null,
                     trim((string) $get($r, 'phone')) ?: null, is_numeric($get($r, 'rating')) ? (float) $get($r, 'rating') : null,
                     is_numeric($get($r, 'reviews')) ? (int) $get($r, 'reviews') : null, $isCustomer, trim((string) $get($r, 'address')) ?: null, $lat, $lng];
            $find->execute([$key]);
            $ex = $find->fetch();
            if ($ex) {
                $res['updated']++;
                if ($apply) {
                    $upd->execute([...$vals, $assigned, $source, $operator, date('Y-m-d H:i:s'), $ex['id']]);
                }
            } else {
                $res['new']++;
                if ($apply) {
                    $ins->execute([...$vals, $assigned, $assigned ? date('Y-m-d H:i:s') : null, $assigned ? $operator : null, $source, $key, $operator]);
                }
            }
        }
        if ($apply) {
            $pdo->commit();
        }
        $res['unknown_pic'] = array_keys($res['unknown_pic']);
        ksort($res['regions']);
        ksort($res['categories']);
        return $res;
    }
}
