-- SAQF 2.3: periodic access review. Another administrator confirms (or removes) each person's
-- access on a schedule; every decision is kept here and in the tamper-evident audit log.
CREATE TABLE access_reviews (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  reviewer_id  INT          NULL,
  decision     VARCHAR(10)  NOT NULL,            -- confirmed | removed
  role_at_review VARCHAR(20) NOT NULL,
  note         VARCHAR(255) NULL,
  reviewed_at  DATETIME     NOT NULL,
  KEY idx_review_user (user_id, reviewed_at),
  CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
