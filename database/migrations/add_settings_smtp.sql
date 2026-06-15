-- Migration: Create settings_smtp table
-- Stores SMTP configuration per business for transactional emails (REQ-017)

CREATE TABLE IF NOT EXISTS settings_smtp (
    business_id INT PRIMARY KEY,
    host VARCHAR(255) NOT NULL,
    port SMALLINT NOT NULL DEFAULT 587,
    username VARCHAR(255) NOT NULL,
    password_enc TEXT NOT NULL,
    from_address VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
