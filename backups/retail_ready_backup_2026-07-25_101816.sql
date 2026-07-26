-- Retail Ready Database Backup
-- Date: 2026-07-25 10:18:16
-- Database: retail_ready
-- =============================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;
SET AUTOCOMMIT = 0;

-- -------------------------------------------
-- Table: `bank_accounts`
-- -------------------------------------------
DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ifsc_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `current_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_accounts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `categories`
-- -------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `parent_id` int unsigned DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categories_parent_id` (`parent_id`),
  KEY `idx_categories_status` (`status`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `categories` WRITE;
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('1', 'Laptops', 'Laptop computers', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('2', 'Desktops', 'Desktop computers', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('3', 'Monitors', 'Display monitors', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('4', 'Printers', 'Printers and scanners', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('5', 'Keyboards', 'Keyboards', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('6', 'Mice', 'Computer mice', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('7', 'Headphones', 'Headphones and earphones', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('8', 'Speakers', 'Speakers and soundbars', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('9', 'Cables', 'Cables and adapters', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('10', 'Storage (HDD/SSD)', 'Hard drives and SSDs', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('11', 'RAM', 'Memory modules', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('12', 'Processors', 'CPU processors', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('13', 'Motherboards', 'Motherboards', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('14', 'Graphics Cards', 'GPU / graphics cards', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('15', 'Software', 'Software and licenses', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('16', 'Accessories', 'Misc accessories', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('17', 'Networking', 'Routers, switches, cables', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('18', 'UPS', 'Uninterruptible power supplies', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('19', 'Webcams', 'Webcams and cameras', NULL, '1', '2026-07-25 11:56:25');
INSERT INTO `categories` (`id`, `name`, `description`, `parent_id`, `status`, `created_at`) VALUES ('20', 'Others', 'Other items', NULL, '1', '2026-07-25 11:56:25');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `company`
-- -------------------------------------------
DROP TABLE IF EXISTS `company`;
CREATE TABLE `company` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ifsc` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `upi_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `company` WRITE;
INSERT INTO `company` (`id`, `name`, `email`, `phone`, `address`, `city`, `state`, `pincode`, `gstin`, `pan`, `logo`, `signature`, `bank_name`, `bank_account`, `bank_ifsc`, `upi_id`, `created_at`) VALUES ('1', 'Webora Software Solution', 'mdcngr2016@gmail.com', '07008376203', 'Itamati', 'Nayagarh', 'Odisha', '752068', '', '', NULL, NULL, '', '', '', '', '2026-07-25 11:55:46');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `delivery_challans`
-- -------------------------------------------
DROP TABLE IF EXISTS `delivery_challans`;
CREATE TABLE `delivery_challans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `challan_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `items_description` text COLLATE utf8mb4_unicode_ci,
  `vehicle_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','delivered','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_challans_challan_no` (`challan_no`),
  KEY `idx_delivery_challans_party_id` (`party_id`),
  KEY `idx_delivery_challans_user_id` (`user_id`),
  KEY `idx_delivery_challans_date` (`date`),
  KEY `idx_delivery_challans_status` (`status`),
  CONSTRAINT `fk_delivery_challans_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_challans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `email_settings`
-- -------------------------------------------
DROP TABLE IF EXISTS `email_settings`;
CREATE TABLE `email_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_port` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '587',
  `smtp_username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_encryption` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'tls',
  `from_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `estimate_items`
-- -------------------------------------------
DROP TABLE IF EXISTS `estimate_items`;
CREATE TABLE `estimate_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `estimate_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estimate_items_estimate_id` (`estimate_id`),
  KEY `idx_estimate_items_item_id` (`item_id`),
  CONSTRAINT `fk_estimate_items_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_estimate_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `estimates`
-- -------------------------------------------
DROP TABLE IF EXISTS `estimates`;
CREATE TABLE `estimates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `estimate_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `valid_until` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','sent','accepted','rejected','converted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estimates_estimate_no` (`estimate_no`),
  KEY `idx_estimates_party_id` (`party_id`),
  KEY `idx_estimates_user_id` (`user_id`),
  KEY `idx_estimates_date` (`date`),
  KEY `idx_estimates_status` (`status`),
  CONSTRAINT `fk_estimates_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_estimates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `expense_categories`
-- -------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_categories_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `expense_categories` WRITE;
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('1', 'Rent', 'Office / shop rent', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('2', 'Salary', 'Employee salaries', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('3', 'Electricity', 'Electricity bills', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('4', 'Internet', 'Internet / broadband bills', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('5', 'Transport', 'Transport and logistics', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('6', 'Office Supplies', 'Stationery and office items', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('7', 'Maintenance', 'Repair and maintenance', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('8', 'Marketing', 'Advertising and marketing', '1', '2026-07-25 11:56:25');
INSERT INTO `expense_categories` (`id`, `name`, `description`, `status`, `created_at`) VALUES ('9', 'Misc', 'Miscellaneous expenses', '1', '2026-07-25 11:56:25');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `expenses`
-- -------------------------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `expense_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','bank','upi','cheque') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `receipt_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_expenses_expense_no` (`expense_no`),
  KEY `idx_expenses_category_id` (`category_id`),
  KEY `idx_expenses_user_id` (`user_id`),
  KEY `idx_expenses_date` (`date`),
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expenses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `item_serials`
-- -------------------------------------------
DROP TABLE IF EXISTS `item_serials`;
CREATE TABLE `item_serials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int unsigned NOT NULL,
  `serial_number` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sale_id` int unsigned DEFAULT NULL,
  `status` enum('available','sold','returned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_serials_serial_number` (`serial_number`),
  KEY `idx_item_serials_item_id` (`item_id`),
  KEY `idx_item_serials_sale_id` (`sale_id`),
  KEY `idx_item_serials_status` (`status`),
  CONSTRAINT `fk_item_serials_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_item_serials_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `items`
-- -------------------------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pcs',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` int unsigned DEFAULT NULL,
  `hsn_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_stock` int NOT NULL DEFAULT '10',
  `current_stock` int NOT NULL DEFAULT '0',
  `opening_stock` int NOT NULL DEFAULT '0',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_items_sku` (`sku`),
  KEY `idx_items_barcode` (`barcode`),
  KEY `idx_items_category_id` (`category_id`),
  KEY `idx_items_tax_rate_id` (`tax_rate_id`),
  KEY `idx_items_name` (`name`),
  KEY `idx_items_status` (`status`),
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_items_tax_rate` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `items` WRITE;
INSERT INTO `items` (`id`, `name`, `sku`, `barcode`, `category_id`, `description`, `unit`, `purchase_price`, `sale_price`, `tax_rate_id`, `hsn_code`, `min_stock`, `current_stock`, `opening_stock`, `image`, `status`, `created_at`, `updated_at`) VALUES ('1', 'test1', 'ITM-TCS946', '', '16', '', 'Pcs', '100.00', '120.00', '7', '', '10', '0', '10', NULL, '1', '2026-07-25 14:12:05', '2026-07-25 14:13:06');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `other_income`
-- -------------------------------------------
DROP TABLE IF EXISTS `other_income`;
CREATE TABLE `other_income` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `income_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','bank','upi','cheque') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_other_income_income_no` (`income_no`),
  KEY `idx_other_income_category_id` (`category_id`),
  KEY `idx_other_income_user_id` (`user_id`),
  KEY `idx_other_income_date` (`date`),
  CONSTRAINT `fk_other_income_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_other_income_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `parties`
-- -------------------------------------------
DROP TABLE IF EXISTS `parties`;
CREATE TABLE `parties` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('customer','supplier','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `balance_type` enum('credit','debit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'credit',
  `party_group` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parties_type` (`type`),
  KEY `idx_parties_name` (`name`),
  KEY `idx_parties_phone` (`phone`),
  KEY `idx_parties_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `payments_in`
-- -------------------------------------------
DROP TABLE IF EXISTS `payments_in`;
CREATE TABLE `payments_in` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned NOT NULL,
  `sale_id` int unsigned DEFAULT NULL,
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','bank','upi','cheque') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `user_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_in_receipt_no` (`receipt_no`),
  KEY `idx_payments_in_party_id` (`party_id`),
  KEY `idx_payments_in_sale_id` (`sale_id`),
  KEY `idx_payments_in_date` (`date`),
  KEY `idx_payments_in_user_id` (`user_id`),
  CONSTRAINT `fk_payments_in_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_in_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_in_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `payments_out`
-- -------------------------------------------
DROP TABLE IF EXISTS `payments_out`;
CREATE TABLE `payments_out` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned NOT NULL,
  `purchase_id` int unsigned DEFAULT NULL,
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','bank','upi','cheque') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `user_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_out_payment_no` (`payment_no`),
  KEY `idx_payments_out_party_id` (`party_id`),
  KEY `idx_payments_out_purchase_id` (`purchase_id`),
  KEY `idx_payments_out_date` (`date`),
  KEY `idx_payments_out_user_id` (`user_id`),
  CONSTRAINT `fk_payments_out_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_out_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_out_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `purchase_items`
-- -------------------------------------------
DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE `purchase_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_items_purchase_id` (`purchase_id`),
  KEY `idx_purchase_items_item_id` (`item_id`),
  CONSTRAINT `fk_purchase_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `purchase_return_items`
-- -------------------------------------------
DROP TABLE IF EXISTS `purchase_return_items`;
CREATE TABLE `purchase_return_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_return_items_return_id` (`return_id`),
  KEY `idx_purchase_return_items_item_id` (`item_id`),
  CONSTRAINT `fk_purchase_return_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `purchase_returns`
-- -------------------------------------------
DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_id` int unsigned DEFAULT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','approved','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_returns_return_no` (`return_no`),
  KEY `idx_purchase_returns_purchase_id` (`purchase_id`),
  KEY `idx_purchase_returns_party_id` (`party_id`),
  KEY `idx_purchase_returns_user_id` (`user_id`),
  KEY `idx_purchase_returns_date` (`date`),
  CONSTRAINT `fk_purchase_returns_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_returns_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_returns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `purchases`
-- -------------------------------------------
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bill_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `due_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('paid','unpaid','partial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `payment_method` enum('cash','bank','upi','cheque','mixed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','received','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchases_bill_no` (`bill_no`),
  KEY `idx_purchases_party_id` (`party_id`),
  KEY `idx_purchases_user_id` (`user_id`),
  KEY `idx_purchases_date` (`date`),
  KEY `idx_purchases_payment_status` (`payment_status`),
  KEY `idx_purchases_status` (`status`),
  CONSTRAINT `fk_purchases_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `sale_items`
-- -------------------------------------------
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale_items_sale_id` (`sale_id`),
  KEY `idx_sale_items_item_id` (`item_id`),
  CONSTRAINT `fk_sale_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `sale_return_items`
-- -------------------------------------------
DROP TABLE IF EXISTS `sale_return_items`;
CREATE TABLE `sale_return_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale_return_items_return_id` (`return_id`),
  KEY `idx_sale_return_items_item_id` (`item_id`),
  CONSTRAINT `fk_sale_return_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `sale_returns`
-- -------------------------------------------
DROP TABLE IF EXISTS `sale_returns`;
CREATE TABLE `sale_returns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sale_id` int unsigned DEFAULT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','approved','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sale_returns_return_no` (`return_no`),
  KEY `idx_sale_returns_sale_id` (`sale_id`),
  KEY `idx_sale_returns_party_id` (`party_id`),
  KEY `idx_sale_returns_user_id` (`user_id`),
  KEY `idx_sale_returns_date` (`date`),
  CONSTRAINT `fk_sale_returns_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_returns_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_returns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `sales`
-- -------------------------------------------
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `due_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('paid','unpaid','partial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `payment_method` enum('cash','bank','upi','cheque','mixed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','sent','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sales_invoice_no` (`invoice_no`),
  KEY `idx_sales_party_id` (`party_id`),
  KEY `idx_sales_user_id` (`user_id`),
  KEY `idx_sales_date` (`date`),
  KEY `idx_sales_payment_status` (`payment_status`),
  KEY `idx_sales_status` (`status`),
  CONSTRAINT `fk_sales_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `settings`
-- -------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settings_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `settings` WRITE;
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('1', 'company_name', 'Webora Software Solution', '2026-07-25 11:56:25', '2026-07-25 15:22:17');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('2', 'currency', '₹', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('3', 'invoice_prefix', 'INV-', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('4', 'purchase_prefix', 'PUR-', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('5', 'estimate_prefix', 'EST-', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('6', 'challan_prefix', 'CHL-', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('7', 'return_prefix', 'RET-', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('8', 'default_tax_rate', '18', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES ('9', 'invoice_footer_text', 'Thank you for your business!', '2026-07-25 11:56:25', '2026-07-25 11:56:25');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `stock_adjustments`
-- -------------------------------------------
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int unsigned NOT NULL,
  `adjustment_type` enum('addition','subtraction','damage','expired','correction') COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` int NOT NULL DEFAULT '0',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_adjustments_item_id` (`item_id`),
  KEY `idx_stock_adjustments_user_id` (`user_id`),
  KEY `idx_stock_adjustments_date` (`date`),
  CONSTRAINT `fk_stock_adjustments_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `tax_rates`
-- -------------------------------------------
DROP TABLE IF EXISTS `tax_rates`;
CREATE TABLE `tax_rates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `type` enum('cgst','sgst','igst','cess') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tax_rates_type` (`type`),
  KEY `idx_tax_rates_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `tax_rates` WRITE;
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('1', 'CGST 6%', '6.00', 'cgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('2', 'SGST 6%', '6.00', 'sgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('3', 'CGST 9%', '9.00', 'cgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('4', 'SGST 9%', '9.00', 'sgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('5', 'CGST 12%', '12.00', 'cgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('6', 'SGST 12%', '12.00', 'sgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('7', 'CGST 18%', '18.00', 'cgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('8', 'SGST 18%', '18.00', 'sgst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('9', 'IGST 6%', '6.00', 'igst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('10', 'IGST 9%', '9.00', 'igst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('11', 'IGST 12%', '12.00', 'igst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('12', 'IGST 18%', '18.00', 'igst', '1', '2026-07-25 11:56:25');
INSERT INTO `tax_rates` (`id`, `name`, `rate`, `type`, `status`, `created_at`) VALUES ('13', 'CESS 12%', '12.00', 'cess', '1', '2026-07-25 11:56:25');
UNLOCK TABLES;

-- -------------------------------------------
-- Table: `transactions`
-- -------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('sale','purchase','expense','income','payment_in','payment_out','opening','adjustment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` int unsigned DEFAULT NULL,
  `party_id` int unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transactions_type` (`type`),
  KEY `idx_transactions_reference_id` (`reference_id`),
  KEY `idx_transactions_party_id` (`party_id`),
  KEY `idx_transactions_date` (`date`),
  CONSTRAINT `fk_transactions_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: `users`
-- -------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','accountant','sales') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sales',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `users` WRITE;
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone`, `role`, `status`, `created_at`, `updated_at`) VALUES ('1', 'admin', 'admin@retailready.local', '$2y$12$nYaD/osiOM7hDmOb2NT9w.1yiQI9886nna5lFC2H8697qohDtdypO', 'Admin', NULL, 'admin', '1', '2026-07-25 11:55:46', '2026-07-25 11:55:46');
UNLOCK TABLES;

SET FOREIGN_KEY_CHECKS = 1;
SET AUTOCOMMIT = 1;
-- End of Backup
