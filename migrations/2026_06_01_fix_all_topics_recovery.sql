-- Patch: fix all Telegram forum topics + safer message recovery.
-- Jalankan sekali via phpMyAdmin/cPanel sebelum menjalankan import log.

START TRANSACTION;

UPDATE telegram_messages
SET message_thread_id = 0
WHERE message_thread_id IS NULL;

-- Hapus duplikat pesan yang sama agar unique index aman dibuat.
DELETE m1 FROM telegram_messages m1
INNER JOIN telegram_messages m2
    ON m1.telegram_chat_id = m2.telegram_chat_id
   AND m1.telegram_message_id = m2.telegram_message_id
   AND COALESCE(m1.message_thread_id,0) = COALESCE(m2.message_thread_id,0)
   AND m1.id > m2.id
WHERE m1.telegram_message_id IS NOT NULL;

ALTER TABLE telegram_messages
    MODIFY message_thread_id bigint(20) NOT NULL DEFAULT 0;

-- Index tambahan untuk dedup dan filter dashboard. Idempotent untuk MariaDB/MySQL.
SET @idx_exists := (
    SELECT COUNT(1)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'telegram_messages'
      AND INDEX_NAME = 'idx_unique_chat_message'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE telegram_messages ADD UNIQUE KEY idx_unique_chat_message (telegram_chat_id, telegram_message_id)',
    'SELECT "idx_unique_chat_message already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'telegram_messages'
      AND INDEX_NAME = 'idx_update_id'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE telegram_messages ADD KEY idx_update_id (telegram_update_id)',
    'SELECT "idx_update_id already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
