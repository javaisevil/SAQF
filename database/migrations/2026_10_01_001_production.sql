-- Production capabilities: SSO identities, e-mail delivery, password reset,
-- user provisioning and per-user notification preferences.
-- Applied by bin/migrate.php (and by bin/install.php on new installations).

ALTER TABLE users
  ADD COLUMN sso_subject  VARCHAR(255) NULL AFTER external_id,
  ADD COLUMN auth_source  VARCHAR(12)  NOT NULL DEFAULT 'local' AFTER sso_subject,
  ADD COLUMN notify_email TINYINT(1)   NOT NULL DEFAULT 1 AFTER email,
  ADD COLUMN provisioned_by VARCHAR(12) NOT NULL DEFAULT 'admin' AFTER notify_email,
  ADD UNIQUE KEY uq_user_sso (sso_subject),
  ADD KEY idx_user_external (external_id),
  ADD KEY idx_user_email (email);

ALTER TABLE notifications
  ADD COLUMN emailed_at DATETIME NULL AFTER read_at,
  ADD KEY idx_notif_email (emailed_at, created_at);

-- One row per SSO sign-in attempt (state/nonce/PKCE), bound to the browser that started it.
CREATE TABLE sso_transactions (
  state_hash   CHAR(64)     PRIMARY KEY,
  nonce        VARCHAR(64)  NOT NULL,
  verifier     VARCHAR(128) NOT NULL,
  browser_hash CHAR(64)     NOT NULL,
  created_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  KEY idx_sso_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-service password reset tokens (only the SHA-256 of the token is stored).
CREATE TABLE password_resets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NOT NULL,
  token_hash  CHAR(64)     NOT NULL UNIQUE,
  ip          VARCHAR(45)  NOT NULL,
  created_at  DATETIME     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  KEY idx_reset_ip (ip, created_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing e-mail queue: requests never wait for the mail server; the scheduler retries failures.
CREATE TABLE mail_outbox (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  to_email        VARCHAR(160) NOT NULL,
  to_name         VARCHAR(160) NULL,
  subject         VARCHAR(200) NOT NULL,
  body            MEDIUMTEXT   NOT NULL,
  purpose         VARCHAR(30)  NOT NULL,
  created_at      DATETIME     NOT NULL,
  next_attempt_at DATETIME     NOT NULL,
  attempts        INT          NOT NULL DEFAULT 0,
  sent_at         DATETIME     NULL,
  last_error      VARCHAR(400) NULL,
  KEY idx_outbox_due (sent_at, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
