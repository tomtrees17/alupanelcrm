-- AluPanel CRM schema (SQLite)
-- Aluminum composite panel (ACP) business, Indonesia market.

-- ── Users / staff (role drives the approval workflow) ──
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'sales',  -- admin|sales|supervisor|manager|warehouse
    title         TEXT,                              -- display job title
    phone         TEXT,                              -- WhatsApp number, 628xxx (notifications)
    lang          TEXT    NOT NULL DEFAULT 'id',     -- preferred UI + notification language
    must_change_password INTEGER NOT NULL DEFAULT 0, -- force a password change on next login
    employee_id   INTEGER,                           -- 人力: the person behind the account (HR-00)
    active        INTEGER NOT NULL DEFAULT 1,        -- 0 = deactivated on resignation; login refused, never deleted
    created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Customers ──
CREATE TABLE customers (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    company      TEXT,
    phone        TEXT,
    email        TEXT,
    city         TEXT,
    tag          TEXT    DEFAULT '潜在',   -- 重点|潜在|成交|流失
    value        REAL    DEFAULT 0,         -- potential value (IDR)
    note         TEXT,
    last_contact TEXT,
    owner        TEXT,                    -- responsible salesperson (name)
    -- inferred by tools/enrich_customers.php (区域 / 行业), see docs 6p
    industry            TEXT,             -- contractor|signage|aluminium_shop|renovation|end_owner|distributor|other
    industry_source     TEXT,             -- rule|ai|manual (manual is never overwritten)
    industry_confidence REAL,
    industry_pending    INTEGER NOT NULL DEFAULT 0,  -- 1 = 待确认 (AI confidence below threshold)
    industry_note       TEXT,             -- which rule hit / the model's reason
    region              TEXT,             -- jkt_utara..bogor_depok|other|unknown; NULL = no shipped order yet
    delivery_regions    TEXT,             -- JSON {region: order count} over all shipped orders
    enriched_at         TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    tax_npwp       TEXT,                  -- buyer tax data for Coretax (FA-TAX-01), kept by finance: NPWP 16 digits
    tax_nik        TEXT,                  -- NIK 16 digits (individual buyer)
    tax_name       TEXT,                  -- name as registered with the NPWP
    tax_address    TEXT,                  -- address as registered with the NPWP
    tax_nitku      TEXT,                  -- 22 digits; empty = NPWP/NIK + 000000
    tax_email      TEXT,
    tax_updated_by TEXT,
    tax_updated_at TEXT
);

-- ── Deals (sales pipeline) ──
CREATE TABLE deals (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
    value       REAL    DEFAULT 0,
    stage       TEXT    NOT NULL DEFAULT '初步接触',  -- 初步接触|需求确认|方案报价|谈判中|已成交
    close_date  TEXT,
    note        TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Tasks / reminders ──
CREATE TABLE tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    due_date    TEXT,
    priority    TEXT    DEFAULT '中',       -- 高|中|低
    customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
    done        INTEGER NOT NULL DEFAULT 0,
    note        TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Products (ACP sheets) ──
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT,
    name       TEXT    NOT NULL,
    color_zh   TEXT,
    color_en   TEXT,
    spec       TEXT,                         -- e.g. 4.0*0.30PVDF-FR
    size       TEXT    DEFAULT '1.220 x 2.440',
    category   TEXT,
    unit       TEXT    DEFAULT '张',
    price      REAL    NOT NULL DEFAULT 0,
    stock      INTEGER NOT NULL DEFAULT 0,
    reserved   INTEGER NOT NULL DEFAULT 0,   -- qty reserved by open (pending) orders
    min_stock  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Stock transactions (in / out / out_auto) ──
CREATE TABLE stock_txn (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    type       TEXT    NOT NULL,             -- in|out|out_auto
    qty        INTEGER NOT NULL,
    ref        TEXT,
    note       TEXT,
    txn_date   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Sales orders + 4-level approval workflow ──
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    order_no         TEXT    UNIQUE,         -- 0476/AMI-CO/05/26
    customer_id      INTEGER REFERENCES customers(id) ON DELETE SET NULL,
    customer_name    TEXT,
    company          TEXT,
    address          TEXT,
    phone            TEXT,
    client_type      TEXT,                   -- Contractor|Distributor|Retailer|End User
    delivery_service TEXT,                   -- Lala Move|Self Pickup|Truck|JNE ...
    delivery_address TEXT,
    submitter        TEXT,                   -- salesperson the order belongs to
    created_by       TEXT,                   -- who keyed it in (sales assistant); may differ from submitter
    shipping_cost    REAL    DEFAULT 0,
    delivery_date    TEXT,
    note             TEXT,
    payment_term     TEXT    DEFAULT 'CBD',  -- CBD|COD|custom
    price_basis      TEXT    NOT NULL DEFAULT 'incl', -- incl: prices typed tax-inclusive | excl: typed pre-tax (customer quoted before tax)
    custom_days      INTEGER DEFAULT 0,
    status           TEXT    NOT NULL DEFAULT 'draft',
                     -- draft|pending_sup|pending_mgr|pending_wh|approved|rejected
    sup_note     TEXT, sup_approver TEXT, sup_date TEXT,
    mgr_note     TEXT, mgr_approver TEXT, mgr_date TEXT,
    wh_note      TEXT, wh_approver  TEXT, wh_date  TEXT,
    reject_note  TEXT, reject_by    TEXT, reject_date TEXT,   -- last rejection (sent back to draft)
    do_number        TEXT,
    invoice_number   TEXT,
    co_no            TEXT,                   -- CO number as the customer knows it (= order_no from 1379 on; older orders link the Google-Form number here)
    delivery_lat     REAL,                   -- geocoded delivery point (NULL = none / not resolved)
    delivery_lng     REAL,
    delivery_region  TEXT,                   -- NULL = not processed yet; 'unknown' = could not resolve
    geo_source       TEXT,                   -- google|text|none
    created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
    sku        TEXT,
    color      TEXT,
    spec       TEXT,
    size       TEXT,
    qty        REAL    NOT NULL DEFAULT 0,
    unit       TEXT    DEFAULT 'Unit',
    price      REAL    NOT NULL DEFAULT 0,   -- tax-inclusive unit price (always set)
    price_excl REAL                          -- pre-tax unit price as typed, on price_basis = 'excl' orders
);

-- ── Delivery orders (issued by warehouse) ──
CREATE TABLE delivery_orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    do_no         TEXT    UNIQUE,
    order_id      INTEGER REFERENCES orders(id) ON DELETE SET NULL,
    customer      TEXT,
    company       TEXT,
    address       TEXT,
    phone         TEXT,
    delivery_address TEXT,
    delivery_service TEXT,
    pickup_date   TEXT,
    issued_by     TEXT,
    driver        TEXT,
    vehicle_plate TEXT,
    note          TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Invoices (PPN 11%, payment terms, receivables) ──
CREATE TABLE invoices (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_no    TEXT    UNIQUE,
    order_id      INTEGER REFERENCES orders(id) ON DELETE SET NULL,
    do_number     TEXT,
    customer      TEXT,
    bill_to_name  TEXT,
    address       TEXT,
    npwp          TEXT,
    no_po         TEXT,
    currency      TEXT    DEFAULT 'IDR',
    shipping_cost REAL    DEFAULT 0,
    subtotal      REAL    DEFAULT 0,
    ppn           REAL    DEFAULT 0,         -- VAT 11%
    rounding      REAL    NOT NULL DEFAULT 0,-- Pembulatan: makes total equal the order's inclusive amount
    calc          TEXT    NOT NULL DEFAULT 'legacy', -- legacy (whole rupiah, before 2026-10-21) | coretax (2 decimals)
    dpp           REAL,                      -- DPP Nilai Lain as on the tax invoice (coretax invoices only)
    total         REAL    DEFAULT 0,
    invoice_date  TEXT,
    due_date      TEXT,
    issued_by     TEXT,
    note          TEXT,
    payment_term  TEXT    DEFAULT 'custom',
    custom_days   INTEGER DEFAULT 30,
    payment_status TEXT   DEFAULT 'pending', -- paid|partial|pending|overdue
    amount_paid   REAL    DEFAULT 0,
    paid_date     TEXT,
    receipt_no    TEXT,
    payment_method TEXT,
    payment_note  TEXT,
    tax_invoice_no TEXT,
    voided_at     TEXT,                      -- set = 作废; excluded from receivables
    voided_by     TEXT,
    void_reason   TEXT,
    faktur_class  TEXT,                      -- FA-TAX-01 override: FAKTUR | GUNGGUNG | TBC; NULL = automatic
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE invoice_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    sku        TEXT, color TEXT, spec TEXT, size TEXT,
    qty        REAL    NOT NULL DEFAULT 0,
    unit       TEXT    DEFAULT 'Unit',
    price      REAL    NOT NULL DEFAULT 0
);

-- ── Payment receipts (history; also reflected on invoice) ──
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id  INTEGER REFERENCES invoices(id) ON DELETE CASCADE,
    customer    TEXT,
    amount      REAL    NOT NULL DEFAULT 0,   -- negative on a reversal row
    pay_date    TEXT,
    method      TEXT,
    receipt_no  TEXT,
    note        TEXT,
    created_by  TEXT,                          -- who registered / reversed it
    reversal_of INTEGER REFERENCES payments(id),-- set on the offsetting row only
    noncash     INTEGER NOT NULL DEFAULT 0,       -- 1 = settlement of an accepted shortfall (withholding tax, bank fee, rounding), not cash in
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_payments_invoice ON payments(invoice_id);

-- ── Administrative requests (business trip / expense / leave) ──
CREATE TABLE admin_requests (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    req_no      TEXT    UNIQUE,             -- BT- / EX- / LV- / PY- + YYYY-NNN
    type        TEXT    NOT NULL DEFAULT 'expense',   -- trip|expense|leave|payment
    applicant   TEXT,                       -- requester name
    title       TEXT,                       -- short subject (事由)
    destination TEXT,                       -- trip destination / payment payee
    category    TEXT,                       -- expense/payment category or leave type
    ref_no      TEXT,                       -- linked order / invoice / request number
    start_date  TEXT,
    end_date    TEXT,
    amount      REAL    DEFAULT 0,          -- expense amount / trip budget (IDR)
    reason      TEXT,                       -- detail note
    status      TEXT    NOT NULL DEFAULT 'draft',
                -- draft|pending_hr|pending_mgr|pending_fin|approved  (reject → back to draft)
                -- trip/expense/leave start at pending_hr; payment starts at pending_mgr
    hr_note     TEXT, hr_approver  TEXT, hr_date  TEXT,
    mgr_note    TEXT, mgr_approver TEXT, mgr_date TEXT,
    fin_note    TEXT, fin_approver TEXT, fin_date TEXT,
    reject_note TEXT, reject_by    TEXT, reject_date TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Attachments for administrative requests (stored under data/uploads/req/<id>/) ──
CREATE TABLE request_files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id  INTEGER NOT NULL REFERENCES admin_requests(id) ON DELETE CASCADE,
    stored      TEXT    NOT NULL,            -- random server-side filename
    orig_name   TEXT,                        -- original upload name (display)
    mime        TEXT,
    size        INTEGER DEFAULT 0,
    uploaded_by TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_reqfiles_req ON request_files(request_id);

-- ── Role → module access permissions (editable by admin) ──
CREATE TABLE role_permissions (
    role   TEXT NOT NULL,
    module TEXT NOT NULL,
    PRIMARY KEY (role, module)
);

-- ── App metadata (one-time migration markers, etc.) ──
CREATE TABLE app_meta (
    k TEXT PRIMARY KEY,
    v TEXT
);

-- ── Failed login attempts (brute-force throttle) ──
CREATE TABLE login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ip           TEXT,
    email        TEXT,
    attempt_time INTEGER NOT NULL DEFAULT 0   -- unix epoch of the failed attempt
);
CREATE INDEX idx_login_ip ON login_attempts(ip, attempt_time);

-- ── Outbound notifications (WhatsApp); a queue so a slow API never blocks a request ──
CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    phone      TEXT,                     -- resolved at queue time; kept even if the user changes it
    event      TEXT NOT NULL,            -- order_submitted / order_approved / request_pending / ...
    entity     TEXT, entity_id INTEGER,  -- what it is about
    label      TEXT,                     -- 单号, for the admin list
    body       TEXT NOT NULL,            -- fully rendered message
    status     TEXT NOT NULL DEFAULT 'queued',  -- queued|sent|failed|skipped
    attempts   INTEGER NOT NULL DEFAULT 0,
    error      TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    sent_at    TEXT
);
CREATE INDEX idx_notif_pending ON notifications(status, id);
CREATE INDEX idx_notif_user    ON notifications(user_id, id);

-- ── AI assistant queries (cost tracking + per-user rate limit) ──
CREATE TABLE ai_queries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    user_name     TEXT,
    question      TEXT NOT NULL,
    matched       TEXT,        -- comma-separated product ids the model picked
    ok            INTEGER NOT NULL DEFAULT 1,
    error         TEXT,
    in_tokens     INTEGER DEFAULT 0,
    cached_tokens INTEGER DEFAULT 0,
    out_tokens    INTEGER DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_ai_user_day ON ai_queries(user_id, created_at);

-- ── AI task layer (FA-AI-00, app/AiTask.php): one row per task call, every feature but the inventory assistant ──
CREATE TABLE ai_calls (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    task_type          TEXT NOT NULL,              -- ai.tasks key: industry | …
    ref_table          TEXT,                       -- the record the call is about (per-record 24 h guard)
    ref_id             INTEGER,
    model              TEXT,                       -- the model that answered (a server-side fallback may differ)
    prompt_version     INTEGER,                    -- prompts/{task}_v{n}.txt
    input_tokens       INTEGER NOT NULL DEFAULT 0,
    cached_tokens      INTEGER NOT NULL DEFAULT 0, -- cache reads
    cache_write_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens      INTEGER NOT NULL DEFAULT 0,
    cost_usd           REAL    NOT NULL DEFAULT 0, -- at config ai.prices
    latency_ms         INTEGER NOT NULL DEFAULT 0,
    attempts           INTEGER NOT NULL DEFAULT 0, -- 2 = the answer failed the schema once and was asked again
    status             TEXT    NOT NULL,           -- ok | invalid | api_error | over_budget | repeat_skipped
    error              TEXT,
    user_id            INTEGER,
    user_name          TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_ai_calls_day ON ai_calls(created_at);
CREATE INDEX idx_ai_calls_ref ON ai_calls(task_type, ref_table, ref_id, created_at);

-- What the model suggested for a record, and what a person finally confirmed (adoption rate, most-edited fields)
CREATE TABLE ai_suggestions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    ai_call_id     INTEGER REFERENCES ai_calls(id),
    task_type      TEXT NOT NULL,
    ref_table      TEXT NOT NULL,
    ref_id         INTEGER NOT NULL,
    ai_suggestion  TEXT NOT NULL,                  -- JSON
    ai_confidence  TEXT,                           -- high|medium|low or 0–1
    final_value    TEXT,                           -- JSON, as confirmed
    changed_fields TEXT,                           -- comma-separated top-level keys the person changed
    confirmed_by   TEXT,
    confirmed_at   TEXT,
    ai_accepted    INTEGER,                        -- 1 = taken as suggested
    created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_ai_sugg_ref ON ai_suggestions(ref_table, ref_id);
CREATE INDEX idx_ai_sugg_task ON ai_suggestions(task_type, created_at);

-- ── Audit trail (who changed what, append-only) ──
CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    user_id    INTEGER,           -- NULL for anonymous events (failed login)
    user_name  TEXT,              -- denormalised: survives user deletion
    user_role  TEXT,
    module     TEXT NOT NULL,     -- orders / finance / inventory / customers / users / roles / approvals / auth / ...
    action     TEXT NOT NULL,     -- create / update / delete / approve / reject / pay / adjust / login / ...
    entity     TEXT,              -- business object type: order / invoice / product / customer / user / request
    entity_id  INTEGER,
    label      TEXT,              -- human-readable id: 单号 / 名称
    detail     TEXT,              -- change summary ("field: old → new; ...") or note
    ip         TEXT
);
CREATE INDEX idx_audit_created ON audit_log(created_at);
CREATE INDEX idx_audit_user    ON audit_log(user_id);
CREATE INDEX idx_audit_entity  ON audit_log(entity, entity_id);

-- ── Geocoding results per normalised address (one paid lookup per address, ever) ──
CREATE TABLE geocode_cache (
    addr_key   TEXT PRIMARY KEY,          -- normalised address
    query      TEXT,
    status     TEXT NOT NULL,             -- ok|coarse|zero|error
    lat        REAL,
    lng        REAL,
    kota       TEXT,
    kecamatan  TEXT,
    error      TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Follow-ups (跟进记录) and "no need" dismissals of call-list items ──
CREATE TABLE customer_followups (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    user_id     INTEGER,
    user_name   TEXT,
    kind        TEXT NOT NULL DEFAULT 'followup',   -- followup (hides 7 days) | no_need (reason; hides 30 days)
    note        TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_followups_customer ON customer_followups(customer_id, created_at);

-- ── Call lists, recomputed nightly by tools/compute_insights.php (read-only to users) ──
CREATE TABLE customer_insights (
    customer_id     INTEGER PRIMARY KEY,
    computed_on     TEXT NOT NULL,
    last_order_date TEXT,
    last_order_id   INTEGER,
    last_items      TEXT,          -- "SJ8010 3.0*0.06 ×20, ..." of the last purchase
    order_count     INTEGER,       -- shipped orders, last 12 months
    purchase_days   INTEGER,       -- distinct purchase days (all time)
    total_amount    REAL,          -- last 12 months, tax-inclusive
    avg_interval    REAL,          -- days; NULL = no basis
    interval_source TEXT,          -- own|industry|global
    days_since      INTEGER,
    in_a INTEGER NOT NULL DEFAULT 0, in_b INTEGER NOT NULL DEFAULT 0, in_c INTEGER NOT NULL DEFAULT 0,
    overdue_days    INTEGER,       -- days_since − avg_interval
    overdue_red     INTEGER NOT NULL DEFAULT 0,
    top_specs       TEXT
);

-- ── Who was on which list each day (manager view: list processing rate) ──
CREATE TABLE insights_history (
    day         TEXT    NOT NULL,
    customer_id INTEGER NOT NULL,
    lists       TEXT    NOT NULL,   -- e.g. "a", "bc"
    PRIMARY KEY (day, customer_id)
);

-- ── 人力 / SDM (HR-00, HR-01): employees are the platform's "people" table ──
CREATE TABLE employees (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_no         TEXT UNIQUE,                 -- AMI-0001
    full_name           TEXT NOT NULL,
    nik                 TEXT UNIQUE,                 -- 16 digits; NULL until HR fills it (migrated accounts)
    npwp TEXT, birth_place TEXT, birth_date TEXT, gender TEXT, religion TEXT, marital_status TEXT,
    ktp_address TEXT, current_address TEXT, phone TEXT, personal_email TEXT,
    emergency_contact_name TEXT, emergency_contact_relation TEXT, emergency_contact_phone TEXT,
    bank_name TEXT, bank_account_no TEXT, bank_account_name TEXT,
    ptkp_status         TEXT,                        -- stored only (no tax maths in phase 1)
    department          TEXT,                        -- sales|warehouse|finance|office|management
    position            TEXT,
    work_location       TEXT,                        -- Jakarta|Surabaya|Bandung
    supervisor_id       INTEGER REFERENCES employees(id),
    is_dept_head        INTEGER NOT NULL DEFAULT 0,  -- 部门主管: reads own department (none today: flat team)
    attendance_mode     TEXT,                        -- visit (拜访: records visits, sales default) | office (打卡, not built) — HR-04
    join_date           TEXT,
    employment_status   TEXT NOT NULL DEFAULT 'active', -- probation|active|resigned
    resign_date TEXT, resign_reason TEXT,
    base_salary_enc     TEXT,                        -- AES-256-GCM, key HR_SALARY_KEY in .env
    salary_effective_date TEXT,
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE employee_attachments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id),
    doc_type    TEXT NOT NULL,                       -- ktp|npwp|kk|ijazah|foto|lainnya
    file_path   TEXT NOT NULL,                       -- stored name under data/hr/{employee_id}/
    file_name   TEXT, mime TEXT, size INTEGER,
    uploaded_by TEXT, uploaded_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE employee_changes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id    INTEGER NOT NULL REFERENCES employees(id),
    change_type    TEXT NOT NULL,                    -- position|salary|location|supervisor|status
    field TEXT, old_value TEXT, new_value TEXT,      -- salary values encrypted
    effective_date TEXT NOT NULL, note TEXT NOT NULL, operator TEXT,
    created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE hr_todos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    type        TEXT NOT NULL,                       -- contract_expiring|…|ai_budget (boss only, no employee) (see i18n hrtodo_*)
    employee_id INTEGER REFERENCES employees(id),
    ref         TEXT NOT NULL DEFAULT '',            -- e.g. contract id, so one todo per thing
    due_date    TEXT,
    status      TEXT NOT NULL DEFAULT 'open',        -- open|done|cancelled (resignation undone)
    handled_by TEXT, handled_at TEXT, handled_note TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (type, employee_id, ref)
);
-- HR-02 contracts
CREATE TABLE employment_contracts (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    contract_no          TEXT UNIQUE,                -- KK/AMI/{MM}/{YYYY}/{NNN}: start date's month/year, serial per year
    employee_id          INTEGER NOT NULL REFERENCES employees(id),
    contract_type        TEXT NOT NULL,              -- PKWT|PKWTT
    is_probation         INTEGER NOT NULL DEFAULT 0, -- PKWTT only
    probation_months     INTEGER,                    -- ≤ 3
    start_date           TEXT NOT NULL,
    end_date             TEXT,                       -- NULL for PKWTT
    position TEXT, work_location TEXT,
    salary_enc           TEXT,                       -- AES-256-GCM like employees.base_salary_enc
    signed_date          TEXT,
    status               TEXT NOT NULL DEFAULT 'draft', -- draft|active|expired|terminated|converted
    previous_contract_id INTEGER REFERENCES employment_contracts(id),
    renewal_count        INTEGER NOT NULL DEFAULT 0, -- PKWT renewals so far in the chain
    file_path TEXT, file_name TEXT, file_mime TEXT, file_size INTEGER,  -- scan under data/hr/{employee_id}/
    termination_date TEXT, termination_reason TEXT,
    synced_at            TEXT,                       -- copied salary/position/location to the employee record
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
-- HR-03 BPJS (rates only at company level, by effective month; money columns encrypted)
CREATE TABLE bpjs_kesehatan (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id      INTEGER NOT NULL UNIQUE REFERENCES employees(id),
    bpjs_no          TEXT,
    registered_date  TEXT,
    status           TEXT NOT NULL DEFAULT 'pending',  -- pending|active|pending_stop|stopped
    class            TEXT,                             -- I|II|III
    salary_base_enc  TEXT,                             -- HR override of the base (encrypted); NULL = employees.base_salary
    base_note        TEXT,                             -- why overridden (e.g. legal cap)
    deactivated_date TEXT,
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE bpjs_kesehatan_dependents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id),
    name        TEXT NOT NULL,
    relation    TEXT NOT NULL,                         -- spouse|child
    nik TEXT, birth_date TEXT, bpjs_no TEXT,
    active      INTEGER NOT NULL DEFAULT 1,            -- removed = 0 (never deleted)
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    removed_by TEXT, removed_at TEXT, removed_note TEXT
);
CREATE TABLE bpjs_ketenagakerjaan (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id      INTEGER NOT NULL UNIQUE REFERENCES employees(id),
    bpjs_no          TEXT,
    registered_date  TEXT,
    status           TEXT NOT NULL DEFAULT 'pending',
    jp_enrolled      INTEGER NOT NULL DEFAULT 1,
    salary_base_enc  TEXT,
    base_note        TEXT,
    deactivated_date TEXT,
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE bpjs_rates (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    effective_from TEXT NOT NULL UNIQUE,               -- YYYY-MM; in force until the next row
    kes_company REAL NOT NULL, kes_employee REAL NOT NULL,
    jht_company REAL NOT NULL, jht_employee REAL NOT NULL,
    jkk REAL NOT NULL, jkm REAL NOT NULL,
    jp_company REAL NOT NULL, jp_employee REAL NOT NULL,
    kes_cap REAL, jp_cap REAL,                         -- wage caps HR fills in (no built-in figures)
    note TEXT,
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE bpjs_monthly (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    period              TEXT NOT NULL,                 -- YYYY-MM
    run                 INTEGER NOT NULL DEFAULT 1,    -- locked once generated; recalculating = a new run
    employee_id         INTEGER NOT NULL REFERENCES employees(id),
    program             TEXT NOT NULL,                 -- kesehatan|ketenagakerjaan
    salary_base_enc TEXT, company_amount_enc TEXT, employee_amount_enc TEXT, total_enc TEXT,
    breakdown_enc       TEXT,                          -- JSON per component
    rates_id            INTEGER REFERENCES bpjs_rates(id),
    generated_by TEXT, generated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (period, run, employee_id, program)
);
-- HR-04 拜访记录 (visits replace the weekly Excel 回收表; prospects = the Daftar Calon Pelanggan list)
CREATE TABLE prospects (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    category      TEXT,                          -- Kategori (Kontraktor / Advertising / Toko Aluminium & Kaca / Interior …)
    region        TEXT,                          -- Wilayah as in the list
    priority      REAL,                          -- Skor Prioritas
    phone TEXT, rating REAL, reviews INTEGER,
    is_customer   INTEGER NOT NULL DEFAULT 0,    -- Sudah Pelanggan = Ya: kept, never offered as a prospect
    store_type    TEXT,                          -- from a visit (Toko / Workshop / Advertising …), v3
    address TEXT, lat REAL, lng REAL,
    assigned_to   INTEGER REFERENCES employees(id),
    assigned_at TEXT, assigned_by TEXT,
    last_visit_at TEXT, last_outcome TEXT,
    visit_count   INTEGER NOT NULL DEFAULT 0,
    source        TEXT,                          -- import file | visit (a new store met on a visit)
    source_key    TEXT UNIQUE,                   -- sha1(name | lat,lng): re-imports update, never duplicate
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE visits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    visit_no          TEXT UNIQUE,               -- KV-YYMM-NNNN
    employee_id       INTEGER REFERENCES employees(id),   -- NULL until the WhatsApp sender is mapped (employee_wa_ids)
    visited_at        TEXT NOT NULL,             -- message time (or the watermark's when sent > 2 h later); form: day + saving time
    target_type       TEXT NOT NULL,             -- customer|prospect|new
    customer_id       INTEGER REFERENCES customers(id),
    prospect_id       INTEGER REFERENCES prospects(id),
    new_customer_name TEXT,
    outcome           TEXT NOT NULL,             -- berminat|penawaran|order|tidak_butuh|tidak_terhubung|unknown
    note TEXT, current_supplier TEXT, current_price TEXT,
    photo_path TEXT, photo_name TEXT, photo_mime TEXT,  -- main photo; form: data/visits/{employee_id}/, import: data/visits/{yyyymm}/
    photos            TEXT,                      -- JSON list of all stored photos (import); NULL entries = left out of the export
    lat REAL, lng REAL, accuracy REAL,           -- form: phone (if already allowed); import: geocoded watermark address
    distance_m        INTEGER,
    distance_flag     TEXT NOT NULL DEFAULT 'none',  -- ok|far|none
    followup_id       INTEGER,                   -- the customer_followups row this visit wrote
    -- HR-04 v3: WhatsApp group import
    source            TEXT NOT NULL DEFAULT 'manual',  -- manual|wa_import
    wa_sender TEXT, wa_message_ts TEXT,
    wa_hash           TEXT UNIQUE,               -- sha1(sender | time | caption): re-imports skip, a media import only adds photos
    wa_import_id      INTEGER,
    caption           TEXT,                      -- the message as sent
    store_name_raw    TEXT,                      -- the store as written (Toko: line / first line / AI)
    store_type TEXT, pic_name TEXT, needs TEXT,
    status_lama_baru  TEXT,                      -- lama|baru as the salesperson wrote it (four-line format)
    system_lama_baru  TEXT,                      -- lama|baru from the match (customer = lama); a mismatch is only shown
    address_text      TEXT,                      -- watermark address lines
    watermark_at      TEXT,
    late_flag         INTEGER NOT NULL DEFAULT 0,  -- 补发 (watermark > 2 h before the message, or a date line)
    photo_is_store    TEXT,                      -- yes|no|unsure (watermark read)
    competitor_brand TEXT, competitor_price INTEGER,
    format_ok         INTEGER NOT NULL DEFAULT 0,  -- complete: tags #Lama/#Baru + a result (rule v2), or the shelved four-line format
    has_tags          INTEGER NOT NULL DEFAULT 0,  -- any trailing tag (rule v2); quality only
    unknown_tags      TEXT,                      -- JSON #words not known yet (maybe a brand) → supervisor
    template_text     INTEGER NOT NULL DEFAULT 0,  -- an old template sentence (quality only)
    match_status      TEXT,                      -- auto|pending|confirmed|new|manual (import); NULL = form
    match_score REAL, match_candidates TEXT,     -- JSON top candidates for the confirmation queue
    ai_parse TEXT, ai_outcome_guess TEXT, ai_photo TEXT, ai_match TEXT,   -- JSON answers (suggestions only)
    ai_parse_status TEXT, ai_photo_status TEXT, ai_match_status TEXT,     -- ok|failed|skipped; NULL = not tried
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_by TEXT, updated_at TEXT
);
CREATE TABLE employee_wa_ids (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id),
    wa_key      TEXT NOT NULL UNIQUE,            -- WaChat::sender_key(): phone digits or folded name
    wa_display  TEXT,                            -- as the export wrote it
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE customer_name_aliases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id  INTEGER REFERENCES customers(id),
    prospect_id  INTEGER REFERENCES prospects(id),
    alias        TEXT NOT NULL,                  -- the store name as the group writes it
    alias_key    TEXT NOT NULL UNIQUE,           -- VisitImport::name_key()
    source       TEXT NOT NULL,                  -- confirm (supervisor) | manual
    confirmed_by TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE visit_tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_key    TEXT NOT NULL UNIQUE,             -- lower case, without #
    label      TEXT NOT NULL,                    -- brand as shown
    kind       TEXT NOT NULL,                    -- brand (joins the brand list) | ignore (not a brand)
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE wa_imports (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name TEXT, file_hash TEXT,
    messages     INTEGER NOT NULL DEFAULT 0,
    visits_found INTEGER NOT NULL DEFAULT 0,     -- visit candidates in the file
    visits_new   INTEGER NOT NULL DEFAULT 0,
    photos_added INTEGER NOT NULL DEFAULT 0,
    ignored      INTEGER NOT NULL DEFAULT 0,
    unmapped     TEXT,                           -- JSON senders not yet mapped to an employee
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE visit_digests (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    week        TEXT NOT NULL,                   -- YYYY-Www
    text_zh TEXT, text_id TEXT,
    ai_call_id  INTEGER,
    created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_emp_attach ON employee_attachments(employee_id);
CREATE INDEX idx_visits_emp ON visits(employee_id, visited_at);
CREATE INDEX idx_visits_day ON visits(visited_at);
CREATE INDEX idx_visits_prospect ON visits(prospect_id);
CREATE INDEX idx_prospects_assigned ON prospects(assigned_to);
CREATE INDEX idx_visits_match ON visits(match_status);
CREATE INDEX idx_visits_sender ON visits(wa_sender);
CREATE INDEX idx_digests_week ON visit_digests(week, id);
CREATE INDEX idx_bpjs_deps ON bpjs_kesehatan_dependents(employee_id);
CREATE INDEX idx_contracts_emp ON employment_contracts(employee_id, status);
CREATE INDEX idx_contracts_end ON employment_contracts(status, end_date);
CREATE INDEX idx_emp_changes ON employee_changes(employee_id, id);
CREATE INDEX idx_hr_todos_open ON hr_todos(status, due_date);

CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_deals_customer  ON deals(customer_id);
CREATE INDEX idx_tasks_customer  ON tasks(customer_id);
CREATE INDEX idx_items_order     ON order_items(order_id);
CREATE INDEX idx_stocktxn_prod   ON stock_txn(product_id);
CREATE INDEX idx_invitems_inv    ON invoice_items(invoice_id);

-- ── Historical sales: pre-platform orders imported from the old bookkeeping app (read-only; never touch stock / AR) ──
CREATE TABLE sales_history (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_no        TEXT    NOT NULL UNIQUE,     -- old app's 单据编号, e.g. XSD202609010005
    doc_date      TEXT    NOT NULL,            -- 单据日期 (ISO); yearly sales count history by this date
    customer_name TEXT    NOT NULL,            -- as written in the old app
    customer_id   INTEGER,                     -- matched platform customer, NULL = not matched / walk-in
    salesperson   TEXT,                        -- as written in the old app
    submitter     TEXT,                        -- matched users.name (same meaning as orders.submitter)
    goods_amount  REAL    NOT NULL DEFAULT 0,  -- 折后金额
    shipping      REAL    NOT NULL DEFAULT 0,  -- 运费
    amount        REAL    NOT NULL DEFAULT 0,  -- 本单应收 (tax-inclusive total)
    paid          REAL    NOT NULL DEFAULT 0,  -- 本单实收; old app booked everything as settled in cash
    note          TEXT,
    source        TEXT    NOT NULL DEFAULT 'legacy_excel',
    batch         TEXT,                        -- import run label, for --purge-batch
    imported_by   TEXT,
    imported_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_sales_history_date ON sales_history(doc_date);
CREATE INDEX idx_sales_history_customer ON sales_history(customer_id);

CREATE TABLE sales_history_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    history_id INTEGER NOT NULL REFERENCES sales_history(id) ON DELETE CASCADE,
    line_no    INTEGER NOT NULL DEFAULT 1,
    product_id INTEGER,                        -- matched products.id by sku + spec, NULL = not in catalogue
    sku        TEXT,
    color      TEXT,
    spec       TEXT,
    qty        REAL    NOT NULL DEFAULT 0,
    unit       TEXT,
    price      REAL    NOT NULL DEFAULT 0,     -- tax-inclusive unit price after discount
    amount     REAL    NOT NULL DEFAULT 0,
    raw_name   TEXT,                           -- 商品名称 exactly as exported
    note       TEXT                            -- 样品 / 理赔 / 退换 etc.
);
CREATE INDEX idx_sales_history_items ON sales_history_items(history_id);

-- ── Bank statements and collection matching (FA-AI-01, app/Bank.php) ──
CREATE TABLE bank_accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    bank       TEXT NOT NULL,                      -- BCA | ICBC
    last4      TEXT NOT NULL,                      -- the account number is never stored in full
    currency   TEXT NOT NULL DEFAULT 'IDR',
    parser     TEXT NOT NULL,                      -- bca_csv | icbc_csv
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (parser, last4)
);
CREATE TABLE bank_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL REFERENCES bank_accounts(id), file_name TEXT, period TEXT,
    rows_total INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, skipped INTEGER NOT NULL DEFAULT 0, pending INTEGER NOT NULL DEFAULT 0,
    credit_sum REAL NOT NULL DEFAULT 0, credit_n INTEGER NOT NULL DEFAULT 0, debit_sum REAL NOT NULL DEFAULT 0, debit_n INTEGER NOT NULL DEFAULT 0,
    file_credit REAL, file_credit_n INTEGER, file_debit REAL, file_debit_n INTEGER,   -- the file's own footer
    totals_ok INTEGER, balance_ok INTEGER, rule_matched INTEGER NOT NULL DEFAULT 0,
    imported_by TEXT, imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE bank_transactions (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id        INTEGER NOT NULL REFERENCES bank_accounts(id),
    import_id         INTEGER,
    trx_date          TEXT NOT NULL,               -- 回款日期 = this date
    amount            REAL NOT NULL,
    direction         TEXT NOT NULL,               -- in | out
    description       TEXT NOT NULL,               -- Keterangan / Summary as exported
    branch            TEXT,
    balance           REAL,
    counterparty_name TEXT,                        -- payer (BCA, by rule from the note) / receiver (ICBC)
    refs              TEXT,                        -- JSON: invoice / CO numbers found in the note
    hash              TEXT NOT NULL UNIQUE,        -- re-imports skip known lines
    trx_code          TEXT,                        -- BCA-IN-2610-0001 (Buku Bank numbering)
    tag               TEXT NOT NULL DEFAULT 'other', -- goods|platform|salary|tax|internal|interest|fee|social|other
    match_status      TEXT NOT NULL DEFAULT 'na',  -- unmatched|rule|ai|confirmed|non_ar|na (ICBC, outgoing)
    match_note        TEXT,
    proposal          TEXT,                        -- JSON: rule / AI proposal {source, lines, diff_amount, diff_reason}
    ai_suggestion_id  INTEGER,                     -- ai_suggestions.id
    ai_confidence     TEXT,
    ai_reason         TEXT,
    ai_status         TEXT,                        -- ok|none|no_candidates|failed|over_budget
    non_ar_reason     TEXT,                        -- capital|refund|interest|internal|other
    confirmed_by      TEXT,
    confirmed_at      TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_bank_trx_date ON bank_transactions(account_id, trx_date);
CREATE INDEX idx_bank_trx_status ON bank_transactions(match_status, trx_date);
CREATE INDEX idx_bank_trx_code ON bank_transactions(trx_code);
CREATE TABLE payment_allocations (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id        INTEGER NOT NULL REFERENCES bank_transactions(id),
    invoice_id            INTEGER NOT NULL REFERENCES invoices(id),
    amount                REAL NOT NULL,           -- cash put on this invoice
    diff_amount           REAL NOT NULL DEFAULT 0, -- amount − what the invoice owed (− short, + over)
    diff_reason           TEXT NOT NULL DEFAULT 'none', -- none|partial|overpaid|fee|withholding|rounding|other
    payment_id            INTEGER REFERENCES payments(id),
    settle_payment_id     INTEGER REFERENCES payments(id), -- the non-cash row closing a shortfall
    refund_transaction_id INTEGER REFERENCES bank_transactions(id), -- overpayment refunded by this outgoing line
    bukti_potong_at       TEXT,                    -- withholding: slip received
    created_by            TEXT,
    created_at            TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_alloc_trx ON payment_allocations(transaction_id);
CREATE INDEX idx_alloc_invoice ON payment_allocations(invoice_id);
CREATE TABLE customer_payer_aliases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id  INTEGER NOT NULL REFERENCES customers(id),
    payer_name   TEXT NOT NULL,                    -- as the bank writes it (cut at ~18 characters)
    payer_key    TEXT NOT NULL,                    -- upper case, punctuation folded
    source       TEXT NOT NULL,                    -- history (Buku Bank import) | confirmed (finance)
    confirmed_by TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (payer_key, customer_id)
);
-- Buku Bank IN lines from the finance workbook (tools/import_bank_book.php): alias seed + matching benchmark
CREATE TABLE bank_book (
    id INTEGER PRIMARY KEY AUTOINCREMENT, trx_code TEXT NOT NULL UNIQUE, trx_date TEXT NOT NULL, description TEXT NOT NULL,
    amount REAL NOT NULL, invoice_raw TEXT, invoice_ids TEXT, customer_id INTEGER, payer_name TEXT,
    batch TEXT, imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ── Output tax invoices for Coretax (FA-TAX-01, app/Faktur.php): files generated per period ──
CREATE TABLE faktur_exports (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT NOT NULL,                  -- faktur (DJP import template) | gunggung (retail total list)
    period_from   TEXT NOT NULL,
    period_to     TEXT NOT NULL,
    stored        TEXT NOT NULL,                  -- file name under data/faktur/ (private)
    file_name     TEXT NOT NULL,                  -- download name
    invoices      INTEGER NOT NULL DEFAULT 0,
    lines         INTEGER NOT NULL DEFAULT 0,
    dpp_total     REAL NOT NULL DEFAULT 0,
    ppn_total     REAL NOT NULL DEFAULT 0,
    superseded_at TEXT,                           -- set when the same period is generated again
    created_by    TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
-- ── CO number register: imported from the Google Form "Request CO Number" the sales used before the platform issued numbers ──
CREATE TABLE co_requests (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    seq         INTEGER NOT NULL UNIQUE,     -- 1378
    co_no       TEXT    NOT NULL UNIQUE,     -- 1378/AMI-CO/10/26
    issued_at   TEXT    NOT NULL,            -- form timestamp
    sales_name  TEXT,                        -- as chosen on the form (Ridwan / Angga W / Mr. Fang …)
    company     TEXT,
    client_name TEXT,
    phone       TEXT,
    order_id    INTEGER,                     -- platform order this number belongs to, once linked
    linked_by   TEXT,
    linked_at   TEXT,
    link_note   TEXT,                        -- auto / manual / why
    source      TEXT    NOT NULL DEFAULT 'google_form',
    batch       TEXT,
    imported_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_co_requests_order ON co_requests(order_id);
