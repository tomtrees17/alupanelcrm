-- AluPanel CRM schema (SQLite)

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'sales',  -- admin | sales
    created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE customers (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    company      TEXT    NOT NULL,
    contact_name TEXT,
    email        TEXT,
    phone        TEXT,
    address      TEXT,
    city         TEXT,
    country      TEXT,
    notes        TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT,
    name        TEXT    NOT NULL,
    description TEXT,
    spec        TEXT,
    unit        TEXT    DEFAULT 'pc',
    price       REAL    NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE quotes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    quote_no    TEXT,
    customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    status      TEXT    NOT NULL DEFAULT 'draft',  -- draft|sent|accepted|ordered|completed|cancelled
    quote_date  TEXT,
    valid_until TEXT,
    notes       TEXT,
    tax_rate    REAL    NOT NULL DEFAULT 0,
    subtotal    REAL    NOT NULL DEFAULT 0,
    tax_amount  REAL    NOT NULL DEFAULT 0,
    total       REAL    NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE quote_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    quote_id   INTEGER NOT NULL REFERENCES quotes(id) ON DELETE CASCADE,
    product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
    description TEXT,
    qty        REAL    NOT NULL DEFAULT 1,
    unit_price REAL    NOT NULL DEFAULT 0,
    line_total REAL    NOT NULL DEFAULT 0
);

CREATE INDEX idx_quotes_customer ON quotes(customer_id);
CREATE INDEX idx_items_quote     ON quote_items(quote_id);
