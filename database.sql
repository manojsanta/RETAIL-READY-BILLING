-- ============================================================
-- Retail-Ready: Computer Billing Shop Database Schema
-- MySQL / InnoDB / utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS `retail_ready`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `retail_ready`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. users
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` ENUM('admin','accountant','sales') NOT NULL DEFAULT 'sales',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. company
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `company`;
CREATE TABLE `company` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `pincode` VARCHAR(10) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `signature` VARCHAR(255) DEFAULT NULL,
  `bank_name` VARCHAR(150) DEFAULT NULL,
  `bank_account` VARCHAR(50) DEFAULT NULL,
  `bank_ifsc` VARCHAR(20) DEFAULT NULL,
  `upi_id` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. tax_rates
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tax_rates`;
CREATE TABLE `tax_rates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `type` ENUM('cgst','sgst','igst','cess') NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_tax_rates_type` (`type`),
  INDEX `idx_tax_rates_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. categories
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_categories_parent_id` (`parent_id`),
  INDEX `idx_categories_status` (`status`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. parties
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `parties`;
CREATE TABLE `parties` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('customer','supplier','both') NOT NULL DEFAULT 'customer',
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `pincode` VARCHAR(10) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  `gst_reg_type` VARCHAR(50) DEFAULT NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_type` ENUM('credit','debit') NOT NULL DEFAULT 'credit',
  `party_group` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_parties_type` (`type`),
  INDEX `idx_parties_name` (`name`),
  INDEX `idx_parties_phone` (`phone`),
  INDEX `idx_parties_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'Pcs',
  `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `purchase_price_with_tax` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_price_with_tax` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate_id` INT UNSIGNED DEFAULT NULL,
  `purchase_tax_rate_id` INT UNSIGNED DEFAULT NULL,
  `purchase_tax_mode` ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive',
  `sale_tax_mode` ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive',
  `hsn_code` VARCHAR(20) DEFAULT NULL,
  `min_stock` INT NOT NULL DEFAULT 10,
  `current_stock` INT NOT NULL DEFAULT 0,
  `opening_stock` INT NOT NULL DEFAULT 0,
  `image` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_items_sku` (`sku`),
  INDEX `idx_items_barcode` (`barcode`),
  INDEX `idx_items_category_id` (`category_id`),
  INDEX `idx_items_tax_rate_id` (`tax_rate_id`),
  INDEX `idx_items_name` (`name`),
  INDEX `idx_items_status` (`status`),
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_items_tax_rate` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. sales
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `due_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
  `payment_method` ENUM('cash','bank','upi','cheque','mixed','credit') DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sales_invoice_no` (`invoice_no`),
  INDEX `idx_sales_party_id` (`party_id`),
  INDEX `idx_sales_user_id` (`user_id`),
  INDEX `idx_sales_date` (`date`),
  INDEX `idx_sales_pm_date` (`payment_method`, `date`),
  INDEX `idx_sales_payment_status` (`payment_status`),
  INDEX `idx_sales_status` (`status`),
  CONSTRAINT `fk_sales_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. sale_items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sale_items_sale_id` (`sale_id`),
  INDEX `idx_sale_items_item_id` (`item_id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. sale_returns
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sale_returns`;
CREATE TABLE `sale_returns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no` VARCHAR(30) NOT NULL,
  `sale_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reason` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sale_returns_return_no` (`return_no`),
  INDEX `idx_sale_returns_sale_id` (`sale_id`),
  INDEX `idx_sale_returns_party_id` (`party_id`),
  INDEX `idx_sale_returns_user_id` (`user_id`),
  INDEX `idx_sale_returns_date` (`date`),
  CONSTRAINT `fk_sale_returns_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_returns_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_returns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. sale_return_items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sale_return_items`;
CREATE TABLE `sale_return_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sale_return_items_return_id` (`return_id`),
  INDEX `idx_sale_return_items_item_id` (`item_id`),
  CONSTRAINT `fk_sale_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_return_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. purchases
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `due_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
  `payment_method` ENUM('cash','bank','upi','cheque','mixed') DEFAULT NULL,
  `supplier_bill_no` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','received','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchases_bill_no` (`bill_no`),
  INDEX `idx_purchases_party_id` (`party_id`),
  INDEX `idx_purchases_user_id` (`user_id`),
  INDEX `idx_purchases_date` (`date`),
  INDEX `idx_purchases_payment_status` (`payment_status`),
  INDEX `idx_purchases_status` (`status`),
  CONSTRAINT `fk_purchases_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. purchase_items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE `purchase_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_purchase_items_purchase_id` (`purchase_id`),
  INDEX `idx_purchase_items_item_id` (`item_id`),
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. purchase_returns
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no` VARCHAR(30) NOT NULL,
  `purchase_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reason` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_returns_return_no` (`return_no`),
  INDEX `idx_purchase_returns_purchase_id` (`purchase_id`),
  INDEX `idx_purchase_returns_party_id` (`party_id`),
  INDEX `idx_purchase_returns_user_id` (`user_id`),
  INDEX `idx_purchase_returns_date` (`date`),
  CONSTRAINT `fk_purchase_returns_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_returns_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_returns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. purchase_return_items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_return_items`;
CREATE TABLE `purchase_return_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_purchase_return_items_return_id` (`return_id`),
  INDEX `idx_purchase_return_items_item_id` (`item_id`),
  CONSTRAINT `fk_purchase_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_return_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. payments_in
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `payments_in`;
CREATE TABLE `payments_in` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED NOT NULL,
  `sale_id` INT UNSIGNED DEFAULT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_in_receipt_no` (`receipt_no`),
  INDEX `idx_payments_in_party_id` (`party_id`),
  INDEX `idx_payments_in_sale_id` (`sale_id`),
  INDEX `idx_payments_in_date` (`date`),
  INDEX `idx_payments_in_pm_date` (`payment_method`, `date`),
  INDEX `idx_payments_in_user_id` (`user_id`),
  CONSTRAINT `fk_payments_in_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_in_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_in_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. payments_out
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `payments_out`;
CREATE TABLE `payments_out` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED NOT NULL,
  `purchase_id` INT UNSIGNED DEFAULT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_out_payment_no` (`payment_no`),
  INDEX `idx_payments_out_party_id` (`party_id`),
  INDEX `idx_payments_out_purchase_id` (`purchase_id`),
  INDEX `idx_payments_out_date` (`date`),
  INDEX `idx_payments_out_pm_date` (`payment_method`, `date`),
  INDEX `idx_payments_out_user_id` (`user_id`),
  CONSTRAINT `fk_payments_out_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_out_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_out_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17. estimates
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `estimates`;
CREATE TABLE `estimates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estimate_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `valid_until` DATE DEFAULT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `service_needed` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','sent','accepted','rejected','converted') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estimates_estimate_no` (`estimate_no`),
  INDEX `idx_estimates_party_id` (`party_id`),
  INDEX `idx_estimates_user_id` (`user_id`),
  INDEX `idx_estimates_date` (`date`),
  INDEX `idx_estimates_status` (`status`),
  CONSTRAINT `fk_estimates_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_estimates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18. estimate_items
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `estimate_items`;
CREATE TABLE `estimate_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estimate_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_estimate_items_estimate_id` (`estimate_id`),
  INDEX `idx_estimate_items_item_id` (`item_id`),
  CONSTRAINT `fk_estimate_items_estimate` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_estimate_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 19. delivery_challans
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `delivery_challans`;
CREATE TABLE `delivery_challans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challan_no` VARCHAR(30) NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `items_description` TEXT DEFAULT NULL,
  `vehicle_no` VARCHAR(50) DEFAULT NULL,
  `driver_name` VARCHAR(100) DEFAULT NULL,
  `destination` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('pending','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_challans_challan_no` (`challan_no`),
  INDEX `idx_delivery_challans_party_id` (`party_id`),
  INDEX `idx_delivery_challans_user_id` (`user_id`),
  INDEX `idx_delivery_challans_date` (`date`),
  INDEX `idx_delivery_challans_status` (`status`),
  CONSTRAINT `fk_delivery_challans_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_challans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 20. expenses
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_no` VARCHAR(30) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `receipt_image` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_expenses_expense_no` (`expense_no`),
  INDEX `idx_expenses_category_id` (`category_id`),
  INDEX `idx_expenses_user_id` (`user_id`),
  INDEX `idx_expenses_date` (`date`),
  INDEX `idx_expenses_pm_date` (`payment_method`, `date`),
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expenses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 21. expense_categories
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_expense_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 22. other_income
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `other_income`;
CREATE TABLE `other_income` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `income_no` VARCHAR(30) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank','upi','cheque') NOT NULL DEFAULT 'cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_other_income_income_no` (`income_no`),
  INDEX `idx_other_income_category_id` (`category_id`),
  INDEX `idx_other_income_user_id` (`user_id`),
  INDEX `idx_other_income_date` (`date`),
  CONSTRAINT `fk_other_income_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_other_income_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 23. bank_accounts
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_name` VARCHAR(150) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_no` VARCHAR(50) NOT NULL,
  `ifsc_code` VARCHAR(20) DEFAULT NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_bank_accounts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 24. transactions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('sale','purchase','expense','income','payment_in','payment_out','opening','adjustment') NOT NULL,
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(20) DEFAULT NULL,
  `date` DATE NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_transactions_type` (`type`),
  INDEX `idx_transactions_reference_id` (`reference_id`),
  INDEX `idx_transactions_party_id` (`party_id`),
  INDEX `idx_transactions_date` (`date`),
  CONSTRAINT `fk_transactions_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 25. stock_adjustments
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` INT UNSIGNED NOT NULL,
  `adjustment_type` ENUM('addition','subtraction','damage','expired','correction') NOT NULL,
  `qty` INT NOT NULL DEFAULT 0,
  `reason` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_stock_adjustments_item_id` (`item_id`),
  INDEX `idx_stock_adjustments_user_id` (`user_id`),
  INDEX `idx_stock_adjustments_date` (`date`),
  CONSTRAINT `fk_stock_adjustments_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 26. settings
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settings_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 27. item_serials
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `item_serials`;
CREATE TABLE `item_serials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` INT UNSIGNED NOT NULL,
  `serial_number` VARCHAR(100) NOT NULL,
  `sale_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('available','sold','returned') NOT NULL DEFAULT 'available',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_serials_serial_number` (`serial_number`),
  INDEX `idx_item_serials_item_id` (`item_id`),
  INDEX `idx_item_serials_sale_id` (`sale_id`),
  INDEX `idx_item_serials_status` (`status`),
  CONSTRAINT `fk_item_serials_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_item_serials_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 27. email_settings
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `email_settings`;
CREATE TABLE `email_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `smtp_host` VARCHAR(255) DEFAULT NULL,
  `smtp_port` VARCHAR(10) DEFAULT '587',
  `smtp_username` VARCHAR(255) DEFAULT NULL,
  `smtp_password` VARCHAR(255) DEFAULT NULL,
  `smtp_encryption` VARCHAR(10) DEFAULT 'tls',
  `from_name` VARCHAR(150) DEFAULT NULL,
  `from_email` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 28. units
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `short_name` VARCHAR(20) NOT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_units_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 29. unit_compounds
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `unit_compounds`;
CREATE TABLE `unit_compounds` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` INT UNSIGNED NOT NULL,
  `base_unit_id` INT UNSIGNED NOT NULL,
  `conversion_factor` DECIMAL(12,4) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_uc_unit_id` (`unit_id`),
  INDEX `idx_uc_base_unit_id` (`base_unit_id`),
  CONSTRAINT `fk_uc_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uc_base_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 30. financial_years
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `financial_years`;
CREATE TABLE `financial_years` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_financial_years_active` (`is_active`),
  INDEX `idx_financial_years_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default admin user (password: password)
INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `phone`, `role`, `status`)
VALUES (
  'admin',
  'admin@retailready.local',
  '$2y$12$B1WfrINfMnKKGrVu3tph7OB9y7X95LM7k8Z6wdETzwTVddJ/WqzU.',
  'Admin',
  NULL,
  'admin',
  1
);

-- Default company (empty placeholders)
INSERT INTO `company` (`name`, `email`, `phone`, `address`, `city`, `state`, `pincode`, `gstin`, `pan`, `logo`, `bank_name`, `bank_account`, `bank_ifsc`, `upi_id`)
VALUES (
  '', '', '', '', '', '', '', '', '', NULL, '', '', '', ''
);

-- Default tax rates
INSERT INTO `tax_rates` (`name`, `rate`, `type`, `status`) VALUES
('CGST 6%',   6.00, 'cgst', 1),
('SGST 6%',   6.00, 'sgst', 1),
('CGST 9%',   9.00, 'cgst', 1),
('SGST 9%',   9.00, 'sgst', 1),
('CGST 12%', 12.00, 'cgst', 1),
('SGST 12%', 12.00, 'sgst', 1),
('CGST 18%', 18.00, 'cgst', 1),
('SGST 18%', 18.00, 'sgst', 1),
('IGST 6%',   6.00, 'igst', 1),
('IGST 9%',   9.00, 'igst', 1),
('IGST 12%', 12.00, 'igst', 1),
('IGST 18%', 18.00, 'igst', 1),
('CESS 12%', 12.00, 'cess', 1);

-- Default computer shop categories
INSERT INTO `categories` (`name`, `description`, `status`) VALUES
('Laptops',           'Laptop computers',         1),
('Desktops',          'Desktop computers',        1),
('Monitors',          'Display monitors',         1),
('Printers',          'Printers and scanners',    1),
('Keyboards',         'Keyboards',                1),
('Mice',              'Computer mice',            1),
('Headphones',        'Headphones and earphones', 1),
('Speakers',          'Speakers and soundbars',   1),
('Cables',            'Cables and adapters',      1),
('Storage (HDD/SSD)', 'Hard drives and SSDs',     1),
('RAM',               'Memory modules',           1),
('Processors',        'CPU processors',           1),
('Motherboards',      'Motherboards',             1),
('Graphics Cards',    'GPU / graphics cards',     1),
('Software',          'Software and licenses',    1),
('Accessories',       'Misc accessories',         1),
('Networking',        'Routers, switches, cables',1),
('UPS',               'Uninterruptible power supplies', 1),
('Webcams',           'Webcams and cameras',      1),
('Others',            'Other items',              1);

-- Default expense categories
INSERT INTO `expense_categories` (`name`, `description`, `status`) VALUES
('Rent',            'Office / shop rent',          1),
('Salary',          'Employee salaries',           1),
('Electricity',     'Electricity bills',           1),
('Internet',        'Internet / broadband bills',  1),
('Transport',       'Transport and logistics',     1),
('Office Supplies', 'Stationery and office items', 1),
('Maintenance',     'Repair and maintenance',      1),
('Marketing',       'Advertising and marketing',   1),
('Misc',            'Miscellaneous expenses',      1);

-- Default units
INSERT INTO `units` (`name`, `short_name`) VALUES
('Piece', 'Pcs'),
('Kilogram', 'Kg'),
('Gram', 'g'),
('Litre', 'Ltr'),
('Millilitre', 'ml'),
('Box', 'Box'),
('Pack', 'Pkt'),
('Dozen', 'Dzn'),
('Set', 'Set'),
('Carton', 'Ctn');

-- Default financial years
INSERT INTO `financial_years` (`name`, `start_date`, `end_date`, `is_active`) VALUES
('FY 2025-26', '2025-04-01', '2026-03-31', 1),
('FY 2026-27', '2026-04-01', '2027-03-31', 0);

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name',         ''),
('currency',             '₹'),
('invoice_prefix',       'INV-'),
('purchase_prefix',      'PUR-'),
('estimate_prefix',      'EST-'),
('challan_prefix',       'CHL-'),
('return_prefix',        'RET-'),
('default_tax_rate',     '18'),
('invoice_footer_text',  'Thank you for your business!');
