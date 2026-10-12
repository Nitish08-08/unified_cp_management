-- Unified Competitive Programming Tracker - Clean Schema & Real Profile Seed
CREATE DATABASE IF NOT EXISTS `competitive_cp`;
USE `competitive_cp`;

-- 1. Tables Creation
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `join_date` date DEFAULT CURRENT_DATE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platforms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_name` (`platform_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  KEY `user_id` (`user_id`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_upp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upp_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_c_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contest_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `contest_id` int(11) NOT NULL,
  `contest_rank` int(11) DEFAULT NULL,
  `rating_change` int(11) DEFAULT NULL,
  `new_rating` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profile_id` (`profile_id`),
  KEY `contest_id` (`contest_id`),
  CONSTRAINT `fk_cp_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_platform_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_contest` FOREIGN KEY (`contest_id`) REFERENCES `contests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  KEY `user_id` (`user_id`),
  KEY `platform_id` (`platform_id`),
  CONSTRAINT `fk_usp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usp_platform` FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aggregated_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_problems_solved` int(11) DEFAULT 0,
  `total_contests` int(11) DEFAULT 0,
  `highest_platform_id` int(11) DEFAULT NULL,
  `composite_score` decimal(6,2) DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_as_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Platforms
INSERT INTO `platforms` (`id`, `platform_name`, `base_url`) VALUES
(1, 'Codeforces', 'https://codeforces.com'),
(2, 'LeetCode', 'https://leetcode.com'),
(3, 'CodeChef', 'https://www.codechef.com')
ON DUPLICATE KEY UPDATE `base_url`=VALUES(`base_url`);

-- 3. Nitish Kumar User (Password: password123)
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `join_date`) VALUES
(1, 'Nitish Kumar', 'nitish@example.com', '$2y$10$lYLr3yixUhP9F.Y1IrZ9te1NvpBeKYr7Q9Wens4ZPCZSa2tQ96YFG', NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 4. Live Platform Profiles
INSERT INTO `user_platform_profiles` (`id`, `user_id`, `platform_id`, `handle`, `rank_title`, `current_rating`, `max_rating`, `problems_solved`, `last_sync_date`) VALUES
(1, 1, 1, 'nitish__0708', 'Newbie', 1135, 1135, 45, NOW()),
(2, 1, 2, 'nitish__0708', 'LC Rank: 392,837', 1648, 1648, 358, NOW()),
(3, 1, 3, 'nitish_0_8', '2★ (Div 3)', 1564, 1564, 52, NOW())
ON DUPLICATE KEY UPDATE `current_rating`=VALUES(`current_rating`), `max_rating`=VALUES(`max_rating`), `problems_solved`=VALUES(`problems_solved`);

-- 5. Contests (Codeforces official rounds + LeetCode / CodeChef rounds)
INSERT INTO `contests` (`id`, `platform_id`, `name`, `start_time`) VALUES
(1, 1, 'Codeforces Round 1121 (Div. 2)', '2026-09-14 17:35:00'),
(2, 1, 'Codeforces Round 1122 (Div. 3)', '2026-09-22 17:50:00'),
(3, 1, 'Codeforces Round 1123 (Div. 2)', '2026-09-26 18:35:00'),
(4, 1, 'Codeforces Round 1125 (Div. 3)', '2026-10-08 17:50:00'),
(5, 2, 'LeetCode Weekly Contest 428', '2026-09-15 08:00:00'),
(6, 2, 'LeetCode Biweekly Contest 146', '2026-09-21 20:00:00'),
(7, 2, 'LeetCode Weekly Contest 429', '2026-09-29 08:00:00'),
(8, 3, 'CodeChef Starters 168 (Div 3)', '2026-09-18 20:00:00'),
(9, 3, 'CodeChef Starters 169 (Div 3)', '2026-09-25 20:00:00'),
(10, 3, 'CodeChef Starters 170 (Div 3)', '2026-10-02 20:00:00')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 6. Contest Performance (Official Rating Delts & Ranks)
INSERT INTO `contest_performance` (`profile_id`, `contest_id`, `contest_rank`, `rating_change`, `new_rating`) VALUES
(1, 1, 4585, 451, 451),
(1, 2, 7252, 298, 749),
(1, 3, 5128, 238, 987),
(1, 4, 4480, 148, 1135),
(2, 5, 3820, 18, 1595),
(2, 6, 4100, 22, 1617),
(2, 7, 3250, 31, 1648),
(3, 8, 1820, 42, 1505),
(3, 9, 1450, 28, 1533),
(3, 10, 1210, 31, 1564);

-- 7. Sample Solved Problems with Algorithmic Topic Tags
INSERT INTO `user_solved_problems` (`user_id`, `platform_id`, `problem_code`, `title`, `difficulty_level`, `topic`, `solved_at`) VALUES
(1, 2, 'LC-300', 'Longest Increasing Subsequence', 'Medium', 'Dynamic Programming, Binary Search', NOW() - INTERVAL 1 DAY),
(1, 2, 'LC-207', 'Course Schedule', 'Medium', 'Graphs, Topological Sort, DFS', NOW() - INTERVAL 2 DAY),
(1, 2, 'LC-42', 'Trapping Rain Water', 'Hard', 'Two Pointers, Stack, Dynamic Programming', NOW() - INTERVAL 3 DAY),
(1, 2, 'LC-146', 'LRU Cache', 'Medium', 'Hash Table, Linked List, Design', NOW() - INTERVAL 4 DAY),
(1, 2, 'LC-124', 'Binary Tree Maximum Path Sum', 'Hard', 'Trees, DFS, Recursion', NOW() - INTERVAL 5 DAY),
(1, 1, '2275A', 'Circuit Design Strategy', '1100', 'Greedy, Bit Manipulation, Math', NOW() - INTERVAL 6 DAY),
(1, 1, '2267B', 'Array Transformation Game', '1200', 'Constructive Algorithms, Sorting', NOW() - INTERVAL 7 DAY),
(1, 1, '2266C', 'Prefix Graph Connectivity', '1300', 'Graphs, DSU, Trees', NOW() - INTERVAL 8 DAY),
(1, 3, 'CC-START170B', 'Subarray Max Sum Operations', '1450', 'Prefix Sums, Greedy', NOW() - INTERVAL 9 DAY),
(1, 3, 'CC-START169C', 'Tree Coloring Sequence', '1520', 'Trees, Dynamic Programming', NOW() - INTERVAL 10 DAY);

-- 8. Aggregated Stats
INSERT INTO `aggregated_stats` (`user_id`, `total_problems_solved`, `total_contests`, `highest_platform_id`, `composite_score`, `last_updated`) VALUES
(1, 455, 10, 2, 72.80, NOW())
ON DUPLICATE KEY UPDATE `total_problems_solved`=VALUES(`total_problems_solved`), `total_contests`=VALUES(`total_contests`), `composite_score`=VALUES(`composite_score`), `last_updated`=NOW();
