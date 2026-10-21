<?php
declare(strict_types=1);

/**
 * 人力 / SDM — HR-02 employment contracts (docs/任务卡-人力一期.md).
 * Decisions taken with the owner (2026-10-21):
 *   - contract_no KK/AMI/{MM}/{YYYY}/{NNN}: month and year of the start date,
 *     serial restarts every year; fixed once the contract leaves draft.
 *   - when a contract takes effect its salary / position / work location are
 *     copied to the employee record, with a change-history row.
 *   - historical (paper) contracts may be entered with their real dates; on
 *     activation a contract whose end date has passed becomes "expired".
 *   - no server cron: scan() runs on every 人力 page load (it is idempotent),
 *     so expiry and reminders are always current when someone looks.
 * "One active contract at a time" = no two active contracts with overlapping
 * periods (a renewal starting the day after the old one ends may be activated
 * in advance).
 */
final class HrContract
{
    public const TYPES = ['PKWT', 'PKWTT'];
    public const STATUSES = ['draft', 'active', 'expired', 'terminated', 'converted'];
    /** Legal reminder thresholds (card: hint only, never blocks). */
    public const MAX_RENEWALS = 2;
    public const MAX_PKWT_YEARS = 5;

    public static function next_no(PDO $pdo, string $startDate, int $exceptId = 0): string
    {
        $y = substr($startDate, 0, 4);
        $m = substr($startDate, 5, 2);
        $st = $pdo->prepare('SELECT contract_no FROM employment_contracts WHERE contract_no LIKE ? AND id <> ?');
        $st->execute(["KK/AMI/%/{$y}/%", $exceptId]);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('#^KK/AMI/\d{2}/\d{4}/(\d+)$#', (string) $no, $mm)) {
                $max = max($max, (int) $mm[1]);
            }
        }
        return sprintf('KK/AMI/%s/%s/%03d', $m, $y, $max + 1);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM employment_contracts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** PKWT renewals so far in this chain: 0 for a first PKWT, previous + 1 after a PKWT. */
    public static function renewal_count(PDO $pdo, string $type, ?int $previousId): int
    {
        if ($type !== 'PKWT' || !$previousId) {
            return 0;
        }
        $prev = self::find($pdo, $previousId);
        return ($prev && $prev['contract_type'] === 'PKWT') ? (int) $prev['renewal_count'] + 1 : 0;
    }

    public static function days(string $from, string $to): int
    {
        return (int) ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a'));
    }

    public static function add_days(string $ymd, int $n): string
    {
        return (new DateTimeImmutable($ymd))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
    }

    /** Total PKWT days along the chain ending at $c (inclusive of both ends). */
    public static function pkwt_days(PDO $pdo, array $c): int
    {
        $total = 0;
        $seen = [];
        while ($c && !isset($seen[$c['id'] ?? 0])) {
            $seen[$c['id'] ?? 0] = true;
            if ($c['contract_type'] === 'PKWT' && $c['end_date']) {
                $total += self::days((string) $c['start_date'], (string) $c['end_date']) + 1;
            }
            $c = $c['previous_contract_id'] ? self::find($pdo, (int) $c['previous_contract_id']) : null;
        }
        return $total;
    }

    /** Red hint on renewal: already renewed twice, or the chain would pass 5 years. */
    public static function over_limit(PDO $pdo, array $current, ?string $newEnd = null): bool
    {
        if ((int) $current['renewal_count'] >= self::MAX_RENEWALS) {
            return true;
        }
        $days = self::pkwt_days($pdo, $current);
        if ($newEnd !== null && $current['end_date']) {
            $days += self::days(self::add_days((string) $current['end_date'], 1), $newEnd) + 1;
        }
        return $days > self::MAX_PKWT_YEARS * 365 + 1;
    }

    public static function probation_end(array $c): ?string
    {
        if ((int) $c['is_probation'] !== 1 || !(int) $c['probation_months']) {
            return null;
        }
        return self::months_end((string) $c['start_date'], (int) $c['probation_months']);
    }

    /** Last day of an N-month period from $start: 15-01 + 1 → 14-02; 31-01 + 1 → 28-02 (no overflow into March). */
    public static function months_end(string $start, int $months): string
    {
        $s = new DateTimeImmutable($start);
        $target = $s->modify('first day of this month')->modify("+{$months} month");
        if ((int) $s->format('j') > (int) $target->format('t')) {
            return $target->modify('last day of this month')->format('Y-m-d');
        }
        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), (int) $s->format('j'))->modify('-1 day')->format('Y-m-d');
    }

    /** Another active contract of this employee whose period overlaps [start, end]? */
    public static function overlapping(PDO $pdo, int $empId, string $start, ?string $end, int $exceptId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM employment_contracts WHERE employee_id = ? AND id <> ? AND status = 'active'
                              AND start_date <= COALESCE(?, '9999-12-31') AND COALESCE(end_date, '9999-12-31') >= ?");
        $st->execute([$empId, $exceptId, $end, $start]);
        return $st->fetch() ?: null;
    }

    /** The contract in force today, else the latest active one. */
    public static function current(PDO $pdo, int $empId, string $today): ?array
    {
        $st = $pdo->prepare("SELECT * FROM employment_contracts WHERE employee_id = ? AND status = 'active'
                              ORDER BY (start_date <= ? AND COALESCE(end_date, '9999-12-31') >= ?) DESC, start_date DESC LIMIT 1");
        $st->execute([$empId, $today, $today]);
        return $st->fetch() ?: null;
    }

    /** Has HR decided what follows this contract (a renewal / PKWTT draft or later contract exists)? */
    public static function has_successor(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM employment_contracts WHERE previous_contract_id = ? AND status <> 'terminated'");
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** Close this contract's expiry todos once HR has decided. */
    public static function close_todos(PDO $pdo, int $contractId, string $note, string $operator): void
    {
        $pdo->prepare("UPDATE hr_todos SET status = 'done', handled_by = ?, handled_at = datetime('now','localtime'), handled_note = ?
                       WHERE ref = ? AND type IN ('contract_expiring', 'contract_expired', 'probation_end') AND status = 'open'")
            ->execute([$operator, $note, 'contract:' . $contractId]);
    }

    /**
     * Copy salary / position / work location of a contract that has taken
     * effect onto the employee record (owner decision), with history + audit.
     */
    public static function sync(PDO $pdo, array $c, string $operator): void
    {
        $pdo->prepare("UPDATE employment_contracts SET synced_at = datetime('now','localtime') WHERE id = ?")->execute([$c['id']]);
        $st = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
        $st->execute([$c['employee_id']]);
        $e = $st->fetch();
        if (!$e || $e['employment_status'] === 'resigned') {
            return;
        }
        $set = [];
        $note = (string) $c['contract_no'];
        foreach (['position' => 'position', 'work_location' => 'location'] as $field => $type) {
            if ((string) $c[$field] !== '' && (string) $c[$field] !== (string) $e[$field]) {
                $set[$field] = $c[$field];
                Hr::record_change($pdo, (int) $e['id'], $type, $field, $e[$field], (string) $c[$field], (string) $c['start_date'], $note, $operator);
            }
        }
        $newSalary = Hr::decrypt($c['salary_enc']);
        $oldSalary = Hr::decrypt($e['base_salary_enc']);
        $salaryChanged = $newSalary !== null && $newSalary !== $oldSalary;
        if ($salaryChanged) {
            Hr::record_change($pdo, (int) $e['id'], 'salary', 'base_salary', $oldSalary, $newSalary, (string) $c['start_date'], $note, $operator);
            $set['base_salary_enc'] = Hr::encrypt($newSalary);
            $set['salary_effective_date'] = $c['start_date'];
        }
        if (!$set) {
            return;
        }
        $set['updated_by'] = $operator;
        $set['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE employees SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), $e['id']]);
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        Hr::audit_fields($pdo, 'update', 'employees', (int) $e['id'], $label,
            array_intersect_key($e, array_flip(['position', 'work_location'])), array_intersect_key($set, array_flip(['position', 'work_location'])));
        if ($salaryChanged) {
            audit($pdo, 'hr', 'update', 'employees', (int) $e['id'], $label, "employees.base_salary: (已修改，金额不记入日志); {$note}");
        }
    }

    /**
     * Idempotent housekeeping, run on every 人力 page load (owner: no cron):
     * active → expired after the end date; sync contracts that have started;
     * reminders — PKWT 30 days before the end, "expired, undecided" from the end
     * date on. (Probation end is shown on the contract, not raised as a todo: 收尾.)
     */
    public static function scan(PDO $pdo, string $today): void
    {
        $operator = 'system';
        $due = $pdo->prepare("SELECT * FROM employment_contracts WHERE status = 'active' AND end_date IS NOT NULL AND end_date < ?");
        $due->execute([$today]);
        foreach ($due->fetchAll() as $c) {
            $pdo->prepare("UPDATE employment_contracts SET status = 'expired', updated_by = ?, updated_at = ? WHERE id = ? AND status = 'active'")
                ->execute([$operator, date('Y-m-d H:i:s'), $c['id']]);
            audit($pdo, 'hr', 'update', 'employment_contracts', (int) $c['id'], (string) $c['contract_no'], 'employment_contracts.status: active → expired (到期自动)');
        }
        $start = $pdo->prepare("SELECT * FROM employment_contracts WHERE status = 'active' AND synced_at IS NULL AND start_date <= ?");
        $start->execute([$today]);
        foreach ($start->fetchAll() as $c) {
            self::sync($pdo, $c, $operator);
        }
        $pk = $pdo->query("SELECT c.* FROM employment_contracts c JOIN employees e ON e.id = c.employee_id
            WHERE c.contract_type = 'PKWT' AND c.status IN ('active', 'expired') AND c.end_date IS NOT NULL AND e.employment_status <> 'resigned'
              AND NOT EXISTS (SELECT 1 FROM employment_contracts n WHERE n.previous_contract_id = c.id AND n.status <> 'terminated')
              AND NOT EXISTS (SELECT 1 FROM employment_contracts o WHERE o.employee_id = c.employee_id AND o.id <> c.id
                              AND o.status IN ('draft', 'active') AND o.start_date > c.end_date)");
        foreach ($pk->fetchAll() as $c) {
            if ($c['end_date'] <= $today) {
                Hr::add_todo($pdo, 'contract_expired', (int) $c['employee_id'], (string) $c['end_date'], 'contract:' . $c['id']);
            } elseif ($c['end_date'] <= self::add_days($today, 30)) {
                Hr::add_todo($pdo, 'contract_expiring', (int) $c['employee_id'], (string) $c['end_date'], 'contract:' . $c['id']);
            }
        }
    }
}
