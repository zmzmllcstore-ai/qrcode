-- ============================================================================
-- Business QR Generator SaaS - Complete SQL Database Schema
-- Database: MySQL / MariaDB (InnoDB, utf8mb4)
-- ============================================================================

-- (Database creation omitted so you can import into any existing database)
-- CREATE DATABASE IF NOT EXISTS `qr_saas` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `qr_saas`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. COMPANIES / TENANTS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `companies` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `owner_name` VARCHAR(255) NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(64) NULL,
    `description` TEXT NULL,
    `plan` VARCHAR(32) NOT NULL DEFAULT 'free',
    `qr_limit` INT NOT NULL DEFAULT 3,
    `scan_limit_monthly` INT NOT NULL DEFAULT 500,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
    `status` VARCHAR(32) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_comp_plan` (`plan`),
    INDEX `idx_comp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. USERS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `company_id` VARCHAR(64) NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(64) NULL,
    `role` VARCHAR(32) NOT NULL DEFAULT 'owner', -- 'superadmin', 'owner', 'member'
    `status` VARCHAR(32) NOT NULL DEFAULT 'active', -- 'active', 'pending_approval', 'suspended'
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `two_factor_secret` VARCHAR(128) NULL,
    `last_login_at` DATETIME NULL,
    `last_login_ip` VARCHAR(64) NULL,
    `session_version` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_company` (`company_id`),
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`),
    CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. QR CODES TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_codes` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `company_id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(64) NOT NULL UNIQUE,
    `type` VARCHAR(32) NOT NULL DEFAULT 'website', -- 'website', 'multi_links', 'vcard_plus', 'barcode_qr', 'pdf', 'wifi'
    `target_url` TEXT NULL,
    `extra_data` LONGTEXT NULL, -- JSON formatted data (links, vcard fields, etc.)
    `design_config` LONGTEXT NULL, -- JSON formatted design parameters (colors, frame, dots, logo)
    `scans_count` INT NOT NULL DEFAULT 0,
    `unique_scans_count` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(32) NOT NULL DEFAULT 'active', -- 'active', 'paused', 'archived'
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_qr_company` (`company_id`),
    INDEX `idx_qr_slug` (`slug`),
    INDEX `idx_qr_type` (`type`),
    INDEX `idx_qr_status` (`status`),
    CONSTRAINT `fk_qr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. QR SCANS & ANALYTICS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_scans` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `qr_id` VARCHAR(64) NOT NULL,
    `company_id` VARCHAR(64) NOT NULL,
    `ip_address` VARCHAR(64) NULL,
    `user_agent` TEXT NULL,
    `device_type` VARCHAR(32) NULL DEFAULT 'Mobile',
    `browser` VARCHAR(64) NULL,
    `os` VARCHAR(64) NULL,
    `country` VARCHAR(64) NULL DEFAULT 'Global',
    `city` VARCHAR(64) NULL,
    `referer` VARCHAR(512) NULL,
    `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_scans_qr` (`qr_id`),
    INDEX `idx_scans_company` (`company_id`),
    INDEX `idx_scans_time` (`scanned_at`),
    CONSTRAINT `fk_scans_qr` FOREIGN KEY (`qr_id`) REFERENCES `qr_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. SUBSCRIPTIONS & TRANSACTIONS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `company_id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NULL,
    `plan_id` VARCHAR(32) NOT NULL,
    `billing_cycle` VARCHAR(32) NOT NULL DEFAULT 'monthly', -- 'monthly', 'yearly', 'lifetime'
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
    `gateway` VARCHAR(32) NOT NULL,
    `transaction_id` VARCHAR(255) NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'active', -- 'active', 'pending_verification', 'cancelled', 'expired'
    `proof_file` VARCHAR(512) NULL,
    `starts_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sub_company` (`company_id`),
    INDEX `idx_sub_status` (`status`),
    INDEX `idx_sub_gateway` (`gateway`),
    CONSTRAINT `fk_sub_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. PAYMENT GATEWAYS CONFIGURATION TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_gateways` (
    `id` VARCHAR(32) NOT NULL PRIMARY KEY,
    `name` VARCHAR(128) NOT NULL,
    `icon` VARCHAR(64) NOT NULL DEFAULT 'fa-solid fa-credit-card',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `mode` VARCHAR(16) NOT NULL DEFAULT 'live',
    `account_title` VARCHAR(255) NULL,
    `account_number` VARCHAR(255) NULL,
    `bank_name` VARCHAR(255) NULL,
    `swift_code` VARCHAR(64) NULL,
    `wallet_address` VARCHAR(255) NULL,
    `network` VARCHAR(64) NULL,
    `instructions` TEXT NULL,
    `config_data` LONGTEXT NULL, -- JSON API Keys, Webhook Secrets, etc.
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. SYSTEM SETTINGS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(128) NOT NULL PRIMARY KEY,
    `setting_value` LONGTEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. ACTIVITY & AUDIT LOGS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `company_id` VARCHAR(64) NULL,
    `user_id` VARCHAR(64) NULL,
    `action` VARCHAR(64) NOT NULL,
    `description` TEXT NOT NULL,
    `ip_address` VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_act_company` (`company_id`),
    INDEX `idx_act_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- INITIAL SEED DATA
-- ----------------------------------------------------------------------------

-- Seed Default Payment Gateways
INSERT INTO `payment_gateways` (`id`, `name`, `icon`, `is_active`, `mode`, `account_title`, `account_number`, `bank_name`, `swift_code`, `wallet_address`, `network`, `instructions`)
VALUES 
('stripe', 'Stripe Card & Digital Wallets', 'fa-brands fa-stripe', 1, 'live', NULL, NULL, NULL, NULL, NULL, NULL, 'Supports Visa, MasterCard, Amex, Apple Pay, and Google Pay worldwide.'),
('paypal', 'PayPal Express Checkout', 'fa-brands fa-paypal', 1, 'live', NULL, NULL, NULL, NULL, NULL, NULL, 'Instant checkout with PayPal account balance and international cards.'),
('jazzcash', 'JazzCash Mobile Account & Card', 'fa-solid fa-mobile-screen-button', 1, 'live', 'QRSpark Business Pvt', '0300-1234567', 'Mobilink Microfinance Bank', NULL, NULL, NULL, 'Transfer the PKR amount to the JazzCash mobile number and provide your 12-digit TRX ID.'),
('easypaisa', 'EasyPaisa Wallet & QR Payment', 'fa-solid fa-wallet', 1, 'live', 'QRSpark Business Pvt', '0345-7654321', 'Telenor Microfinance Bank', NULL, NULL, NULL, 'Transfer the amount to the EasyPaisa account and provide your 11-digit TRX ID.'),
('bank_transfer', 'Direct Bank Wire / Online IBAN', 'fa-solid fa-building-columns', 1, 'live', 'QRSpark Global Technologies Ltd', 'PK36MEZN0001234567890101', 'Meezan Bank Ltd', 'MEZNPKKA', NULL, NULL, 'Transfer funds via Online Banking / IBAN and submit your Transaction Reference Number.'),
('crypto', 'Cryptocurrency (USDT TRC-20)', 'fa-brands fa-bitcoin', 1, 'live', NULL, NULL, NULL, NULL, 'TXyZ9876543210abcdef9876543210TRC20', 'USDT TRC-20', 'Transfer USDT on TRC-20 network to the address above, then provide your TxHash.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed Default Demo Company
INSERT INTO `companies` (`id`, `name`, `owner_name`, `email`, `phone`, `description`, `plan`, `qr_limit`, `scan_limit_monthly`, `currency`, `status`)
VALUES 
('comp_apex_demo', 'Apex Digital Agency', 'Sarah Jenkins', 'owner@apexdigital.com', '+1 (555) 234-5678', 'Official brand channels, portfolio, and digital contacts.', 'business', 25, 25000, 'USD', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed Default Admin User & Demo User (Passwords: 'admin123')
INSERT INTO `users` (`id`, `company_id`, `email`, `password_hash`, `name`, `phone`, `role`, `status`)
VALUES 
('usr_admin_root', NULL, 'admin@qrsaas.com', '$2y$10$tM9s7/K7j7L1gZ2c51Fm3.Gqv4p7mXq8QW2w8Wb5P0kK.pL/8kZ92', 'Super Admin', '+1 (800) 555-0199', 'superadmin', 'active'),
('usr_demo_owner', 'comp_apex_demo', 'owner@apexdigital.com', '$2y$10$tM9s7/K7j7L1gZ2c51Fm3.Gqv4p7mXq8QW2w8Wb5P0kK.pL/8kZ92', 'Sarah Jenkins', '+1 (555) 234-5678', 'owner', 'active')
ON DUPLICATE KEY UPDATE `email` = VALUES(`email`);

-- Seed Default Sample Dynamic QR Code
INSERT INTO `qr_codes` (`id`, `company_id`, `user_id`, `name`, `slug`, `type`, `target_url`, `extra_data`, `design_config`, `scans_count`)
VALUES 
('qr_demo_apex', 'comp_apex_demo', 'usr_demo_owner', 'Apex Digital Agency Launch', 'apex-bio', 'multi_links', 'https://example.com', '{"title":"Apex Digital Agency","description":"Connect with our official brand channels & booking portals.","image":"","image_style":"full_logo","links":[{"title":"Official Website","url":"https://apexdigital.com","platform":"website","icon":"fa-solid fa-globe"},{"title":"Schedule Strategy Call","url":"https://calendly.com","platform":"calendly","icon":"fa-solid fa-calendar-check"},{"title":"Official Instagram","url":"https://instagram.com","platform":"instagram","icon":"fa-brands fa-instagram"}],"cta_button":{"enabled":true,"text":"Visit Main Portal","url":"https://apexdigital.com"}}', '{"color_dark":"#0f172a","dots_style":"rounded","corner_style":"extra-rounded","frame":"bottom_badge","logo":""}', 128)
ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`);
