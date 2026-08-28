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
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
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
    custom_days      INTEGER DEFAULT 0,
    status           TEXT    NOT NULL DEFAULT 'draft',
                     -- draft|pending_sup|pending_mgr|pending_wh|approved|rejected
    sup_note     TEXT, sup_approver TEXT, sup_date TEXT,
    mgr_note     TEXT, mgr_approver TEXT, mgr_date TEXT,
    wh_note      TEXT, wh_approver  TEXT, wh_date  TEXT,
    reject_note  TEXT, reject_by    TEXT, reject_date TEXT,   -- last rejection (sent back to draft)
    do_number        TEXT,
    invoice_number   TEXT,
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
    price      REAL    NOT NULL DEFAULT 0
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

CREATE INDEX idx_deals_customer  ON deals(customer_id);
CREATE INDEX idx_tasks_customer  ON tasks(customer_id);
CREATE INDEX idx_items_order     ON order_items(order_id);
CREATE INDEX idx_stocktxn_prod   ON stock_txn(product_id);
CREATE INDEX idx_invitems_inv    ON invoice_items(invoice_id);
