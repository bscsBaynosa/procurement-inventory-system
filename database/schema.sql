-- PostgreSQL schema for the Procurement & Inventory System
-- This script is intended for use on Heroku Postgres or any managed PostgreSQL instance.
-- Run with: psql "$DATABASE_URL" -f database/schema.sql

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ----- Domain & Enum Definitions -------------------------------------------------

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
        CREATE TYPE user_role AS ENUM ('custodian', 'procurement_manager', 'admin');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'inventory_status') THEN
        CREATE TYPE inventory_status AS ENUM ('good', 'for_repair', 'for_replacement', 'retired');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'request_status') THEN
        CREATE TYPE request_status AS ENUM ('draft', 'pending', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'request_type') THEN
        CREATE TYPE request_type AS ENUM ('job_order', 'purchase_order', 'equipment_request');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'movement_reason') THEN
        CREATE TYPE movement_reason AS ENUM ('stock_in', 'stock_out', 'adjustment', 'transfer', 'repair', 'return');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'audit_action') THEN
        CREATE TYPE audit_action AS ENUM ('create', 'update', 'delete', 'login', 'logout', 'status_change');
    END IF;
END $$;

-- ----- User ID generation ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_id_sequences (
    calendar_year INTEGER PRIMARY KEY,
    last_value INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE OR REPLACE FUNCTION generate_user_id()
RETURNS BIGINT
LANGUAGE plpgsql
AS $$
DECLARE
    current_year INTEGER := EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER;
    next_value INTEGER;
BEGIN
    INSERT INTO user_id_sequences (calendar_year) VALUES (current_year)
    ON CONFLICT (calendar_year) DO NOTHING;

    UPDATE user_id_sequences
    SET last_value = last_value + 1,
        updated_at = NOW()
    WHERE calendar_year = current_year
    RETURNING last_value INTO next_value;

    RETURN (current_year * 10000 + next_value);
END;
$$;

-- ----- Core Reference Tables ------------------------------------------------------

CREATE TABLE IF NOT EXISTS branches (
    branch_id BIGSERIAL PRIMARY KEY,
    code VARCHAR(32) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT,
    latitude NUMERIC(10, 6),
    longitude NUMERIC(10, 6),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS users (
    user_id BIGINT PRIMARY KEY DEFAULT generate_user_id(),
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    -- New normalized name fields (split from legacy full_name)
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    -- Keep full_name for compatibility with existing templates and joins
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role user_role NOT NULL,
    branch_id BIGINT REFERENCES branches(branch_id) ON DELETE SET NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    last_login_at TIMESTAMPTZ,
    last_login_ip INET,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ,
    password_changed_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_users_branch_id ON users(branch_id);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
-- Enforce unique email (case-insensitive) when provided
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_unique ON users ((lower(email))) WHERE email IS NOT NULL;

-- ----- Inventory -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS inventory_items (
    item_id BIGSERIAL PRIMARY KEY,
    sku VARCHAR(50) UNIQUE,
    asset_tag VARCHAR(100),
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    quantity INTEGER NOT NULL DEFAULT 0,
    unit VARCHAR(32) DEFAULT 'pcs',
    status inventory_status NOT NULL DEFAULT 'good',
    branch_id BIGINT REFERENCES branches(branch_id) ON DELETE SET NULL,
    minimum_quantity INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_inventory_branch ON inventory_items(branch_id);
CREATE INDEX IF NOT EXISTS idx_inventory_status ON inventory_items(status);

CREATE TABLE IF NOT EXISTS inventory_movements (
    movement_id BIGSERIAL PRIMARY KEY,
    item_id BIGINT NOT NULL REFERENCES inventory_items(item_id) ON DELETE CASCADE,
    quantity_delta INTEGER NOT NULL,
    quantity_after INTEGER NOT NULL,
    reason movement_reason NOT NULL,
    notes TEXT,
    reference_number VARCHAR(100),
    performed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_movements_item_id ON inventory_movements(item_id);
CREATE INDEX IF NOT EXISTS idx_movements_performed_at ON inventory_movements(performed_at DESC);

-- ----- Procurement Requests -------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_requests (
    request_id BIGSERIAL PRIMARY KEY,
    item_id BIGINT REFERENCES inventory_items(item_id) ON DELETE SET NULL,
    branch_id BIGINT REFERENCES branches(branch_id) ON DELETE SET NULL,
    requested_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    assigned_to BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    request_type request_type NOT NULL DEFAULT 'purchase_order',
    quantity INTEGER NOT NULL DEFAULT 1,
    unit VARCHAR(32) DEFAULT 'pcs',
    justification TEXT,
    status request_status NOT NULL DEFAULT 'pending',
    priority SMALLINT NOT NULL DEFAULT 3,
    needed_by DATE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_requests_status ON purchase_requests(status);
CREATE INDEX IF NOT EXISTS idx_requests_branch ON purchase_requests(branch_id);
CREATE INDEX IF NOT EXISTS idx_requests_assigned_to ON purchase_requests(assigned_to);

CREATE TABLE IF NOT EXISTS purchase_request_events (
    event_id BIGSERIAL PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES purchase_requests(request_id) ON DELETE CASCADE,
    old_status request_status,
    new_status request_status,
    notes TEXT,
    performed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----- Audit Trails ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGSERIAL PRIMARY KEY,
    table_name VARCHAR(128) NOT NULL,
    record_id BIGINT,
    action audit_action NOT NULL,
    payload JSONB,
    performed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip_address INET,
    user_agent TEXT
);

CREATE INDEX IF NOT EXISTS idx_audit_table_name ON audit_logs(table_name);
CREATE INDEX IF NOT EXISTS idx_audit_performed_at ON audit_logs(performed_at DESC);

CREATE TABLE IF NOT EXISTS auth_activity (
    activity_id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(user_id) ON DELETE CASCADE,
    action audit_action NOT NULL CHECK (action IN ('login', 'logout')),
    ip_address INET,
    user_agent TEXT,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----- Helper Functions & Triggers ------------------------------------------------

CREATE OR REPLACE FUNCTION touch_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = NOW();
    IF TG_OP = 'INSERT' AND NEW.created_at IS NULL THEN
        NEW.created_at = NOW();
    END IF;
    RETURN NEW;
END;
$$;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger WHERE tgname = 'trg_users_touch'
    ) THEN
        CREATE TRIGGER trg_users_touch
        BEFORE INSERT OR UPDATE ON users
        FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger WHERE tgname = 'trg_inventory_touch'
    ) THEN
        CREATE TRIGGER trg_inventory_touch
        BEFORE INSERT OR UPDATE ON inventory_items
        FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger WHERE tgname = 'trg_requests_touch'
    ) THEN
        CREATE TRIGGER trg_requests_touch
        BEFORE INSERT OR UPDATE ON purchase_requests
        FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
    END IF;
END $$;

COMMIT;

-- Messaging (ensure table exists in base schema)
BEGIN;
CREATE TABLE IF NOT EXISTS messages (
    id BIGSERIAL PRIMARY KEY,
    sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMIT;
