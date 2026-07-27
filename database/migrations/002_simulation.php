<?php

declare(strict_types=1);

return [
    'id' => '002_simulation',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ies_library (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL,
    product_id INTEGER NOT NULL,
    configuration_schema_id INTEGER,
    option_signature TEXT NOT NULL DEFAULT '',
    configured_model TEXT NOT NULL DEFAULT '',
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    original_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    checksum_sha256 TEXT NOT NULL,
    ies_standard TEXT NOT NULL DEFAULT '',
    photometric_type TEXT NOT NULL DEFAULT '',
    tilt_mode TEXT NOT NULL DEFAULT '',
    lumens NUMERIC,
    power_w NUMERIC,
    beam_angle_deg NUMERIC,
    candela_multiplier NUMERIC NOT NULL DEFAULT 1,
    vertical_angles_json TEXT NOT NULL DEFAULT '[]',
    horizontal_angles_json TEXT NOT NULL DEFAULT '[]',
    distribution_json TEXT NOT NULL DEFAULT '[]',
    parsed_data_json TEXT NOT NULL DEFAULT '{}',
    parser_version TEXT NOT NULL DEFAULT '',
    validation_status TEXT NOT NULL DEFAULT 'pending'
        CHECK (validation_status IN ('pending', 'valid', 'warning', 'invalid')),
    validation_messages_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'active', 'archived', 'rejected')),
    created_by INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (configuration_schema_id)
        REFERENCES product_configuration_schemas(id) ON DELETE SET NULL,
    UNIQUE (public_id),
    UNIQUE (checksum_sha256),
    UNIQUE (product_id, option_signature, version)
);

CREATE INDEX IF NOT EXISTS idx_ies_library_product
    ON ies_library (product_id, configured_model, status, version);
CREATE INDEX IF NOT EXISTS idx_ies_library_validation
    ON ies_library (validation_status, status, updated_at);

CREATE TABLE IF NOT EXISTS simulation_projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL,
    customer_id INTEGER,
    owner_user_id INTEGER,
    session_key_hash TEXT,
    project_name TEXT NOT NULL DEFAULT '',
    room_type TEXT NOT NULL,
    room_length_m NUMERIC NOT NULL CHECK (room_length_m > 0),
    room_width_m NUMERIC NOT NULL CHECK (room_width_m > 0),
    room_height_m NUMERIC NOT NULL CHECK (room_height_m > 0),
    installation_height_m NUMERIC NOT NULL CHECK (installation_height_m > 0),
    work_plane_height_m NUMERIC NOT NULL DEFAULT 0 CHECK (work_plane_height_m >= 0),
    mounting_type TEXT NOT NULL,
    target_lux NUMERIC NOT NULL CHECK (target_lux > 0),
    maintenance_factor NUMERIC NOT NULL DEFAULT 0.8
        CHECK (maintenance_factor > 0 AND maintenance_factor <= 1),
    product_id INTEGER NOT NULL,
    ies_library_id INTEGER NOT NULL,
    configured_model TEXT NOT NULL DEFAULT '',
    configuration_snapshot_json TEXT NOT NULL DEFAULT '{}',
    simulation_mode TEXT NOT NULL DEFAULT 'auto_layout'
        CHECK (simulation_mode IN ('one_light', 'auto_layout', 'manual_layout')),
    fixture_quantity INTEGER NOT NULL DEFAULT 1 CHECK (fixture_quantity > 0),
    layout_rows INTEGER CHECK (layout_rows IS NULL OR layout_rows > 0),
    layout_columns INTEGER CHECK (layout_columns IS NULL OR layout_columns > 0),
    spacing_x_m NUMERIC CHECK (spacing_x_m IS NULL OR spacing_x_m > 0),
    spacing_y_m NUMERIC CHECK (spacing_y_m IS NULL OR spacing_y_m > 0),
    average_lux NUMERIC,
    maximum_lux NUMERIC,
    minimum_lux NUMERIC,
    uniformity NUMERIC,
    input_snapshot_json TEXT NOT NULL,
    result_json TEXT,
    heatmap_json TEXT,
    algorithm_version TEXT NOT NULL DEFAULT '',
    report_path TEXT,
    report_checksum_sha256 TEXT,
    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'queued', 'running', 'completed', 'failed', 'archived')),
    error_message TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (ies_library_id) REFERENCES ies_library(id) ON DELETE RESTRICT,
    UNIQUE (public_id)
);

CREATE INDEX IF NOT EXISTS idx_simulation_projects_owner
    ON simulation_projects (customer_id, owner_user_id, status, updated_at);
CREATE INDEX IF NOT EXISTS idx_simulation_projects_session
    ON simulation_projects (session_key_hash, status, updated_at);
CREATE INDEX IF NOT EXISTS idx_simulation_projects_product
    ON simulation_projects (product_id, ies_library_id, created_at);
SQL);
    },
];
