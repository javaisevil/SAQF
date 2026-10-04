-- SAQF 2.5: course file closeout. A person's review of a course file item (today: the evidence set),
-- tied to a fingerprint of exactly what was reviewed (evidence ids and checksums), so adding, replacing
-- or removing a file after the review makes it due again. SAQF never accepts evidence by itself.
CREATE TABLE closeout_reviews (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  offering_id INT          NOT NULL,
  item_key    VARCHAR(40)  NOT NULL,
  fingerprint CHAR(64)     NOT NULL,
  decision    VARCHAR(12)  NOT NULL,
  note        TEXT         NULL,
  reviewed_by INT          NOT NULL,
  reviewed_at DATETIME     NOT NULL,
  KEY idx_closeout_offering (offering_id, item_key),
  CONSTRAINT fk_closeout_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_closeout_user FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
