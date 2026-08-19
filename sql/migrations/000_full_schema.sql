-- ============================================================
-- KPAW Canteen Portal — Full Schema (consolidated)
-- Run this ONCE in phpMyAdmin's SQL tab, inside your database.
-- Safe to run even on the existing DB — it drops and recreates
-- every table cleanly, so you get a guaranteed-fresh, fully
-- up-to-date structure in one shot (replaces 001 + 002 both).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Drop everything first, in reverse dependency order
-- ------------------------------------------------------------
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS meal_items;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS otp_verifications;
DROP TABLE IF EXISTS guests;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS canteens;

-- ------------------------------------------------------------
-- Canteens
-- ------------------------------------------------------------
CREATE TABLE canteens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,          -- 'Loco Canteen' / 'Carriage Canteen'
    brand_name    VARCHAR(100) NOT NULL,           -- 'Annapurna' / 'Zaika'
    upi_vpa       VARCHAR(100) NULL,                -- fill in once real UPI IDs are confirmed
    is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Employees
-- ------------------------------------------------------------
CREATE TABLE users (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    full_name          VARCHAR(150) NOT NULL,
    hrms_id            CHAR(6) NOT NULL UNIQUE,     -- validated as ^[A-Z0-9]{6}$ in PHP, uppercased before insert
    phone              VARCHAR(15) NOT NULL,
    email              VARCHAR(150) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NOT NULL,
    email_verified_at  DATETIME NULL,
    remember_token     VARCHAR(100) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Guests / Visitors / Contractors
-- ------------------------------------------------------------
CREATE TABLE guests (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    full_name          VARCHAR(150) NOT NULL,
    phone              VARCHAR(15) NOT NULL UNIQUE,  -- login ID
    email              VARCHAR(150) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NOT NULL,
    email_verified_at  DATETIME NULL,
    remember_token     VARCHAR(100) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- OTP verification (registration)
-- ------------------------------------------------------------
CREATE TABLE otp_verifications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_type    ENUM('employee','guest') NOT NULL,
    user_id      INT NOT NULL,
    otp_code     CHAR(6) NOT NULL,
    expires_at   DATETIME NOT NULL,
    verified_at  DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Password reset tokens
-- ------------------------------------------------------------
CREATE TABLE password_resets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_type    ENUM('employee','guest') NOT NULL,
    user_id      INT NOT NULL,
    token        VARCHAR(100) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Menu items per canteen
-- ------------------------------------------------------------
CREATE TABLE meal_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    canteen_id   INT NOT NULL,
    meal_type    ENUM('breakfast','lunch','snacks') NOT NULL,
    name         VARCHAR(150) NOT NULL,
    price        DECIMAL(8,2) NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (canteen_id) REFERENCES canteens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Admin-managed holiday dates
-- ------------------------------------------------------------
CREATE TABLE holidays (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date      DATE NOT NULL UNIQUE,
    reason            VARCHAR(255) NULL,
    blocks_all_meals  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Admins & receptionists
-- ------------------------------------------------------------
CREATE TABLE admins (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(150) NOT NULL,
    email                VARCHAR(150) NOT NULL UNIQUE,
    password_hash        VARCHAR(255) NOT NULL,
    role                 ENUM('super_admin','receptionist') NOT NULL,
    assigned_canteen_id  INT NULL,                  -- NULL for super_admin, set for receptionists
    last_login_at        DATETIME NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_canteen_id) REFERENCES canteens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Orders / Tokens — the core table
-- ------------------------------------------------------------
CREATE TABLE orders (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_type           ENUM('employee','guest') NOT NULL,
    user_id             INT NOT NULL,
    canteen_id          INT NOT NULL,
    meal_type           ENUM('breakfast','lunch','snacks') NOT NULL,
    meal_item_id        INT NOT NULL,
    amount              DECIMAL(8,2) NOT NULL,
    utr_number          VARCHAR(20) NULL,
    status              ENUM('PENDING_CLEARANCE','APPROVED','SERVED','REJECTED','EXPIRED')
                         NOT NULL DEFAULT 'PENDING_CLEARANCE',
    order_date          DATE NOT NULL,
    approved_at         DATETIME NULL,
    rejected_at         DATETIME NULL,
    served_at           DATETIME NULL,
    served_by_admin_id  INT NULL,
    is_vip_token        TINYINT(1) NOT NULL DEFAULT 0,
    vip_reason          VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (canteen_id) REFERENCES canteens(id),
    FOREIGN KEY (meal_item_id) REFERENCES meal_items(id),
    FOREIGN KEY (served_by_admin_id) REFERENCES admins(id),
    INDEX idx_utr (utr_number),
    INDEX idx_status (status),
    INDEX idx_order_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Audit log — manual overrides, VIP tokens, force-approvals
-- ------------------------------------------------------------
CREATE TABLE audit_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    admin_id     INT NOT NULL,
    action_type  VARCHAR(100) NOT NULL,
    order_id     INT NULL,
    details      TEXT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id),
    FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Login attempts — backs Phase 2's rate-limiting requirement
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(150) NOT NULL,   -- HRMS ID, phone, or admin email attempted
    ip_address    VARCHAR(45) NULL,
    success       TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Seed data: the two canteens
-- ------------------------------------------------------------
INSERT INTO canteens (name, brand_name, upi_vpa, is_active) VALUES
    ('Loco Canteen', 'Annapurna', NULL, 1),
    ('Carriage Canteen', 'Zaika', NULL, 1);

-- ------------------------------------------------------------
-- Sample menu items (safe to delete later — for testing Phases 2-6)
-- ------------------------------------------------------------
INSERT INTO meal_items (canteen_id, meal_type, name, price, is_active) VALUES
    (1, 'breakfast', 'Poha', 25.00, 1),
    (1, 'lunch', 'Veg Thali', 45.00, 1),
    (1, 'snacks', 'Samosa + Tea', 15.00, 1),
    (2, 'breakfast', 'Idli Sambar', 25.00, 1),
    (2, 'lunch', 'Veg Thali', 45.00, 1),
    (2, 'snacks', 'Pakora + Tea', 15.00, 1);
