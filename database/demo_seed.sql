-- Optional hackathon demo records.
-- Run after AQMS_db.sql and quality_gate_migration.sql.
-- This adds one green course and one amber exception course for rehearsal.

SET @demo_program_id := (SELECT program_id FROM program_specs ORDER BY program_id LIMIT 1);
SET @demo_faculty_id := (SELECT user_id FROM user WHERE role = 'faculty' ORDER BY user_id LIMIT 1);
SET @demo_hod_id := (SELECT user_id FROM user WHERE role = 'hod' ORDER BY user_id LIMIT 1);

INSERT INTO course_specs
    (program_id, faculty_id, course_title, course_code, department, college, institution,
     credit_hours, course_type, required_elective, course_level, course_description,
     objectives, version, due_date, submitted_at, deadline_status, status,
     quality_gate_status, quality_gate_score, qa_sample_required)
VALUES
    (@demo_program_id, @demo_faculty_id, 'Quality-Driven Web Systems', 'AQMS-DEMO-GREEN',
     'Software Engineering', 'College of Engineering and Architecture', 'Al Yamamah University',
     3.0, 'Department', 'Required', 3,
     'This course develops the ability to design, implement, evaluate, and improve reliable web systems using measurable quality evidence and traceable learning outcomes.',
     'Students will analyze quality requirements, design a traceable system, implement a working prototype, and evaluate the result using evidence-based improvement cycles.',
     '1.0', DATE_ADD(CURDATE(), INTERVAL 14 DAY), NOW(), 'on_time', 'pending_hod',
     'green', 100.00, 1);
SET @demo_green_id := LAST_INSERT_ID();

INSERT INTO teaching_modes (course_id, mode_type, contact_hours, percentage)
VALUES (@demo_green_id, 'Hybrid', 42, 70);
INSERT INTO contact_hours (course_id, activity_type, hours)
VALUES (@demo_green_id, 'Lectures', 28), (@demo_green_id, 'Laboratory/Studio', 14);
INSERT INTO course_topics (course_id, topic_text, contact_hours, sort_order)
VALUES (@demo_green_id, 'Quality requirements and traceability', 8, 1),
       (@demo_green_id, 'Web architecture and implementation', 16, 2),
       (@demo_green_id, 'Testing, evidence, and continuous improvement', 18, 3);

INSERT INTO course_learning_outcomes
    (course_id, clo_code, description, category, teaching_strategies, assessment_methods)
VALUES
    (@demo_green_id, 'CLO-1', 'Analyze quality requirements and traceability risks in a web system.', 'Knowledge and Understanding', 'Case analysis and guided discussion', 'Written test'),
    (@demo_green_id, 'CLO-2', 'Design and implement a working web system that links evidence to outcomes.', 'Skills', 'Studio implementation and peer review', 'Group project'),
    (@demo_green_id, 'CLO-3', 'Evaluate system evidence and propose a measurable improvement cycle.', 'Values, Autonomy, and Responsibility', 'Reflection and evidence review', 'Oral presentation');
SET @demo_green_clo1 := (SELECT clo_id FROM course_learning_outcomes WHERE course_id = @demo_green_id AND clo_code = 'CLO-1' LIMIT 1);
SET @demo_green_clo2 := (SELECT clo_id FROM course_learning_outcomes WHERE course_id = @demo_green_id AND clo_code = 'CLO-2' LIMIT 1);
SET @demo_green_clo3 := (SELECT clo_id FROM course_learning_outcomes WHERE course_id = @demo_green_id AND clo_code = 'CLO-3' LIMIT 1);

INSERT INTO clo_plo_mapping (clo_id, plo_id)
SELECT @demo_green_clo1, plo_id FROM program_learning_outcomes WHERE program_id = @demo_program_id AND plo_code = 'K1';
INSERT INTO clo_plo_mapping (clo_id, plo_id)
SELECT @demo_green_clo2, plo_id FROM program_learning_outcomes WHERE program_id = @demo_program_id AND plo_code = 'S1';
INSERT INTO clo_plo_mapping (clo_id, plo_id)
SELECT @demo_green_clo3, plo_id FROM program_learning_outcomes WHERE program_id = @demo_program_id AND plo_code = 'V1';

INSERT INTO jahiziah_skills (course_id, clo_id, skill_type)
VALUES (@demo_green_id, @demo_green_clo2, 'Digital'),
       (@demo_green_id, @demo_green_clo2, 'Teamwork'),
       (@demo_green_id, @demo_green_clo3, 'Communication'),
       (@demo_green_id, @demo_green_clo3, 'Ethics');

INSERT INTO assessments
    (course_id, activity_name, timing_week, percentage, assessment_timing, proportion_of_total, rubric, performance_task)
VALUES
    (@demo_green_id, 'Written test', 6, 30, 'Week 6', 30,
     'Accuracy of analysis 40%; use of evidence 30%; clarity of reasoning 30%.',
     'Analyze a quality-risk case and submit a justified traceability report.'),
    (@demo_green_id, 'Group project', 12, 50, 'Week 12', 50,
     'Architecture quality 30%; implementation quality 30%; traceability 20%; teamwork 20%.',
     'Build and demonstrate a working quality-managed web system with an evidence trail.'),
    (@demo_green_id, 'Oral presentation', 14, 20, 'Week 14', 20,
     'Technical accuracy 35%; communication 25%; reflection 20%; improvement proposal 20%.',
     'Present the system evidence and defend one measurable improvement cycle.');
SET @demo_green_assess1 := (SELECT id FROM assessments WHERE course_id = @demo_green_id AND activity_name = 'Written test' LIMIT 1);
SET @demo_green_assess2 := (SELECT id FROM assessments WHERE course_id = @demo_green_id AND activity_name = 'Group project' LIMIT 1);
SET @demo_green_assess3 := (SELECT id FROM assessments WHERE course_id = @demo_green_id AND activity_name = 'Oral presentation' LIMIT 1);
INSERT INTO assessment_clo (assessment_id, clo_id)
VALUES (@demo_green_assess1, @demo_green_clo1),
       (@demo_green_assess2, @demo_green_clo2),
       (@demo_green_assess3, @demo_green_clo3);

INSERT INTO resources (course_id, category, resource_text)
VALUES (@demo_green_id, 'Essential References', 'University-approved web engineering and software quality reference'),
       (@demo_green_id, 'Electronic Materials', 'AQMS evidence templates and secure coding lab materials');
INSERT INTO course_facilities (course_id, item, resources)
VALUES (@demo_green_id, 'Classrooms', 'Smart classroom with projector'),
       (@demo_green_id, 'Technology equipment', 'Development lab with version control and web testing tools');
INSERT INTO course_quality (course_id, assessment_area, assessor, assessment_method)
VALUES (@demo_green_id, 'Effectiveness of teaching', 'Faculty', 'Direct'),
       (@demo_green_id, 'Effectiveness of Students assessment', 'Peer Reviewers', 'Direct'),
       (@demo_green_id, 'Quality of learning resources', 'Students', 'Indirect'),
       (@demo_green_id, 'The extent to which CLOs have been achieved', 'Program Leaders', 'Direct');
INSERT INTO course_approval (course_id, council_committee, reference_no, approval_date)
VALUES (@demo_green_id, 'College Curriculum Committee', 'AQMS-DEMO-2026-01', CURDATE());
INSERT INTO course_pdca (course_id, stage, description, phase, content, created_at)
VALUES (@demo_green_id, 'Plan', 'Review assessment evidence and traceability.', 'Plan', 'Review assessment evidence and traceability.', NOW()),
       (@demo_green_id, 'Do', 'Run the evidence-backed prototype workflow.', 'Do', 'Run the evidence-backed prototype workflow.', NOW()),
       (@demo_green_id, 'Check', 'Review quality-gate results and stakeholder feedback.', 'Check', 'Review quality-gate results and stakeholder feedback.', NOW()),
       (@demo_green_id, 'Act', 'Record the next improvement action and owner.', 'Act', 'Record the next improvement action and owner.', NOW());
INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment)
VALUES (@demo_green_id, @demo_faculty_id, 'draft', 'pending_hod', 'Demo record submitted with all deterministic checks complete.');

INSERT INTO course_specs
    (program_id, faculty_id, course_title, course_code, department, college, institution,
     credit_hours, course_type, required_elective, course_level, course_description,
     objectives, version, due_date, submitted_at, deadline_status, status,
     quality_gate_status, quality_gate_score, qa_sample_required)
VALUES
    (@demo_program_id, @demo_faculty_id, 'Quality Gate Exception Lab', 'AQMS-DEMO-AMBER',
     'Software Engineering', 'College of Engineering and Architecture', 'Al Yamamah University',
     3.0, 'Department', 'Required', 3,
     'A short demo record used to illustrate focused review.',
     'Build a system.',
     '1.0', DATE_ADD(CURDATE(), INTERVAL 14 DAY), NOW(), 'on_time', 'pending_hod',
     'amber', 75.00, 0);
SET @demo_amber_id := LAST_INSERT_ID();

INSERT INTO teaching_modes (course_id, mode_type, contact_hours, percentage)
VALUES (@demo_amber_id, 'Traditional classroom', 42, 100);
INSERT INTO contact_hours (course_id, activity_type, hours)
VALUES (@demo_amber_id, 'Lectures', 30), (@demo_amber_id, 'Tutorial', 12);
INSERT INTO course_topics (course_id, topic_text, contact_hours, sort_order)
VALUES (@demo_amber_id, 'Quality-gate exception handling', 42, 1);
INSERT INTO course_learning_outcomes
    (course_id, clo_code, description, category, teaching_strategies, assessment_methods)
VALUES (@demo_amber_id, 'CLO-1', 'Apply a quality-gate checklist to a course record.', 'Skills', 'Guided lab', 'Written test');
SET @demo_amber_clo := LAST_INSERT_ID();
INSERT INTO clo_plo_mapping (clo_id, plo_id)
SELECT @demo_amber_clo, plo_id FROM program_learning_outcomes WHERE program_id = @demo_program_id AND plo_code = 'S1';
INSERT INTO assessments
    (course_id, activity_name, timing_week, percentage, assessment_timing,
     proportion_of_total, rubric, performance_task)
VALUES (@demo_amber_id, 'Written test', 8, 100, 'Week 8', 100,
        'Rubric criteria for Written test', 'Performance task for Written test');
SET @demo_amber_assess := LAST_INSERT_ID();
INSERT INTO assessment_clo (assessment_id, clo_id) VALUES (@demo_amber_assess, @demo_amber_clo);
INSERT INTO resources (course_id, category, resource_text)
VALUES (@demo_amber_id, 'Essential References', 'Quality-gate demo reference');
INSERT INTO course_facilities (course_id, item, resources)
VALUES (@demo_amber_id, 'Classrooms', 'Smart classroom');
INSERT INTO course_quality (course_id, assessment_area, assessor, assessment_method)
VALUES (@demo_amber_id, 'Effectiveness of teaching', 'Faculty', 'Direct');
INSERT INTO course_approval (course_id, council_committee, reference_no, approval_date)
VALUES (@demo_amber_id, 'College Curriculum Committee', 'AQMS-DEMO-2026-02', CURDATE());
INSERT INTO course_pdca (course_id, stage, description, phase, content, created_at)
VALUES (@demo_amber_id, 'Plan', 'Record the issue for review.', 'Plan', 'Record the issue for review.', NOW());
INSERT INTO approval_log (course_id, user_id, from_status, to_status, comment)
VALUES (@demo_amber_id, @demo_faculty_id, 'draft', 'pending_hod', 'Demo exception submitted for focused QA review.');
