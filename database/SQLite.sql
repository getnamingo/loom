PRAGMA foreign_keys = OFF;

CREATE TABLE "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL CHECK ("id" >= 0),
    "email" VARCHAR(249) NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "username" VARCHAR(100) DEFAULT NULL,
    "status" INTEGER NOT NULL CHECK ("status" >= 0) DEFAULT "0",
    "verified" INTEGER NOT NULL CHECK ("verified" >= 0) DEFAULT "0",
    "resettable" INTEGER NOT NULL CHECK ("resettable" >= 0) DEFAULT "1",
    "roles_mask" INTEGER NOT NULL CHECK ("roles_mask" >= 0) DEFAULT "0",
    "registered" INTEGER NOT NULL CHECK ("registered" >= 0),
    "last_login" INTEGER CHECK ("last_login" >= 0) DEFAULT NULL,
    "force_logout" INTEGER NOT NULL CHECK ("force_logout" >= 0) DEFAULT "0",
    "tfa_secret" VARCHAR(32),
    "tfa_enabled" INTEGER DEFAULT 0,
    "auth_method" VARCHAR(10) NOT NULL DEFAULT 'password' CHECK(auth_method IN ('password','2fa','webauthn')),
    "backup_codes" TEXT,
    "password_last_updated" DATETIME DEFAULT CURRENT_TIMESTAMP,
    "nin" TEXT,
    "vat_number" TEXT,
    "nin_type" TEXT,
    "validation" TEXT,
    "validation_stamp" DATETIME,
    "validation_log" TEXT,
    "currency" TEXT NOT NULL DEFAULT 'EUR',
    "account_balance" REAL NOT NULL DEFAULT 0.0,
    "credit_limit" REAL NOT NULL DEFAULT 0.0,
    CONSTRAINT "email" UNIQUE ("email")
);

CREATE TABLE "users_audit" (
    "user_id" INTEGER NOT NULL,
    "user_event" VARCHAR(255) NOT NULL,
    "user_resource" VARCHAR(255) DEFAULT NULL,
    "user_agent" VARCHAR(255) NOT NULL,
    "user_ip" VARCHAR(45) NOT NULL,
    "user_location" VARCHAR(45) DEFAULT NULL,
    "event_time" DATETIME NOT NULL,
    "user_data" TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_id ON users_audit(user_id);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_event ON users_audit(user_event);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_ip ON users_audit(user_ip);

CREATE TABLE "users_confirmations" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "email" VARCHAR(249) NOT NULL,
    "selector" VARCHAR(16) NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "selector" UNIQUE ("selector")
);
CREATE INDEX "users_confirmations.email_expires" ON "users_confirmations" ("email", "expires");
CREATE INDEX "users_confirmations.user_id" ON "users_confirmations" ("user_id");

CREATE TABLE "users_remembered" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "selector" VARCHAR(24) NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "selector" UNIQUE ("selector")
);
CREATE INDEX "users_remembered.user_id" ON "users_remembered" ("user_id");

CREATE TABLE "users_resets" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "selector" VARCHAR(20) NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0),
    CONSTRAINT "selector" UNIQUE ("selector")
);
CREATE INDEX "users_resets.user_expires" ON "users_resets" ("user_id", "expires");

CREATE TABLE "users_throttling" (
    "bucket" VARCHAR(44) PRIMARY KEY NOT NULL,
    "tokens" REAL NOT NULL CHECK ("tokens" >= 0),
    "replenished_at" INTEGER NOT NULL CHECK ("replenished_at" >= 0),
    "expires_at" INTEGER NOT NULL CHECK ("expires_at" >= 0)
);
CREATE INDEX "users_throttling.expires_at" ON "users_throttling" ("expires_at");

CREATE TABLE "users_webauthn" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "credential_id" BLOB NOT NULL UNIQUE,
    "public_key" TEXT NOT NULL,
    "attestation_object" BLOB,
    "sign_count" INTEGER NOT NULL,
    "user_agent" VARCHAR(512),
    "created_at" DATETIME DEFAULT CURRENT_TIMESTAMP,
    "last_used_at" DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE "users_contact" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "type" VARCHAR(10) NOT NULL DEFAULT 'admin' CHECK(type IN ('owner','admin','billing','tech','abuse')),
    "title" VARCHAR(255) DEFAULT NULL,
    "first_name" VARCHAR(255) NOT NULL,
    "middle_name" VARCHAR(255) DEFAULT NULL,
    "last_name" VARCHAR(255) NOT NULL,
    "org" VARCHAR(255) DEFAULT NULL,
    "street1" VARCHAR(255) DEFAULT NULL,
    "street2" VARCHAR(255) DEFAULT NULL,
    "street3" VARCHAR(255) DEFAULT NULL,
    "city" VARCHAR(255) NOT NULL,
    "sp" VARCHAR(255) DEFAULT NULL,
    "pc" VARCHAR(16) DEFAULT NULL,
    "cc" CHAR(2) NOT NULL,
    "voice" VARCHAR(17) DEFAULT NULL,
    "fax" VARCHAR(17) DEFAULT NULL,
    "email" VARCHAR(255) NOT NULL,
    UNIQUE (user_id, type),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE "ticket_categories" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "name" VARCHAR(255) NOT NULL,
    "description" TEXT
);

CREATE TABLE "support_tickets" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "category_id" INTEGER NOT NULL,
    "subject" VARCHAR(255) NOT NULL,
    "message" TEXT NOT NULL,
    "status" VARCHAR(20) NOT NULL DEFAULT 'Open' CHECK(status IN ('Open','In Progress','Resolved','Closed')),
    "priority" VARCHAR(20) NOT NULL DEFAULT 'Medium' CHECK(priority IN ('Low','Medium','High','Critical')),
    "date_created" DATETIME DEFAULT CURRENT_TIMESTAMP,
    "last_updated" DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES ticket_categories(id)
);

CREATE TABLE "ticket_responses" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "ticket_id" INTEGER NOT NULL,
    "responder_id" INTEGER NOT NULL,
    "response" TEXT NOT NULL,
    "date_created" DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
);

CREATE TABLE "invoices" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER,
    "invoice_type" TEXT NOT NULL DEFAULT 'regular' CHECK (invoice_type IN ('regular', 'deposit', 'credit', 'proforma')),
    "invoice_number" VARCHAR(25) DEFAULT NULL,
    "billing_contact_id" INTEGER,
    "issue_date" DATETIME,
    "due_date" DATETIME DEFAULT NULL,
    "total_amount" DECIMAL(10,2),
    "payment_status" VARCHAR(20) NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid','paid','overdue','cancelled')),
    "notes" TEXT DEFAULT NULL,
    "created_at" DATETIME DEFAULT CURRENT_TIMESTAMP,
    "updated_at" DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (billing_contact_id) REFERENCES users_contact(id)
);

CREATE TABLE "orders" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "service_type" TEXT NOT NULL,
    "service_data" TEXT DEFAULT NULL,
    "status" TEXT NOT NULL DEFAULT 'pending',
    "amount_due" REAL NOT NULL,
    "currency" TEXT NOT NULL DEFAULT 'EUR',
    "invoice_id" INTEGER DEFAULT NULL,
    "created_at" TEXT DEFAULT CURRENT_TIMESTAMP,
    "paid_at" TEXT,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
);

CREATE INDEX idx_orders_user_service_status ON orders(user_id, service_type, status);

CREATE TABLE "transactions" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "related_entity_type" TEXT NOT NULL,
    "related_entity_id" INTEGER NOT NULL,
    "type" TEXT NOT NULL DEFAULT 'debit',
    "category" TEXT NOT NULL,
    "description" TEXT NOT NULL,
    "amount" REAL NOT NULL,
    "currency" TEXT NOT NULL DEFAULT 'EUR',
    "gateway" TEXT DEFAULT NULL,
    "gateway_reference" TEXT DEFAULT NULL,
    "status" TEXT NOT NULL DEFAULT 'completed',
    "created_at" TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_transactions_user_entity ON transactions(user_id, related_entity_type, related_entity_id);
CREATE UNIQUE INDEX transactions_gateway_reference_unique ON transactions(gateway, gateway_reference);

CREATE TABLE "providers" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "name" TEXT NOT NULL UNIQUE,
    "type" TEXT NOT NULL DEFAULT 'custom',
    "api_endpoint" TEXT,
    "credentials" TEXT,
    "pricing" TEXT,
    "status" TEXT NOT NULL DEFAULT 'active',
    "tld" TEXT UNIQUE,
    "created_at" TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE "services" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "provider_id" INTEGER,
    "order_id" INTEGER,
    "type" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'active',
    "config" TEXT DEFAULT NULL,
    "service_name" TEXT UNIQUE,
    "registered_at" TEXT,
    "expires_at" TEXT,
    "updated_at" TEXT DEFAULT CURRENT_TIMESTAMP,
    "created_at" TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX idx_services_user_type_status ON services(user_id, type, status);

CREATE TABLE "service_logs" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "service_id" INTEGER NOT NULL,
    "event" TEXT NOT NULL,
    "actor_type" TEXT NOT NULL DEFAULT 'system',
    "actor_id" INTEGER,
    "details" TEXT DEFAULT NULL,
    "created_at" TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_service_logs_service_event ON service_logs(service_id, event);

CREATE TABLE "contact" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "identifier" TEXT NOT NULL UNIQUE,
    "voice" TEXT,
    "email" TEXT NOT NULL,
    "nin" TEXT,
    "nin_type" TEXT CHECK (nin_type IN ('personal', 'business')),
    "clid" INTEGER NOT NULL,
    "crid" INTEGER NOT NULL,
    "crdate" TEXT NOT NULL,
    "upid" INTEGER,
    "lastupdate" TEXT,
    "status" TEXT NOT NULL CHECK (status IN (
        'clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited',
        'linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate',
        'serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited')),
    "validation" TEXT CHECK (validation IN ('0', '1', '2', '3', '4')),
    "validation_stamp" TEXT,
    "validation_log" TEXT,
    FOREIGN KEY (clid) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (crid) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (upid) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE INDEX idx_contact_identifier ON contact(identifier);
CREATE INDEX idx_contact_email ON contact(email);
CREATE INDEX idx_contact_crid ON contact(crid);
CREATE INDEX idx_contact_clid ON contact(clid);
CREATE INDEX idx_contact_upid ON contact(upid);
CREATE INDEX idx_contact_validation ON contact(validation);

CREATE TABLE "contact_postalInfo" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "contact_id" INTEGER NOT NULL,
    "type" TEXT NOT NULL CHECK (type IN ('int', 'loc')),
    "name" TEXT NOT NULL,
    "org" TEXT,
    "street1" TEXT,
    "city" TEXT NOT NULL,
    "sp" TEXT,
    "pc" TEXT,
    "cc" TEXT NOT NULL,
    UNIQUE (contact_id, type),
    FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT
);

CREATE INDEX idx_contact_postalInfo_contact_id ON contact_postalInfo(contact_id);
CREATE INDEX idx_contact_postalInfo_city ON contact_postalInfo(city);
CREATE INDEX idx_contact_postalInfo_cc ON contact_postalInfo(cc);

CREATE TABLE "domain_contact_map" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "domain_id" INTEGER NOT NULL,
    "contact_id" INTEGER NOT NULL,
    "type" TEXT NOT NULL CHECK (type IN ('registrant','admin','billing','tech')),
    UNIQUE (domain_id, contact_id, type),
    FOREIGN KEY (domain_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT
);

CREATE INDEX idx_domain_contact_map_domain_id ON domain_contact_map(domain_id);
CREATE INDEX idx_domain_contact_map_contact_id ON domain_contact_map(contact_id);
CREATE INDEX idx_domain_contact_map_type ON domain_contact_map(type);