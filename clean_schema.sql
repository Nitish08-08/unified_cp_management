-- Clean Database Schema for Unified CP Management (TiDB Cloud / MySQL)
CREATE DATABASE IF NOT EXISTS `competitive_cp`;
USE `competitive_cp`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
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
