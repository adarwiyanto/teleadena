<?php

// webhook.php
// Menerima pesan dari Telegram dan menyimpan ke database

require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Jakarta');

$rawData = file_get_contents('php://input');
$update = json_decode($rawData, true);

// Tetap simpan log mentah untuk debugging
$logFile = __DIR__ . '/telegram_log.txt';
file_put_contents(
    $logFile,
    "==== " . date('Y-m-d H:i:s') . " ====\n" . $rawData . "\n\n",
    FILE_APPEND
);

try {
    if (!$update || !is_array($update)) {
        http_response_code(200);
        echo 'NO DATA';
        exit;
    }

    $updateId = $update['update_id'] ?? null;

    // Telegram bisa mengirim message, edited_message, channel_post, dll.
    $message = null;

    if (isset($update['message'])) {
        $message = $update['message'];
    } elseif (isset($update['edited_message'])) {
        $message = $update['edited_message'];
    } elseif (isset($update['channel_post'])) {
        $message = $update['channel_post'];
    }

    if (!$message) {
        http_response_code(200);
        echo 'NO MESSAGE';
        exit;
    }

    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];

    $chatId = $chat['id'] ?? null;
    $chatTitle = $chat['title'] ?? ($chat['first_name'] ?? null);
    $chatType = $chat['type'] ?? null;

    $messageId = $message['message_id'] ?? null;
    $senderId = $from['id'] ?? null;

    $senderNameParts = [];
    if (!empty($from['first_name'])) {
        $senderNameParts[] = $from['first_name'];
    }
    if (!empty($from['last_name'])) {
        $senderNameParts[] = $from['last_name'];
    }
    $senderName = trim(implode(' ', $senderNameParts));
    $senderUsername = $from['username'] ?? null;

    // Ambil text. Jika bukan text biasa, coba ambil caption.
    $messageText = $message['text'] ?? ($message['caption'] ?? null);

    // Kalau tidak ada teks, tetap simpan tipe pesan
    $messageType = 'text';
    if (isset($message['photo'])) {
        $messageType = 'photo';
    } elseif (isset($message['video'])) {
        $messageType = 'video';
    } elseif (isset($message['document'])) {
        $messageType = 'document';
    } elseif (isset($message['voice'])) {
        $messageType = 'voice';
    } elseif (isset($message['sticker'])) {
        $messageType = 'sticker';
    } elseif ($messageText === null) {
        $messageType = 'other';
    }

    // Waktu pesan dari Telegram dalam Unix timestamp
    $messageDate = null;
    if (!empty($message['date'])) {
        $messageDate = date('Y-m-d H:i:s', (int)$message['date']);
    }

    if (!$chatId) {
        http_response_code(200);
        echo 'NO CHAT ID';
        exit;
    }

    $pdo = db();

    // Simpan/update data grup
    $stmtGroup = $pdo->prepare("
        INSERT INTO telegram_groups 
            (telegram_chat_id, group_name, group_type, is_active)
        VALUES 
            (:telegram_chat_id, :group_name, :group_type, 1)
        ON DUPLICATE KEY UPDATE
            group_name = VALUES(group_name),
            group_type = VALUES(group_type),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmtGroup->execute([
        ':telegram_chat_id' => $chatId,
        ':group_name' => $chatTitle,
        ':group_type' => $chatType,
    ]);

    // Deteksi kategori sederhana tahap awal
    $detectedCategory = detectCategory($messageText);

    // Simpan pesan
    $stmtMsg = $pdo->prepare("
        INSERT INTO telegram_messages
            (
                telegram_update_id,
                telegram_chat_id,
                telegram_message_id,
                sender_id,
                sender_name,
                sender_username,
                message_text,
                message_date,
                message_type,
                detected_category,
                raw_payload
            )
        VALUES
            (
                :telegram_update_id,
                :telegram_chat_id,
                :telegram_message_id,
                :sender_id,
                :sender_name,
                :sender_username,
                :message_text,
                :message_date,
                :message_type,
                :detected_category,
                :raw_payload
            )
    ");

    $stmtMsg->execute([
        ':telegram_update_id' => $updateId,
        ':telegram_chat_id' => $chatId,
        ':telegram_message_id' => $messageId,
        ':sender_id' => $senderId,
        ':sender_name' => $senderName,
        ':sender_username' => $senderUsername,
        ':message_text' => $messageText,
        ':message_date' => $messageDate,
        ':message_type' => $messageType,
        ':detected_category' => $detectedCategory,
        ':raw_payload' => $rawData,
    ]);

    http_response_code(200);
    echo 'OK';

} catch (Throwable $e) {
    // Simpan error supaya mudah dicek
    $errorFile = __DIR__ . '/telegram_error_log.txt';
    file_put_contents(
        $errorFile,
        "==== " . date('Y-m-d H:i:s') . " ====\n" .
        $e->getMessage() . "\n" .
        $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );

    // Tetap balas 200 agar Telegram tidak spam retry terus
    http_response_code(200);
    echo 'ERROR LOGGED';
}

function detectCategory($text)
{
    if (!$text) {
        return null;
    }

    $lower = mb_strtolower($text, 'UTF-8');

    $categories = [
        'omset' => ['omset', 'revenue', 'pendapatan', 'total jual', 'penjualan hari ini'],
        'order' => ['order', 'pesanan', 'booking', 'pesan', 'beli', 'checkout'],
        'komplain' => ['komplain', 'complain', 'keluhan', 'kecewa', 'marah', 'refund', 'retur'],
        'stok' => ['stok', 'stock', 'habis', 'ready', 'kosong', 'restock'],
        'pembayaran' => ['transfer', 'qris', 'cash', 'tunai', 'bayar', 'payment', 'lunas'],
        'pengiriman' => ['kirim', 'delivery', 'gojek', 'grab', 'kurir', 'ongkir'],
        'news' => ['info', 'berita', 'pengumuman', 'urgent', 'penting'],
    ];

    foreach ($categories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($lower, $keyword) !== false) {
                return $category;
            }
        }
    }

    return 'umum';
}