<?php

// tools/backfill_topics_from_raw_payload.php
// Jalankan setelah migrasi SQL untuk mengisi message_thread_id, topic_name, dan metadata foto dari raw_payload lama.
// CLI: php tools/backfill_topics_from_raw_payload.php
// Browser: /tools/backfill_topics_from_raw_payload.php?token=CRON_ACCESS_TOKEN&limit=1000

require_once __DIR__ . '/../config.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../auth.php';
    requireCronToken();
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

try {
    $pdo = db();
    $limit = $isCli ? 5000 : (int)($_GET['limit'] ?? 1000);
    if ($limit <= 0 || $limit > 20000) $limit = 1000;

    $stmt = $pdo->prepare("\n        SELECT id, telegram_chat_id, telegram_message_id, message_text, message_type, message_date, raw_payload\n        FROM telegram_messages\n        WHERE raw_payload IS NOT NULL\n          AND (topic_name IS NULL OR media_file_id IS NULL OR message_thread_id IS NULL)\n        ORDER BY id ASC\n        LIMIT " . (int)$limit . "\n    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $updated = 0; $topics = 0; $photos = 0; $skipped = 0;

    foreach ($rows as $row) {
        $payload = json_decode($row['raw_payload'], true);
        if (!is_array($payload)) { $skipped++; continue; }

        $message = $payload['message'] ?? $payload['edited_message'] ?? $payload['channel_post'] ?? null;
        if (!$message || !is_array($message)) { $skipped++; continue; }

        $chatId = $message['chat']['id'] ?? $row['telegram_chat_id'];
        $threadId = $message['message_thread_id'] ?? null;
        $threadForTopic = $threadId ?: 0;
        $topicName = detectTopicName($message) ?: getKnownTopicName($pdo, $chatId, $threadForTopic);
        if (!$topicName) $topicName = $threadForTopic === 0 ? 'General / Topik Umum' : 'Topic #' . $threadForTopic;

        $messageType = detectMessageType($message, $row['message_type']);
        $photo = extractLargestPhoto($message);
        $mediaFileId = $photo['file_id'] ?? null;
        $mediaFileUniqueId = $photo['file_unique_id'] ?? null;

        $messageText = $row['message_text'];
        if (($messageText === null || trim((string)$messageText) === '') && $messageType === 'photo') {
            $messageText = '[photo]';
        }

        upsertTopic($pdo, $chatId, $threadForTopic, $topicName, $row['message_date']);
        $topics++;
        if ($mediaFileId) $photos++;

        $detectedCategory = detectCategory($messageText);

        $upd = $pdo->prepare("\n            UPDATE telegram_messages\n            SET message_thread_id = :message_thread_id,\n                topic_name = :topic_name,\n                message_type = :message_type,\n                message_text = :message_text,\n                media_file_id = COALESCE(media_file_id, :media_file_id),\n                media_file_unique_id = COALESCE(media_file_unique_id, :media_file_unique_id),\n                detected_category = COALESCE(detected_category, :detected_category)\n            WHERE id = :id\n        ");
        $upd->execute([
            ':message_thread_id' => $threadId,
            ':topic_name' => $topicName,
            ':message_type' => $messageType,
            ':message_text' => $messageText,
            ':media_file_id' => $mediaFileId,
            ':media_file_unique_id' => $mediaFileUniqueId,
            ':detected_category' => $detectedCategory,
            ':id' => $row['id'],
        ]);
        $updated++;
    }

    echo "Backfill selesai.\n";
    echo "Rows dibaca: " . count($rows) . "\n";
    echo "Rows diupdate: {$updated}\n";
    echo "Topic upsert: {$topics}\n";
    echo "Foto terdeteksi: {$photos}\n";
    echo "Skip: {$skipped}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

function detectTopicName(array $message)
{
    if (!empty($message['forum_topic_created']['name'])) return $message['forum_topic_created']['name'];
    if (!empty($message['reply_to_message']['forum_topic_created']['name'])) return $message['reply_to_message']['forum_topic_created']['name'];
    if (!empty($message['reply_to_message']['reply_to_message']['forum_topic_created']['name'])) return $message['reply_to_message']['reply_to_message']['forum_topic_created']['name'];
    return null;
}

function detectMessageType(array $message, $fallback = 'text')
{
    if (isset($message['photo'])) return 'photo';
    if (isset($message['video'])) return 'video';
    if (isset($message['document'])) return 'document';
    if (isset($message['voice'])) return 'voice';
    if (isset($message['sticker'])) return 'sticker';
    if (isset($message['forum_topic_created'])) return 'topic_created';
    if (isset($message['text']) || isset($message['caption'])) return 'text';
    return $fallback ?: 'other';
}

function extractLargestPhoto(array $message)
{
    if (empty($message['photo']) || !is_array($message['photo'])) return null;
    $photos = $message['photo'];
    usort($photos, fn($a, $b) => (($b['file_size'] ?? 0) <=> ($a['file_size'] ?? 0)));
    return $photos[0] ?? null;
}

function getKnownTopicName(PDO $pdo, $chatId, $threadId)
{
    $stmt = $pdo->prepare("SELECT topic_name FROM telegram_topics WHERE telegram_chat_id=:chat_id AND message_thread_id=:thread_id LIMIT 1");
    $stmt->execute([':chat_id'=>$chatId, ':thread_id'=>$threadId ?: 0]);
    $row = $stmt->fetch();
    return $row['topic_name'] ?? null;
}

function upsertTopic(PDO $pdo, $chatId, $threadId, $topicName, $seenAt)
{
    $stmt = $pdo->prepare("\n        INSERT INTO telegram_topics (telegram_chat_id, message_thread_id, topic_name, first_seen_at, last_seen_at)\n        VALUES (:chat_id, :thread_id, :topic_name, :seen_at, :seen_at)\n        ON DUPLICATE KEY UPDATE\n            topic_name = VALUES(topic_name),\n            last_seen_at = VALUES(last_seen_at),\n            updated_at = CURRENT_TIMESTAMP\n    ");
    $stmt->execute([':chat_id'=>$chatId, ':thread_id'=>$threadId ?: 0, ':topic_name'=>$topicName, ':seen_at'=>$seenAt ?: date('Y-m-d H:i:s')]);
}

function detectCategory($text)
{
    if (!$text) return null;
    $lower = mb_strtolower($text, 'UTF-8');
    $categories = [
        'omset' => ['omset','revenue','pendapatan','total jual','penjualan hari ini','total','subtotal'],
        'order' => ['order','pesanan','booking','pesan','beli','checkout','customer','item'],
        'komplain' => ['komplain','complain','keluhan','kecewa','marah','refund','retur'],
        'stok' => ['stok','stock','habis','ready','kosong','restock','produksi'],
        'pembayaran' => ['transfer','qris','cash','tunai','bayar','payment','lunas','utang'],
        'pengiriman' => ['kirim','delivery','gojek','grab','kurir','ongkir'],
        'news' => ['info','berita','pengumuman','urgent','penting'],
    ];
    foreach($categories as $cat=>$words) foreach($words as $w) if(mb_strpos($lower,$w)!==false) return $cat;
    return 'umum';
}
