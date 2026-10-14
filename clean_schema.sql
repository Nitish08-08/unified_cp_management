-- Clean Database Schema for Unified CP Management (TiDB Cloud / MySQL)
CREATE DATABASE IF NOT EXISTS `competitive_cp`;
USE `competitive_cp`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'student',
  `join_date` date DEFAULT CURRENT_DATE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Platforms Table
CREATE TABLE IF NOT EXISTS `platforms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_name` (`platform_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Problems Table
CREATE TABLE IF NOT EXISTS `problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_id` int(11) NOT NULL,
  `problem_code` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `difficulty_level` varchar(50) DEFAULT 'Easy',
  `topic` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_plat_problem` (`platform_id`, `problem_code`),
  CONSTRAINT `fk_prob_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. User Platform Profiles
CREATE TABLE IF NOT EXISTS `user_platform_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `platform_id` int(11) NOT NULL,
  `handle` varchar(100) NOT NULL,
  `rank_title` varchar(100) DEFAULT NULL,
  `current_rating` int(11) DEFAULT NULL,
  `max_rating` int(11) DEFAULT NULL,
  `problems_solved` int(11) DEFAULT 0,
  `last_sync_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_platform` (`user_id`, `platform_id`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_upp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upp_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Contests
CREATE TABLE IF NOT EXISTS `contests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_c_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Contest Performance
CREATE TABLE IF NOT EXISTS `contest_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `contest_id` int(11) NOT NULL,
  `contest_rank` int(11) DEFAULT NULL,
  `rating_change` int(11) DEFAULT NULL,
  `new_rating` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_profile_contest` (`profile_id`, `contest_id`),
  KEY `contest_id` (`contest_id`),
  CONSTRAINT `fk_cp_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_platform_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_contest` FOREIGN KEY (`contest_id`) REFERENCES `contests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. User Solved Problems
CREATE TABLE IF NOT EXISTS `user_solved_problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `platform_id` int(11) NOT NULL,
  `problem_code` varchar(100) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `difficulty_level` varchar(50) DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `solved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_plat_prob` (`user_id`, `platform_id`, `problem_code`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_usp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usp_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Aggregated Stats
CREATE TABLE IF NOT EXISTS `aggregated_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_problems_solved` int(11) DEFAULT 0,
  `total_contests` int(11) DEFAULT 0,
  `highest_platform_id` int(11) DEFAULT NULL,
  `consistency_score` decimal(6,2) DEFAULT 0.00,
  `problem_solving_score` decimal(6,2) DEFAULT 0.00,
  `composite_score` decimal(6,2) DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_as_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Platform Entries
INSERT INTO `platforms` (`id`, `platform_name`, `base_url`) VALUES
(1, 'Codeforces', 'https://codeforces.com'),
(2, 'LeetCode', 'https://leetcode.com'),
(3, 'CodeChef', 'https://www.codechef.com')
ON DUPLICATE KEY UPDATE `base_url`=VALUES(`base_url`);

-- 9. Mentor-Student Assignments
CREATE TABLE IF NOT EXISTS `mentor_student_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mentor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
  `invited_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_mentor_student` (`mentor_id`, `student_id`),
  KEY `idx_mentor` (`mentor_id`),
  KEY `idx_student` (`student_id`),
  CONSTRAINT `fk_msa_mentor` FOREIGN KEY (`mentor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msa_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Mentor Notes
CREATE TABLE IF NOT EXISTS `mentor_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mentor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `note_text` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mn_mentor_student` (`mentor_id`, `student_id`),
  CONSTRAINT `fk_mn_mentor` FOREIGN KEY (`mentor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mn_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Mentor Goals
CREATE TABLE IF NOT EXISTS `mentor_goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mentor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `goal_title` varchar(255) NOT NULL,
  `target_problems` int(11) DEFAULT NULL,
  `target_rating` int(11) DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `status` enum('pending', 'completed') NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mg_mentor_student` (`mentor_id`, `student_id`),
  CONSTRAINT `fk_mg_mentor` FOREIGN KEY (`mentor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mg_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. AI Conversations
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'New Conversation',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ac_user` (`user_id`),
  CONSTRAINT `fk_ac_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. AI Messages
CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `role` enum('user', 'assistant', 'system') NOT NULL,
  `content` mediumtext NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_am_conv` (`conversation_id`),
  CONSTRAINT `fk_am_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. AI Practice Plans
CREATE TABLE IF NOT EXISTS `ai_practice_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 7,
  `objective` text DEFAULT NULL,
  `status` enum('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app_user` (`user_id`),
  CONSTRAINT `fk_app_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. AI Plan Tasks
CREATE TABLE IF NOT EXISTS `ai_plan_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `day_number` int(11) NOT NULL DEFAULT 1,
  `topic` varchar(100) DEFAULT NULL,
  `task_description` text NOT NULL,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_apt_plan` (`plan_id`),
  CONSTRAINT `fk_apt_plan` FOREIGN KEY (`plan_id`) REFERENCES `ai_practice_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. AI Feedback
CREATE TABLE IF NOT EXISTS `ai_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` enum('up', 'down') NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_af_msg` (`message_id`),
  CONSTRAINT `fk_af_msg` FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_af_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


