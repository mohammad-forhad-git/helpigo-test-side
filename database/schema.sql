-- Helpigo database schema (MySQL 5.7+/8 and MariaDB 10.4+).
-- Money columns are DECIMAL(12,2) Taka; PHP converts them to integer paisa (App\Services\Money).
-- Times are Asia/Dhaka (the connection sets time_zone = '+06:00').

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role                ENUM('customer','worker','admin') NOT NULL DEFAULT 'customer',
    name                VARCHAR(100) NOT NULL,
    phone               VARCHAR(11)  NOT NULL,
    email               VARCHAR(190) NULL,
    password_hash       VARCHAR(255) NOT NULL,
    phone_verified_at   DATETIME     NULL,
    status              ENUM('active','suspended') NOT NULL DEFAULT 'active',
    locale              ENUM('en','bn') NOT NULL DEFAULT 'en',
    last_login_at       DATETIME     NULL,
    password_changed_at DATETIME     NULL,
    referral_code       VARCHAR(10)  NULL,         -- customers: shared with friends (business-rules §9)
    referred_by         INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_referral (referral_code),
    KEY idx_users_role (role, status),
    CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone        VARCHAR(20)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_phone_time (phone, attempted_at),
    KEY idx_login_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes sent by SMS (phone verification, password reset). Only the hash is stored.
CREATE TABLE otp_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone      VARCHAR(11)  NOT NULL,
    purpose    ENUM('verify_phone','reset_password') NOT NULL,
    code_hash  CHAR(64)     NOT NULL,
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    ip_address VARCHAR(45)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_otp_phone (phone, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(40)  NOT NULL,
    entity_id   BIGINT UNSIGNED NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_entity (entity_type, entity_id),
    KEY idx_activity_time (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_settings (
    setting_key   VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Service setup (admin-managed)
-- ---------------------------------------------------------------------------
CREATE TABLE zones (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80)  NOT NULL,
    name_bn    VARCHAR(80)  NULL,
    slug       VARCHAR(90)  NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zones_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(30)  NOT NULL,
    name             VARCHAR(80)  NOT NULL,
    name_bn          VARCHAR(80)  NULL,
    description      VARCHAR(255) NULL,
    icon             VARCHAR(30)  NOT NULL DEFAULT 'task',
    pricing_mode     ENUM('fixed','offer') NOT NULL DEFAULT 'fixed',
    base_fee         DECIMAL(12,2) NOT NULL,           -- worker fee (fixed) or minimum offer (offer)
    included_items   SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = item count not charged
    per_item_fee     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    involves_purchase TINYINT(1)  NOT NULL DEFAULT 1,  -- worker buys something (budget + receipt)
    needs_dropoff    TINYINT(1)   NOT NULL DEFAULT 1,
    allows_prescription TINYINT(1) NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    label        VARCHAR(40)  NOT NULL,
    zone_id      INT UNSIGNED NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    landmark     VARCHAR(120) NULL,
    contact_name VARCHAR(100) NULL,     -- booking for someone else (e.g. parents)
    contact_phone VARCHAR(11) NULL,
    lat          DECIMAL(9,6) NULL,
    lng          DECIMAL(9,6) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addresses_user (user_id),
    CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_addresses_zone FOREIGN KEY (zone_id) REFERENCES zones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Workers
-- ---------------------------------------------------------------------------
CREATE TABLE worker_profiles (
    user_id          INT UNSIGNED NOT NULL,
    status           ENUM('incomplete','pending','approved','rejected','suspended') NOT NULL DEFAULT 'incomplete',
    gender           ENUM('female','male','other') NULL,
    nid_number       VARCHAR(20)  NULL,
    nid_image        VARCHAR(80)  NULL,   -- file name in storage/private/worker-docs
    selfie_image     VARCHAR(80)  NULL,
    reference_name   VARCHAR(100) NULL,
    reference_phone  VARCHAR(11)  NULL,
    payout_method    ENUM('bkash','nagad','rocket') NULL,
    payout_number    VARCHAR(12)  NULL,
    float_limit      DECIMAL(12,2) NOT NULL DEFAULT 1000.00,  -- max cash the worker may front for purchases
    is_online        TINYINT(1)   NOT NULL DEFAULT 0,
    last_seen_at     DATETIME     NULL,
    rating_avg       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count     INT UNSIGNED NOT NULL DEFAULT 0,
    completed_count  INT UNSIGNED NOT NULL DEFAULT 0,
    reviewed_by      INT UNSIGNED NULL,
    reviewed_at      DATETIME     NULL,
    review_note      VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_worker_status (status, is_online),
    CONSTRAINT fk_worker_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_zones (
    worker_id INT UNSIGNED NOT NULL,
    zone_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (worker_id, zone_id),
    KEY idx_wz_zone (zone_id),
    CONSTRAINT fk_wz_worker FOREIGN KEY (worker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wz_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_categories (
    worker_id   INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (worker_id, category_id),
    KEY idx_wc_category (category_id),
    CONSTRAINT fk_wc_worker FOREIGN KEY (worker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wc_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tasks
-- ---------------------------------------------------------------------------
-- Status flow (App\Services\TaskStatus):
--   open -> assigned -> in_progress -> delivering -> completed
--                                   \-> awaiting_confirmation -> completed   (finished without the delivery code)
--   open -> expired | cancelled;   assigned/in_progress -> cancelled;   any active -> disputed
CREATE TABLE tasks (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_number      VARCHAR(20)  NOT NULL,
    customer_id      INT UNSIGNED NOT NULL,
    worker_id        INT UNSIGNED NULL,
    category_id      INT UNSIGNED NOT NULL,
    zone_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(120) NOT NULL,
    description      TEXT NULL,
    pickup_location  VARCHAR(255) NULL,    -- shop / pharmacy / market / pickup point
    dropoff_address  VARCHAR(255) NULL,
    dropoff_landmark VARCHAR(120) NULL,
    recipient_name   VARCHAR(100) NULL,
    recipient_phone  VARCHAR(11)  NULL,
    preferred_at     DATETIME     NULL,    -- NULL = as soon as possible
    item_budget      DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- max the worker may spend on items
    item_cost        DECIMAL(12,2) NULL,                   -- actual, from the receipt
    item_prepaid     TINYINT(1)   NOT NULL DEFAULT 0,      -- customer paid the item budget to Helpigo in advance
    worker_fee       DECIMAL(12,2) NOT NULL,
    platform_fee     DECIMAL(12,2) NOT NULL,               -- charged to the customer
    discount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- first-task / referral credit, paid by Helpigo
    cancellation_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    commission       DECIMAL(12,2) NOT NULL,               -- taken from the worker fee
    payment_method   ENUM('cash','bkash','nagad') NOT NULL DEFAULT 'cash',
    prefer_female_worker TINYINT(1) NOT NULL DEFAULT 0,
    status           ENUM('open','assigned','in_progress','delivering','awaiting_confirmation','completed','cancelled','expired','disputed') NOT NULL DEFAULT 'open',
    delivery_code    CHAR(4)      NOT NULL,              -- shown to the customer only; never on helper pages
    code_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- wrong codes entered; 5 = locked
    needs_review     TINYINT(1)   NOT NULL DEFAULT 0,      -- custom task with flagged words: hidden until admin clears it
    cancelled_by     INT UNSIGNED NULL,
    cancel_reason    VARCHAR(255) NULL,
    expires_at       DATETIME     NOT NULL,
    assigned_at      DATETIME     NULL,
    started_at       DATETIME     NULL,
    delivered_at     DATETIME     NULL,
    completed_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_number (task_number),
    KEY idx_tasks_feed (status, zone_id, category_id, created_at),
    KEY idx_tasks_customer (customer_id, created_at),
    KEY idx_tasks_worker (worker_id, status),
    CONSTRAINT fk_tasks_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT fk_tasks_worker FOREIGN KEY (worker_id) REFERENCES users (id),
    CONSTRAINT fk_tasks_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_tasks_zone FOREIGN KEY (zone_id) REFERENCES zones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_items (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id    BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(120) NOT NULL,
    quantity   VARCHAR(40)  NULL,          -- free text: "2 kg", "1 strip", "3 pcs"
    note       VARCHAR(255) NULL,
    status     ENUM('pending','bought','substituted','unavailable') NOT NULL DEFAULT 'pending',
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_items_task (task_id),
    CONSTRAINT fk_items_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images: shopping-list photo, prescription, receipt, pickup / delivery proof. Stored in storage/private/task-files.
CREATE TABLE task_attachments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id     BIGINT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    kind        ENUM('list','prescription','receipt','pickup','delivery','other') NOT NULL,
    file_name   VARCHAR(80)  NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attach_task (task_id),
    CONSTRAINT fk_attach_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_attach_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offers on custom (pricing_mode = offer) tasks.
CREATE TABLE task_offers (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id    BIGINT UNSIGNED NOT NULL,
    worker_id  INT UNSIGNED NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    note       VARCHAR(255) NULL,
    status     ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_offer_task_worker (task_id, worker_id),
    CONSTRAINT fk_offer_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_worker FOREIGN KEY (worker_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Worker asks the customer before spending over budget or substituting an item.
CREATE TABLE task_approvals (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id      BIGINT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    type         ENUM('over_budget','substitution','other') NOT NULL,
    description  VARCHAR(500) NOT NULL,
    new_budget   DECIMAL(12,2) NULL,
    status       ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    decided_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_task (task_id, status),
    CONSTRAINT fk_approval_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_approval_user FOREIGN KEY (requested_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every status change, for disputes and audits.
CREATE TABLE task_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id     BIGINT UNSIGNED NOT NULL,
    actor_id    INT UNSIGNED NULL,
    from_status VARCHAR(30)  NULL,
    to_status   VARCHAR(30)  NOT NULL,
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_task (task_id, created_at),
    CONSTRAINT fk_events_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id    BIGINT UNSIGNED NOT NULL,
    sender_id  INT UNSIGNED NOT NULL,
    body       VARCHAR(1000) NOT NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_task (task_id, id),
    CONSTRAINT fk_messages_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(40)  NOT NULL,
    title      VARCHAR(150) NOT NULL,
    body       VARCHAR(500) NULL,
    link       VARCHAR(255) NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, read_at, id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Money
-- ---------------------------------------------------------------------------
-- Payments a customer sends to Helpigo (item advance above the worker's float, or fees paid by wallet).
CREATE TABLE payments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id       BIGINT UNSIGNED NULL,          -- NULL for paying a due
    payer_id      INT UNSIGNED NOT NULL,
    purpose       ENUM('item_advance','fees','due') NOT NULL,
    method        ENUM('bkash','nagad','rocket') NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    sender_number VARCHAR(12)  NOT NULL,
    trx_id        VARCHAR(40)  NOT NULL,
    status        ENUM('pending','verified','rejected','refunded') NOT NULL DEFAULT 'pending',
    verified_by   INT UNSIGNED NULL,
    verified_at   DATETIME NULL,
    note          VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_trx (method, trx_id),
    KEY idx_payments_status (status, created_at),
    CONSTRAINT fk_payments_task FOREIGN KEY (task_id) REFERENCES tasks (id),
    CONSTRAINT fk_payments_payer FOREIGN KEY (payer_id) REFERENCES users (id),
    CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Worker balance ledger. Positive = Helpigo owes the worker; negative = worker owes Helpigo.
-- Balance = SUM(amount). Rows are never edited; corrections are new 'adjustment' rows.
CREATE TABLE worker_ledger (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id  INT UNSIGNED NOT NULL,
    task_id    BIGINT UNSIGNED NULL,
    type       ENUM('earning','commission','fees_collected_cash','advance_paid','item_reimbursement','cancellation_fee','payout','settlement','adjustment') NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    note       VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ledger_worker (worker_id, created_at),
    CONSTRAINT fk_ledger_worker FOREIGN KEY (worker_id) REFERENCES users (id),
    CONSTRAINT fk_ledger_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_requests (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id      INT UNSIGNED NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    method         ENUM('bkash','nagad','rocket') NOT NULL,
    account_number VARCHAR(12)  NOT NULL,
    status         ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
    trx_id         VARCHAR(40)  NULL,
    processed_by   INT UNSIGNED NULL,
    processed_at   DATETIME NULL,
    note           VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payout_status (status, created_at),
    CONSTRAINT fk_payout_worker FOREIGN KEY (worker_id) REFERENCES users (id),
    CONSTRAINT fk_payout_admin FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer credits and dues. Positive = credit to use; negative = due owed. Balance = SUM(amount). Append-only.
CREATE TABLE customer_ledger (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    task_id     BIGINT UNSIGNED NULL,
    type        ENUM('promo_credit','referral_credit','credit_used','fees_due','cancellation_due','payment','refund','refund_paid','adjustment') NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    note        VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cledger_customer (customer_id, created_at),
    CONSTRAINT fk_cledger_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT fk_cledger_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_cledger_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Trust
-- ---------------------------------------------------------------------------
CREATE TABLE reviews (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id     BIGINT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    reviewee_id INT UNSIGNED NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_review_task_reviewer (task_id, reviewer_id),
    KEY idx_review_reviewee (reviewee_id),
    CONSTRAINT fk_review_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id),
    CONSTRAINT fk_review_reviewee FOREIGN KEY (reviewee_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE complaints (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id     BIGINT UNSIGNED NULL,
    reporter_id INT UNSIGNED NOT NULL,
    against_id  INT UNSIGNED NULL,
    type        ENUM('not_delivered','wrong_items','overcharge','behaviour','safety','payment','other') NOT NULL,
    priority    TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 1 = SOS / safety, shown first
    description VARCHAR(1000) NOT NULL,
    status      ENUM('open','in_review','resolved','rejected') NOT NULL DEFAULT 'open',
    resolution  VARCHAR(1000) NULL,
    handled_by  INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_complaints_status (status, created_at),
    CONSTRAINT fk_complaint_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_complaint_reporter FOREIGN KEY (reporter_id) REFERENCES users (id),
    CONSTRAINT fk_complaint_against FOREIGN KEY (against_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_complaint_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
