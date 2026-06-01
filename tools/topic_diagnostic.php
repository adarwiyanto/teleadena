<?php

// tools/topic_diagnostic.php
// Diagnostik topik Telegram: membandingkan data DB dengan telegram_log.txt.
// CLI: php tools/topic_diagnostic.php
// Web: /tools/topic_diagnostic.php?token=APP_ACCESS_TOKEN

require_once __DIR__ . '/../config.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

try {
    guardToolAccess();
    $pdo = db();
    $logPath = stringArg('log') ?: (__DIR__ . '/../telegram_log.txt');

    $dbTopics = fetchDbTopics($pdo);
    $dbMessages = fetchDbMessageStats($pdo);
    $logTopics = is_file($logPath) ? fetchLogTopics(file_get_contents($logPath)) : [];

    header('Content-Type: text/plain; charset=utf-8');
    echo "DIAGNOSTIC TOPIK TELEGRAM ADENA\n";
    echo "Waktu: " . date('Y-m-d H:i:s') . "\n";
    echo "Log: {$logPath}\n\n";

    echo "TOPIK DI DATABASE telegram_topics:\n";
    if (!$dbTopics) echo "- Tidak ada topik.\n";
    foreach ($dbTopics as $row) {
        $key = topicKey($row['telegram_chat_id'], $row['message_thread_id']);
        $msg = $dbMessages[$key] ?? ['total' => 0, 'last_message_date' => null];
        echo "- Chat {$row['telegram_chat_id']} | Thread {$row['message_thread_id']} | {$row['topic_name']} | pesan DB: {$msg['total']} | terakhir: " . ($msg['last_message_date'] ?: '-') . "\n";
    }

    echo "\nTOPIK TERBACA DI telegram_log.txt:\n";
    if (!$logTopics) echo "- Tidak ada topik terbaca dari log.\n";
    foreach ($logTopics as $key => $row) {
        $exists = isset($dbMessages[$key]) ? 'ADA DI DB' : 'BELUM ADA PESAN DI DB';
        echo "- Chat {$row['chat_id']} | Thread {$row['thread_id']} | {$row['topic_name']} | update log: {$row['total']} | {$exists}\n";
    }

    echo "\nTOPIK ADA DI LOG TAPI BELUM ADA PESAN DI DB:\n";
    $missing = 0;
    foreach ($logTopics as $key => $row) {
        if (!isset($dbMessages[$key])) {
            $missing++;
            echo "- Chat {$row['chat_id']} | Thread {$row['thread_id']} | {$row['topic_name']} | update log: {$row['total']}\n";
        }
    }
    if ($missing === 0) echo "- Tidak ada.\n";

    echo "\nSARAN:\n";
    echo "1. Jika ada topik di log tapi belum ada di DB, jalankan tools/import_telegram_log_to_messages.php.\n";
    echo "2. Jika topik tidak ada di log sama sekali, bot belum menerima pesan dari topik itu. Cek Privacy Mode BotFather dan jadikan bot admin group.\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ERROR: ' . $e->getMessage();
}

function guardToolAccess()
{
    if (PHP_SAPI === 'cli') return;
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    if (!defined('APP_ACCESS_TOKEN') || APP_ACCESS_TOKEN === '' || !hash_equals((string)APP_ACCESS_TOKEN, (string)$token)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

function stringArg($name)
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, '--' . $name . '=') === 0) return substr($arg, strlen($name) + 3);
        }
        return null;
    }
    return $_GET[$name] ?? $_POST[$name] ?? null;
}

function fetchDbTopics(PDO $pdo)
{
    return $pdo->query("SELECT telegram_chat_id, message_thread_id, topic_name, first_seen_at, last_seen_at FROM telegram_topics ORDER BY telegram_chat_id ASC, message_thread_id ASC")->fetchAll();
}

function fetchDbMessageStats(PDO $pdo)
{
    $rows = $pdo->query("SELECT telegram_chat_id, COALESCE(message_thread_id,0) AS message_thread_id, COUNT(*) AS total, MAX(message_date) AS last_message_date FROM telegram_messages GROUP BY telegram_chat_id, COALESCE(message_thread_id,0)")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[topicKey($row['telegram_chat_id'], $row['message_thread_id'])] = $row;
    }
    return $out;
}

function fetchLogTopics($content)
{
    $out = [];
    preg_match_all('/^====\s+([^=]+?)\s+====\s*\R(.*?)(?=^====\s+|\z)/ms', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $raw = trim($m[2]);
        if ($raw === '') continue;
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) continue;
        $update = json_decode(trim(substr($raw, $jsonStart)), true);
        if (!is_array($update)) continue;
        $env = extractTelegramMessageEnvelope($update);
        if (!$env) continue;
        $message = $env['message'];
        $chat = $message['chat'] ?? [];
        $chatId = $chat['id'] ?? null;
        if (!$chatId) continue;
        $threadId = normalizeThreadId($message['message_thread_id'] ?? null);
        $topicName = detectTopicName($message) ?: ($threadId === 0 ? 'General / Topik Umum' : 'Topic #' . $threadId);
        $key = topicKey($chatId, $threadId);
        if (!isset($out[$key])) {
            $out[$key] = ['chat_id' => $chatId, 'thread_id' => $threadId, 'topic_name' => $topicName, 'total' => 0];
        }
        if (strpos($out[$key]['topic_name'], 'Topic #') === 0 && strpos($topicName, 'Topic #') !== 0) {
            $out[$key]['topic_name'] = $topicName;
        }
        $out[$key]['total']++;
    }
    uasort($out, fn($a, $b) => [$a['chat_id'], $a['thread_id']] <=> [$b['chat_id'], $b['thread_id']]);
    return $out;
}

function topicKey($chatId, $threadId){return (string)$chatId . ':' . (string)normalizeThreadId($threadId);}
function extractTelegramMessageEnvelope(array $update){foreach(['message','edited_message','channel_post','edited_channel_post'] as $key){if(!empty($update[$key])&&is_array($update[$key]))return['type'=>$key,'message'=>$update[$key]];}return null;}
function normalizeThreadId($threadId){return($threadId===null||$threadId===''||$threadId===false)?0:(int)$threadId;}
function detectTopicName(array $message){$paths=[['forum_topic_created','name'],['forum_topic_edited','name'],['reply_to_message','forum_topic_created','name'],['reply_to_message','forum_topic_edited','name'],['reply_to_message','reply_to_message','forum_topic_created','name'],['reply_to_message','reply_to_message','forum_topic_edited','name']];foreach($paths as $path){$value=arrayPath($message,$path);if(is_string($value)&&trim($value)!=='')return trim($value);}return null;}
function arrayPath(array $array,array $path){$value=$array;foreach($path as $key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return$value;}
