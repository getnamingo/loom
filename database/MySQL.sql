CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(249) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(2) unsigned NOT NULL DEFAULT '0',
  `verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `resettable` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `roles_mask` int(10) unsigned NOT NULL DEFAULT '0',
  `registered` int(10) unsigned NOT NULL,
  `last_login` int(10) unsigned DEFAULT NULL,
  `force_logout` mediumint(7) unsigned NOT NULL DEFAULT '0',
  `tfa_secret` VARCHAR(32),
  `tfa_enabled` TINYINT DEFAULT 0,
  `auth_method` ENUM('password', '2fa', 'webauthn') DEFAULT 'password',
  `backup_codes` TEXT,
  `password_last_updated` timestamp NULL DEFAULT current_timestamp(),
  `nin` varchar(255) default NULL,
  `vat_number` varchar(64) DEFAULT NULL,
  `nin_type` enum('personal','business') default NULL,
  `validation` enum('0','1','2','3','4'),
  `validation_stamp` datetime(3) default NULL,
  `validation_log` varchar(255) DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `account_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `credit_limit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_audit` (
  `user_id` int(10) unsigned NOT NULL,
  `user_event` VARCHAR(255) NOT NULL,
  `user_resource` VARCHAR(255) default NULL,
  `user_agent` VARCHAR(255) NOT NULL,
  `user_ip` VARCHAR(45) NOT NULL,
  `user_location` VARCHAR(45) default NULL,
  `event_time` DATETIME(3) NOT NULL,
  `user_data` JSON default NULL,
  KEY `user_id` (`user_id`),
  KEY `user_event` (`user_event`),
  KEY `user_ip` (`user_ip`),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_confirmations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `email` varchar(249) COLLATE utf8mb4_unicode_ci NOT NULL,
  `selector` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `email_expires` (`email`,`expires`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_remembered` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(24) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `expires` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `user_expires` (`user_id`,`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_throttling` (
  `bucket` varchar(44) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `tokens` float unsigned NOT NULL,
  `replenished_at` int(10) unsigned NOT NULL,
  `expires_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`bucket`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_webauthn` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `credential_id` VARBINARY(1364) NOT NULL,
  `public_key` TEXT NOT NULL,
  `attestation_object` BLOB,
  `sign_count` BIGINT NOT NULL,
  `user_agent` VARCHAR(512),
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `credential_id` (`credential_id`),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_contact` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` enum('owner','admin','billing','tech','abuse') NOT NULL default 'admin',
  `title` varchar(255) default NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) default NULL,
  `last_name` varchar(255) NOT NULL,
  `org` varchar(255) default NULL,
  `street1` varchar(255) default NULL,
  `street2` varchar(255) default NULL,
  `street3` varchar(255) default NULL,
  `city` varchar(255) NOT NULL,
  `sp` varchar(255) default NULL,
  `pc` varchar(16) default NULL,
  `cc` char(2) NOT NULL,
  `voice` varchar(17) default NULL,
  `fax` varchar(17) default NULL,
  `email` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniquekey` (`user_id`,`type`),
  CONSTRAINT `user_contact_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_categories` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) UNSIGNED NOT NULL, 
  `category_id` INT(11) UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('Open', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
  `priority` ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
  `date_created` datetime(3) DEFAULT CURRENT_TIMESTAMP,
  `last_updated` datetime(3) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (category_id) REFERENCES ticket_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_responses` (
  `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT(11) UNSIGNED NOT NULL,
  `responder_id` INT(11) UNSIGNED NOT NULL,
  `response` TEXT NOT NULL,
  `date_created` datetime(3) DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED,
  `invoice_type` ENUM('regular', 'deposit', 'credit', 'proforma') NOT NULL DEFAULT 'regular',
  `invoice_number` varchar(25) default NULL,
  `billing_contact_id` INT(10) UNSIGNED,
  `issue_date` DATETIME(3),
  `due_date` DATETIME(3) default NULL,
  `total_amount` DECIMAL(10,2),
  `payment_status` ENUM('unpaid', 'paid', 'overdue', 'cancelled') DEFAULT 'unpaid',
  `notes` TEXT default NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (billing_contact_id) REFERENCES users_contact(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `service_type` VARCHAR(32) NOT NULL,        -- e.g. 'domain', 'hosting', 'ssl', 'product'
  `service_data` JSON DEFAULT NULL,           -- holds service-specific info like domain name, SKU, etc.
  `status` ENUM('pending', 'active', 'inactive', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `amount_due` DECIMAL(12,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `invoice_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `paid_at` DATETIME(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX (`user_id`, `service_type`, `status`),
  CONSTRAINT `orders_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `related_entity_type` VARCHAR(32) NOT NULL, -- e.g. 'domain', 'hosting', 'subscription', 'order'
  `related_entity_id` INT UNSIGNED NOT NULL,  -- ID of the related entity
  `type` ENUM('debit', 'credit') NOT NULL DEFAULT 'debit',
  `category` VARCHAR(32) NOT NULL,            -- e.g. 'purchase', 'refund', 'top-up', 'charge'
  `description` TEXT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `status` ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'completed',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX (`user_id`, `related_entity_type`, `related_entity_id`),
  CONSTRAINT `transactions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,                          -- e.g. 'Vultr', 'OpenSRS', 'InternalDNS'
  `type` ENUM('domain', 'hosting', 'email', 'api', 'custom') NOT NULL DEFAULT 'custom',
  `api_endpoint` VARCHAR(255) DEFAULT NULL,
  `credentials` JSON DEFAULT NULL,                      -- e.g. API keys, tokens
  `pricing` JSON DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'testing') NOT NULL DEFAULT 'active',
  `tld` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`),
  UNIQUE KEY `unique_tld` (`tld`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED DEFAULT NULL,              -- FK to provider
  `order_id` INT UNSIGNED DEFAULT NULL,                 -- FK to original order
  `type` VARCHAR(32) NOT NULL,                          -- e.g. 'domain', 'vps', 'ssl', 'mail'
  `status` ENUM('active', 'suspended', 'terminated', 'expired', 'pending') NOT NULL DEFAULT 'active',
  `config` JSON DEFAULT NULL,                           -- full config: e.g. domain contacts, NS, VPS specs
  `service_name` VARCHAR(128) DEFAULT NULL,
  `registered_at` DATETIME(3) DEFAULT NULL,             -- when the resource was created
  `expires_at` DATETIME(3) DEFAULT NULL,                -- when it ends
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_service_name` (`service_name`),
  INDEX (`user_id`, `type`, `status`),
  CONSTRAINT `services_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `services_provider_fk` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `services_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(64) NOT NULL,                         -- e.g. 'provisioned', 'suspended', 'dns_updated'
  `actor_type` ENUM('system', 'user', 'admin') NOT NULL DEFAULT 'system',
  `actor_id` INT UNSIGNED DEFAULT NULL,                 -- optional: who triggered the event
  `details` TEXT DEFAULT NULL,                          -- optional JSON string or free text
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX (`service_id`, `event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `voice` varchar(17) default NULL,
  `email` varchar(255) NOT NULL,
  `nin` varchar(255) default NULL,
  `nin_type` enum('personal','business') default NULL,
  `clid` int(10) unsigned NOT NULL,
  `crid` int(10) unsigned NOT NULL,
  `crdate` datetime(3) NOT NULL,
  `upid` int(10) unsigned default NULL,
  `lastupdate` datetime(3) default NULL,
  `status` enum('clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited') NOT NULL default 'ok',
  `validation` enum('0','1','2','3','4'),
  `validation_stamp` datetime(3) default NULL,
  `validation_log` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifier` (`identifier`),
  CONSTRAINT `contact_ibfk_1` FOREIGN KEY (`clid`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contact_ibfk_2` FOREIGN KEY (`crid`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contact_ibfk_3` FOREIGN KEY (`upid`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_postalInfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int(10) unsigned NOT NULL,
  `type` enum('int','loc') NOT NULL default 'int',
  `name` varchar(255) NOT NULL,
  `org` varchar(255) default NULL,
  `street1` varchar(255) default NULL,
  `city` varchar(255) NOT NULL,
  `sp` varchar(255) default NULL,
  `pc` varchar(16) default NULL,
  `cc` char(2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniquekey` (`contact_id`,`type`),
  CONSTRAINT `contact_postalInfo_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domain_contact_map` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned NOT NULL,
  `type` enum('registrant','admin','billing','tech') NOT NULL default 'admin',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniquekey` (`domain_id`,`contact_id`,`type`),
  CONSTRAINT `domain_contact_map_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `domain_contact_map_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;