-- Artdon Procurement Platform V1.0
-- MySQL 8.0 / MariaDB 10.6+ foundation schema
-- The downloadable prototype runs without a database; this schema is for the live integration phase.

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(80) NOT NULL,
  series_code VARCHAR(80) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  category_slug VARCHAR(120) NOT NULL,
  subcategory_slug VARCHAR(120) DEFAULT NULL,
  summary TEXT DEFAULT NULL,
  description MEDIUMTEXT DEFAULT NULL,
  status ENUM('draft','active','inactive','discontinued') NOT NULL DEFAULT 'draft',
  order_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sample_enabled TINYINT(1) NOT NULL DEFAULT 0,
  price_mode ENUM('fixed','from','review') NOT NULL DEFAULT 'review',
  base_currency CHAR(3) NOT NULL DEFAULT 'USD',
  base_price DECIMAL(14,4) DEFAULT NULL,
  default_moq DECIMAL(14,3) NOT NULL DEFAULT 1,
  default_lead_time_days SMALLINT UNSIGNED DEFAULT NULL,
  source_system VARCHAR(80) NOT NULL DEFAULT 'naming_system',
  source_id VARCHAR(120) DEFAULT NULL,
  source_version VARCHAR(80) DEFAULT NULL,
  synced_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_products_sku (sku),
  UNIQUE KEY uk_products_slug (slug),
  KEY idx_products_category (category_slug, subcategory_slug, status),
  KEY idx_products_source (source_system, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_option_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  option_code VARCHAR(80) NOT NULL,
  label VARCHAR(120) NOT NULL,
  input_type ENUM('select','radio','swatch','number','text') NOT NULL DEFAULT 'select',
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_product_option_group (product_id, option_code),
  CONSTRAINT fk_option_groups_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_option_values (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_group_id BIGINT UNSIGNED NOT NULL,
  value_code VARCHAR(100) NOT NULL,
  label VARCHAR(150) NOT NULL,
  sku_fragment VARCHAR(40) DEFAULT NULL,
  price_delta DECIMAL(14,4) NOT NULL DEFAULT 0,
  lead_time_delta_days SMALLINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json JSON DEFAULT NULL,
  UNIQUE KEY uk_option_value (option_group_id, value_code),
  CONSTRAINT fk_option_values_group FOREIGN KEY (option_group_id) REFERENCES product_option_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_sku VARCHAR(140) NOT NULL,
  option_signature CHAR(64) NOT NULL,
  option_snapshot_json JSON NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  unit_price DECIMAL(14,4) DEFAULT NULL,
  moq DECIMAL(14,3) NOT NULL DEFAULT 1,
  lead_time_days SMALLINT UNSIGNED DEFAULT NULL,
  weight_kg DECIMAL(12,4) DEFAULT NULL,
  status ENUM('active','review','inactive') NOT NULL DEFAULT 'review',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_variant_sku (variant_sku),
  UNIQUE KEY uk_variant_signature (product_id, option_signature),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_combination_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  rule_type ENUM('allow','deny','require','recommend','review','price','lead_time') NOT NULL,
  rule_name VARCHAR(180) NOT NULL,
  condition_json JSON NOT NULL,
  effect_json JSON NOT NULL,
  priority INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_combination_rules (product_id, is_active, priority),
  CONSTRAINT fk_combination_rules_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  country_code CHAR(2) DEFAULT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Singapore',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uk_warehouse_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED DEFAULT NULL,
  quantity_on_hand DECIMAL(16,3) NOT NULL DEFAULT 0,
  quantity_reserved DECIMAL(16,3) NOT NULL DEFAULT 0,
  quantity_available DECIMAL(16,3) GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED,
  batch_no VARCHAR(100) DEFAULT NULL,
  ready_date DATE DEFAULT NULL,
  clearance_price DECIMAL(14,4) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inventory_item (warehouse_id, product_id, variant_id, batch_no),
  KEY idx_inventory_available (quantity_available),
  CONSTRAINT fk_inventory_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(80) DEFAULT NULL,
  company_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  website_domain VARCHAR(180) DEFAULT NULL,
  country_code CHAR(2) DEFAULT NULL,
  owner_user_id BIGINT UNSIGNED DEFAULT NULL,
  customer_level VARCHAR(40) DEFAULT NULL,
  status ENUM('pending','active','paused','blocked') NOT NULL DEFAULT 'pending',
  source VARCHAR(80) NOT NULL DEFAULT 'shop.artdonlighting.com',
  crm_customer_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_customer_code (customer_code),
  KEY idx_customer_match (normalized_name, website_domain, country_code),
  KEY idx_customer_crm (crm_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(80) DEFAULT NULL,
  job_title VARCHAR(120) DEFAULT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_contact_email_customer (customer_id, email),
  KEY idx_contact_email (email),
  CONSTRAINT fk_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procurement_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(80) NOT NULL,
  request_type ENUM('quick_rfq','sample','ready_stock','oem','odm','bulk','project_package','service','contact') NOT NULL,
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  contact_id BIGINT UNSIGNED DEFAULT NULL,
  company_snapshot_json JSON NOT NULL,
  project_name VARCHAR(255) DEFAULT NULL,
  project_country VARCHAR(120) DEFAULT NULL,
  requested_delivery_date DATE DEFAULT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  trade_term VARCHAR(20) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status ENUM('submitted','under_review','more_information','quoted','converted','cancelled') NOT NULL DEFAULT 'submitted',
  owner_user_id BIGINT UNSIGNED DEFAULT NULL,
  crm_record_id BIGINT UNSIGNED DEFAULT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'shop.artdonlighting.com',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_procurement_request_no (request_no),
  KEY idx_procurement_status (status, submitted_at),
  KEY idx_procurement_customer (customer_id),
  CONSTRAINT fk_procurement_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_procurement_contact FOREIGN KEY (contact_id) REFERENCES customer_contacts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procurement_request_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED DEFAULT NULL,
  variant_id BIGINT UNSIGNED DEFAULT NULL,
  line_no INT NOT NULL,
  product_snapshot_json JSON NOT NULL,
  configuration_snapshot_json JSON DEFAULT NULL,
  quantity DECIMAL(16,3) NOT NULL DEFAULT 1,
  estimated_unit_price DECIMAL(14,4) DEFAULT NULL,
  customer_note TEXT DEFAULT NULL,
  review_status ENUM('pending','valid','invalid','review','replaced') NOT NULL DEFAULT 'pending',
  UNIQUE KEY uk_request_line (request_id, line_no),
  CONSTRAINT fk_request_items_request FOREIGN KEY (request_id) REFERENCES procurement_requests(id),
  CONSTRAINT fk_request_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_request_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_no VARCHAR(80) NOT NULL,
  request_id BIGINT UNSIGNED DEFAULT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  revision_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(16,4) NOT NULL DEFAULT 0,
  shipping_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
  total_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
  trade_term VARCHAR(20) DEFAULT NULL,
  payment_terms VARCHAR(255) DEFAULT NULL,
  lead_time_text VARCHAR(255) DEFAULT NULL,
  valid_until DATE DEFAULT NULL,
  status ENUM('draft','internal_review','sent','customer_confirmed','rejected','expired','converted') NOT NULL DEFAULT 'draft',
  snapshot_json JSON NOT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_quote_revision (quote_no, revision_no),
  KEY idx_quote_customer (customer_id, status),
  CONSTRAINT fk_quote_request FOREIGN KEY (request_id) REFERENCES procurement_requests(id),
  CONSTRAINT fk_quote_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotation_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  product_snapshot_json JSON NOT NULL,
  configuration_snapshot_json JSON DEFAULT NULL,
  quantity DECIMAL(16,3) NOT NULL,
  unit_price DECIMAL(14,4) NOT NULL,
  line_total DECIMAL(16,4) NOT NULL,
  lead_time_text VARCHAR(120) DEFAULT NULL,
  UNIQUE KEY uk_quote_line (quotation_id, line_no),
  CONSTRAINT fk_quote_items_quote FOREIGN KEY (quotation_id) REFERENCES quotations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(80) NOT NULL,
  quotation_id BIGINT UNSIGNED DEFAULT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  customer_po_no VARCHAR(120) DEFAULT NULL,
  revision_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  total_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
  status ENUM('pending_confirmation','confirmed','in_production','ready_to_ship','shipped','completed','cancelled') NOT NULL DEFAULT 'pending_confirmation',
  confirmed_at DATETIME DEFAULT NULL,
  snapshot_json JSON NOT NULL,
  erp_order_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_sales_order_revision (order_no, revision_no),
  KEY idx_order_customer (customer_id, status),
  KEY idx_order_erp (erp_order_id),
  CONSTRAINT fk_order_quote FOREIGN KEY (quotation_id) REFERENCES quotations(id),
  CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sales_order_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  product_snapshot_json JSON NOT NULL,
  configuration_snapshot_json JSON DEFAULT NULL,
  quantity DECIMAL(16,3) NOT NULL,
  unit_price DECIMAL(14,4) NOT NULL,
  line_total DECIMAL(16,4) NOT NULL,
  UNIQUE KEY uk_order_line (sales_order_id, line_no),
  CONSTRAINT fk_order_items_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED DEFAULT NULL,
  file_type ENUM('product_image','dimension','datasheet','ies','ldt','bim','cad','installation','certificate','brochure','video','attachment') NOT NULL,
  title VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(150) DEFAULT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  revision VARCHAR(40) DEFAULT NULL,
  checksum_sha256 CHAR(64) DEFAULT NULL,
  status ENUM('active','archived','pending') NOT NULL DEFAULT 'active',
  uploaded_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_media_product_type (product_id, file_type, status),
  CONSTRAINT fk_media_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  media_file_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_request_file (request_id, media_file_id),
  CONSTRAINT fk_request_files_request FOREIGN KEY (request_id) REFERENCES procurement_requests(id),
  CONSTRAINT fk_request_files_media FOREIGN KEY (media_file_id) REFERENCES media_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_pages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  page_type ENUM('home','listing','solution','project','resource','article','ai','procurement','support','about','account') NOT NULL,
  title VARCHAR(255) NOT NULL,
  meta_title VARCHAR(255) DEFAULT NULL,
  meta_description VARCHAR(500) DEFAULT NULL,
  content_json JSON NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_content_path (path),
  KEY idx_content_type_status (page_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type ENUM('product_pull','inventory_pull','customer_push','request_push','quote_pull','order_pull','media_pull') NOT NULL,
  idempotency_key VARCHAR(190) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','running','success','failed','dead') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_sync_idempotency (idempotency_key),
  KEY idx_sync_queue (status, next_attempt_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('guest','customer','staff','system','api') NOT NULL,
  actor_id VARCHAR(120) DEFAULT NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(120) NOT NULL,
  before_json JSON DEFAULT NULL,
  after_json JSON DEFAULT NULL,
  ip_hash CHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_actor (actor_type, actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
