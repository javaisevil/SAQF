-- SAQF 2.6: passkeys (WebAuthn) as a phishing-resistant way to pass the second step. Only the public key is
-- stored: a stolen database cannot sign in as anyone.
CREATE TABLE passkeys (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT           NOT NULL,
  credential_id VARCHAR(1400) NOT NULL,
  public_key    TEXT          NOT NULL,
  sign_count    BIGINT        NOT NULL DEFAULT 0,
  label         VARCHAR(80)   NOT NULL,
  aaguid        CHAR(32)      NOT NULL DEFAULT '',
  created_at    DATETIME      NOT NULL,
  last_used_at  DATETIME      NULL,
  revoked_at    DATETIME      NULL,
  UNIQUE KEY uq_passkey_credential (credential_id(255)),
  KEY idx_passkey_user (user_id, revoked_at),
  CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_sessions MODIFY COLUMN method VARCHAR(24) NOT NULL;
