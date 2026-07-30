-- ============================================
-- setup.sql — RPT System (XAMPP / phpMyAdmin)
-- ============================================
-- Paano gamitin:
-- 1. Buksan ang phpMyAdmin (http://localhost/phpmyadmin)
-- 2. Click ang "SQL" tab sa taas (hindi na kailangan pumili ng database)
-- 3. Paste mo ang buong file na ito, then "Go"
-- (Optional na rin: kusang gagawa ang api.php ng mga tables kung wala pa,
--  kaya hindi mandatory itong file. Para lang siguradong tama agad lahat.)

CREATE DATABASE IF NOT EXISTS rpt_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE rpt_system;

-- ============================================
-- USERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS rpt_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'staff',
    avatar MEDIUMTEXT DEFAULT NULL,
    accent_color VARCHAR(20) DEFAULT NULL,
    created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- RPT RECORDS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS rpt_records (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    lot          VARCHAR(100) NOT NULL UNIQUE,
    prepared_by  VARCHAR(100) DEFAULT NULL,
    grand_total  DECIMAL(15,2) DEFAULT 0,
    full_data    LONGTEXT DEFAULT NULL,
    date_saved   DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- CHAT MESSAGES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS chat_messages (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    sender    VARCHAR(50) NOT NULL,
    message   TEXT NOT NULL,
    timestamp DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- TAX RATES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS tax_rates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    lot_code   VARCHAR(20) NOT NULL UNIQUE,
    tax_rate   VARCHAR(20) NOT NULL DEFAULT '2%',
    updated_at DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- (OPTIONAL) DEFAULT ADMIN ACCOUNT
-- Username: ADMIN   Password: admin123
-- I-edit/i-delete ito kung gusto mo ng iba.
-- Pwede mo ring palitan ang password pagkatapos mong makapag-login
-- (gamitin ang "Change Password" feature ng app — naka-hash na agad ito).
-- ============================================
INSERT INTO rpt_users (username, password, role)
VALUES ('ADMIN', 'admin123', 'admin')
ON DUPLICATE KEY UPDATE username = username;
