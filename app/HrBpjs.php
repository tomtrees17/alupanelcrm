<?php
declare(strict_types=1);

/**
 * 人力 / SDM — HR-03 BPJS Kesehatan + BPJS Ketenagakerjaan (docs/任务卡-人力一期.md).
 * Decisions taken with the owner (2026-10-21):
 *   - rates live only in the company-level table bpjs_rates, one row per
 *     effective month; a month's list uses the row in force that month and
 *     keeps a reference to it. Employee records carry no rates (only JP
 *     enrolment and Kesehatan class).
 *   - wage caps HR fills in: Kesehatan and JP (no built-in figures).
 *   - Kesehatan covers worker + 1 spouse + 3 children; each extra dependant
 *     adds 1% of the (capped) base to the employee share.
 *   - bases and amounts reveal salaries: encrypted, visible only to salary
 *     viewers (HR, the boss, the employee themself). The general manager sees
 *     statuses only; department heads see no BPJS (HR-00 matrix).
 * Membership in a month: registered on or before the month's last day and not
 * deactivated before its first day (the month of deactivation is still due).
 * Each component is rounded to whole rupiah.
 */
final class HrBpjs
{
    public const PROGRAMS = ['kesehatan', 'ketenagakerjaan'];
    public const STATUSES = ['pending', 'active', 'pending_stop', 'stopped'];
    public const CLASSES = ['I', 'II', 'III'];
    public const RELATIONS = ['spouse', 'child'];
    /** JKK by industry risk level (PP 44/2015). */
    public const JKK_LEVELS = ['0.24' => 'sangat_rendah', '0.54' => 'rendah', '0.89' => 'sedang', '1.27' => 'tinggi', '1.74' => 'sangat_tinggi'];
    /** Prefill for the first rate row; HR confirms against the current regulation. */
    public const DEFAULT_RATES = [
        'kes_company' => 4.0, 'kes_employee' => 1.0, 'jht_company' => 3.7, 'jht_employee' => 2.0,
        'jkk' => 0.24, 'jkm' => 0.3, 'jp_company' => 2.0, 'jp_employee' => 1.0,
    ];
    public const RATE_FIELDS = ['kes_company', 'kes_employee', 'jht_company', 'jht_employee', 'jkk', 'jkm', 'jp_company', 'jp_employee'];
    public const COVERED_SPOUSES = 1;
    public const COVERED_CHILDREN = 3;

    public static function table(string $program): string
    {
        return $program === 'kesehatan' ? 'bpjs_kesehatan' : 'bpjs_ketenagakerjaan';
    }

    /** BPJS reach of a viewer: all (HR, boss, general manager) or self. Department heads: self only. */
    public static function scope(array $viewer): string
    {
        return $viewer['scope'] === 'all' ? 'all' : ($viewer['employee_id'] ? 'self' : 'none');
    }

    public static function can_see(array $viewer, array $employee): bool
    {
        return self::scope($viewer) === 'all' || (int) $employee['id'] === (int) $viewer['employee_id'];
    }

    /** Monthly list and rates: people who see every salary (HR edits, the boss reads). */
    public static function can_see_money(array $viewer): bool
    {
        return $viewer['scope'] === 'all' && $viewer['salary'];
    }

    public static function record(PDO $pdo, string $program, int $empId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ' . self::table($program) . ' WHERE employee_id = ?');
        $st->execute([$empId]);
        return $st->fetch() ?: null;
    }

    /** Both records for every employee who is not resigned (idempotent). */
    public static function ensure_records(PDO $pdo): void
    {
        foreach (self::PROGRAMS as $p) {
            $pdo->exec('INSERT OR IGNORE INTO ' . self::table($p) . " (employee_id, status, created_by)
                        SELECT id, 'pending', 'system' FROM employees WHERE employment_status <> 'resigned'");
        }
    }

    /** The base actually used: HR override, else the employee's base salary. Plain string or null. */
    public static function base(array $record, array $employee): ?string
    {
        return Hr::decrypt($record['salary_base_enc'] ?? null) ?? Hr::decrypt($employee['base_salary_enc'] ?? null);
    }

    /** Rate row in force for a period (YYYY-MM), or null. */
    public static function rates_for(PDO $pdo, string $period): ?array
    {
        $st = $pdo->prepare('SELECT * FROM bpjs_rates WHERE effective_from <= ? ORDER BY effective_from DESC LIMIT 1');
        $st->execute([$period]);
        return $st->fetch() ?: null;
    }

    public static function valid_period(string $p): bool
    {
        return (bool) preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p);
    }

    public static function period_bounds(string $period): array
    {
        $first = $period . '-01';
        return [$first, (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d')];
    }

    /** Dependants beyond the covered family (1 spouse + 3 children). */
    public static function extra_dependents(PDO $pdo, int $empId): int
    {
        $st = $pdo->prepare('SELECT relation, COUNT(*) n FROM bpjs_kesehatan_dependents WHERE employee_id = ? AND active = 1 GROUP BY relation');
        $st->execute([$empId]);
        $n = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return max(0, (int) ($n['spouse'] ?? 0) - self::COVERED_SPOUSES) + max(0, (int) ($n['child'] ?? 0) - self::COVERED_CHILDREN);
    }

    private static function pct(float $base, float $rate): int
    {
        return (int) round($base * $rate / 100);
    }

    /** Kesehatan amounts for one person. */
    public static function calc_kesehatan(float $base, array $rates, int $extraDeps): array
    {
        $b = $rates['kes_cap'] !== null && (float) $rates['kes_cap'] > 0 ? min($base, (float) $rates['kes_cap']) : $base;
        $company = self::pct($b, (float) $rates['kes_company']);
        $own = self::pct($b, (float) $rates['kes_employee']);
        $extra = $extraDeps * self::pct($b, 1.0);
        return ['base' => $b, 'company' => $company, 'employee' => $own + $extra, 'total' => $company + $own + $extra,
                'parts' => ['kes_company' => $company, 'kes_employee' => $own, 'extra_dependents' => $extraDeps, 'extra_amount' => $extra]];
    }

    /** Ketenagakerjaan amounts for one person (JP on the capped base, only if enrolled). */
    public static function calc_ketenagakerjaan(float $base, array $rates, bool $jp): array
    {
        $jpBase = $rates['jp_cap'] !== null && (float) $rates['jp_cap'] > 0 ? min($base, (float) $rates['jp_cap']) : $base;
        $parts = [
            'jht_company' => self::pct($base, (float) $rates['jht_company']), 'jht_employee' => self::pct($base, (float) $rates['jht_employee']),
            'jkk' => self::pct($base, (float) $rates['jkk']), 'jkm' => self::pct($base, (float) $rates['jkm']),
            'jp_company' => $jp ? self::pct($jpBase, (float) $rates['jp_company']) : 0, 'jp_employee' => $jp ? self::pct($jpBase, (float) $rates['jp_employee']) : 0,
        ];
        $company = $parts['jht_company'] + $parts['jkk'] + $parts['jkm'] + $parts['jp_company'];
        $employee = $parts['jht_employee'] + $parts['jp_employee'];
        return ['base' => $base, 'company' => $company, 'employee' => $employee, 'total' => $company + $employee, 'parts' => $parts + ['jp_base' => $jp ? $jpBase : 0]];
    }

    /** Next run number of a period (a generated month is locked; recalculating makes a new run). */
    public static function next_run(PDO $pdo, string $period): int
    {
        $st = $pdo->prepare('SELECT COALESCE(MAX(run), 0) FROM bpjs_monthly WHERE period = ?');
        $st->execute([$period]);
        return (int) $st->fetchColumn() + 1;
    }

    /**
     * Snapshot a month. @return array{run:int, rows:int, missing:list<string>}
     * missing = members without any base (left out; HR fixes and generates a new run).
     */
    public static function generate(PDO $pdo, string $period, array $rates, string $operator): array
    {
        [$first, $last] = self::period_bounds($period);
        $run = self::next_run($pdo, $period);
        $ins = $pdo->prepare('INSERT INTO bpjs_monthly (period, run, employee_id, program, salary_base_enc, company_amount_enc, employee_amount_enc, total_enc, breakdown_enc, rates_id, generated_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $rows = 0;
        $missing = [];
        $pdo->beginTransaction();
        foreach (self::PROGRAMS as $p) {
            $st = $pdo->prepare('SELECT b.*, e.employee_no, e.full_name, e.base_salary_enc FROM ' . self::table($p) . " b JOIN employees e ON e.id = b.employee_id
                                  WHERE b.registered_date IS NOT NULL AND b.registered_date <= ? AND b.status IN ('active', 'pending_stop', 'stopped')
                                    AND (b.deactivated_date IS NULL OR b.deactivated_date >= ?) ORDER BY e.employee_no");
            $st->execute([$last, $first]);
            foreach ($st->fetchAll() as $r) {
                $base = self::base($r, $r);
                if ($base === null || (float) $base <= 0) {
                    $missing[] = $r['employee_no'] . ' ' . $r['full_name'] . ' (' . ($p === 'kesehatan' ? 'Kesehatan' : 'Ketenagakerjaan') . ')';
                    continue;
                }
                $c = $p === 'kesehatan'
                    ? self::calc_kesehatan((float) $base, $rates, self::extra_dependents($pdo, (int) $r['employee_id']))
                    : self::calc_ketenagakerjaan((float) $base, $rates, (int) $r['jp_enrolled'] === 1);
                $ins->execute([$period, $run, $r['employee_id'], $p, Hr::encrypt((string) $c['base']), Hr::encrypt((string) $c['company']),
                    Hr::encrypt((string) $c['employee']), Hr::encrypt((string) $c['total']), Hr::encrypt(json_encode($c['parts'])), $rates['id'], $operator]);
                $rows++;
            }
        }
        $pdo->commit();
        return ['run' => $run, 'rows' => $rows, 'missing' => array_values(array_unique($missing))];
    }

    /** One run decrypted, grouped per employee: [employee_id => ['no','name','kesehatan'=>[...],'ketenagakerjaan'=>[...]]]. */
    public static function load_run(PDO $pdo, string $period, int $run): array
    {
        $st = $pdo->prepare('SELECT m.*, e.employee_no, e.full_name FROM bpjs_monthly m JOIN employees e ON e.id = m.employee_id
                              WHERE m.period = ? AND m.run = ? ORDER BY e.employee_no, m.program');
        $st->execute([$period, $run]);
        $out = [];
        foreach ($st->fetchAll() as $m) {
            $id = (int) $m['employee_id'];
            $out[$id] ??= ['no' => $m['employee_no'], 'name' => $m['full_name'], 'kesehatan' => null, 'ketenagakerjaan' => null];
            $out[$id][$m['program']] = [
                'base' => (float) Hr::decrypt($m['salary_base_enc']), 'company' => (int) Hr::decrypt($m['company_amount_enc']),
                'employee' => (int) Hr::decrypt($m['employee_amount_enc']), 'total' => (int) Hr::decrypt($m['total_enc']),
                'parts' => (array) json_decode((string) Hr::decrypt($m['breakdown_enc']), true), 'generated_at' => $m['generated_at'], 'generated_by' => $m['generated_by'],
            ];
        }
        return $out;
    }

    // ───────────────────────────── lifecycle ─────────────────────────────

    /** Resignation: both programs → pending_stop (HR then fills the deactivation date). */
    public static function on_resign(PDO $pdo, int $empId, string $label): void
    {
        foreach (self::PROGRAMS as $p) {
            $r = self::record($pdo, $p, $empId);
            if ($r && in_array($r['status'], ['pending', 'active'], true)) {
                $pdo->prepare('UPDATE ' . self::table($p) . " SET status = 'pending_stop', updated_by = 'system', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $r['id']]);
                audit($pdo, 'hr', 'resign', self::table($p), (int) $r['id'], $label, self::table($p) . '.status: ' . $r['status'] . ' → pending_stop');
            }
        }
    }

    /** Resignation undone: pending_stop goes back (active if registered, else pending). */
    public static function on_unresign(PDO $pdo, int $empId, string $label): void
    {
        foreach (self::PROGRAMS as $p) {
            $r = self::record($pdo, $p, $empId);
            if ($r && $r['status'] === 'pending_stop') {
                $to = $r['registered_date'] ? 'active' : 'pending';
                $pdo->prepare('UPDATE ' . self::table($p) . ' SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$to, 'system', date('Y-m-d H:i:s'), $r['id']]);
                audit($pdo, 'hr', 'unresign', self::table($p), (int) $r['id'], $label, self::table($p) . ".status: pending_stop → {$to}");
            }
        }
    }

    /**
     * Idempotent housekeeping on every 人力 page load (owner: no cron): records
     * for everyone employed; "BPJS 逾期未登记" 30 days after joining; the
     * resignation's "BPJS 停保" todo closes once both programs are stopped.
     */
    public static function scan(PDO $pdo, string $today): void
    {
        self::ensure_records($pdo);
        $limit = HrContract::add_days($today, -30);
        foreach (self::PROGRAMS as $p) {
            $st = $pdo->prepare('SELECT b.employee_id, e.join_date FROM ' . self::table($p) . " b JOIN employees e ON e.id = b.employee_id
                                  WHERE b.status = 'pending' AND e.employment_status <> 'resigned' AND e.join_date IS NOT NULL AND e.join_date <= ?");
            $st->execute([$limit]);
            foreach ($st->fetchAll() as $r) {
                Hr::add_todo($pdo, 'bpjs_overdue', (int) $r['employee_id'], HrContract::add_days((string) $r['join_date'], 30), $p);
            }
            // Registered since: the reminder is done.
            $pdo->exec("UPDATE hr_todos SET status = 'done', handled_by = 'system', handled_at = datetime('now','localtime'), handled_note = 'registered'
                        WHERE type = 'bpjs_overdue' AND ref = '{$p}' AND status = 'open'
                          AND employee_id IN (SELECT employee_id FROM " . self::table($p) . " WHERE status <> 'pending')");
        }
        $pdo->exec("UPDATE hr_todos SET status = 'done', handled_by = 'system', handled_at = datetime('now','localtime'), handled_note = 'stopped'
                    WHERE type = 'bpjs_stop' AND status = 'open'
                      AND employee_id IN (SELECT k.employee_id FROM bpjs_kesehatan k JOIN bpjs_ketenagakerjaan t ON t.employee_id = k.employee_id
                                          WHERE k.status = 'stopped' AND t.status = 'stopped')");
    }
}
