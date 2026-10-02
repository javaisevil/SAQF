-- SAQF 2.1: course sections, assessment evidence, two-step verification, session control,
-- rate limiting, operational alerts and the interface language.
-- Applied by bin/migrate.php (and by bin/install.php on new installations).

-- Course sections. The offering's instructor_id is the course coordinator (owns the
-- specification and the course report); each section may have its own instructor.
CREATE TABLE offering_sections (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  offering_id   INT          NOT NULL,
  section_code  VARCHAR(10)  NOT NULL,
  instructor_id INT          NULL,
  enrolled      INT          NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_section (offering_id, section_code),
  KEY idx_section_instructor (instructor_id),
  CONSTRAINT fk_sec_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id) ON DELETE CASCADE,
  CONSTRAINT fk_sec_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Results remember the section the student was enrolled in (per-section achievement).
ALTER TABLE assessment_results
  ADD COLUMN section_code VARCHAR(10) NULL AFTER student_ref;

-- Assessment evidence: exam papers, marking rubrics, samples of student work. Files are stored
-- outside the web root under random names; only metadata lives here.
CREATE TABLE evidence_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  offering_id   INT          NOT NULL,
  assessment_id INT          NULL,
  section_code  VARCHAR(10)  NULL,
  kind          VARCHAR(20)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  original_name VARCHAR(200) NOT NULL,
  stored_name   CHAR(40)     NOT NULL,
  mime          VARCHAR(100) NOT NULL,
  size_bytes    INT          NOT NULL,
  sha256        CHAR(64)     NOT NULL,
  scan_status   VARCHAR(12)  NOT NULL DEFAULT 'not_scanned',
  uploaded_by   INT          NULL,
  uploaded_at   DATETIME     NOT NULL,
  deleted_at    DATETIME     NULL,
  deleted_by    INT          NULL,
  delete_reason VARCHAR(300) NULL,
  UNIQUE KEY uq_evidence_stored (stored_name),
  KEY idx_evidence_offering (offering_id, deleted_at),
  CONSTRAINT fk_ev_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_ev_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-step verification (TOTP; the secret is encrypted with the application key) and the
-- person's interface language.
ALTER TABLE users
  ADD COLUMN locale         VARCHAR(5)   NULL AFTER notify_email,
  ADD COLUMN mfa_secret     VARCHAR(255) NULL AFTER must_change_password,
  ADD COLUMN mfa_enabled_at DATETIME     NULL AFTER mfa_secret,
  ADD COLUMN mfa_last_step  BIGINT       NULL AFTER mfa_enabled_at;

-- One-time recovery codes for two-step verification (only SHA-256 hashes are stored).
CREATE TABLE mfa_recovery_codes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT      NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  KEY idx_recovery_user (user_id),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every signed-in session, so people can see where they are signed in and end sessions, and
-- a password change or an administrator can sign a person out everywhere.
CREATE TABLE user_sessions (
  token_hash   CHAR(64)     PRIMARY KEY,
  user_id      INT          NOT NULL,
  method       VARCHAR(12)  NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  user_agent   VARCHAR(250) NOT NULL,
  device_hash  CHAR(64)     NOT NULL,
  created_at   DATETIME     NOT NULL,
  last_seen_at DATETIME     NOT NULL,
  ended_at     DATETIME     NULL,
  end_reason   VARCHAR(60)  NULL,
  KEY idx_sessions_user (user_id, ended_at),
  KEY idx_sessions_device (user_id, device_hash),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limits (API changes, uploads, exports).
CREATE TABLE rate_limits (
  bucket       VARCHAR(120) PRIMARY KEY,
  window_start DATETIME     NOT NULL,
  hits         INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operational alerts for IT (one row per kind; re-opened when the problem returns).
CREATE TABLE alerts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(60)  NOT NULL,
  severity    VARCHAR(10)  NOT NULL,
  title       VARCHAR(200) NOT NULL,
  detail      TEXT         NULL,
  first_at    DATETIME     NOT NULL,
  last_at     DATETIME     NOT NULL,
  occurrences INT          NOT NULL DEFAULT 1,
  notified_at DATETIME     NULL,
  resolved_at DATETIME     NULL,
  UNIQUE KEY uq_alert_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
