-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: May 18, 2026 at 12:56 PM
-- Server version: 8.0.44
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `AQMS_db`
--


DROP DATABASE IF EXISTS `AQMS_db`;
CREATE DATABASE `AQMS_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `AQMS_db`;

-- --------------------------------------------------------

--
-- Table structure for table `approval_log`
--

CREATE TABLE `approval_log` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `user_id` int NOT NULL,
  `from_status` varchar(50) DEFAULT NULL,
  `to_status` varchar(50) NOT NULL,
  `comment` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

CREATE TABLE `assessments` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `activity_name` varchar(200) NOT NULL,
  `timing_week` int DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assessment_clo`
--

CREATE TABLE `assessment_clo` (
  `id` int NOT NULL,
  `assessment_id` int NOT NULL,
  `clo_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clo_plo_mapping`
--

CREATE TABLE `clo_plo_mapping` (
  `id` int NOT NULL,
  `clo_id` int NOT NULL,
  `plo_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_hours`
--

CREATE TABLE `contact_hours` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `activity_type` varchar(100) DEFAULT NULL,
  `hours` decimal(5,1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `contact_hours`
--

INSERT INTO `contact_hours` (`id`, `course_id`, `activity_type`, `hours`) VALUES
(1, 2, 'Lectures', NULL),
(2, 2, 'Laboratory/Studio', NULL),
(3, 2, 'Field', NULL),
(4, 2, 'Tutorial', NULL),
(5, 2, 'Others', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `course_learning_outcomes`
--

CREATE TABLE `course_learning_outcomes` (
  `clo_id` int NOT NULL,
  `course_id` int NOT NULL,
  `clo_code` varchar(20) NOT NULL,
  `description` text NOT NULL,
  `category` enum('Knowledge','Skills','Values') NOT NULL,
  `teaching_strategies` text,
  `assessment_methods` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_pdca`
--

CREATE TABLE `course_pdca` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `stage` enum('Plan','Do','Check','Act') NOT NULL,
  `description` text,
  `cycle_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_specs`
--

CREATE TABLE `course_specs` (
  `course_id` int NOT NULL,
  `program_id` int DEFAULT NULL,
  `faculty_id` int DEFAULT NULL,
  `course_title` varchar(200) NOT NULL,
  `course_code` varchar(50) DEFAULT NULL,
  `credit_hours` decimal(4,1) DEFAULT NULL,
  `course_type` varchar(100) DEFAULT NULL,
  `teaching_mode` varchar(100) DEFAULT NULL,
  `course_level` int DEFAULT NULL,
  `prerequisites` varchar(200) DEFAULT NULL,
  `corequisites` varchar(200) DEFAULT NULL,
  `course_description` text,
  `objectives` text,
  `learning_resources` text,
  `version` varchar(20) DEFAULT '1.0',
  `status` enum('draft','pending_hod','pending_qa','approved') DEFAULT 'draft',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `course_specs`
--

INSERT INTO `course_specs` (`course_id`, `program_id`, `faculty_id`, `course_title`, `course_code`, `credit_hours`, `course_type`, `teaching_mode`, `course_level`, `prerequisites`, `corequisites`, `course_description`, `objectives`, `learning_resources`, `version`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Advanced Web Programming', 'CIS312', 3.0, NULL, NULL, 3, NULL, NULL, NULL, NULL, NULL, '1.0', 'draft', '2026-05-15 19:24:12', '2026-05-15 19:24:12'),
(2, 1, 1, 'General Chemistry', 'CHM 101', 4.0, '', '', 1, '', '', '', '', NULL, '1.0', 'draft', '2026-05-15 20:03:23', '2026-05-15 20:03:47');

-- --------------------------------------------------------

--
-- Table structure for table `course_topics`
--

CREATE TABLE `course_topics` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `topic_text` text NOT NULL,
  `contact_hours` decimal(5,1) DEFAULT NULL,
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jahiziah_skills`
--

CREATE TABLE `jahiziah_skills` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `clo_id` int DEFAULT NULL,
  `skill_type` enum('Digital','Communication','Teamwork','Ethics') NOT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_kpis`
--

CREATE TABLE `program_kpis` (
  `id` int NOT NULL,
  `program_id` int NOT NULL,
  `kpi_code` varchar(50) DEFAULT NULL,
  `kpi_text` text NOT NULL,
  `target_level` varchar(200) DEFAULT NULL,
  `measurement_method` text,
  `measurement_time` varchar(200) DEFAULT NULL,
  `years_to_achieve` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `program_kpis`
--

INSERT INTO `program_kpis` (`id`, `program_id`, `kpi_code`, `kpi_text`, `target_level`, `measurement_method`, `measurement_time`, `years_to_achieve`) VALUES
(1, 1, 'KPI-01', 'Student completion rate', '80%', 'Data from registration office.', 'End of academic year', 1),
(2, 1, 'KPI-02', 'First year student retention rate', '90%', 'Data from registration office', 'Beginning of following year', 1),
(3, 1, 'KPI-03', 'Student evaluation of quality of learning', '95%', 'Biannual survey', 'End of academic semester', 1),
(4, 1, 'KPI-04', 'Student evaluation of quality of courses', '90%', 'Biannual survey', 'End of academic semester', 1),
(5, 1, 'KPI-05', 'Student performance in national examinations', '85%', 'Exam result data', 'After results are published', 1),
(6, 1, 'KPI-06', 'Graduate employability', '95%', 'Alumni survey', 'One year after graduation', 1),
(7, 1, 'KPI-07', 'Employer\'s evaluation of graduate\'s proficiency', '90%', 'Employer survey', 'Annually', 1),
(8, 1, 'KPI-08', 'Ratio of students to teaching staff', '95%', 'Statistical data', 'End of academic year', 1),
(9, 1, 'KPI-09', 'Percentage of faculty member publications', '80%', 'Faculty reports', 'End of academic year', 1),
(10, 1, 'KPI-10', 'Citation rate in refrenced journals per faculty member', '80%', 'Citation index', 'End of academic year', 1);

-- --------------------------------------------------------

--
-- Table structure for table `program_learning_outcomes`
--

CREATE TABLE `program_learning_outcomes` (
  `plo_id` int NOT NULL,
  `program_id` int NOT NULL,
  `plo_code` varchar(20) NOT NULL,
  `description` text NOT NULL,
  `category` enum('Knowledge','Skills','Values') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `program_learning_outcomes`
--

INSERT INTO `program_learning_outcomes` (`plo_id`, `program_id`, `plo_code`, `description`, `category`) VALUES
(1, 1, 'K1', 'Demonstrate foundational knowledge in core computing concepts, algorithms and data structures', 'Knowledge'),
(2, 1, 'K2', 'Understand software development methodologies, design patterns, and engineering best practices', 'Knowledge'),
(3, 1, 'K3', 'Recognize the role of ethics, privacy and security in computing systems', 'Knowledge'),
(4, 1, 'S1', 'Apply programming and problem-solving skills to design and implement software solutions', 'Skills'),
(5, 1, 'S2', 'Analyze complex technical problems and propose well-justified solutions using appropriate tools', 'Skills'),
(6, 1, 'S3', 'Communicate technical ideas effectively in written, oral and visual forms', 'Skills'),
(7, 1, 'V1', 'Commit to professional, ethical and legal standards in computing practice', 'Values'),
(8, 1, 'V2', 'Work effectively in diverse team environments and demonstrate leadership in projects', 'Values');

-- --------------------------------------------------------

--
-- Table structure for table `program_specs`
--

CREATE TABLE `program_specs` (
  `program_id` int NOT NULL,
  `program_name` varchar(200) NOT NULL,
  `program_code` varchar(50) DEFAULT NULL,
  `college` varchar(150) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `credit_hours` int DEFAULT NULL,
  `qualification_level` varchar(100) DEFAULT NULL,
  `mission` text,
  `goals` text,
  `program_aims` text,
  `program_structure` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `program_specs`
--

INSERT INTO `program_specs` (`program_id`, `program_name`, `program_code`, `college`, `department`, `credit_hours`, `qualification_level`, `mission`, `goals`, `program_aims`, `program_structure`) VALUES
(1, 'Bachelor of Science in Software Engineering', 'BSE', 'College of Engineering and Architecture', 'Software Engineering', 141, 'NQF Level 6 — Bachelor', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `quality_improvements`
--

CREATE TABLE `quality_improvements` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `stage` enum('Plan','Do','Check','Act') NOT NULL,
  `description` text,
  `cycle_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resources`
--

CREATE TABLE `resources` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `category` enum('Essential','Supportive','Electronic','Other') NOT NULL,
  `resource_text` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teaching_modes`
--

CREATE TABLE `teaching_modes` (
  `id` int NOT NULL,
  `course_id` int NOT NULL,
  `mode_type` varchar(100) DEFAULT NULL,
  `contact_hours` decimal(5,1) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('faculty','hod','qa') NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`user_id`, `username`, `password`, `role`, `full_name`, `department`, `created_at`) VALUES
(1, 'faculty1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'Dr. Conrado Vizcarra', 'Engineering', '2026-05-15 14:24:04'),
(2, 'hod1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hod', 'Dr. Roxane Mallouhy', 'Computer Science', '2026-05-15 14:24:04'),
(3, 'qa1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'qa', 'Mr. Khalid Al-Qahtani', 'Quality Assurance Office', '2026-05-15 14:24:04');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approval_log`
--
ALTER TABLE `approval_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assessments`
--
ALTER TABLE `assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `assessment_clo`
--
ALTER TABLE `assessment_clo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `clo_id` (`clo_id`);

--
-- Indexes for table `clo_plo_mapping`
--
ALTER TABLE `clo_plo_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_clo_plo` (`clo_id`,`plo_id`),
  ADD KEY `plo_id` (`plo_id`);

--
-- Indexes for table `contact_hours`
--
ALTER TABLE `contact_hours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `course_learning_outcomes`
--
ALTER TABLE `course_learning_outcomes`
  ADD PRIMARY KEY (`clo_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `course_pdca`
--
ALTER TABLE `course_pdca`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `course_specs`
--
ALTER TABLE `course_specs`
  ADD PRIMARY KEY (`course_id`),
  ADD KEY `program_id` (`program_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `course_topics`
--
ALTER TABLE `course_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `jahiziah_skills`
--
ALTER TABLE `jahiziah_skills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `clo_id` (`clo_id`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `program_kpis`
--
ALTER TABLE `program_kpis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `program_learning_outcomes`
--
ALTER TABLE `program_learning_outcomes`
  ADD PRIMARY KEY (`plo_id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `program_specs`
--
ALTER TABLE `program_specs`
  ADD PRIMARY KEY (`program_id`);

--
-- Indexes for table `quality_improvements`
--
ALTER TABLE `quality_improvements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `resources`
--
ALTER TABLE `resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `teaching_modes`
--
ALTER TABLE `teaching_modes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approval_log`
--
ALTER TABLE `approval_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `assessments`
--
ALTER TABLE `assessments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assessment_clo`
--
ALTER TABLE `assessment_clo`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clo_plo_mapping`
--
ALTER TABLE `clo_plo_mapping`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_hours`
--
ALTER TABLE `contact_hours`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `course_learning_outcomes`
--
ALTER TABLE `course_learning_outcomes`
  MODIFY `clo_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_pdca`
--
ALTER TABLE `course_pdca`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_specs`
--
ALTER TABLE `course_specs`
  MODIFY `course_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `course_topics`
--
ALTER TABLE `course_topics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jahiziah_skills`
--
ALTER TABLE `jahiziah_skills`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `program_kpis`
--
ALTER TABLE `program_kpis`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `program_learning_outcomes`
--
ALTER TABLE `program_learning_outcomes`
  MODIFY `plo_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `program_specs`
--
ALTER TABLE `program_specs`
  MODIFY `program_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `quality_improvements`
--
ALTER TABLE `quality_improvements`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resources`
--
ALTER TABLE `resources`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teaching_modes`
--
ALTER TABLE `teaching_modes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `approval_log`
--
ALTER TABLE `approval_log`
  ADD CONSTRAINT `approval_log_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approval_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `assessments`
--
ALTER TABLE `assessments`
  ADD CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `assessment_clo`
--
ALTER TABLE `assessment_clo`
  ADD CONSTRAINT `assessment_clo_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assessment_clo_ibfk_2` FOREIGN KEY (`clo_id`) REFERENCES `course_learning_outcomes` (`clo_id`) ON DELETE CASCADE;

--
-- Constraints for table `clo_plo_mapping`
--
ALTER TABLE `clo_plo_mapping`
  ADD CONSTRAINT `clo_plo_mapping_ibfk_1` FOREIGN KEY (`clo_id`) REFERENCES `course_learning_outcomes` (`clo_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clo_plo_mapping_ibfk_2` FOREIGN KEY (`plo_id`) REFERENCES `program_learning_outcomes` (`plo_id`) ON DELETE CASCADE;

--
-- Constraints for table `contact_hours`
--
ALTER TABLE `contact_hours`
  ADD CONSTRAINT `contact_hours_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `course_learning_outcomes`
--
ALTER TABLE `course_learning_outcomes`
  ADD CONSTRAINT `course_learning_outcomes_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `course_pdca`
--
ALTER TABLE `course_pdca`
  ADD CONSTRAINT `course_pdca_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `course_specs`
--
ALTER TABLE `course_specs`
  ADD CONSTRAINT `course_specs_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `course_specs_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `user` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `course_topics`
--
ALTER TABLE `course_topics`
  ADD CONSTRAINT `course_topics_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `jahiziah_skills`
--
ALTER TABLE `jahiziah_skills`
  ADD CONSTRAINT `jahiziah_skills_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `jahiziah_skills_ibfk_2` FOREIGN KEY (`clo_id`) REFERENCES `course_learning_outcomes` (`clo_id`) ON DELETE SET NULL;

--
-- Constraints for table `program_kpis`
--
ALTER TABLE `program_kpis`
  ADD CONSTRAINT `program_kpis_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE;

--
-- Constraints for table `program_learning_outcomes`
--
ALTER TABLE `program_learning_outcomes`
  ADD CONSTRAINT `program_learning_outcomes_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE;

--
-- Constraints for table `quality_improvements`
--
ALTER TABLE `quality_improvements`
  ADD CONSTRAINT `quality_improvements_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `resources`
--
ALTER TABLE `resources`
  ADD CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `teaching_modes`
--
ALTER TABLE `teaching_modes`
  ADD CONSTRAINT `teaching_modes_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE;
COMMIT;



-- =========================================================
-- FINAL AQMS COMPLETION ADDITIONS
-- Combined from all patch and seed files into one import file.
-- =========================================================

ALTER TABLE `user`
  MODIFY `role` varchar(50) NOT NULL;

INSERT INTO `user` (`username`, `password`, `role`, `full_name`, `department`)
SELECT 'dean1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3V6G3FvL3R8Q0Sg0g0mFQpO7dVQK', 'dean', 'Department Dean', 'Software Engineering'
WHERE NOT EXISTS (SELECT 1 FROM `user` WHERE `username` = 'dean1');

ALTER TABLE `course_specs`
  ADD COLUMN `department` varchar(150) DEFAULT NULL AFTER `course_code`,
  ADD COLUMN `college` varchar(150) DEFAULT NULL AFTER `department`,
  ADD COLUMN `institution` varchar(150) DEFAULT 'Al Yamamah University' AFTER `college`,
  ADD COLUMN `last_revision_date` date DEFAULT NULL AFTER `version`,
  ADD COLUMN `required_elective` varchar(50) DEFAULT NULL AFTER `course_type`,
  ADD COLUMN `due_date` date DEFAULT NULL AFTER `objectives`,
  ADD COLUMN `submitted_at` datetime DEFAULT NULL AFTER `due_date`,
  ADD COLUMN `deadline_status` varchar(50) DEFAULT 'not_due' AFTER `submitted_at`;

ALTER TABLE `course_specs`
  MODIFY `status` enum('draft','pending_hod','returned_by_hod','pending_qa','returned_by_qa','approved','archived') DEFAULT 'draft';

ALTER TABLE `course_learning_outcomes`
  MODIFY `category` varchar(150) NOT NULL;

ALTER TABLE `program_learning_outcomes`
  MODIFY `category` varchar(150) NOT NULL;

ALTER TABLE `resources`
  MODIFY `category` varchar(100) NOT NULL;

ALTER TABLE `teaching_modes`
  MODIFY `mode_type` varchar(100) DEFAULT NULL;

ALTER TABLE `contact_hours`
  MODIFY `activity_type` varchar(100) DEFAULT NULL;

UPDATE `program_learning_outcomes` SET `category` = 'Knowledge and Understanding' WHERE `category` = 'Knowledge';
UPDATE `program_learning_outcomes` SET `category` = 'Values, Autonomy, and Responsibility' WHERE `category` = 'Values';
UPDATE `course_learning_outcomes` SET `category` = 'Knowledge and Understanding' WHERE `category` = 'Knowledge';
UPDATE `course_learning_outcomes` SET `category` = 'Values, Autonomy, and Responsibility' WHERE `category` = 'Values';

ALTER TABLE `assessments`
  ADD COLUMN `assessment_timing` varchar(100) DEFAULT NULL AFTER `percentage`,
  ADD COLUMN `proportion_of_total` decimal(5,2) DEFAULT NULL AFTER `assessment_timing`,
  ADD COLUMN `rubric` text AFTER `proportion_of_total`,
  ADD COLUMN `performance_task` text AFTER `rubric`;

UPDATE `assessments`
SET `assessment_timing` = IF(`timing_week` IS NULL, NULL, CONCAT('Week ', `timing_week`)),
    `proportion_of_total` = `percentage`
WHERE `id` IS NOT NULL;

ALTER TABLE `course_pdca`
  ADD COLUMN `phase` enum('Plan','Do','Check','Act') DEFAULT NULL AFTER `course_id`,
  ADD COLUMN `content` text AFTER `phase`,
  ADD COLUMN `created_at` datetime DEFAULT CURRENT_TIMESTAMP AFTER `cycle_date`;

UPDATE `course_pdca`
SET `phase` = `stage`,
    `content` = `description`
WHERE `id` IS NOT NULL;

ALTER TABLE `course_pdca`
  MODIFY COLUMN `stage` enum('Plan','Do','Check','Act') DEFAULT NULL,
  MODIFY COLUMN `description` text NULL;

CREATE TABLE IF NOT EXISTS `course_facilities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `item` varchar(150) NOT NULL,
  `resources` text,
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `course_facilities_course_fk` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `course_quality` (
  `id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `assessment_area` varchar(200) NOT NULL,
  `assessor` varchar(200) DEFAULT NULL,
  `assessment_method` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `course_quality_course_fk` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `course_approval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `council_committee` varchar(200) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_id` (`course_id`),
  CONSTRAINT `course_approval_course_fk` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `program_specs`
  ADD COLUMN `institution` varchar(150) DEFAULT 'Al Yamamah University' AFTER `college`,
  ADD COLUMN `last_review_date` date DEFAULT NULL AFTER `program_structure`,
  ADD COLUMN `specification_status` enum('new','updated') DEFAULT 'new' AFTER `last_review_date`;

CREATE TABLE IF NOT EXISTS `program_tp151_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `section_key` varchar(100) NOT NULL,
  `section_title` varchar(200) NOT NULL,
  `section_value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_section` (`program_id`, `section_key`),
  CONSTRAINT `program_tp151_sections_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_curriculum_structure` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `requirement_type` varchar(150) NOT NULL,
  `required_hours` decimal(6,1) DEFAULT NULL,
  `elective_hours` decimal(6,1) DEFAULT NULL,
  `total_hours` decimal(6,1) DEFAULT NULL,
  `percentage` decimal(6,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `program_curriculum_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_course_plan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `level_no` int DEFAULT NULL,
  `course_code` varchar(50) DEFAULT NULL,
  `course_title` varchar(200) DEFAULT NULL,
  `required_elective` varchar(50) DEFAULT NULL,
  `prerequisites` varchar(200) DEFAULT NULL,
  `credit_hours` decimal(5,1) DEFAULT NULL,
  `course_spec_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `program_course_plan_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_plo_course_matrix` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `plo_id` int NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `level_mark` enum('I','P','M') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_plo_course` (`program_id`, `plo_id`, `course_code`),
  CONSTRAINT `program_plo_course_matrix_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE,
  CONSTRAINT `program_plo_course_matrix_plo_fk` FOREIGN KEY (`plo_id`) REFERENCES `program_learning_outcomes` (`plo_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_plo_course_mapping` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `course_plan_id` int NOT NULL,
  `plo_id` int NOT NULL,
  `performance_level` enum('I','P','M') NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_program_course_plo` (`program_id`, `course_plan_id`, `plo_id`),
  CONSTRAINT `program_plo_mapping_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE,
  CONSTRAINT `program_plo_mapping_course_plan_fk` FOREIGN KEY (`course_plan_id`) REFERENCES `program_course_plan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `program_plo_mapping_plo_fk` FOREIGN KEY (`plo_id`) REFERENCES `program_learning_outcomes` (`plo_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_plo_methods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `plo_id` int NOT NULL,
  `teaching_strategies` text,
  `assessment_methods` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_plo` (`program_id`, `plo_id`),
  CONSTRAINT `program_plo_methods_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE,
  CONSTRAINT `program_plo_methods_plo_fk` FOREIGN KEY (`plo_id`) REFERENCES `program_learning_outcomes` (`plo_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_staffing` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `academic_rank` varchar(100) DEFAULT NULL,
  `specialty` varchar(150) DEFAULT NULL,
  `special_requirements` text,
  `male_count` int DEFAULT NULL,
  `female_count` int DEFAULT NULL,
  `total_count` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `program_staffing_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_evaluation_matrix` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `evaluation_area` varchar(200) DEFAULT NULL,
  `evaluation_sources` text,
  `evaluation_methods` text,
  `evaluation_time` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `program_evaluation_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `program_approval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `program_id` int NOT NULL,
  `council_committee` varchar(200) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_id` (`program_id`),
  CONSTRAINT `program_approval_program_fk` FOREIGN KEY (`program_id`) REFERENCES `program_specs` (`program_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE program_specs SET
  program_code = 'SWE',
  program_name = 'Software Engineering',
  department = 'Computer Engineering Department',
  college = 'College of Engineering',
  institution = 'Al Yamamah University',
  qualification_level = 'Undergraduate'
WHERE program_id = 1;

INSERT INTO program_specs (program_id, program_code, program_name, department, college, institution, qualification_level) VALUES
(2, 'ACC', 'Accounting', 'Accounting & Finance Department', 'College of Business', 'Al Yamamah University', 'Undergraduate'),
(3, 'FIN', 'Finance', 'Accounting & Finance Department', 'College of Business', 'Al Yamamah University', 'Undergraduate'),
(4, 'MGT', 'Management', 'Management and Marketing Department', 'College of Business', 'Al Yamamah University', 'Undergraduate'),
(5, 'MKT', 'Marketing', 'Management and Marketing Department', 'College of Business', 'Al Yamamah University', 'Undergraduate'),
(6, 'MIS', 'Management Information Systems', 'Management Information Systems', 'College of Business', 'Al Yamamah University', 'Undergraduate'),
(7, 'AIA', 'Architecture', 'Architecture Department', 'College of Engineering', 'Al Yamamah University', 'Undergraduate'),
(8, 'CNE', 'Computer Network Engineering', 'Computer Engineering Department', 'College of Engineering', 'Al Yamamah University', 'Undergraduate'),
(9, 'IE', 'Industrial Engineering', 'Industrial Engineering Department', 'College of Engineering', 'Al Yamamah University', 'Undergraduate'),
(10, 'LL.B', 'Bachelor of Law', 'Law Department', 'College of Law', 'Al Yamamah University', 'Undergraduate'),
(11, 'MCS', 'Master in Cyber Security', 'Computer Engineering Department', 'College of Engineering', 'Al Yamamah University', 'Postgraduate'),
(12, 'MBA', 'Masters of Business Administration (MBA)', 'Management and Marketing Department', 'College of Business', 'Al Yamamah University', 'Postgraduate'),
(13, 'EMBA', 'Executive Masters of Business Administration (EMBA)', 'Management and Marketing Department', 'College of Business', 'Al Yamamah University', 'Postgraduate'),
(14, 'MBL', 'Masters of Business Law', 'Law Department', 'College of Law', 'Al Yamamah University', 'Postgraduate')
ON DUPLICATE KEY UPDATE
  program_code = VALUES(program_code),
  program_name = VALUES(program_name),
  department = VALUES(department),
  college = VALUES(college),
  institution = VALUES(institution),
  qualification_level = VALUES(qualification_level);

INSERT INTO program_learning_outcomes (program_id, plo_code, description, category)
SELECT p.program_id, x.plo_code, x.description, x.category
FROM program_specs p
JOIN (
  SELECT 'K1' AS plo_code, 'Program learning outcome K1' AS description, 'Knowledge and Understanding' AS category
  UNION ALL SELECT 'K2', 'Program learning outcome K2', 'Knowledge and Understanding'
  UNION ALL SELECT 'K3', 'Program learning outcome K3', 'Knowledge and Understanding'
  UNION ALL SELECT 'S1', 'Program learning outcome S1', 'Skills'
  UNION ALL SELECT 'S2', 'Program learning outcome S2', 'Skills'
  UNION ALL SELECT 'S3', 'Program learning outcome S3', 'Skills'
  UNION ALL SELECT 'V1', 'Program learning outcome V1', 'Values, Autonomy, and Responsibility'
  UNION ALL SELECT 'V2', 'Program learning outcome V2', 'Values, Autonomy, and Responsibility'
) x
WHERE NOT EXISTS (
  SELECT 1 FROM program_learning_outcomes existing
  WHERE existing.program_id = p.program_id AND existing.plo_code = x.plo_code
);

DELIMITER $$

DROP TRIGGER IF EXISTS aqms_assessment_fill_details$$
CREATE TRIGGER aqms_assessment_fill_details
BEFORE INSERT ON assessments
FOR EACH ROW
BEGIN
  IF NEW.assessment_timing IS NULL OR NEW.assessment_timing = '' THEN
    SET NEW.assessment_timing = IF(NEW.timing_week IS NULL, 'Not specified', CONCAT('Week ', NEW.timing_week));
  END IF;
  IF NEW.proportion_of_total IS NULL THEN
    SET NEW.proportion_of_total = NEW.percentage;
  END IF;
  IF NEW.rubric IS NULL OR TRIM(NEW.rubric) = '' THEN
    SET NEW.rubric = CONCAT('Rubric criteria for ', NEW.activity_name);
  END IF;
  IF NEW.performance_task IS NULL OR TRIM(NEW.performance_task) = '' THEN
    SET NEW.performance_task = CONCAT('Performance task for ', NEW.activity_name);
  END IF;
END$$

DROP TRIGGER IF EXISTS aqms_assessment_keep_details$$
CREATE TRIGGER aqms_assessment_keep_details
BEFORE UPDATE ON assessments
FOR EACH ROW
BEGIN
  IF NEW.assessment_timing IS NULL OR NEW.assessment_timing = '' THEN
    SET NEW.assessment_timing = IF(NEW.timing_week IS NULL, OLD.assessment_timing, CONCAT('Week ', NEW.timing_week));
  END IF;
  IF NEW.proportion_of_total IS NULL THEN
    SET NEW.proportion_of_total = NEW.percentage;
  END IF;
  IF NEW.rubric IS NULL OR TRIM(NEW.rubric) = '' THEN
    SET NEW.rubric = CONCAT('Rubric criteria for ', NEW.activity_name);
  END IF;
  IF NEW.performance_task IS NULL OR TRIM(NEW.performance_task) = '' THEN
    SET NEW.performance_task = CONCAT('Performance task for ', NEW.activity_name);
  END IF;
END$$

DROP TRIGGER IF EXISTS aqms_course_status_gate$$
CREATE TRIGGER aqms_course_status_gate
BEFORE UPDATE ON course_specs
FOR EACH ROW
BEGIN
  DECLARE clo_count INT DEFAULT 0;
  DECLARE unmapped_count INT DEFAULT 0;
  DECLARE assessment_count INT DEFAULT 0;
  DECLARE unlinked_assessment_count INT DEFAULT 0;
  DECLARE bad_assessment_count INT DEFAULT 0;
  DECLARE assessment_total DECIMAL(8,2) DEFAULT 0;
  DECLARE resource_count INT DEFAULT 0;
  DECLARE pdca_count INT DEFAULT 0;

  IF NEW.status <> OLD.status THEN
    IF NEW.status = 'pending_hod' AND OLD.status NOT IN ('draft','returned_by_hod','returned_by_qa') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid workflow: faculty can only submit draft or returned courses to HoD.';
    END IF;
    IF NEW.status = 'pending_qa' AND OLD.status <> 'pending_hod' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid workflow: HoD approval must happen before QA review.';
    END IF;
    IF NEW.status = 'approved' AND OLD.status <> 'pending_qa' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid workflow: QA can only approve after HoD sign-off.';
    END IF;
    IF NEW.status IN ('pending_hod','pending_qa','approved') THEN
      IF NEW.course_title IS NULL OR TRIM(NEW.course_title) = '' OR NEW.course_code IS NULL OR TRIM(NEW.course_code) = '' OR NEW.program_id IS NULL OR NEW.credit_hours IS NULL OR NEW.course_level IS NULL OR NEW.course_description IS NULL OR TRIM(NEW.course_description) = '' OR NEW.objectives IS NULL OR TRIM(NEW.objectives) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing mandatory course identification or objectives.';
      END IF;
      SELECT COUNT(*) INTO clo_count FROM course_learning_outcomes WHERE course_id = NEW.course_id;
      IF clo_count = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At least one CLO is required before approval.';
      END IF;
      SELECT COUNT(*) INTO unmapped_count FROM course_learning_outcomes clo WHERE clo.course_id = NEW.course_id AND NOT EXISTS (SELECT 1 FROM clo_plo_mapping m WHERE m.clo_id = clo.clo_id);
      IF unmapped_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every CLO must map to at least one PLO.';
      END IF;
      SELECT COUNT(*) INTO assessment_count FROM assessments WHERE course_id = NEW.course_id;
      IF assessment_count = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At least one assessment is required.';
      END IF;
      SELECT COUNT(*) INTO unlinked_assessment_count FROM assessments a WHERE a.course_id = NEW.course_id AND NOT EXISTS (SELECT 1 FROM assessment_clo ac WHERE ac.assessment_id = a.id);
      IF unlinked_assessment_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every assessment must link to at least one CLO.';
      END IF;
      SELECT COUNT(*) INTO bad_assessment_count FROM assessments WHERE course_id = NEW.course_id AND (timing_week IS NULL OR percentage IS NULL OR assessment_timing IS NULL OR assessment_timing = '' OR proportion_of_total IS NULL OR rubric IS NULL OR TRIM(rubric) = '' OR performance_task IS NULL OR TRIM(performance_task) = '');
      IF bad_assessment_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every assessment needs timing, weight, rubric, performance task, and CLO link.';
      END IF;
      SELECT IFNULL(SUM(proportion_of_total), IFNULL(SUM(percentage),0)) INTO assessment_total FROM assessments WHERE course_id = NEW.course_id;
      IF assessment_total < 99.99 OR assessment_total > 100.01 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assessment proportions must total 100 percent.';
      END IF;
      SELECT COUNT(*) INTO resource_count FROM resources WHERE course_id = NEW.course_id AND TRIM(resource_text) <> '';
      IF resource_count = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At least one learning resource is required.';
      END IF;
      SELECT COUNT(*) INTO pdca_count FROM course_pdca WHERE course_id = NEW.course_id AND ((content IS NOT NULL AND TRIM(content) <> '') OR (description IS NOT NULL AND TRIM(description) <> ''));
      IF pdca_count = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At least one PDCA improvement entry is required.';
      END IF;
    END IF;
  END IF;
END$$

DELIMITER ;

UPDATE assessments
SET assessment_timing = IF(assessment_timing IS NULL OR assessment_timing = '', IF(timing_week IS NULL, 'Not specified', CONCAT('Week ', timing_week)), assessment_timing),
    proportion_of_total = IF(proportion_of_total IS NULL, percentage, proportion_of_total),
    rubric = IF(rubric IS NULL OR TRIM(rubric) = '', CONCAT('Rubric criteria for ', activity_name), rubric),
    performance_task = IF(performance_task IS NULL OR TRIM(performance_task) = '', CONCAT('Performance task for ', activity_name), performance_task);

-- =========================================================
-- EXPLAINABLE QUALITY GATE
-- Red blocks incomplete records, amber routes exceptions to QA,
-- and green records may be auto-cleared after HoD sign-off.
-- =========================================================

ALTER TABLE `course_specs`
  ADD COLUMN `quality_gate_status` varchar(20) NOT NULL DEFAULT 'not_run',
  ADD COLUMN `quality_gate_score` decimal(5,2) DEFAULT NULL,
  ADD COLUMN `quality_gate_run_id` bigint unsigned DEFAULT NULL,
  ADD COLUMN `qa_sample_required` tinyint(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `quality_gate_runs` (
  `run_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `triggered_by` int DEFAULT NULL,
  `result` varchar(20) NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `summary` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`run_id`),
  KEY `quality_gate_course_idx` (`course_id`, `created_at`),
  CONSTRAINT `quality_gate_runs_course_fk` FOREIGN KEY (`course_id`) REFERENCES `course_specs` (`course_id`) ON DELETE CASCADE,
  CONSTRAINT `quality_gate_runs_user_fk` FOREIGN KEY (`triggered_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `quality_gate_results` (
  `result_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `check_code` varchar(80) NOT NULL,
  `severity` varchar(20) NOT NULL,
  `title` varchar(150) NOT NULL,
  `detail` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`result_id`),
  KEY `quality_gate_results_run_idx` (`run_id`),
  CONSTRAINT `quality_gate_results_run_fk` FOREIGN KEY (`run_id`) REFERENCES `quality_gate_runs` (`run_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
