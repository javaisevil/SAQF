-- SAQF 2.4: sign-in protection. Browsers a person chose to trust after two-step verification (only a
-- hash of the browser's token is kept, so a stolen database cannot be replayed as a cookie), and the
-- robot-check puzzles already used (each puzzle works once).
CREATE TABLE trusted_devices (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,
  token_hash    CHAR(64)     NOT NULL UNIQUE,
  description   VARCHAR(80)  NOT NULL,
  ip            VARCHAR(45)  NOT NULL,
  created_at    DATETIME     NOT NULL,
  last_used_at  DATETIME     NULL,
  expires_at    DATETIME     NOT NULL,
  revoked_at    DATETIME     NULL,
  KEY idx_trusted_user (user_id),
  CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bot_challenges_used (
  challenge_hash CHAR(64)  NOT NULL PRIMARY KEY,
  expires_at     DATETIME  NOT NULL,
  KEY idx_bot_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in methods now name the second step: password+email, password+trusted (browser).
ALTER TABLE user_sessions MODIFY COLUMN method VARCHAR(20) NOT NULL;
