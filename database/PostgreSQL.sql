BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY CHECK (id >= 0),
    email VARCHAR(249) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT '0' CHECK (status >= 0),
    verified SMALLINT NOT NULL DEFAULT '0' CHECK (verified >= 0),
    resettable SMALLINT NOT NULL DEFAULT '1' CHECK (resettable >= 0),
    roles_mask INTEGER NOT NULL DEFAULT '0' CHECK (roles_mask >= 0),
    registered INTEGER NOT NULL CHECK (registered >= 0),
    last_login INTEGER DEFAULT NULL CHECK (last_login >= 0),
    force_logout INTEGER NOT NULL DEFAULT '0' CHECK (force_logout >= 0),
    tfa_secret VARCHAR(32),
    tfa_enabled BOOLEAN DEFAULT false,
    auth_method VARCHAR(255) DEFAULT 'password',
    backup_codes TEXT,
    password_last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    nin VARCHAR(255),
    vat_number VARCHAR(64),
    nin_type VARCHAR(20) CHECK (nin_type IN ('personal', 'business')),
    validation VARCHAR(1) CHECK (validation IN ('0', '1', '2', '3', '4')),
    validation_stamp TIMESTAMP(3),
    validation_log VARCHAR(255),
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    account_balance NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    credit_limit NUMERIC(12,2) NOT NULL DEFAULT 0.00
);

CREATE TABLE IF NOT EXISTS users_audit (
    user_id INT NOT NULL,
    user_event VARCHAR(255) NOT NULL,
    user_resource VARCHAR(255) DEFAULT NULL,
    user_agent VARCHAR(255) NOT NULL,
    user_ip VARCHAR(45) NOT NULL,
    user_location VARCHAR(45) DEFAULT NULL,
    event_time TIMESTAMP(3) NOT NULL,
    user_data JSONB DEFAULT NULL
);
CREATE INDEX idx_user_event ON users_audit (user_event);
CREATE INDEX idx_user_ip ON users_audit (user_ip);

CREATE TABLE IF NOT EXISTS users_confirmations (
    id SERIAL PRIMARY KEY CHECK (id >= 0),
    user_id INTEGER NOT NULL CHECK (user_id >= 0),
    email VARCHAR(249) NOT NULL,
    selector VARCHAR(16) UNIQUE NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL CHECK (expires >= 0)
);
CREATE INDEX IF NOT EXISTS email_expires ON users_confirmations (email, expires);
CREATE INDEX IF NOT EXISTS user_id ON users_confirmations (user_id);

CREATE TABLE IF NOT EXISTS users_remembered (
    id BIGSERIAL PRIMARY KEY CHECK (id >= 0),
    user_id INTEGER NOT NULL CHECK (user_id >= 0),
    selector VARCHAR(24) UNIQUE NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL CHECK (expires >= 0)
);
CREATE INDEX IF NOT EXISTS user_id ON users_remembered (user_id);

CREATE TABLE IF NOT EXISTS users_resets (
    id BIGSERIAL PRIMARY KEY CHECK (id >= 0),
    user_id INTEGER NOT NULL CHECK (user_id >= 0),
    selector VARCHAR(20) UNIQUE NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL CHECK (expires >= 0)
);
CREATE INDEX IF NOT EXISTS user_expires ON users_resets (user_id, expires);

CREATE TABLE IF NOT EXISTS users_throttling (
    bucket VARCHAR(44) PRIMARY KEY,
    tokens REAL NOT NULL CHECK (tokens >= 0),
    replenished_at INTEGER NOT NULL CHECK (replenished_at >= 0),
    expires_at INTEGER NOT NULL CHECK (expires_at >= 0)
);
CREATE INDEX IF NOT EXISTS expires_at ON users_throttling (expires_at);

CREATE TABLE IF NOT EXISTS users_webauthn (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    credential_id BYTEA NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    attestation_object BYTEA,
    sign_count BIGINT NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE users_webauthn ADD FOREIGN KEY (user_id) REFERENCES users(id);

CREATE TABLE IF NOT EXISTS users_contact (
     id SERIAL PRIMARY KEY,
     user_id int CHECK (user_id >= 0) NOT NULL,
     type varchar CHECK (type IN ('owner','admin','billing','tech','abuse')) NOT NULL DEFAULT 'admin',
     title varchar(255) DEFAULT NULL,
     first_name varchar(255) NOT NULL,
     middle_name varchar(255) DEFAULT NULL,
     last_name varchar(255) NOT NULL,
     org varchar(255) DEFAULT NULL,
     street1 varchar(255) DEFAULT NULL,
     street2 varchar(255) DEFAULT NULL,
     street3 varchar(255) DEFAULT NULL,
     city varchar(255) NOT NULL,
     sp varchar(255) DEFAULT NULL,
     pc varchar(16) DEFAULT NULL,
     cc char(2) NOT NULL,
     voice varchar(17) DEFAULT NULL,
     fax varchar(17) DEFAULT NULL,
     email varchar(255) NOT NULL,
     UNIQUE (user_id, type) 
);
ALTER TABLE users_contact ADD FOREIGN KEY (user_id) REFERENCES users(id);

CREATE TYPE ticket_status AS ENUM ('Open', 'In Progress', 'Resolved', 'Closed');
CREATE TYPE ticket_priority AS ENUM ('Low', 'Medium', 'High', 'Critical');

CREATE TABLE IF NOT EXISTS ticket_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT
);

CREATE TABLE IF NOT EXISTS support_tickets (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL, 
    category_id INTEGER NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ticket_status DEFAULT 'Open',
    priority ticket_priority DEFAULT 'Medium',
    date_created TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE support_tickets ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE support_tickets ADD FOREIGN KEY (category_id) REFERENCES ticket_categories(id);
CREATE INDEX idx_support_tickets_date_created ON support_tickets (date_created);

CREATE TABLE IF NOT EXISTS ticket_responses (
    id SERIAL PRIMARY KEY,
    ticket_id INTEGER NOT NULL,
    responder_id INTEGER NOT NULL,
    response TEXT NOT NULL,
    date_created TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE ticket_responses ADD FOREIGN KEY (ticket_id) REFERENCES support_tickets(id);

CREATE TYPE invoice_type_enum AS ENUM ('regular', 'deposit', 'credit', 'proforma');

CREATE TABLE IF NOT EXISTS invoices (
     id SERIAL PRIMARY KEY,
     user_id INT,
     invoice_type invoice_type_enum NOT NULL DEFAULT 'regular',
     invoice_number varchar(25) DEFAULT NULL,
     billing_contact_id INT,
     issue_date TIMESTAMP(3),
     due_date TIMESTAMP(3) DEFAULT NULL,
     total_amount NUMERIC(10,2),
     payment_status VARCHAR(10) DEFAULT 'unpaid' CHECK (payment_status IN ('unpaid', 'paid', 'overdue', 'cancelled')),
     notes TEXT DEFAULT NULL,
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE invoices ADD FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE invoices ADD FOREIGN KEY (billing_contact_id) REFERENCES users_contact(id);

CREATE TABLE IF NOT EXISTS orders (
     id SERIAL PRIMARY KEY,
     user_id INTEGER NOT NULL,
     service_type VARCHAR(32) NOT NULL,
     service_data JSON DEFAULT NULL,
     status VARCHAR(16) NOT NULL DEFAULT 'pending',
     amount_due NUMERIC(12,2) NOT NULL,
     currency CHAR(3) NOT NULL DEFAULT 'EUR',
     invoice_id INTEGER DEFAULT NULL,
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,
     paid_at TIMESTAMP(3),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
);

CREATE INDEX idx_orders_user_service_status ON orders(user_id, service_type, status);

CREATE TABLE IF NOT EXISTS transactions (
     id SERIAL PRIMARY KEY,
     user_id INTEGER NOT NULL,
     related_entity_type VARCHAR(32) NOT NULL,
     related_entity_id INTEGER NOT NULL,
     type VARCHAR(8) NOT NULL DEFAULT 'debit',
     category VARCHAR(32) NOT NULL,
     description TEXT NOT NULL,
     amount NUMERIC(12,2) NOT NULL,
     currency CHAR(3) NOT NULL DEFAULT 'EUR',
     status VARCHAR(16) NOT NULL DEFAULT 'completed',
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_transactions_user_entity ON transactions(user_id, related_entity_type, related_entity_id);

CREATE TABLE IF NOT EXISTS providers (
     id SERIAL PRIMARY KEY,
     name VARCHAR(64) NOT NULL UNIQUE,
     type VARCHAR(16) NOT NULL DEFAULT 'custom',
     api_endpoint VARCHAR(255),
     credentials JSONB,
     pricing JSONB,
     status VARCHAR(16) NOT NULL DEFAULT 'active',
     tld VARCHAR(64) UNIQUE,
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS services (
     id SERIAL PRIMARY KEY,
     user_id INTEGER NOT NULL,
     provider_id INTEGER,
     order_id INTEGER,
     type VARCHAR(32) NOT NULL,
     status VARCHAR(16) NOT NULL DEFAULT 'active',
     config JSONB DEFAULT NULL,
     service_name VARCHAR(128) UNIQUE,
     registered_at TIMESTAMP(3),
     expires_at TIMESTAMP(3),
     updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE INDEX idx_services_user_type_status ON services(user_id, type, status);

CREATE TABLE IF NOT EXISTS service_logs (
     id SERIAL PRIMARY KEY,
     service_id INTEGER NOT NULL,
     event VARCHAR(64) NOT NULL,
     actor_type VARCHAR(16) NOT NULL DEFAULT 'system',
     actor_id INTEGER,
     details TEXT,
     created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_service_logs_service_event ON service_logs(service_id, event);

CREATE TABLE IF NOT EXISTS contact (
     id SERIAL PRIMARY KEY,
     identifier VARCHAR(255) NOT NULL UNIQUE,
     voice VARCHAR(17),
     email VARCHAR(255) NOT NULL,
     nin VARCHAR(255),
     nin_type VARCHAR(8) CHECK (nin_type IN ('personal', 'business')),
     clid INTEGER NOT NULL,
     crid INTEGER NOT NULL,
     crdate TIMESTAMP(3) NOT NULL,
     upid INTEGER,
     lastupdate TIMESTAMP(3),
     status VARCHAR(32) NOT NULL CHECK (status IN (
        'clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited',
        'linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate',
        'serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited')),
     validation VARCHAR(1) CHECK (validation IN ('0', '1', '2', '3', '4')),
     validation_stamp TIMESTAMP(3),
     validation_log VARCHAR(255),
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

CREATE TABLE IF NOT EXISTS contact_postalInfo (
    id SERIAL PRIMARY KEY,
    contact_id INTEGER NOT NULL,
    type VARCHAR(3) NOT NULL CHECK (type IN ('int', 'loc')),
    name VARCHAR(255) NOT NULL,
    org VARCHAR(255),
    street1 VARCHAR(255),
    city VARCHAR(255) NOT NULL,
    sp VARCHAR(255),
    pc VARCHAR(16),
    cc CHAR(2) NOT NULL,
    UNIQUE (contact_id, type),
    FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT
);

CREATE INDEX idx_contact_postalInfo_contact_id ON contact_postalInfo(contact_id);
CREATE INDEX idx_contact_postalInfo_city ON contact_postalInfo(city);
CREATE INDEX idx_contact_postalInfo_cc ON contact_postalInfo(cc);

CREATE TABLE IF NOT EXISTS domain_contact_map (
     id SERIAL PRIMARY KEY,
     domain_id INTEGER NOT NULL,
     contact_id INTEGER NOT NULL,
     type VARCHAR(10) NOT NULL CHECK (type IN ('registrant','admin','billing','tech')),
    UNIQUE (domain_id, contact_id, type),
    FOREIGN KEY (domain_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT
);

CREATE INDEX idx_domain_contact_map_domain_id ON domain_contact_map(domain_id);
CREATE INDEX idx_domain_contact_map_contact_id ON domain_contact_map(contact_id);
CREATE INDEX idx_domain_contact_map_type ON domain_contact_map(type);

COMMIT;