-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 22, 2025 at 12:10 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u850093904_smartcontent`
--

-- --------------------------------------------------------

--
-- Table structure for table `articles`
--

CREATE TABLE `articles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `content` longtext NOT NULL,
  `source_keyword` varchar(255) DEFAULT NULL,
  `source_url` varchar(1000) DEFAULT NULL,
  `word_count` int(11) DEFAULT 0,
  `seo_score` int(11) DEFAULT NULL,
  `status` enum('Generating','Generated','Draft','Publishing','Published','Error') DEFAULT 'Draft',
  `generated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_outputs`
--

CREATE TABLE `content_outputs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('Ad','Social','Email','Idea') NOT NULL,
  `source_url` varchar(1000) DEFAULT NULL,
  `source_topic` varchar(255) DEFAULT NULL,
  `cta_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cta_info`)),
  `content` text NOT NULL,
  `status` enum('Idea','Planned','Generated') DEFAULT 'Idea',
  `scheduled_for` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `licenses`
--

CREATE TABLE `licenses` (
  `id` int(11) NOT NULL,
  `license_key` varchar(255) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('NotActivated','Active','Expired') NOT NULL DEFAULT 'NotActivated',
  `activated_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `licenses`
--

INSERT INTO `licenses` (`id`, `license_key`, `plan_id`, `user_id`, `status`, `activated_at`, `expires_at`, `created_at`) VALUES
(2, 'SCAI-SUPERADMIN-DO-NOT-DELETE', 999, 2, 'Active', '2025-10-19 09:11:11', NULL, '2025-10-19 09:11:11'),
(6, 'SCAI-35KV-77N7-H39D-58CF', 1000, 3, 'Active', '2025-10-20 08:53:08', NULL, '2025-10-20 05:07:45'),
(12, 'SCAI-VE5A-8V99-5SEC-LBG6', 1002, NULL, 'NotActivated', NULL, NULL, '2025-10-20 14:13:52'),
(15, 'SCAI-JBED-Z7PT-JRWJ-4TNF', 1002, NULL, 'NotActivated', NULL, NULL, '2025-10-21 05:01:36'),
(16, 'SCAI-4YNU-CJ88-TSCB-TLPJ', 1002, 6, 'Active', NULL, NULL, '2025-10-21 05:36:49'),
(17, 'SCAI-JLKQ-AQTH-BBPD-3NNW', 1002, NULL, 'NotActivated', NULL, NULL, '2025-10-21 05:44:36');

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('Tháng','Năm','Trọn đời') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `article_limit` int(11) NOT NULL,
  `feature_bulk_write` tinyint(1) DEFAULT 1,
  `feature_rewrite_url` tinyint(1) DEFAULT 1,
  `feature_analyze_competitor` tinyint(1) DEFAULT 1,
  `feature_custom_prompt` tinyint(1) DEFAULT 1,
  `feature_keyword_research` tinyint(1) DEFAULT 0,
  `feature_content_assistant` tinyint(1) DEFAULT 0,
  `feature_content_calendar` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `name`, `type`, `price`, `article_limit`, `feature_bulk_write`, `feature_rewrite_url`, `feature_analyze_competitor`, `feature_custom_prompt`, `feature_keyword_research`, `feature_content_assistant`, `feature_content_calendar`, `created_at`) VALUES
(999, 'Super Admin Plan', 'Trọn đời', 0.00, -1, 1, 1, 1, 1, 1, 1, 1, '2025-10-19 09:10:33'),
(1000, 'Trọn đời - Không giới hạn', 'Trọn đời', 9999000.00, -1, 1, 1, 1, 1, 0, 0, 0, '2025-10-20 03:06:11'),
(1001, 'Hàng Tháng', 'Tháng', 299000.00, 100, 1, 1, 1, 1, 0, 0, 0, '2025-10-20 03:08:30'),
(1002, 'Hàng Năm', 'Năm', 2999000.00, 1500, 1, 1, 1, 1, 0, 0, 0, '2025-10-20 03:08:52'),
(1004, 'test2', 'Tháng', 12.00, 3123, 1, 1, 1, 1, 0, 0, 0, '2025-10-21 06:20:47');

-- --------------------------------------------------------

--
-- Table structure for table `saved_keywords`
--

CREATE TABLE `saved_keywords` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `competition` enum('Low','Medium','High') DEFAULT NULL,
  `difficulty` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('User','Admin') NOT NULL DEFAULT 'User',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `phone`, `role`, `status`, `created_at`, `updated_at`) VALUES
(2, 'SPAdmin', 'thienphuc1988@gmail.com', '$2y$10$BcHBOI1Cgl8zfD3tdAn//.of.CSbQYBItsyPGRUWyJrAd.BUN/GAK', '943599239', 'Admin', 'Active', '2025-10-19 04:03:37', '2025-10-19 06:15:53'),
(3, 'Thanh Thao', 'cmthanhthao@gmail.com', '$2y$10$SFcCrry/puYxnkGr7.RLjOxcaBUHn6ypBKc9kQaHGGJ0wgHCSNRWa', '0933599239', 'User', 'Active', '2025-10-20 02:38:29', '2025-10-20 06:10:46'),
(5, 'test', '123', '$2y$10$u65ovAih2B7WSb.kLcSlXuQJXiceV/LQFqKItbcIIs4yG1QP8vO9C', '3123', 'User', 'Active', '2025-10-21 04:39:45', '2025-10-21 04:48:58'),
(6, '123', '1233', '$2y$10$chpTzcmFKt2gOjTTof0W3.Kufw5E7l9.PuNrLcH6WUIQNtvDPS47m', '123', 'User', 'Active', '2025-10-21 04:46:49', '2025-10-21 04:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `ai_provider` enum('gemini','openai') DEFAULT 'gemini',
  `gemini_api_key` text DEFAULT NULL,
  `gemini_model` varchar(100) DEFAULT NULL,
  `openai_api_key` text DEFAULT NULL,
  `openai_model` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`user_id`, `ai_provider`, `gemini_api_key`, `gemini_model`, `openai_api_key`, `openai_model`) VALUES
(2, 'openai', NULL, 'gemini-2.5-flash', 'dRw+QTds62vvRtL6tININgeAyqwr0qu8IPVYaX5wZ6k415/E2vlPjYPYf1LRpgBzJoLHRiniDn2g7EbdhmDyLR78yDbGPKAcoJ6gOVoygmVdiDHRqHFlFPo1y1DBCEXp6XIv44CKW257r0/lVBIJt7cWm52Ny4Mnk/O6FJrVV4Rm1zX2khWXSTGNCTAGhlRXikVlwaBWGU/qgulH1M22MN1GUiH5aMhqjakB8P5RqNRxpY2cxmeaLSbx5UJZTFxH', 'gpt-5');

-- --------------------------------------------------------

--
-- Table structure for table `wordpress_sites`
--

CREATE TABLE `wordpress_sites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `site_url` varchar(255) NOT NULL,
  `wp_username` varchar(255) NOT NULL,
  `wp_application_password` varchar(255) NOT NULL,
  `is_connection_valid` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wordpress_sites`
--

INSERT INTO `wordpress_sites` (`id`, `user_id`, `site_url`, `wp_username`, `wp_application_password`, `is_connection_valid`, `created_at`) VALUES
(5, 2, 'https://test.tnnt.vn', 'HatoriHanzou', '9j23WMoHIXvhA6GnddNFx3QfS1x+3bu8OLBKdzJ3QtUC7a4AUEKIORt4tUfrN2cp', 0, '2025-10-21 10:15:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `articles`
--
ALTER TABLE `articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `content_outputs`
--
ALTER TABLE `content_outputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `licenses`
--
ALTER TABLE `licenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `license_key` (`license_key`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `saved_keywords`
--
ALTER TABLE `saved_keywords`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_keyword` (`user_id`,`keyword`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `wordpress_sites`
--
ALTER TABLE `wordpress_sites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `articles`
--
ALTER TABLE `articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `content_outputs`
--
ALTER TABLE `content_outputs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `licenses`
--
ALTER TABLE `licenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1005;

--
-- AUTO_INCREMENT for table `saved_keywords`
--
ALTER TABLE `saved_keywords`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `wordpress_sites`
--
ALTER TABLE `wordpress_sites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `articles`
--
ALTER TABLE `articles`
  ADD CONSTRAINT `articles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `content_outputs`
--
ALTER TABLE `content_outputs`
  ADD CONSTRAINT `content_outputs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `licenses`
--
ALTER TABLE `licenses`
  ADD CONSTRAINT `licenses_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `licenses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `saved_keywords`
--
ALTER TABLE `saved_keywords`
  ADD CONSTRAINT `saved_keywords_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `wordpress_sites`
--
ALTER TABLE `wordpress_sites`
  ADD CONSTRAINT `wordpress_sites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
