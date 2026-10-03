-- SAQF 2.2: Arabic wording for university data and course content.
-- The interface itself is translated in code (src/Web/lang); this table holds the Arabic version of
-- data: course and program names, program outcomes, department names, people's names and course
-- content (learning outcomes, assessments, topics). It is filled from the Registrar catalogue, by
-- faculty when they write an outcome, and by Quality or IT on the "Arabic wording" page (form or CSV).
-- Applied by bin/migrate.php (and by bin/install.php on new installations).

CREATE TABLE translations (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  lang         CHAR(2)      NOT NULL DEFAULT 'ar',
  source_hash  CHAR(40)     NOT NULL,
  source_text  TEXT         NOT NULL,
  text         TEXT         NOT NULL,
  kind         VARCHAR(20)  NOT NULL DEFAULT 'content',
  updated_by   INT          NULL,
  updated_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_translation (lang, source_hash),
  KEY idx_translation_kind (kind),
  CONSTRAINT fk_translation_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
