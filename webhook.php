<?php

// webhook.php
// Menerima pesan dari Telegram, menyimpan semua topik/forum, dan menganalisa foto/struk jika tersedia.

require_once __DIR__ . '/config.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

$rawData = file_get_contents('php://input');
$update = json_decode($rawData, true);

logText('telegram_log.txt', "==== " . date('Y-m-d H:i:s') . " ====\n" . $rawData . "\n\n");

try {
    if (!$update || !is_array($update)) {
        ok('NO DATA');
    }

    $updateId = $update['update_id'] ?? null;
    $message = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? null;

    if (!$message) {
        ok('NO MESSAGE');
    }

    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];

    $chatId = $chat['id'] ?? null;
    if (!$chatId) {
        ok('NO CHAT ID');
    }

    $chatTitle = $chat['title'] ?? ($chat['first_name'] ?? null);
    $chatType = $chat['type'] ?? null;
    $messageId = $message['message_id'] ?? null;
    $messageThreadId = $message['message_thread_id'] ?? null;
    $messageThreadIdForTopic = $messageThreadId ?: 0;

    $senderId = $from['id'] ?? null;
    $senderName = trim(implode(' ', array_filter([$from['first_name'] ?? null, $from['last_name'] ?? null])));
    $senderUsername = $from['username'] ?? null;

    $captionOrText = $message['text'] ?? ($message['caption'] ?? null);
    $messageType = detectMessageType($message, $captionOrText);
    $topicName = detectTopicName($message);

    $messageDate = !empty($message['date']) ? date('Y-m-d H:i:s', (int)$message['date']) : date('Y-m-d H:i:s');

    $pdo = db();

    if (isDashboardSetupCommand($captionOrText)) {
        handleDashboardSetupCommand($pdo, $chatId, $senderId);
        ok('DASHBOARD SETUP LINK SENT');
    }

    saveGroup($pdo, $chatId, $chatTitle, $chatType);

    if ($topicName === null) {
        $topicName = getKnownTopicName($pdo, $chatId, $messageThreadIdForTopic);
    }
    if ($topicName === null) {
        $topicName = $messageThreadIdForTopic === 0 ? 'General / Topik Umum' : 'Topic #' . $messageThreadIdForTopic;
    }
    upsertTopic($pdo, $chatId, $messageThreadIdForTopic, $topicName, $messageDate);

    $media = extractLargestPhoto($message);
    $mediaFileId = $media['file_id'] ?? null;
    $mediaFileUniqueId = $media['file_unique_id'] ?? null;
    $mediaFilePath = null;
    $mediaLocalPath = null;
    $mediaPublicUrl = null;
    $imageAnalysisText = null;
    $imageAnalysisStatus = null;
    $imageAnalysisError = null;
    $analyzedAt = null;

    if ($messageType === 'photo' && $mediaFileId) {
        try {
            $download = downloadTelegramFile($mediaFileId, $chatId, $messageId);
            $mediaFilePath = $download['telegram_file_path'] ?? null;
            $mediaLocalPath = $download['local_path'] ?? null;
            $mediaPublicUrl = $download['public_url'] ?? null;

            $analysis = analyzeImageIfEnabled($mediaLocalPath, $captionOrText, $chatTitle, $topicName);
            $imageAnalysisText = $analysis['text'] ?? null;
            $imageAnalysisStatus = $analysis['status'] ?? null;
            $imageAnalysisError = $analysis['error'] ?? null;
            $analyzedAt = $analysis['analyzed_at'] ?? null;
        } catch (Throwable $e) {
            $imageAnalysisStatus = 'error';
            $imageAnalysisError = $e->getMessage();
            logText('telegram_error_log.txt', "==== " . date('Y-m-d H:i:s') . " PHOTO ERROR ====\n" . $e->getMessage() . "\n\n");
        }
    }

    $messageText = buildStoredMessageText($captionOrText, $messageType, $imageAnalysisText);
    $detectedCategory = detectCategory($messageText);

    $stmtMsg = $pdo->prepare("\n        INSERT INTO telegram_messages\n            (\n                telegram_update_id, telegram_chat_id, telegram_message_id, message_thread_id, topic_name,\n                sender_id, sender_name, sender_username, message_text, message_date, message_type,\n                media_file_id, media_file_unique_id, media_file_path, media_local_path, media_public_url,\n                image_analysis_text, image_analysis_status, image_analysis_error, analyzed_at,\n                detected_category, raw_payload\n            )\n        VALUES\n            (\n                :telegram_update_id, :telegram_chat_id, :telegram_message_id, :message_thread_id, :topic_name,\n                :sender_id, :sender_name, :sender_username, :message_text, :message_date, :message_type,\n                :media_file_id, :media_file_unique_id, :media_file_path, :media_local_path, :media_public_url,\n                :image_analysis_text, :image_analysis_status, :image_analysis_error, :analyzed_at,\n                :detected_category, :raw_payload\n            )\n    ");

    $stmtMsg->execute([
        ':telegram_update_id' => $updateId,
        ':telegram_chat_id' => $chatId,
        ':telegram_message_id' => $messageId,
        ':message_thread_id' => $messageThreadId,
        ':topic_name' => $topicName,
        ':sender_id' => $senderId,
        ':sender_name' => $senderName,
        ':sender_username' => $senderUsername,
        ':message_text' => $messageText,
        ':message_date' => $messageDate,
        ':message_type' => $messageType,
        ':media_file_id' => $mediaFileId,
        ':media_file_unique_id' => $mediaFileUniqueId,
        ':media_file_path' => $mediaFilePath,
        ':media_local_path' => $mediaLocalPath,
        ':media_public_url' => $mediaPublicUrl,
        ':image_analysis_text' => $imageAnalysisText,
        ':image_analysis_status' => $imageAnalysisStatus,
        ':image_analysis_error' => $imageAnalysisError,
        ':analyzed_at' => $analyzedAt,
        ':detected_category' => $detectedCategory,
        ':raw_payload' => $rawData,
    ]);

    ok('OK');
} catch (Throwable $e) {
    logText('telegram_error_log.txt', "==== " . date('Y-m-d H:i:s') . " ====\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n");
    ok('ERROR LOGGED');
}

function ok($text)
{
    http_response_code(200);
    echo $text;
    exit;
}

function logText($filename, $text)
{
    file_put_contents(__DIR__ . '/' . $filename, $text, FILE_APPEND);
}

function saveGroup(PDO $pdo, $chatId, $chatTitle, $chatType)
{
    $stmt = $pdo->prepare("\n        INSERT INTO telegram_groups (telegram_chat_id, group_name, group_type, is_active)\n        VALUES (:telegram_chat_id, :group_name, :group_type, 1)\n        ON DUPLICATE KEY UPDATE\n            group_name = VALUES(group_name),\n            group_type = VALUES(group_type),\n            updated_at = CURRENT_TIMESTAMP\n    ");
    $stmt->execute([
        ':telegram_chat_id' => $chatId,
        ':group_name' => $chatTitle,
        ':group_type' => $chatType,
    ]);
}

function upsertTopic(PDO $pdo, $chatId, $threadId, $topicName, $seenAt)
{
    $stmt = $pdo->prepare("\n        INSERT INTO telegram_topics (telegram_chat_id, message_thread_id, topic_name, first_seen_at, last_seen_at)\n        VALUES (:chat_id, :thread_id, :topic_name, :first_seen_at, :last_seen_at)\n        ON DUPLICATE KEY UPDATE\n            topic_name = VALUES(topic_name),\n            last_seen_at = VALUES(last_seen_at),\n            updated_at = CURRENT_TIMESTAMP\n    ");
    $stmt->execute([
        ':chat_id' => $chatId,
        ':thread_id' => $threadId ?: 0,
        ':topic_name' => $topicName,
        ':first_seen_at' => $seenAt,
        ':last_seen_at' => $seenAt,
    ]);
}

function isDashboardSetupCommand($text)
{
    $text = trim((string)$text);
    return preg_match('/^\/(dashboard_user|dashboard_reset)(@\w+)?(\s|$)/i', $text) === 1;
}

function handleDashboardSetupCommand(PDO $pdo, $chatId, $senderId)
{
    if (defined('TELEGRAM_ADMIN_CHAT_ID') && trim((string)TELEGRAM_ADMIN_CHAT_ID) !== '') {
        $allowed = array_map('trim', explode(',', (string)TELEGRAM_ADMIN_CHAT_ID));
        if (!in_array((string)$chatId, $allowed, true) && !in_array((string)$senderId, $allowed, true)) {
            sendTelegramMessage($chatId, 'Command dashboard hanya untuk admin.');
            return;
        }
    }

    if (!defined('APP_BASE_URL') || trim((string)APP_BASE_URL) === '') {
        sendTelegramMessage($chatId, 'APP_BASE_URL belum diatur di config.php. Isi contoh: https://domain.com/telegram');
        return;
    }

    $token = bin2hex(random_bytes(32));
    $minutes = defined('DASHBOARD_SETUP_TOKEN_EXPIRE_MINUTES') ? (int)DASHBOARD_SETUP_TOKEN_EXPIRE_MINUTES : 30;
    if ($minutes < 5) $minutes = 5;
    $expiresAt = (new DateTime('now'))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("\n        INSERT INTO telegram_dashboard_setup_tokens
            (token, requested_by_chat_id, requested_by_user_id, expires_at)
        VALUES
            (:token, :chat_id, :user_id, :expires_at)
    " );
    $stmt->execute([
        ':token' => $token,
        ':chat_id' => (string)$chatId,
        ':user_id' => $senderId !== null ? (string)$senderId : null,
        ':expires_at' => $expiresAt,
    ]);

    $url = rtrim((string)APP_BASE_URL, '/') . '/setup_user.php?token=' . rawurlencode($token);
    $message = "Link buat/reset user dashboard Telegram Adena:\n" . $url . "\n\nBerlaku {$minutes} menit dan satu kali pakai. Password dibuat di halaman web, tidak dikirim lewat Telegram.";
    sendTelegramMessage($chatId, $message);
}

function getKnownTopicName(PDO $pdo, $chatId, $threadId)
{
    $stmt = $pdo->prepare("\n        SELECT topic_name FROM telegram_topics\n        WHERE telegram_chat_id = :chat_id AND message_thread_id = :thread_id\n        LIMIT 1\n    ");
    $stmt->execute([':chat_id' => $chatId, ':thread_id' => $threadId ?: 0]);
    $row = $stmt->fetch();
    return $row['topic_name'] ?? null;
}

function detectMessageType(array $message, $text)
{
    if (isset($message['photo'])) return 'photo';
    if (isset($message['video'])) return 'video';
    if (isset($message['document'])) return 'document';
    if (isset($message['voice'])) return 'voice';
    if (isset($message['sticker'])) return 'sticker';
    if (isset($message['forum_topic_created'])) return 'topic_created';
    if ($text !== null) return 'text';
    return 'other';
}

function detectTopicName(array $message)
{
    if (!empty($message['forum_topic_created']['name'])) {
        return $message['forum_topic_created']['name'];
    }
    if (!empty($message['reply_to_message']['forum_topic_created']['name'])) {
        return $message['reply_to_message']['forum_topic_created']['name'];
    }
    if (!empty($message['reply_to_message']['reply_to_message']['forum_topic_created']['name'])) {
        return $message['reply_to_message']['reply_to_message']['forum_topic_created']['name'];
    }
    return null;
}

function extractLargestPhoto(array $message)
{
    if (empty($message['photo']) || !is_array($message['photo'])) {
        return null;
    }
    $photos = $message['photo'];
    usort($photos, function ($a, $b) {
        return (($b['file_size'] ?? 0) <=> ($a['file_size'] ?? 0));
    });
    return $photos[0] ?? null;
}

function downloadTelegramFile($fileId, $chatId, $messageId)
{
    if (!defined('TELEGRAM_BOT_TOKEN') || trim(TELEGRAM_BOT_TOKEN) === '') {
        return ['telegram_file_path' => null, 'local_path' => null, 'public_url' => null];
    }

    $token = TELEGRAM_BOT_TOKEN;
    $metaUrl = 'https://api.telegram.org/bot' . $token . '/getFile?file_id=' . urlencode($fileId);
    $meta = jsonRequest($metaUrl);

    if (empty($meta['ok']) || empty($meta['result']['file_path'])) {
        throw new RuntimeException('Gagal mengambil file_path Telegram.');
    }

    $filePath = $meta['result']['file_path'];
    $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
    $subdir = date('Y/m');
    $baseDir = defined('TELEGRAM_UPLOAD_DIR') ? TELEGRAM_UPLOAD_DIR : (__DIR__ . '/uploads/telegram');
    $targetDir = rtrim($baseDir, '/') . '/' . $subdir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        throw new RuntimeException('Folder upload tidak bisa dibuat: ' . $targetDir);
    }

    $safeChat = preg_replace('/[^0-9\-]/', '', (string)$chatId);
    $safeMessage = preg_replace('/[^0-9]/', '', (string)$messageId);
    $localPath = $targetDir . '/' . $safeChat . '_' . $safeMessage . '_' . substr(sha1($fileId), 0, 10) . '.' . $ext;

    $downloadUrl = 'https://api.telegram.org/file/bot' . $token . '/' . $filePath;
    $binary = rawRequest($downloadUrl);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Download file Telegram gagal.');
    }
    file_put_contents($localPath, $binary);

    $publicUrl = null;
    if (defined('TELEGRAM_UPLOAD_URL') && trim(TELEGRAM_UPLOAD_URL) !== '') {
        $publicUrl = rtrim(TELEGRAM_UPLOAD_URL, '/') . '/' . $subdir . '/' . basename($localPath);
    }

    return ['telegram_file_path' => $filePath, 'local_path' => $localPath, 'public_url' => $publicUrl];
}

function analyzeImageIfEnabled($localPath, $caption, $groupName, $topicName)
{
    if (!defined('ENABLE_IMAGE_ANALYSIS') || !ENABLE_IMAGE_ANALYSIS) {
        return ['status' => 'skipped', 'text' => null, 'error' => 'ENABLE_IMAGE_ANALYSIS=false', 'analyzed_at' => null];
    }
    if (!defined('OPENAI_API_KEY') || trim(OPENAI_API_KEY) === '') {
        return ['status' => 'skipped', 'text' => null, 'error' => 'OPENAI_API_KEY kosong', 'analyzed_at' => null];
    }
    if (!$localPath || !is_file($localPath)) {
        return ['status' => 'error', 'text' => null, 'error' => 'File lokal gambar tidak ditemukan', 'analyzed_at' => null];
    }
    $maxBytes = defined('MAX_IMAGE_ANALYSIS_BYTES') ? MAX_IMAGE_ANALYSIS_BYTES : 5242880;
    if (filesize($localPath) > $maxBytes) {
        return ['status' => 'skipped', 'text' => null, 'error' => 'Ukuran gambar melebihi batas', 'analyzed_at' => null];
    }

    $mime = mime_content_type($localPath) ?: 'image/jpeg';
    $base64 = base64_encode(file_get_contents($localPath));
    $dataUrl = 'data:' . $mime . ';base64,' . $base64;

    $prompt = "Analisa gambar dari group Telegram untuk dashboard bisnis Adena.\n" .
        "Group: " . ($groupName ?: '-') . "\n" .
        "Topik: " . ($topicName ?: '-') . "\n" .
        "Caption: " . ($caption ?: '-') . "\n\n" .
        "Jika gambar adalah struk/nota/rekap transaksi, ekstrak teks penting: tanggal, toko/customer jika ada, item, jumlah, harga, total, metode pembayaran, status lunas/utang, dan catatan. " .
        "Jika bukan struk, jelaskan isi gambar yang relevan untuk operasional. Jawab ringkas dalam bahasa Indonesia. Jangan mengarang angka yang tidak terbaca.";

    $payload = [
        'model' => defined('OPENAI_VISION_MODEL') ? OPENAI_VISION_MODEL : (defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4.1-mini'),
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]],
        'temperature' => 0.1,
        'max_tokens' => 600,
    ];

    $response = postJson('https://api.openai.com/v1/chat/completions', $payload, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json',
    ]);

    $text = trim($response['choices'][0]['message']['content'] ?? '');
    if ($text === '') {
        return ['status' => 'error', 'text' => null, 'error' => 'OpenAI tidak mengembalikan teks analisa', 'analyzed_at' => null];
    }

    return ['status' => 'done', 'text' => $text, 'error' => null, 'analyzed_at' => date('Y-m-d H:i:s')];
}

function buildStoredMessageText($captionOrText, $messageType, $imageAnalysisText)
{
    $parts = [];
    if ($captionOrText !== null && trim((string)$captionOrText) !== '') {
        $parts[] = trim((string)$captionOrText);
    }
    if ($messageType === 'photo') {
        $parts[] = '[photo]';
    }
    if ($imageAnalysisText) {
        $parts[] = "[Analisa gambar]\n" . trim($imageAnalysisText);
    }
    return empty($parts) ? null : implode("\n\n", $parts);
}

function detectCategory($text)
{
    if (!$text) return null;
    $lower = mb_strtolower($text, 'UTF-8');
    $categories = [
        'omset' => ['omset', 'revenue', 'pendapatan', 'total jual', 'penjualan hari ini', 'total', 'subtotal'],
        'order' => ['order', 'pesanan', 'booking', 'pesan', 'beli', 'checkout', 'customer', 'item'],
        'komplain' => ['komplain', 'complain', 'keluhan', 'kecewa', 'marah', 'refund', 'retur'],
        'stok' => ['stok', 'stock', 'habis', 'ready', 'kosong', 'restock', 'produksi'],
        'pembayaran' => ['transfer', 'qris', 'cash', 'tunai', 'bayar', 'payment', 'lunas', 'utang'],
        'pengiriman' => ['kirim', 'delivery', 'gojek', 'grab', 'kurir', 'ongkir'],
        'news' => ['info', 'berita', 'pengumuman', 'urgent', 'penting'],
    ];
    foreach ($categories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($lower, $keyword) !== false) return $category;
        }
    }
    return 'umum';
}

function sendTelegramMessage($chatId, $text)
{
    if (!defined('TELEGRAM_BOT_TOKEN') || trim((string)TELEGRAM_BOT_TOKEN) === '') {
        return;
    }
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => 1,
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $payload,
        'timeout' => 20,
    ]]);
    @file_get_contents($url, false, $context);
}

function jsonRequest($url)
{
    $raw = rawRequest($url);
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('Response JSON tidak valid.');
    return $json;
}

function postJson($url, array $payload, array $headers = [])
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) throw new RuntimeException('HTTP error OpenAI: ' . $code . ' ' . $err . ' ' . substr((string)$raw, 0, 500));
    } else {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 60]]);
        $raw = file_get_contents($url, false, $context);
        if ($raw === false) throw new RuntimeException('HTTP request gagal.');
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('Response JSON tidak valid.');
    return $json;
}

function rawRequest($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) throw new RuntimeException('HTTP error: ' . $code . ' ' . $err);
        return $raw;
    }
    return file_get_contents($url);
}
