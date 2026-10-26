-- SmartClass migration 031 — customers (original system only) and migration tracking
-- schema_migrations: which migration files this database has had (bin/migrate.php applies the missing ones).
-- customers: the instances you host (domain, database, status, which services are switched off, support secret).
-- customer_events: what happened to a customer (created, suspended, resumed, support access, deleted ...).
-- Customers' own databases get these tables too (empty, never used there).
-- Run ONCE (or let bin/migrate.php do it).
--
--   mysql -u USER -p smartclass_db < config/migrations/031_customers_and_migrations.sql

CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    domain VARCHAR(190) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    db_name VARCHAR(64) NOT NULL,
    db_user VARCHAR(64) NOT NULL,
    contact VARCHAR(190) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    suspended_reason VARCHAR(255) DEFAULT NULL,
    suspended_at DATETIME DEFAULT NULL,
    services_off VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'comma list: ai, email, uploads, signups',
    support_secret CHAR(64) NOT NULL,
    terms_accepted_at DATETIME DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    UNIQUE KEY unique_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(120) DEFAULT NULL,
    event VARCHAR(60) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
