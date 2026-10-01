-- AQMS quality-gate migration.
-- Upgrade an existing AQMS database to the course quality-gate workflow.

SET @aqms_db := DATABASE();

-- The current PHP wizard writes phase/content. Keep the legacy columns for
-- compatibility with older reports, but allow the new workflow to omit them.
SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_pdca' AND COLUMN_NAME = 'phase'
    ),
    'SELECT 1',
    'ALTER TABLE course_pdca ADD COLUMN phase ENUM(''Plan'',''Do'',''Check'',''Act'') DEFAULT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_pdca' AND COLUMN_NAME = 'content'
    ),
    'SELECT 1',
    'ALTER TABLE course_pdca ADD COLUMN content TEXT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_pdca' AND COLUMN_NAME = 'created_at'
    ),
    'SELECT 1',
    'ALTER TABLE course_pdca ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

UPDATE course_pdca
SET phase = COALESCE(phase, stage), content = COALESCE(content, description)
WHERE phase IS NULL OR content IS NULL;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'assessment_timing'
    ),
    'SELECT 1',
    'ALTER TABLE assessments ADD COLUMN assessment_timing VARCHAR(100) DEFAULT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'proportion_of_total'
    ),
    'SELECT 1',
    'ALTER TABLE assessments ADD COLUMN proportion_of_total DECIMAL(5,2) DEFAULT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'rubric'
    ),
    'SELECT 1',
    'ALTER TABLE assessments ADD COLUMN rubric TEXT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'assessments' AND COLUMN_NAME = 'performance_task'
    ),
    'SELECT 1',
    'ALTER TABLE assessments ADD COLUMN performance_task TEXT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

UPDATE assessments
SET assessment_timing = IF(assessment_timing IS NULL OR assessment_timing = '', IF(timing_week IS NULL, 'Not specified', CONCAT('Week ', timing_week)), assessment_timing),
    proportion_of_total = IF(proportion_of_total IS NULL, percentage, proportion_of_total),
    rubric = IF(rubric IS NULL OR TRIM(rubric) = '', CONCAT('Rubric criteria for ', activity_name), rubric),
    performance_task = IF(performance_task IS NULL OR TRIM(performance_task) = '', CONCAT('Performance task for ', activity_name), performance_task);

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_pdca' AND COLUMN_NAME = 'stage'
    ),
    'ALTER TABLE course_pdca MODIFY COLUMN stage ENUM(''Plan'',''Do'',''Check'',''Act'') DEFAULT NULL, MODIFY COLUMN description TEXT NULL',
    'SELECT 1'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_specs' AND COLUMN_NAME = 'quality_gate_status'
    ),
    'SELECT 1',
    'ALTER TABLE course_specs ADD COLUMN quality_gate_status VARCHAR(20) NOT NULL DEFAULT ''not_run'''
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_specs' AND COLUMN_NAME = 'quality_gate_score'
    ),
    'SELECT 1',
    'ALTER TABLE course_specs ADD COLUMN quality_gate_score DECIMAL(5,2) DEFAULT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_specs' AND COLUMN_NAME = 'quality_gate_run_id'
    ),
    'SELECT 1',
    'ALTER TABLE course_specs ADD COLUMN quality_gate_run_id BIGINT UNSIGNED DEFAULT NULL'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

SET @aqms_sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @aqms_db AND TABLE_NAME = 'course_specs' AND COLUMN_NAME = 'qa_sample_required'
    ),
    'SELECT 1',
    'ALTER TABLE course_specs ADD COLUMN qa_sample_required TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE aqms_stmt FROM @aqms_sql;
EXECUTE aqms_stmt;
DEALLOCATE PREPARE aqms_stmt;

CREATE TABLE IF NOT EXISTS quality_gate_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id INT NOT NULL,
    triggered_by INT DEFAULT NULL,
    result VARCHAR(20) NOT NULL,
    score DECIMAL(5,2) NOT NULL,
    summary TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (run_id),
    KEY quality_gate_course_idx (course_id, created_at),
    CONSTRAINT quality_gate_runs_course_fk FOREIGN KEY (course_id) REFERENCES course_specs (course_id) ON DELETE CASCADE,
    CONSTRAINT quality_gate_runs_user_fk FOREIGN KEY (triggered_by) REFERENCES user (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quality_gate_results (
    result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    check_code VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    title VARCHAR(150) NOT NULL,
    detail TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (result_id),
    KEY quality_gate_results_run_idx (run_id),
    CONSTRAINT quality_gate_results_run_fk FOREIGN KEY (run_id) REFERENCES quality_gate_runs (run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
