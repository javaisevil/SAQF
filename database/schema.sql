-- =====================================================================
-- SAQF — Academic Quality Automation Platform
-- MySQL 8.0 schema (also runs on MariaDB 10.6+ and Dolt)
--
-- Layers:
--   1. Institutional master data (synced from university systems; read-only in UI)
--   2. Academic-quality data model (specification versions, CLOs, mappings, assessments)
--   3. Semester evidence (offerings, results, achievement)
--   4. Automation & rules engine state (findings, overrides, recommendations, events)
--   5. Governance (improvement actions, snapshots, policies, audit log)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. INSTITUTIONAL MASTER DATA  (source of truth = SIS / Registrar feed)
-- ---------------------------------------------------------------------

CREATE TABLE colleges (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL UNIQUE,
  name          VARCHAR(160) NOT NULL,
  name_ar       VARCHAR(160) NULL,
  synced_at     DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL UNIQUE,
  college_id    INT          NOT NULL,
  name          VARCHAR(160) NOT NULL,
  synced_at     DATETIME     NULL,
  CONSTRAINT fk_dept_college FOREIGN KEY (college_id) REFERENCES colleges(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20)  NOT NULL UNIQUE,
  name               VARCHAR(200) NOT NULL,
  short_name         VARCHAR(120) NOT NULL,
  degree             VARCHAR(20)  NOT NULL,
  level              VARCHAR(20)  NOT NULL,            -- Undergraduate | Postgraduate
  department_id      INT          NOT NULL,
  total_credits      INT          NULL,
  plan_version       VARCHAR(40)  NULL,
  plan_date          DATE         NULL,
  source_url         VARCHAR(400) NULL,
  plo_source         VARCHAR(400) NULL,
  accreditation_note VARCHAR(300) NULL,
  status             VARCHAR(20)  NOT NULL DEFAULT 'active',
  synced_at          DATETIME     NULL,
  CONSTRAINT fk_program_dept FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE courses (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL UNIQUE,
  title               VARCHAR(200) NOT NULL,
  credits             DECIMAL(4,1) NOT NULL,
  owner_department_id INT          NOT NULL,
  is_graduate         TINYINT(1)   NOT NULL DEFAULT 0,
  description         TEXT         NULL,
  description_source  VARCHAR(200) NULL,
  status              VARCHAR(20)  NOT NULL DEFAULT 'active',
  synced_at           DATETIME     NULL,
  CONSTRAINT fk_course_owner FOREIGN KEY (owner_department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per course (or elective slot) in a program's study plan.
CREATE TABLE study_plan_entries (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  program_id        INT          NOT NULL,
  course_id         INT          NULL,              -- NULL = elective slot placeholder
  slot_title        VARCHAR(160) NULL,
  plan_year         TINYINT      NULL,              -- NULL = elective pool (any slot)
  plan_semester     TINYINT      NULL,              -- 1, 2, 3 = summer
  level_no          TINYINT      NULL,              -- derived: (year-1)*2 + semester
  requirement_group VARCHAR(120) NOT NULL,
  course_type       VARCHAR(10)  NOT NULL,          -- required | elective
  credits           DECIMAL(4,1) NOT NULL,
  credit_threshold  INT          NULL,              -- e.g. 90 CH completed
  preparatory       VARCHAR(120) NULL,              -- ORN preparatory requirements
  UNIQUE KEY uq_plan_course (program_id, course_id, slot_title),
  KEY idx_plan_course (course_id),
  CONSTRAINT fk_spe_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_spe_course  FOREIGN KEY (course_id)  REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prerequisites are per program because study plans may differ.
CREATE TABLE course_requisites (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  program_id         INT         NOT NULL,
  course_id          INT         NOT NULL,
  requires_course_id INT         NOT NULL,
  kind               VARCHAR(12) NOT NULL DEFAULT 'prerequisite',   -- prerequisite | corequisite
  UNIQUE KEY uq_req (program_id, course_id, requires_course_id, kind),
  CONSTRAINT fk_req_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_req_course  FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_req_req     FOREIGN KEY (requires_course_id) REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE elective_rules (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  program_id       INT          NOT NULL,
  group_name       VARCHAR(120) NOT NULL,
  courses_required INT          NOT NULL DEFAULT 0,
  credits_required INT          NOT NULL DEFAULT 0,
  condition_text   VARCHAR(400) NULL,
  CONSTRAINT fk_er_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  program_id  INT          NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  domain      VARCHAR(60)  NOT NULL,
  statement   TEXT         NOT NULL,
  status      VARCHAR(12)  NOT NULL DEFAULT 'approved',   -- approved | retired
  version     INT          NOT NULL DEFAULT 1,
  source      VARCHAR(20)  NOT NULL DEFAULT 'institution',
  synced_at   DATETIME     NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_plo (program_id, code),
  CONSTRAINT fk_plo_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PEOPLE & ACCESS
-- ---------------------------------------------------------------------

CREATE TABLE users (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(80)  NOT NULL UNIQUE,
  external_id          VARCHAR(40)  NULL,              -- SIS / SSO identifier
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(160) NOT NULL,
  title                VARCHAR(80)  NULL,
  email                VARCHAR(160) NULL,
  role                 VARCHAR(20)  NOT NULL,          -- faculty | hod | qa | dean | leadership | admin
  department_id        INT          NULL,
  college_id           INT          NULL,
  status               VARCHAR(12)  NOT NULL DEFAULT 'active',   -- active | locked | disabled
  failed_logins        INT          NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  password_changed_at  DATETIME     NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_dept FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_user_college FOREIGN KEY (college_id) REFERENCES colleges(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(80)  NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  success     TINYINT(1)   NOT NULL,
  reason      VARCHAR(60)  NULL,
  created_at  DATETIME     NOT NULL,
  KEY idx_login_user (username, created_at),
  KEY idx_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ACADEMIC CALENDAR & OFFERINGS (SIS-fed)
-- ---------------------------------------------------------------------

CREATE TABLE terms (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12) NOT NULL UNIQUE,     -- 2026-1
  name          VARCHAR(40) NOT NULL,            -- Fall 2026
  academic_year VARCHAR(12) NOT NULL,            -- 2026-2027
  sequence      INT         NOT NULL,
  starts_on     DATE        NOT NULL,
  ends_on       DATE        NOT NULL,
  grades_due_on DATE        NOT NULL,
  status        VARCHAR(12) NOT NULL DEFAULT 'upcoming'  -- upcoming | active | closed
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spec_versions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  course_id        INT          NOT NULL,
  version_no       INT          NOT NULL,
  status           VARCHAR(16)  NOT NULL DEFAULT 'draft',  -- draft | pending_hod | pending_qa | approved | superseded | rejected
  based_on_id      INT          NULL,
  objectives       TEXT         NULL,
  teaching_strategies TEXT      NULL,
  created_by       INT          NULL,                      -- NULL = created by SAQF automation
  created_at       DATETIME     NOT NULL,
  submitted_by     INT          NULL,
  submitted_at     DATETIME     NULL,
  decided_by       INT          NULL,
  decided_at       DATETIME     NULL,
  decision_route   VARCHAR(20)  NULL,                      -- auto_no_change | auto_green | hod | qa
  decision_note    TEXT         NULL,
  qa_sampled       TINYINT(1)   NOT NULL DEFAULT 0,
  change_summary   JSON         NULL,
  UNIQUE KEY uq_spec_version (course_id, version_no),
  CONSTRAINT fk_spec_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_offerings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  course_id       INT          NOT NULL,
  term_id         INT          NOT NULL,
  instructor_id   INT          NULL,
  sections        INT          NOT NULL DEFAULT 1,
  enrolled        INT          NOT NULL DEFAULT 0,
  spec_version_id INT          NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'active',  -- active | results_partial | results_complete | closed
  source          VARCHAR(12)  NOT NULL DEFAULT 'sis',     -- sis | manual
  initialized_by  VARCHAR(12)  NOT NULL DEFAULT 'system',
  initialized_at  DATETIME     NOT NULL,
  closed_at       DATETIME     NULL,
  UNIQUE KEY uq_offering (course_id, term_id),
  KEY idx_offering_instructor (instructor_id),
  CONSTRAINT fk_off_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_off_term FOREIGN KEY (term_id) REFERENCES terms(id),
  CONSTRAINT fk_off_instructor FOREIGN KEY (instructor_id) REFERENCES users(id),
  CONSTRAINT fk_off_spec FOREIGN KEY (spec_version_id) REFERENCES spec_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. ACADEMIC-QUALITY STRUCTURE (stable, versioned, inherited each term)
-- ---------------------------------------------------------------------

CREATE TABLE clos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  spec_version_id INT          NOT NULL,
  lineage_key     VARCHAR(40)  NOT NULL,     -- stable identity across versions (history)
  code            VARCHAR(20)  NOT NULL,
  statement       TEXT         NOT NULL,
  domain          VARCHAR(60)  NOT NULL,
  target_pct      DECIMAL(5,2) NULL,         -- NULL = institutional default target
  skills_tags     VARCHAR(200) NULL,         -- Jahiziah skill tags (comma-separated)
  sort_order      INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_clo_code (spec_version_id, code),
  KEY idx_clo_lineage (lineage_key),
  CONSTRAINT fk_clo_spec FOREIGN KEY (spec_version_id) REFERENCES spec_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clo_plo (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  clo_id  INT         NOT NULL,
  plo_id  INT         NOT NULL,
  source  VARCHAR(16) NOT NULL DEFAULT 'faculty',    -- faculty | suggestion | inherited
  UNIQUE KEY uq_clo_plo (clo_id, plo_id),
  CONSTRAINT fk_cp_clo FOREIGN KEY (clo_id) REFERENCES clos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_plo FOREIGN KEY (plo_id) REFERENCES plos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  spec_version_id INT          NOT NULL,
  lineage_key     VARCHAR(40)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  kind            VARCHAR(20)  NOT NULL DEFAULT 'other',
  weight_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
  week            TINYINT      NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_assessment_name (spec_version_id, name),
  CONSTRAINT fk_as_spec FOREIGN KEY (spec_version_id) REFERENCES spec_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_clo (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  clo_id        INT NOT NULL,
  UNIQUE KEY uq_assessment_clo (assessment_id, clo_id),
  CONSTRAINT fk_ac_as FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ac_clo FOREIGN KEY (clo_id) REFERENCES clos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spec_topics (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  spec_version_id INT          NOT NULL,
  topic           VARCHAR(300) NOT NULL,
  contact_hours   DECIMAL(5,1) NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  CONSTRAINT fk_topic_spec FOREIGN KEY (spec_version_id) REFERENCES spec_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spec_resources (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  spec_version_id INT          NOT NULL,
  category        VARCHAR(40)  NOT NULL,       -- essential | supportive | electronic | facility
  reference_text  VARCHAR(400) NOT NULL,
  CONSTRAINT fk_res_spec FOREIGN KEY (spec_version_id) REFERENCES spec_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. SEMESTER EVIDENCE  (results arrive from LMS/SIS; achievement is computed)
-- ---------------------------------------------------------------------

CREATE TABLE result_batches (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  offering_id   INT          NOT NULL,
  source        VARCHAR(12)  NOT NULL,            -- lms | upload
  external_ref  VARCHAR(80)  NULL,
  imported_by   INT          NULL,                -- NULL = integration
  imported_at   DATETIME     NOT NULL,
  rows_count    INT          NOT NULL,
  assessments   VARCHAR(400) NOT NULL,
  checksum      CHAR(64)     NOT NULL,
  UNIQUE KEY uq_batch_ref (offering_id, external_ref),
  CONSTRAINT fk_rb_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_results (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  offering_id   INT          NOT NULL,
  assessment_id INT          NOT NULL,
  student_ref   VARCHAR(32)  NOT NULL,           -- pseudonymous student key, never a name
  score_pct     DECIMAL(5,2) NOT NULL,
  batch_id      INT          NOT NULL,
  UNIQUE KEY uq_result (offering_id, assessment_id, student_ref),
  CONSTRAINT fk_ar_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_ar_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id),
  CONSTRAINT fk_ar_batch FOREIGN KEY (batch_id) REFERENCES result_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clo_achievement (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  offering_id       INT          NOT NULL,
  clo_id            INT          NOT NULL,
  lineage_key       VARCHAR(40)  NOT NULL,
  method            VARCHAR(20)  NOT NULL,
  value_pct         DECIMAL(5,2) NOT NULL,
  target_pct        DECIMAL(5,2) NOT NULL,
  met               TINYINT(1)   NOT NULL,
  students_assessed INT          NOT NULL,
  coverage_pct      DECIMAL(5,2) NOT NULL,     -- share of linked assessment weight that has results
  provisional       TINYINT(1)   NOT NULL,
  computed_at       DATETIME     NOT NULL,
  UNIQUE KEY uq_clo_ach (offering_id, clo_id),
  KEY idx_ach_lineage (lineage_key),
  CONSTRAINT fk_ca_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_ca_clo FOREIGN KEY (clo_id) REFERENCES clos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plo_achievement (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  offering_id       INT          NOT NULL,
  program_id        INT          NOT NULL,
  plo_id            INT          NOT NULL,
  value_pct         DECIMAL(5,2) NOT NULL,
  contributing_clos INT          NOT NULL,
  provisional       TINYINT(1)   NOT NULL,
  computed_at       DATETIME     NOT NULL,
  UNIQUE KEY uq_plo_ach (offering_id, program_id, plo_id),
  CONSTRAINT fk_pa_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_pa_plo FOREIGN KEY (plo_id) REFERENCES plos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faculty interpretation for the course report (the only free text the report needs).
CREATE TABLE offering_narratives (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  offering_id INT          NOT NULL,
  section_key VARCHAR(40)  NOT NULL,
  content     TEXT         NOT NULL,
  updated_by  INT          NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_narrative (offering_id, section_key),
  CONSTRAINT fk_on_offering FOREIGN KEY (offering_id) REFERENCES course_offerings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_narratives (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  program_id  INT          NOT NULL,
  section_key VARCHAR(40)  NOT NULL,
  content     TEXT         NOT NULL,
  updated_by  INT          NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_pnarr (program_id, section_key),
  CONSTRAINT fk_pn_program FOREIGN KEY (program_id) REFERENCES programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. AUTOMATION & RULES ENGINE STATE
-- ---------------------------------------------------------------------

-- Every issue the rules engine detects. Open findings auto-resolve when the rule passes.
CREATE TABLE findings (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  fingerprint       VARCHAR(191) NOT NULL UNIQUE,
  rule_code         VARCHAR(60)  NOT NULL,
  scope_type        VARCHAR(16)  NOT NULL,       -- spec | offering | program | course | institution
  scope_id          INT          NOT NULL,
  course_id         INT          NULL,
  offering_id       INT          NULL,
  program_id        INT          NULL,
  department_id     INT          NULL,
  college_id        INT          NULL,
  category          VARCHAR(16)  NOT NULL,       -- validation | data | academic | policy | evidence | workflow | quality_risk
  severity          VARCHAR(10)  NOT NULL,       -- blocker | warning | info
  owner_role        VARCHAR(12)  NOT NULL,       -- who must act: faculty | hod | qa | dean | admin
  title             VARCHAR(200) NOT NULL,
  detail            TEXT         NOT NULL,
  why               TEXT         NULL,
  remedy            TEXT         NULL,
  context           JSON         NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'open',   -- open | auto_resolved | resolved | overridden
  first_detected_at DATETIME     NOT NULL,
  last_detected_at  DATETIME     NOT NULL,
  resolved_at       DATETIME     NULL,
  resolved_by       INT          NULL,
  resolution_note   VARCHAR(400) NULL,
  occurrences       INT          NOT NULL DEFAULT 1,
  KEY idx_find_scope (scope_type, scope_id, status),
  KEY idx_find_status (status, owner_role),
  KEY idx_find_dept (department_id, status),
  KEY idx_find_offering (offering_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE overrides (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  finding_id    INT          NOT NULL,
  rule_code     VARCHAR(60)  NOT NULL,
  scope_type    VARCHAR(16)  NOT NULL,
  scope_id      INT          NOT NULL,
  requested_by  INT          NOT NULL,
  requested_at  DATETIME     NOT NULL,
  justification TEXT         NOT NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'requested',   -- requested | approved | rejected | revoked
  decided_by    INT          NULL,
  decided_at    DATETIME     NULL,
  decision_note TEXT         NULL,
  KEY idx_override_scope (rule_code, scope_type, scope_id, status),
  CONSTRAINT fk_ov_finding FOREIGN KEY (finding_id) REFERENCES findings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assisted (non-deterministic) suggestions. Never applied without a human decision.
CREATE TABLE recommendations (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  fingerprint VARCHAR(191) NOT NULL UNIQUE,
  kind        VARCHAR(20)  NOT NULL,      -- mapping | overlap | trend | anomaly
  scope_type  VARCHAR(16)  NOT NULL,
  scope_id    INT          NOT NULL,
  offering_id INT          NULL,
  title       VARCHAR(200) NOT NULL,
  rationale   TEXT         NOT NULL,
  method      VARCHAR(120) NOT NULL,      -- how it was produced (shown to the user)
  confidence  DECIMAL(4,2) NULL,
  payload     JSON         NULL,
  status      VARCHAR(12)  NOT NULL DEFAULT 'open',   -- open | accepted | dismissed | stale
  decided_by  INT          NULL,
  decided_at  DATETIME     NULL,
  created_at  DATETIME     NOT NULL,
  KEY idx_rec_scope (scope_type, scope_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE improvement_actions (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  course_id            INT          NOT NULL,
  origin_offering_id   INT          NOT NULL,
  clo_lineage_key      VARCHAR(40)  NULL,
  plo_id               INT          NULL,
  finding_id           INT          NULL,
  title                VARCHAR(200) NOT NULL,
  evidence_summary     TEXT         NOT NULL,     -- written by SAQF from the data
  action_text          TEXT         NULL,         -- the academic response (human)
  owner_id             INT          NULL,
  due_on               DATE         NULL,
  status               VARCHAR(16)  NOT NULL DEFAULT 'draft',   -- draft | open | in_progress | completed | cancelled
  created_by           INT          NULL,         -- NULL = drafted automatically by SAQF
  created_at           DATETIME     NOT NULL,
  committed_at         DATETIME     NULL,
  completed_at         DATETIME     NULL,
  completion_note      TEXT         NULL,
  baseline_pct         DECIMAL(5,2) NULL,
  target_pct           DECIMAL(5,2) NULL,
  followup_offering_id INT          NULL,
  followup_pct         DECIMAL(5,2) NULL,
  effect               VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending | improved | similar | declined | not_measurable
  evaluated_at         DATETIME     NULL,
  KEY idx_ia_course (course_id, status),
  CONSTRAINT fk_ia_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_ia_origin FOREIGN KEY (origin_offering_id) REFERENCES course_offerings(id),
  CONSTRAINT fk_ia_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  kind       VARCHAR(30)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(400) NULL,
  link       VARCHAR(300) NULL,
  dedupe_key VARCHAR(120) NOT NULL,
  created_at DATETIME     NOT NULL,
  read_at    DATETIME     NULL,
  UNIQUE KEY uq_notif (user_id, dedupe_key),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event log for the event-driven automation (every reaction is traceable).
CREATE TABLE events (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(60)  NOT NULL,
  payload     JSON         NULL,
  actor_type  VARCHAR(12)  NOT NULL,
  actor_id    INT          NULL,
  created_at  DATETIME     NOT NULL,
  handled_at  DATETIME     NULL,
  outcome     TEXT         NULL,
  KEY idx_events_type (type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Factual counts of work SAQF performed so people did not have to.
CREATE TABLE automation_ledger (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  offering_id INT          NULL,
  course_id   INT          NULL,
  kind        VARCHAR(30)  NOT NULL,
  quantity    INT          NOT NULL,
  detail      VARCHAR(300) NULL,
  created_at  DATETIME     NOT NULL,
  KEY idx_ledger_offering (offering_id),
  KEY idx_ledger_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable snapshots of closed cycles (historical comparison and evidence).
CREATE TABLE snapshots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(30)  NOT NULL,
  scope_id    INT          NOT NULL,
  term_id     INT          NULL,
  title       VARCHAR(200) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  sha256      CHAR(64)     NOT NULL,
  created_by  INT          NULL,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_snapshot (kind, scope_id, term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. GOVERNANCE, CONFIGURATION & OPERATIONS
-- ---------------------------------------------------------------------

CREATE TABLE quality_policies (
  policy_key  VARCHAR(80)  PRIMARY KEY,
  value       VARCHAR(200) NOT NULL,
  value_type  VARCHAR(10)  NOT NULL,       -- int | float | bool | enum | string
  options     VARCHAR(200) NULL,
  label       VARCHAR(160) NOT NULL,
  help        VARCHAR(400) NULL,
  updated_by  INT          NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  value       TEXT        NULL,
  updated_at  DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_runs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(40) NOT NULL,
  started_at  DATETIME    NOT NULL,
  finished_at DATETIME    NULL,
  status      VARCHAR(12) NOT NULL,
  stats       JSON        NULL,
  message     TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_errors (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  ref         VARCHAR(12)  NOT NULL,
  occurred_at DATETIME     NOT NULL,
  level       VARCHAR(12)  NOT NULL,
  message     TEXT         NOT NULL,
  location    VARCHAR(300) NULL,
  trace       TEXT         NULL,
  url         VARCHAR(400) NULL,
  user_id     INT          NULL,
  request_id  VARCHAR(16)  NULL,
  KEY idx_err_ref (ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tamper-evident audit trail: each row stores the SHA-256 of its content plus the previous hash.
CREATE TABLE audit_log (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  occurred_at  DATETIME     NOT NULL,
  actor_type   VARCHAR(12)  NOT NULL,      -- user | system | integration
  actor_id     INT          NULL,
  actor_name   VARCHAR(160) NOT NULL,
  actor_role   VARCHAR(20)  NULL,
  action       VARCHAR(60)  NOT NULL,
  object_type  VARCHAR(40)  NOT NULL,
  object_id    VARCHAR(40)  NULL,
  summary      VARCHAR(400) NOT NULL,
  old_value    MEDIUMTEXT   NULL,          -- JSON text, stored verbatim so the hash is reproducible
  new_value    MEDIUMTEXT   NULL,
  reason       TEXT         NULL,
  ip           VARCHAR(45)  NULL,
  request_id   VARCHAR(16)  NULL,
  prev_hash    CHAR(64)     NOT NULL,
  hash         CHAR(64)     NOT NULL,
  KEY idx_audit_object (object_type, object_id),
  KEY idx_audit_actor (actor_id, occurred_at),
  KEY idx_audit_action (action, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
