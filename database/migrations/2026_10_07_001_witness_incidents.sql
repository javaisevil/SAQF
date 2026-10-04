-- SAQF 2.6: audit-chain witnesses and the security incident register.
-- A witness is a checkpoint of the audit chain (last entry id, number of entries, its hash). It is sent
-- outside the server (e-mail / webhook) so a rewritten database cannot also rewrite what IT already holds.
CREATE TABLE audit_witnesses (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  taken_at    DATETIME     NOT NULL,
  last_id     BIGINT       NOT NULL,
  entries     BIGINT       NOT NULL,
  head_hash   CHAR(64)     NOT NULL,
  line        VARCHAR(300) NOT NULL,
  sent_to     VARCHAR(200) NOT NULL DEFAULT '',
  taken_by    VARCHAR(80)  NOT NULL DEFAULT 'scheduler',
  KEY idx_witness_taken (taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security incident register. The notification clock only counts for incidents involving personal data;
-- whether a notification duty applies is for the university's data protection officer / legal counsel.
CREATE TABLE security_incidents (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  title                VARCHAR(200) NOT NULL,
  category             VARCHAR(24)  NOT NULL,
  severity             VARCHAR(10)  NOT NULL,
  personal_data        TINYINT(1)   NOT NULL DEFAULT 0,
  detected_at          DATETIME     NOT NULL,
  description          TEXT         NOT NULL,
  subjects_estimate    INT          NULL,
  status               VARCHAR(12)  NOT NULL DEFAULT 'open',
  authority_notified_at DATETIME    NULL,
  subjects_notified_at DATETIME     NULL,
  closed_at            DATETIME     NULL,
  closure_note         TEXT         NULL,
  created_by           INT          NOT NULL,
  created_at           DATETIME     NOT NULL,
  KEY idx_incident_status (status, detected_at),
  CONSTRAINT fk_incident_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE incident_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  incident_id INT          NOT NULL,
  kind        VARCHAR(24)  NOT NULL,
  note        TEXT         NULL,
  user_id     INT          NOT NULL,
  occurred_at DATETIME     NOT NULL,
  KEY idx_incident_events (incident_id, occurred_at),
  CONSTRAINT fk_ie_incident FOREIGN KEY (incident_id) REFERENCES security_incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_ie_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
