<?php

declare(strict_types=1);

return [
    'id' => '001_platform',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_system TEXT NOT NULL DEFAULT 'naming_system',
    source_id TEXT,
    source_version TEXT,
    sku TEXT NOT NULL COLLATE NOCASE,
    slug TEXT NOT NULL COLLATE NOCASE,
    name TEXT NOT NULL,
    series_code TEXT NOT NULL DEFAULT '',
    category_slug TEXT NOT NULL DEFAULT '',
    subcategory_slug TEXT NOT NULL DEFAULT '',
    stock_group TEXT NOT NULL DEFAULT '',
    summary TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    specs_json TEXT NOT NULL DEFAULT '{}',
    features_json TEXT NOT NULL DEFAULT '[]',
    image_path TEXT NOT NULL DEFAULT '',
    badge TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'active', 'inactive', 'discontinued')),
    order_enabled INTEGER NOT NULL DEFAULT 0 CHECK (order_enabled IN (0, 1)),
    sample_enabled INTEGER NOT NULL DEFAULT 0 CHECK (sample_enabled IN (0, 1)),
    price_mode TEXT NOT NULL DEFAULT 'review'
        CHECK (price_mode IN ('fixed', 'from', 'review')),
    base_currency TEXT NOT NULL DEFAULT 'USD',
    base_price NUMERIC,
    default_moq NUMERIC NOT NULL DEFAULT 1 CHECK (default_moq > 0),
    lead_time_text TEXT NOT NULL DEFAULT '',
    lead_time_days INTEGER,
    stock_quantity NUMERIC NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
    is_new INTEGER NOT NULL DEFAULT 0 CHECK (is_new IN (0, 1)),
    is_clearance INTEGER NOT NULL DEFAULT 0 CHECK (is_clearance IN (0, 1)),
    synced_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT,
    UNIQUE (sku),
    UNIQUE (slug)
);

CREATE INDEX IF NOT EXISTS idx_products_catalog
    ON products (status, category_slug, subcategory_slug, sku);
CREATE INDEX IF NOT EXISTS idx_products_source
    ON products (source_system, source_id);
CREATE INDEX IF NOT EXISTS idx_products_ready_stock
    ON products (status, stock_group, stock_quantity);

CREATE TABLE IF NOT EXISTS product_configuration_schemas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    source_system TEXT NOT NULL DEFAULT 'naming_system',
    schema_json TEXT NOT NULL,
    checksum TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'active', 'archived')),
    published_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE (product_id, version)
);

CREATE INDEX IF NOT EXISTS idx_configuration_schema_active
    ON product_configuration_schemas (product_id, status, version);

CREATE TABLE IF NOT EXISTS project_carts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL,
    session_key_hash TEXT,
    customer_id INTEGER,
    owner_user_id INTEGER,
    project_name TEXT NOT NULL DEFAULT '',
    currency TEXT NOT NULL DEFAULT 'USD',
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'submitted', 'expired', 'abandoned')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (public_id)
);

CREATE INDEX IF NOT EXISTS idx_project_carts_session
    ON project_carts (session_key_hash, status, updated_at);
CREATE INDEX IF NOT EXISTS idx_project_carts_customer
    ON project_carts (customer_id, status, updated_at);

CREATE TABLE IF NOT EXISTS project_cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL,
    product_id INTEGER,
    configured_model TEXT NOT NULL,
    product_snapshot_json TEXT NOT NULL,
    configuration_json TEXT NOT NULL,
    configuration_hash TEXT NOT NULL,
    quantity NUMERIC NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price NUMERIC,
    price_mode TEXT NOT NULL DEFAULT 'review'
        CHECK (price_mode IN ('fixed', 'from', 'review')),
    currency TEXT NOT NULL DEFAULT 'USD',
    lead_time_text TEXT NOT NULL DEFAULT '',
    customer_note TEXT NOT NULL DEFAULT '',
    simulation_project_id INTEGER,
    simulation_snapshot_json TEXT,
    simulation_report_path TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (cart_id) REFERENCES project_carts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_project_cart_items_cart
    ON project_cart_items (cart_id, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_project_cart_items_configuration
    ON project_cart_items (cart_id, configuration_hash);

CREATE TABLE IF NOT EXISTS procurement_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL,
    request_no TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    request_type TEXT NOT NULL,
    cart_id INTEGER,
    customer_id INTEGER,
    contact_id INTEGER,
    company_name TEXT NOT NULL,
    contact_name TEXT NOT NULL,
    contact_email TEXT NOT NULL,
    contact_phone TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL,
    company_snapshot_json TEXT NOT NULL,
    project_name TEXT NOT NULL DEFAULT '',
    project_type TEXT NOT NULL DEFAULT '',
    project_country TEXT NOT NULL DEFAULT '',
    requested_delivery_date TEXT,
    currency TEXT NOT NULL DEFAULT 'USD',
    trade_term TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'submitted',
    owner_user_id INTEGER,
    crm_record_id TEXT,
    source TEXT NOT NULL DEFAULT 'shop.artdonlighting.com',
    submitted_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (cart_id) REFERENCES project_carts(id) ON DELETE SET NULL,
    UNIQUE (public_id),
    UNIQUE (request_no),
    UNIQUE (idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_procurement_requests_status
    ON procurement_requests (status, submitted_at);
CREATE INDEX IF NOT EXISTS idx_procurement_requests_customer
    ON procurement_requests (customer_id, submitted_at);

CREATE TABLE IF NOT EXISTS procurement_request_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    product_id INTEGER,
    line_no INTEGER NOT NULL CHECK (line_no > 0),
    product_snapshot_json TEXT NOT NULL,
    configuration_snapshot_json TEXT,
    quantity NUMERIC NOT NULL CHECK (quantity > 0),
    estimated_unit_price NUMERIC,
    currency TEXT NOT NULL DEFAULT 'USD',
    customer_note TEXT NOT NULL DEFAULT '',
    simulation_snapshot_json TEXT,
    simulation_report_path TEXT,
    review_status TEXT NOT NULL DEFAULT 'pending'
        CHECK (review_status IN ('pending', 'valid', 'invalid', 'review', 'replaced')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES procurement_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    UNIQUE (request_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_procurement_request_items_request
    ON procurement_request_items (request_id, line_no);

CREATE TABLE IF NOT EXISTS procurement_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_path TEXT NOT NULL,
    mime_type TEXT NOT NULL DEFAULT '',
    extension TEXT NOT NULL DEFAULT '',
    file_size INTEGER NOT NULL CHECK (file_size >= 0),
    checksum_sha256 TEXT,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('pending', 'active', 'quarantined', 'archived', 'deleted')),
    created_at TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES procurement_requests(id) ON DELETE CASCADE,
    UNIQUE (request_id, stored_path)
);

CREATE INDEX IF NOT EXISTS idx_procurement_attachments_request
    ON procurement_attachments (request_id, status);

CREATE TABLE IF NOT EXISTS sync_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'success', 'failed', 'dead')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 8 CHECK (max_attempts > 0),
    next_attempt_at TEXT,
    locked_at TEXT,
    locked_by TEXT,
    last_error TEXT,
    completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_sync_jobs_queue
    ON sync_jobs (status, next_attempt_at, created_at);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type TEXT NOT NULL DEFAULT 'system',
    actor_id TEXT,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    request_id TEXT,
    before_json TEXT,
    after_json TEXT,
    metadata_json TEXT,
    ip_hash TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity
    ON audit_logs (entity_type, entity_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor
    ON audit_logs (actor_type, actor_id, created_at);
SQL);
    },
];
