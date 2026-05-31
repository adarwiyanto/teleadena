-- Patch Telegram Adena: semua topik + analisa gambar/foto struk
-- Jalankan sekali melalui phpMyAdmin / MySQL client setelah backup database.
-- Bersifat append-only: tidak menghapus kolom/data lama.

CREATE TABLE IF NOT EXISTS `telegram_topics` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_chat_id` bigint(20) NOT NULL,
  `message_thread_id` bigint(20) NOT NULL DEFAULT 0,
  `topic_name` varchar(255) DEFAULT NULL,
  `first_seen_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chat_thread` (`telegram_chat_id`, `message_thread_id`),
  KEY `idx_topic_name` (`topic_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `telegram_messages`
  ADD COLUMN IF NOT EXISTS `message_thread_id` bigint(20) DEFAULT NULL AFTER `telegram_message_id`,
  ADD COLUMN IF NOT EXISTS `topic_name` varchar(255) DEFAULT NULL AFTER `message_thread_id`,
  ADD COLUMN IF NOT EXISTS `media_file_id` varchar(255) DEFAULT NULL AFTER `message_type`,
  ADD COLUMN IF NOT EXISTS `media_file_unique_id` varchar(255) DEFAULT NULL AFTER `media_file_id`,
  ADD COLUMN IF NOT EXISTS `media_file_path` varchar(500) DEFAULT NULL AFTER `media_file_unique_id`,
  ADD COLUMN IF NOT EXISTS `media_local_path` varchar(500) DEFAULT NULL AFTER `media_file_path`,
  ADD COLUMN IF NOT EXISTS `media_public_url` varchar(500) DEFAULT NULL AFTER `media_local_path`,
  ADD COLUMN IF NOT EXISTS `image_analysis_text` longtext DEFAULT NULL AFTER `media_public_url`,
  ADD COLUMN IF NOT EXISTS `image_analysis_status` varchar(50) DEFAULT NULL AFTER `image_analysis_text`,
  ADD COLUMN IF NOT EXISTS `image_analysis_error` text DEFAULT NULL AFTER `image_analysis_status`,
  ADD COLUMN IF NOT EXISTS `analyzed_at` datetime DEFAULT NULL AFTER `image_analysis_error`;

ALTER TABLE `telegram_messages`
  ADD KEY IF NOT EXISTS `idx_chat_thread_date` (`telegram_chat_id`, `message_thread_id`, `message_date`),
  ADD KEY IF NOT EXISTS `idx_message_type` (`message_type`),
  ADD KEY IF NOT EXISTS `idx_image_analysis_status` (`image_analysis_status`);
