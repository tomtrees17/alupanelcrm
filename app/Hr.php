<?php
declare(strict_types=1);

/**
 * 人力 / SDM — HR-00 (shared rules) and HR-01 (employee records); contracts (HR-02) in HrContract.
 * Spec: docs/任务卡-人力一期.md. Decisions taken with the owner (2026-10-21):
 *   - viewers: hr role edits everything; the boss (admin) reads everything incl.
 *     salary; the general manager (permission 'hr_all', default: manager role)
 *     reads everything except salary; an employee HR ticks as 部门主管 reads
 *     their own department without salary; everyone else reads only their own
 *     record (own salary visible). Structure is flat today: nobody is ticked.
 *   - salary amounts are AES-256-GCM encrypted with HR_SALARY_KEY from .env and
 *     never written to audit_log (only "changed", effective date, reason).
 *   - nothing is deleted; every change is audited field by field.
 */
final class Hr
{
    public const DEPARTMENTS = ['sales', 'warehouse', 'finance', 'office', 'management'];
    public const LOCATIONS = ['Jakarta', 'Surabaya', 'Bandung'];
    public const STATUSES = ['probation', 'active', 'resigned'];
    public const GENDERS = ['L', 'P'];
    public const RELIGIONS = ['Islam', 'Kristen Protestan', 'Katolik', 'Hindu', 'Buddha', 'Konghucu'];
    public const MARITAL = ['belum_kawin', 'kawin', 'cerai_hidup', 'cerai_mati'];
    public const PTKP = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];
    /** HR-04: visit = records visits on the phone (sales default); office = 打卡 (not built). */
    public const ATTENDANCE_MODES = ['visit', 'office'];
    public const DOC_TYPES = ['ktp', 'npwp', 'kk', 'ijazah', 'foto', 'lainnya', 'bpjs_kes', 'bpjs_tk'];
    public const CHANGE_TYPES = ['position', 'salary', 'location', 'supervisor', 'status'];
    /**
     * The only todos the platform raises (收尾 2026-10-21, "不加负担"): contract
     * ending / ended undecided, BPJS not registered in time, BPJS to stop after
     * a resignation. Anything else (probation end, …) stays as data, no todo.
     */
    public const TODO_TYPES = ['contract_expiring', 'contract_expired', 'bpjs_overdue', 'bpjs_stop'];
    /**
     * Platform todos for the boss alone, not tied to an employee (FA-AI-00: the
     * AI budget was used up). HR never sees them; the boss may mark them done.
     */
    public const BOSS_TODO_TYPES = ['ai_budget', 'sales_review'];

    /** Fields HR fills on the basic-info form (no effective date needed). */
    public const BASIC_FIELDS = [
        'full_name', 'nik', 'npwp', 'birth_place', 'birth_date', 'gender', 'religion', 'marital_status',
        'ktp_address', 'current_address', 'phone', 'personal_email',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'bank_name', 'bank_account_no', 'bank_account_name', 'ptkp_status', 'attendance_mode',
    ];
    /** Job fields: every change needs an effective date and a note, and lands in employee_changes. */
    public const JOB_FIELDS = ['department', 'position', 'work_location', 'supervisor_id', 'employment_status', 'is_dept_head'];

    /** What "资料完整度" counts (NPWP is optional by law, supervisor is optional in a flat team). */
    public const COMPLETENESS_FIELDS = [
        'full_name', 'nik', 'birth_place', 'birth_date', 'gender', 'religion', 'marital_status',
        'ktp_address', 'current_address', 'phone', 'personal_email',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
        'bank_name', 'bank_account_no', 'bank_account_name', 'ptkp_status',
        'department', 'position', 'work_location', 'join_date', 'base_salary_enc',
    ];

    /** Key for tests; production reads HR_SALARY_KEY from .env via config. */
    public static ?string $keyOverride = null;

    // ───────────────────────────── who sees what ─────────────────────────────

    /**
     * The viewer's HR scope.
     * @return array{edit:bool,scope:string,salary:bool,department:?string,employee_id:?int}
     *   scope: all | department | self | none
     */
    public static function viewer(PDO $pdo): array
    {
        $auth = $GLOBALS['auth'] ?? null;
        $user = $auth ? $auth->user() : null;
        $none = ['edit' => false, 'scope' => 'none', 'salary' => false, 'department' => null, 'employee_id' => null];
        if (!$user) {
            return $none;
        }
        $empId = isset($user['employee_id']) && $user['employee_id'] !== null ? (int) $user['employee_id'] : null;
        $emp = null;
        if ($empId) {
            $st = $pdo->prepare('SELECT id, department, is_dept_head FROM employees WHERE id = ?');
            $st->execute([$empId]);
            $emp = $st->fetch() ?: null;
        }
        $role = (string) ($user['role'] ?? '');
        if ($role === 'hr') {
            return ['edit' => true, 'scope' => 'all', 'salary' => true, 'department' => null, 'employee_id' => $empId];
        }
        if ($role === 'admin') {   // the boss: read everything, salary included
            return ['edit' => false, 'scope' => 'all', 'salary' => true, 'department' => null, 'employee_id' => $empId];
        }
        if (can_access('hr_all')) {  // general manager: everything except salary
            return ['edit' => false, 'scope' => 'all', 'salary' => false, 'department' => null, 'employee_id' => $empId];
        }
        if ($emp && (int) $emp['is_dept_head'] === 1 && (string) $emp['department'] !== '') {
            return ['edit' => false, 'scope' => 'department', 'salary' => false, 'department' => (string) $emp['department'], 'employee_id' => $empId];
        }
        if ($emp) {
            return ['edit' => false, 'scope' => 'self', 'salary' => false, 'department' => null, 'employee_id' => $empId];
        }
        return $none;
    }

    /** May this viewer read this employee? */
    public static function can_see(array $viewer, array $employee): bool
    {
        return match ($viewer['scope']) {
            'all'        => true,
            'department' => (string) $employee['department'] === $viewer['department'] || (int) $employee['id'] === $viewer['employee_id'],
            'self'       => (int) $employee['id'] === $viewer['employee_id'],
            default      => false,
        };
    }

    /** May this viewer see this employee's salary? (own salary is always visible to oneself) */
    public static function can_see_salary(array $viewer, array $employee): bool
    {
        return $viewer['salary'] || ($viewer['employee_id'] !== null && (int) $employee['id'] === $viewer['employee_id']);
    }

    /** SQL filter for lists, matching can_see(). @return array{0:string,1:array} */
    public static function scope_sql(array $viewer, string $alias = 'e'): array
    {
        return match ($viewer['scope']) {
            'all'        => ['1=1', []],
            'department' => ["({$alias}.department = ? OR {$alias}.id = ?)", [$viewer['department'], (int) $viewer['employee_id']]],
            'self'       => ["{$alias}.id = ?", [(int) $viewer['employee_id']]],
            default      => ['0=1', []],
        };
    }

    // ───────────────────────────── salary encryption ─────────────────────────────

    private static function key(): ?string
    {
        $raw = self::$keyOverride ?? (string) ($GLOBALS['config']['hr']['salary_key'] ?? '');
        if ($raw === '') {
            return null;
        }
        $k = base64_decode($raw, true);
        return ($k !== false && strlen($k) === 32) ? $k : null;
    }

    public static function crypto_ready(): bool
    {
        return self::key() !== null;
    }

    /** "v1:" + base64(iv | tag | ciphertext). Throws when no key is configured. */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('HR_SALARY_KEY is not configured');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    /** null when empty, unreadable, or no key (never throws on read). */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, 'v1:')) {
            return null;
        }
        $key = self::key();
        $bin = base64_decode(substr($stored, 3), true);
        if ($key === null || $bin === false || strlen($bin) < 29) {
            return null;
        }
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === false ? null : $pt;
    }

    // ───────────────────────────── records ─────────────────────────────

    public static function next_employee_no(PDO $pdo): string
    {
        $max = 0;
        foreach ($pdo->query("SELECT employee_no FROM employees WHERE employee_no LIKE 'AMI-%'") as $r) {
            if (preg_match('/^AMI-(\d+)$/', (string) $r['employee_no'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('AMI-%04d', $max + 1);
    }

    /** 0–100. */
    public static function completeness(array $e): int
    {
        $filled = 0;
        foreach (self::COMPLETENESS_FIELDS as $f) {
            if (trim((string) ($e[$f] ?? '')) !== '') {
                $filled++;
            }
        }
        return (int) floor(100 * $filled / count(self::COMPLETENESS_FIELDS));
    }

    public static function valid_nik(string $nik): bool
    {
        return (bool) preg_match('/^\d{16}$/', $nik);
    }

    /** The employee_no that already holds this NIK, or null. */
    public static function nik_taken(PDO $pdo, string $nik, int $exceptId = 0): ?string
    {
        $st = $pdo->prepare('SELECT employee_no FROM employees WHERE nik = ? AND id <> ?');
        $st->execute([$nik, $exceptId]);
        $no = $st->fetchColumn();
        return $no === false ? null : (string) $no;
    }

    /** 2026-10-22 → 22-10-2026 (Indonesian convention on HR pages). */
    public static function dmy(?string $ymd): string
    {
        if ($ymd === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) {
            return '';
        }
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    public static function valid_date(string $d): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }

    /**
     * Field-level audit (HR-00: table, record id, field, old, new, operator, time).
     * One audit_log row per changed field; salary values are never written.
     */
    public static function audit_fields(PDO $pdo, string $action, string $table, int $id, string $label, array $before, array $after): void
    {
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if ((string) $old === (string) $new) {
                continue;
            }
            $detail = str_contains($field, 'salary')
                ? "{$table}.{$field}: (已修改，金额不记入日志)"
                : sprintf('%s.%s: %s → %s', $table, $field, self::audit_value($old), self::audit_value($new));
            audit($pdo, 'hr', $action, $table, $id, $label, $detail);
        }
    }

    private static function audit_value($v): string
    {
        $s = trim((string) $v);
        return $s === '' ? '(空)' : mb_strimwidth($s, 0, 200, '…');
    }

    /** Append to the employee's change history (salary values stored encrypted). */
    public static function record_change(PDO $pdo, int $empId, string $type, string $field, ?string $old, ?string $new, string $effective, string $note, string $operator): void
    {
        if (str_contains($field, 'salary')) {
            $old = $old === null || $old === '' ? null : self::encrypt($old);
            $new = $new === null || $new === '' ? null : self::encrypt($new);
        }
        $pdo->prepare('INSERT INTO employee_changes (employee_id, change_type, field, old_value, new_value, effective_date, note, operator) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$empId, $type, $field, $old, $new, $effective, $note, $operator]);
    }

    /** A pending todo, once per (type, employee, reference). Types outside TODO_TYPES are not raised. */
    public static function add_todo(PDO $pdo, string $type, ?int $empId, string $due, string $ref = ''): void
    {
        if (!in_array($type, self::TODO_TYPES, true)) {
            return;
        }
        $pdo->prepare('INSERT OR IGNORE INTO hr_todos (type, employee_id, ref, due_date) VALUES (?,?,?,?)')
            ->execute([$type, $empId, $ref, $due]);
    }

    /**
     * One-time (app_meta 'hr_employees_v1'): an employee record for every
     * existing CRM account except the system administrator account, linked via
     * users.employee_id. Department from the CRM role as the company is staffed
     * today (2026-10-21: the only supervisor-role account is in finance; the
     * clerk is office staff). Everything else is left for HR to fill in.
     */
    public static function migrate_users(PDO $pdo): int
    {
        if ($pdo->query("SELECT 1 FROM app_meta WHERE k = 'hr_employees_v1'")->fetchColumn()) {
            return 0;
        }
        $deptByRole = [
            'sales' => 'sales', 'supervisor' => 'finance', 'finance_manager' => 'finance', 'warehouse' => 'warehouse',
            'clerk' => 'office', 'hr' => 'office', 'manager' => 'management', 'ops_supervisor' => 'management',
            'sales_manager' => 'sales', 'sales_assistant' => 'sales', 'finance_assistant' => 'finance',
        ];
        $n = 0;
        $users = $pdo->query("SELECT id, name, role FROM users WHERE role <> 'admin' AND employee_id IS NULL ORDER BY id")->fetchAll();
        $ins = $pdo->prepare("INSERT INTO employees (employee_no, full_name, department, work_location, employment_status, attendance_mode, created_by, updated_by)
                              VALUES (?, ?, ?, 'Jakarta', 'active', ?, 'system', 'system')");
        $link = $pdo->prepare('UPDATE users SET employee_id = ? WHERE id = ?');
        foreach ($users as $u) {
            $dept = $deptByRole[(string) $u['role']] ?? null;
            $ins->execute([self::next_employee_no($pdo), (string) $u['name'], $dept, $dept === 'sales' ? 'visit' : null]);   // HR-04: sales record visits
            $link->execute([(int) $pdo->lastInsertId(), (int) $u['id']]);
            $n++;
        }
        $pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('hr_employees_v1', '1')");
        return $n;
    }

    // ───────────────────────────── resignation ─────────────────────────────

    /**
     * HR-01 resignation, also reached from HR-02 contract termination:
     * status → resigned, linked CRM accounts deactivated (never deleted), active
     * contracts terminated (owner 2026-10-22), both BPJS programs → 待停保 with
     * a "BPJS 停保" todo (the only todo since 收尾).
     * Returns the todo reference of this resignation.
     */
    public static function resign(PDO $pdo, array $e, string $date, string $reason, string $operator): string
    {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE employees SET employment_status = 'resigned', resign_date = ?, resign_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([$date, $reason, $operator, date('Y-m-d H:i:s'), $e['id']]);
        self::record_change($pdo, (int) $e['id'], 'status', 'employment_status', (string) $e['employment_status'], 'resigned', $date, $reason, $operator);
        $ref = 'resign:' . $pdo->lastInsertId();   // one set of todos per resignation (it can be undone and redone)
        self::add_todo($pdo, 'bpjs_stop', (int) $e['id'], $date, $ref);
        $accounts = $pdo->prepare('SELECT id, name, active FROM users WHERE employee_id = ?');
        $accounts->execute([$e['id']]);
        $accts = $accounts->fetchAll();
        $pdo->prepare('UPDATE users SET active = 0 WHERE employee_id = ?')->execute([$e['id']]);
        $pdo->commit();
        $label = $e['employee_no'] . ' ' . $e['full_name'];
        self::audit_fields($pdo, 'resign', 'employees', (int) $e['id'], $label,
            ['employment_status' => $e['employment_status'], 'resign_date' => $e['resign_date'], 'resign_reason' => $e['resign_reason']],
            ['employment_status' => 'resigned', 'resign_date' => $date, 'resign_reason' => $reason]);
        foreach ($accts as $u) {
            audit($pdo, 'hr', 'resign', 'users', (int) $u['id'], (string) $u['name'], 'users.active: ' . (int) $u['active'] . ' → 0 (离职停用)');
        }
        if (class_exists('HrBpjs')) {
            HrBpjs::on_resign($pdo, (int) $e['id'], $label);   // HR-03: both programs → 待停保
        }
        if (class_exists('HrContract')) {
            HrContract::on_resign($pdo, (int) $e['id'], $date, $reason, $operator);   // charter §七: one click switches the contract too
        }
        return $ref;
    }

    // ───────────────────────────── attachments ─────────────────────────────

    /**
     * Validate an uploaded file (≤10MB; extension AND content pdf / jpg / png)
     * and move it into the employee's private dir under a random name.
     * @return array{error?:string, stored?:string, name?:string, mime?:string, size?:int}  error = i18n key
     */
    public static function accept_upload(?array $file, int $empId, ?string $dir = null, ?array $exts = null): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'hr_err_upload'];
        }
        $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if ($exts !== null) {   // e.g. visit photos: images only
            $allowed = array_intersect_key($allowed, array_flip($exts));
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
            return ['error' => 'hr_err_too_big'];
        }
        if (!isset($allowed[$ext]) || self::sniff((string) $file['tmp_name']) !== $allowed[$ext]) {
            return ['error' => 'hr_err_type'];
        }
        $dir ??= self::upload_dir($empId);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $stored)
            : (PHP_SAPI === 'cli' && rename((string) $file['tmp_name'], $dir . '/' . $stored));   // test harness only
        if (!$moved) {
            return ['error' => 'hr_err_upload'];
        }
        return ['stored' => $stored, 'name' => (string) $file['name'], 'mime' => $allowed[$ext], 'size' => (int) $file['size']];
    }

    /** Stream a stored private file (after the caller's permission check). */
    public static function send_file(int $empId, string $stored, ?string $mime, string $name, ?string $dir = null): void
    {
        $path = ($dir ?? self::upload_dir($empId)) . '/' . basename($stored);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** Tests point this at a temp dir so a run on the server never writes into data/. */
    public static ?string $uploadRoot = null;

    public static function upload_dir(int $empId): string
    {
        return (self::$uploadRoot ?? dirname(__DIR__) . '/data/hr') . '/' . $empId;
    }

    /** Magic-byte sniffing for the allowed set (pdf / jpg / png). */
    public static function sniff(string $path): ?string
    {
        return self::sniff_bytes((string) @file_get_contents($path, false, null, 0, 8));
    }

    /** Same, on bytes already in memory (e.g. a photo inside a zip). */
    public static function sniff_bytes(string $bytes): ?string
    {
        $h = substr($bytes, 0, 8);
        if (strncmp($h, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($h, '%PDF', 4) === 0) {
            return 'application/pdf';
        }
        return null;
    }
}
