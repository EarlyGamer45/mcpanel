-- MariaDB schema generated from App\Core\Schema

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(190) NOT NULL,
  `batch` INT NOT NULL DEFAULT 1,
  `ran_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_migrations_migration` ON `migrations` (`migration`);

CREATE TABLE IF NOT EXISTS `sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `value` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_sequences_name` ON `sequences` (`name`);

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL,
  `email_enc` TEXT NOT NULL,
  `email_index` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'customer',
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME NULL,
  `totp_secret_enc` TEXT NULL,
  `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_codes_enc` TEXT NULL,
  `country` VARCHAR(2) NULL,
  `company_enc` TEXT NULL,
  `vat_number_enc` TEXT NULL,
  `vat_number_index` VARCHAR(64) NULL,
  `address_enc` TEXT NULL,
  `city_enc` TEXT NULL,
  `postal_code_enc` TEXT NULL,
  `phone_enc` TEXT NULL,
  `locale` VARCHAR(5) NOT NULL DEFAULT 'it',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/Rome',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `referral_code` VARCHAR(16) NULL,
  `referred_by` INT NULL,
  `credit_cents` BIGINT NOT NULL DEFAULT 0,
  `loyalty_points` INT NOT NULL DEFAULT 0,
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  `deleted_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_users_username` ON `users` (`username`);

CREATE UNIQUE INDEX `uq_users_email_index` ON `users` (`email_index`);

CREATE UNIQUE INDEX `uq_users_referral_code` ON `users` (`referral_code`);

CREATE INDEX `ix_users_status` ON `users` (`status`);

CREATE INDEX `ix_users_role` ON `users` (`role`);

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `session_id` VARCHAR(128) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `last_seen_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_user_sessions_session_id` ON `user_sessions` (`session_id`);

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_password_resets_token_hash` ON `password_resets` (`token_hash`);

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_email_verifications_token_hash` ON `email_verifications` (`token_hash`);

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `actor_id` INT NULL,
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'user',
  `action` VARCHAR(120) NOT NULL,
  `subject_type` VARCHAR(60) NULL,
  `subject_id` INT NULL,
  `ip` VARCHAR(45) NULL,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_audit_logs_action` ON `audit_logs` (`action`);

CREATE INDEX `ix_audit_logs_created_at` ON `audit_logs` (`created_at`);

CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `value_json` TEXT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_settings_name` ON `settings` (`name`);

CREATE TABLE IF NOT EXISTS `feature_flags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_feature_flags_name` ON `feature_flags` (`name`);

CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_categories_slug` ON `categories` (`slug`);

CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `description` TEXT NULL,
  `image` VARCHAR(255) NULL,
  `tier` VARCHAR(20) NOT NULL DEFAULT 'standard',
  `sort` INT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `nest_id` INT NULL,
  `egg_id` INT NULL,
  `docker_image` VARCHAR(190) NULL,
  `startup` TEXT NULL,
  `min_ram_mb` INT NOT NULL DEFAULT 1024,
  `max_ram_mb` INT NOT NULL DEFAULT 16384,
  `ram_step_mb` INT NOT NULL DEFAULT 1024,
  `allowed_versions_json` JSON NULL,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_products_slug` ON `products` (`slug`);

CREATE INDEX `ix_products_visible` ON `products` (`visible`);

CREATE TABLE IF NOT EXISTS `product_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `cycle` VARCHAR(20) NOT NULL,
  `price_cents` BIGINT NOT NULL DEFAULT 0,
  `setup_cents` BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_product_prices_product_id_cycle` ON `product_prices` (`product_id`, `cycle`);

CREATE TABLE IF NOT EXISTS `resource_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NULL,
  `resource` VARCHAR(20) NOT NULL,
  `min_value` INT NOT NULL DEFAULT 0,
  `max_value` INT NOT NULL DEFAULT 0,
  `step` INT NOT NULL DEFAULT 1,
  `included` INT NOT NULL DEFAULT 0,
  `unit_cents` BIGINT NOT NULL DEFAULT 0,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'unit',
  `sort` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `addons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(60) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `price_cents` BIGINT NOT NULL DEFAULT 0,
  `cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly',
  `type` VARCHAR(30) NOT NULL DEFAULT 'extra',
  `meta_json` JSON NULL,
  `visible` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_addons_code` ON `addons` (`code`);

CREATE TABLE IF NOT EXISTS `product_addons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `addon_id` INT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_product_addons_product_id_addon_id` ON `product_addons` (`product_id`, `addon_id`);

CREATE TABLE IF NOT EXISTS `locations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `country` VARCHAR(2) NOT NULL,
  `pterodactyl_location_id` INT NULL,
  `ddos_note` VARCHAR(255) NULL,
  `visible` TINYINT(1) NOT NULL DEFAULT 1,
  `maintenance` TINYINT(1) NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_locations_slug` ON `locations` (`slug`);

CREATE TABLE IF NOT EXISTS `nodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `location_id` INT NOT NULL,
  `pterodactyl_node_id` INT NOT NULL,
  `memory_mb` INT NOT NULL DEFAULT 0,
  `disk_mb` INT NOT NULL DEFAULT 0,
  `cpu_percent` INT NOT NULL DEFAULT 0,
  `overallocation_pct` INT NOT NULL DEFAULT 100,
  `monthly_cost_cents` BIGINT NOT NULL DEFAULT 0,
  `maintenance` TINYINT(1) NOT NULL DEFAULT 0,
  `visible` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(64) NOT NULL,
  `user_id` INT NULL,
  `email_enc` TEXT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `reminder_sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_carts_token` ON `carts` (`token`);

CREATE INDEX `ix_carts_status` ON `carts` (`status`);

CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cart_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `location_id` INT NULL,
  `cycle` VARCHAR(20) NOT NULL,
  `config_json` JSON NOT NULL,
  `price_cents` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL,
  `type` VARCHAR(10) NOT NULL DEFAULT 'percent',
  `value` INT NOT NULL DEFAULT 0,
  `valid_from` DATETIME NULL,
  `valid_to` DATETIME NULL,
  `max_uses` INT NOT NULL DEFAULT 0,
  `uses` INT NOT NULL DEFAULT 0,
  `per_user_limit` INT NOT NULL DEFAULT 1,
  `first_purchase_only` TINYINT(1) NOT NULL DEFAULT 0,
  `recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `applies_to_json` JSON NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_coupons_code` ON `coupons` (`code`);

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `coupon_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_coupon_redemptions_coupon_id` ON `coupon_redemptions` (`coupon_id`);

CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `subtotal_cents` BIGINT NOT NULL DEFAULT 0,
  `discount_cents` BIGINT NOT NULL DEFAULT 0,
  `tax_cents` BIGINT NOT NULL DEFAULT 0,
  `total_cents` BIGINT NOT NULL DEFAULT 0,
  `coupon_id` INT NULL,
  `invoice_id` INT NULL,
  `risk_score` INT NOT NULL DEFAULT 0,
  `meta_json` JSON NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_orders_number` ON `orders` (`number`);

CREATE INDEX `ix_orders_status` ON `orders` (`status`);

CREATE INDEX `ix_orders_user_id` ON `orders` (`user_id`);

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `service_id` INT NULL,
  `description` VARCHAR(255) NOT NULL,
  `cycle` VARCHAR(20) NOT NULL,
  `config_json` JSON NULL,
  `unit_cents` BIGINT NOT NULL DEFAULT 0,
  `quantity` INT NOT NULL DEFAULT 1,
  `total_cents` BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(32) NOT NULL,
  `series` VARCHAR(10) NOT NULL DEFAULT 'A',
  `year` INT NOT NULL,
  `sequence` INT NOT NULL,
  `user_id` INT NOT NULL,
  `order_id` INT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `subtotal_cents` BIGINT NOT NULL DEFAULT 0,
  `discount_cents` BIGINT NOT NULL DEFAULT 0,
  `tax_cents` BIGINT NOT NULL DEFAULT 0,
  `total_cents` BIGINT NOT NULL DEFAULT 0,
  `tax_mode` VARCHAR(30) NOT NULL DEFAULT 'domestic',
  `vat_country` VARCHAR(2) NULL,
  `vat_rate_bp` INT NOT NULL DEFAULT 0,
  `reverse_charge` TINYINT(1) NOT NULL DEFAULT 0,
  `customer_json` JSON NULL,
  `due_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `pdf_path` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_invoices_number` ON `invoices` (`number`);

CREATE INDEX `ix_invoices_status` ON `invoices` (`status`);

CREATE INDEX `ix_invoices_year_series` ON `invoices` (`year`, `series`);

CREATE TABLE IF NOT EXISTS `invoice_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_cents` BIGINT NOT NULL DEFAULT 0,
  `total_cents` BIGINT NOT NULL DEFAULT 0,
  `tax_rate_bp` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(32) NOT NULL,
  `invoice_id` INT NOT NULL,
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_credit_notes_number` ON `credit_notes` (`number`);

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_id` INT NULL,
  `invoice_id` INT NULL,
  `gateway` VARCHAR(30) NOT NULL,
  `external_id` VARCHAR(190) NULL,
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `idempotency_key` VARCHAR(120) NOT NULL,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_payments_idempotency_key` ON `payments` (`idempotency_key`);

CREATE INDEX `ix_payments_status` ON `payments` (`status`);

CREATE INDEX `ix_payments_gateway_external_id` ON `payments` (`gateway`, `external_id`);

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `gateway` VARCHAR(30) NOT NULL,
  `token_enc` TEXT NOT NULL,
  `brand` VARCHAR(30) NULL,
  `last4` VARCHAR(4) NULL,
  `exp_month` INT NULL,
  `exp_year` INT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `gateway` VARCHAR(30) NOT NULL,
  `external_id` VARCHAR(190) NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `payload_json` MEDIUMTEXT NULL,
  `processed_at` DATETIME NULL,
  `error` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_webhook_events_gateway_external_id` ON `webhook_events` (`gateway`, `external_id`);

CREATE TABLE IF NOT EXISTS `wallets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `balance_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_wallets_user_id` ON `wallets` (`user_id`);

CREATE TABLE IF NOT EXISTS `wallet_topups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount_cents` BIGINT NOT NULL DEFAULT 0,
  `reference` VARCHAR(60) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL,
  `paid_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_wallet_topups_reference` ON `wallet_topups` (`reference`);

CREATE INDEX `ix_wallet_topups_status` ON `wallet_topups` (`status`);

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `wallet_id` INT NOT NULL,
  `amount_cents` BIGINT NOT NULL,
  `type` VARCHAR(30) NOT NULL,
  `reference` VARCHAR(120) NULL,
  `balance_after_cents` BIGINT NOT NULL DEFAULT 0,
  `meta_json` JSON NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_wallet_transactions_wallet_id` ON `wallet_transactions` (`wallet_id`);

CREATE TABLE IF NOT EXISTS `services` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_id` INT NULL,
  `product_id` INT NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly',
  `price_cents` BIGINT NOT NULL DEFAULT 0,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `ram_mb` INT NOT NULL DEFAULT 0,
  `cpu_percent` INT NOT NULL DEFAULT 0,
  `disk_mb` INT NOT NULL DEFAULT 0,
  `location_id` INT NULL,
  `node_id` INT NULL,
  `addons_json` JSON NULL,
  `config_json` JSON NULL,
  `provision_key` VARCHAR(64) NOT NULL,
  `provisioning_state` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `pterodactyl_server_id` INT NULL,
  `external_identifier` VARCHAR(32) NULL,
  `ip` VARCHAR(45) NULL,
  `port` INT NULL,
  `next_due_at` DATETIME NULL,
  `suspended_at` DATETIME NULL,
  `terminated_at` DATETIME NULL,
  `cancellation_at` DATETIME NULL,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `suspension_reason` VARCHAR(255) NULL,
  `metadata_json` JSON NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_services_provision_key` ON `services` (`provision_key`);

CREATE INDEX `ix_services_status` ON `services` (`status`);

CREATE INDEX `ix_services_next_due_at` ON `services` (`next_due_at`);

CREATE INDEX `ix_services_user_id` ON `services` (`user_id`);

CREATE TABLE IF NOT EXISTS `service_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT NOT NULL,
  `from_state` VARCHAR(20) NULL,
  `to_state` VARCHAR(20) NOT NULL,
  `reason` VARCHAR(255) NULL,
  `actor_id` INT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provisioning_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT NOT NULL,
  `attempt` INT NOT NULL DEFAULT 1,
  `step` VARCHAR(60) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `status_code` INT NULL,
  `message` TEXT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `service_id` INT NULL,
  `department` VARCHAR(20) NOT NULL DEFAULT 'technical',
  `priority` VARCHAR(10) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(30) NOT NULL DEFAULT 'open',
  `subject` VARCHAR(200) NOT NULL,
  `assigned_to` INT NULL,
  `first_response_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `rating` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_tickets_number` ON `tickets` (`number`);

CREATE INDEX `ix_tickets_status` ON `tickets` (`status`);

CREATE INDEX `ix_tickets_user_id` ON `tickets` (`user_id`);

CREATE TABLE IF NOT EXISTS `ticket_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NULL,
  `body` TEXT NOT NULL,
  `internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT NOT NULL,
  `filename` VARCHAR(190) NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(120) NOT NULL,
  `size` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kb_articles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(80) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `locale` VARCHAR(5) NOT NULL DEFAULT 'it',
  `published` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_kb_articles_slug` ON `kb_articles` (`slug`);

CREATE TABLE IF NOT EXISTS `legal_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX `uq_legal_documents_name` ON `legal_documents` (`name`);

CREATE TABLE IF NOT EXISTS `legal_acceptances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `document` VARCHAR(60) NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `accepted_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `license_state` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `license_key_enc` TEXT NULL,
  `plan` VARCHAR(40) NULL,
  `fingerprint` VARCHAR(64) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'unlicensed',
  `expires_at` DATETIME NULL,
  `last_check_at` DATETIME NULL,
  `last_valid_at` DATETIME NULL,
  `payload_json` JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `to_hash` VARCHAR(64) NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `template` VARCHAR(80) NULL,
  `status` VARCHAR(20) NOT NULL,
  `error` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(190) NOT NULL,
  `body` TEXT NOT NULL,
  `level` VARCHAR(20) NOT NULL DEFAULT 'info',
  `published_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `visible` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `queue` VARCHAR(40) NOT NULL DEFAULT 'default',
  `job` VARCHAR(190) NOT NULL,
  `payload_json` MEDIUMTEXT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `max_attempts` INT NOT NULL DEFAULT 5,
  `available_at` DATETIME NOT NULL,
  `reserved_at` DATETIME NULL,
  `reserved_by` VARCHAR(64) NULL,
  `failed_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `ix_jobs_queue_available_at` ON `jobs` (`queue`, `available_at`);

ALTER TABLE `user_sessions` ADD CONSTRAINT fk_user_sessions_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `password_resets` ADD CONSTRAINT fk_password_resets_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `email_verifications` ADD CONSTRAINT fk_email_verifications_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `products` ADD CONSTRAINT fk_products_category_id FOREIGN KEY (`category_id`) REFERENCES `categories` (id) ON DELETE CASCADE;

ALTER TABLE `product_prices` ADD CONSTRAINT fk_product_prices_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `resource_options` ADD CONSTRAINT fk_resource_options_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `product_addons` ADD CONSTRAINT fk_product_addons_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `product_addons` ADD CONSTRAINT fk_product_addons_addon_id FOREIGN KEY (`addon_id`) REFERENCES `addons` (id) ON DELETE CASCADE;

ALTER TABLE `nodes` ADD CONSTRAINT fk_nodes_location_id FOREIGN KEY (`location_id`) REFERENCES `locations` (id) ON DELETE CASCADE;

ALTER TABLE `carts` ADD CONSTRAINT fk_carts_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `cart_items` ADD CONSTRAINT fk_cart_items_cart_id FOREIGN KEY (`cart_id`) REFERENCES `carts` (id) ON DELETE CASCADE;

ALTER TABLE `cart_items` ADD CONSTRAINT fk_cart_items_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `coupon_redemptions` ADD CONSTRAINT fk_coupon_redemptions_coupon_id FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (id) ON DELETE CASCADE;

ALTER TABLE `coupon_redemptions` ADD CONSTRAINT fk_coupon_redemptions_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `coupon_redemptions` ADD CONSTRAINT fk_coupon_redemptions_order_id FOREIGN KEY (`order_id`) REFERENCES `orders` (id) ON DELETE CASCADE;

ALTER TABLE `orders` ADD CONSTRAINT fk_orders_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `orders` ADD CONSTRAINT fk_orders_coupon_id FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (id) ON DELETE CASCADE;

ALTER TABLE `order_items` ADD CONSTRAINT fk_order_items_order_id FOREIGN KEY (`order_id`) REFERENCES `orders` (id) ON DELETE CASCADE;

ALTER TABLE `order_items` ADD CONSTRAINT fk_order_items_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `invoices` ADD CONSTRAINT fk_invoices_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `invoice_lines` ADD CONSTRAINT fk_invoice_lines_invoice_id FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (id) ON DELETE CASCADE;

ALTER TABLE `credit_notes` ADD CONSTRAINT fk_credit_notes_invoice_id FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (id) ON DELETE CASCADE;

ALTER TABLE `payments` ADD CONSTRAINT fk_payments_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `payment_methods` ADD CONSTRAINT fk_payment_methods_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `wallets` ADD CONSTRAINT fk_wallets_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `wallet_topups` ADD CONSTRAINT fk_wallet_topups_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `wallet_transactions` ADD CONSTRAINT fk_wallet_transactions_wallet_id FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (id) ON DELETE CASCADE;

ALTER TABLE `services` ADD CONSTRAINT fk_services_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `services` ADD CONSTRAINT fk_services_product_id FOREIGN KEY (`product_id`) REFERENCES `products` (id) ON DELETE CASCADE;

ALTER TABLE `services` ADD CONSTRAINT fk_services_order_id FOREIGN KEY (`order_id`) REFERENCES `orders` (id) ON DELETE CASCADE;

ALTER TABLE `service_events` ADD CONSTRAINT fk_service_events_service_id FOREIGN KEY (`service_id`) REFERENCES `services` (id) ON DELETE CASCADE;

ALTER TABLE `provisioning_logs` ADD CONSTRAINT fk_provisioning_logs_service_id FOREIGN KEY (`service_id`) REFERENCES `services` (id) ON DELETE CASCADE;

ALTER TABLE `tickets` ADD CONSTRAINT fk_tickets_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;

ALTER TABLE `ticket_messages` ADD CONSTRAINT fk_ticket_messages_ticket_id FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (id) ON DELETE CASCADE;

ALTER TABLE `ticket_attachments` ADD CONSTRAINT fk_ticket_attachments_message_id FOREIGN KEY (`message_id`) REFERENCES `ticket_messages` (id) ON DELETE CASCADE;

ALTER TABLE `legal_acceptances` ADD CONSTRAINT fk_legal_acceptances_user_id FOREIGN KEY (`user_id`) REFERENCES `users` (id) ON DELETE CASCADE;
