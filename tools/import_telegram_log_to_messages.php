<?php

// tools/import_telegram_log_to_messages.php
// Recovery: memasukkan ulang update Telegram dari telegram_log.txt ke database tanpa re-download/analis gambar.
// CLI: php tools/import_telegram_log_to_messages.php --dry-run
// Web: /tools/import_telegram_log_to_messages.php?token=APP_ACCESS_TOKEN&dry_run=1

require_once __DIR__ . '/../config.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

try {
    guardToolAccess();
    $dryRun = boolArg('dry-run') || boolArg('dry_run');
    $logPath = stringArg('log') ?: (__DIR__ . '/../telegram_log.txt');

    if (!is_file($logPath)) {
        throw new RuntimeException('File log tidak ditemukan: ' . $logPath);
    }

    $content = file_get_contents($logPath);
    [$updates, $invalidBlocks] = parseTelegramLogUpdates($content);

    $pdo = db();
    if ($dryRun) {
        $pdo->beginTransaction();
    }

    $stats = [
        'log_path' => $logPath,
        'updates_found' => count($updates),
        'invalid_blocks' => $invalidBlocks,
        'messages_seen' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped_no_message' => 0,
        'skipped_no_chat' => 0,
        'topics' => [],
    ];

    foreach ($updates as $item) {
        $update = $item['update'];
        $rawJson = $item['raw'];
        $envelope = extractTelegramMessageEnvelope($update);
        if (!$envelope) {
            $stats['skipped_no_message']++;
            continue;
        }

        $message = $envelope['message'];
        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? ($message['sender_chat'] ?? []);
        $chatId = $chat['id'] ?? null;
        if (!$chatId) {
            $stats['skipped_no_chat']++;
            continue;
        }

        $stats['messages_seen']++;

        $messageId = $message['message_id'] ?? null;
        $threadId = normalizeThreadId($message['message_thread_id'] ?? null);
        $chatTitle = $chat['title'] ?? ($chat['first_name'] ?? null);
        $chatType = $chat['type'] ?? null;
        $messageDate = !empty($message['date']) ? date('Y-m-d H:i:s', (int)$message['date']) : date('Y-m-d H:i:s');
        $topicName = detectTopicName($message);
        if ($topicName === null) {
            $topicName = getKnownTopicName($pdo, $chatId, $threadId);
        }
        if ($topicName === null) {
            $topicName = $threadId === 0 ? 'General / Topik Umum' : 'Topic #' . $threadId;
        }

        saveGroup($pdo, $chatId, $chatTitle, $chatType);
        upsertTopic($pdo, $chatId, $threadId, $topicName, $messageDate);

        $captionOrText = getMessageText($message);
        $messageType = detectMessageType($message, $captionOrText);
        $media = extractLargestPhoto($message);
        $messageText = buildStoredMessageText($captionOrText, $messageType, null);
        $detectedCategory = detectCategory($messageText);
        $senderName = trim(implode(' ', array_filter([$from['first_name'] ?? null, $from['last_name'] ?? null])));
        if ($senderName === '' && !empty($from['title'])) {
            $senderName = $from['title'];
        }

        $result = saveTelegramMessage($pdo, [
            'telegram_update_id' => $update['update_id'] ?? null,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'message_thread_id' => $threadId,
            'topic_name' => $topicName,
            'sender_id' => $from['id'] ?? null,
            'sender_name' => $senderName,
            'sender_username' => $from['username'] ?? null,
            'message_text' => $messageText,
            'message_date' => $messageDate,
            'message_type' => $messageType,
            'media_file_id' => $media['file_id'] ?? null,
            'media_file_unique_id' => $media['file_unique_id'] ?? null,
            'media_file_path' => null,
            'media_local_path' => null,
            'media_public_url' => null,
            'image_analysis_text' => null,
            'image_analysis_status' => $messageType === 'photo' ? 'pending_recovery' : null,
            'image_analysis_error' => $messageType === 'photo' ? 'Diimport dari telegram_log.txt; file/analisa gambar tidak diulang otomatis.' : null,
            'analyzed_at' => null,
            'detected_category' => $detectedCategory,
            'raw_payload' => $rawJson,
        ]);

        if ($result === 'inserted') $stats['inserted']++;
        if ($result === 'updated') $stats['updated']++;

        $topicKey = ($chatTitle ?: $chatId) . ' / ' . $topicName . ' (#' . $threadId . ')';
        $stats['topics'][$topicKey] = ($stats['topics'][$topicKey] ?? 0) + 1;
    }

    if ($dryRun) {
        $pdo->rollBack();
    }

    outputText($stats, $dryRun);
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

function boolArg($name)
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) {
            if ($arg === '--' . $name || $arg === '--' . str_replace('_', '-', $name)) return true;
            if (preg_match('/^--' . preg_quote($name, '/') . '=(1|true|yes)$/i', $arg)) return true;
        }
        return false;
    }
    return !empty($_GET[$name]) || !empty($_POST[$name]);
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

function parseTelegramLogUpdates($content)
{
    $updates = [];
    $invalid = 0;
    preg_match_all('/^====\s+([^=]+?)\s+====\s*\R(.*?)(?=^====\s+|\z)/ms', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $raw = trim($m[2]);
        if ($raw === '') continue;
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            $invalid++;
            continue;
        }
        $raw = trim(substr($raw, $jsonStart));
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            $invalid++;
            continue;
        }
        $updates[] = ['update' => $update, 'raw' => $raw];
    }
    return [$updates, $invalid];
}

function outputText(array $stats, $dryRun)
{
    arsort($stats['topics']);
    header('Content-Type: text/plain; charset=utf-8');
    echo "IMPORT TELEGRAM LOG TO MESSAGES\n";
    echo "Mode: " . ($dryRun ? 'DRY RUN - tidak ada perubahan DB' : 'EXECUTE - DB diubah') . "\n";
    echo "Log: {$stats['log_path']}\n";
    echo "Updates valid: {$stats['updates_found']}\n";
    echo "Blok invalid: {$stats['invalid_blocks']}\n";
    echo "Messages seen: {$stats['messages_seen']}\n";
    echo "Inserted: {$stats['inserted']}\n";
    echo "Updated: {$stats['updated']}\n";
    echo "Skipped no message: {$stats['skipped_no_message']}\n";
    echo "Skipped no chat: {$stats['skipped_no_chat']}\n";
    echo "\nTOPIK DARI LOG:\n";
    if (!$stats['topics']) echo "- Tidak ada topik terbaca.\n";
    foreach ($stats['topics'] as $topic => $count) {
        echo "- {$topic}: {$count}\n";
    }
}

function extractTelegramMessageEnvelope(array $update)
{
    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
        if (!empty($update[$key]) && is_array($update[$key])) {
            return ['type' => $key, 'message' => $update[$key]];
        }
    }
    return null;
}
function normalizeThreadId($threadId){return ($threadId === null || $threadId === '' || $threadId === false) ? 0 : (int)$threadId;}
function getMessageText(array $message){if(isset($message['text']))return$message['text'];if(isset($message['caption']))return$message['caption'];if(isset($message['forum_topic_created']['name']))return'[forum topic created] '.$message['forum_topic_created']['name'];if(isset($message['forum_topic_edited']['name']))return'[forum topic edited] '.$message['forum_topic_edited']['name'];if(isset($message['forum_topic_closed']))return'[forum topic closed]';if(isset($message['forum_topic_reopened']))return'[forum topic reopened]';return null;}
function saveGroup(PDO $pdo,$chatId,$chatTitle,$chatType){$stmt=$pdo->prepare("INSERT INTO telegram_groups (telegram_chat_id, group_name, group_type, is_active) VALUES (:telegram_chat_id,:group_name,:group_type,1) ON DUPLICATE KEY UPDATE group_name=VALUES(group_name), group_type=VALUES(group_type), updated_at=CURRENT_TIMESTAMP");$stmt->execute([':telegram_chat_id'=>$chatId,':group_name'=>$chatTitle,':group_type'=>$chatType]);}
function upsertTopic(PDO $pdo,$chatId,$threadId,$topicName,$seenAt){$stmt=$pdo->prepare("INSERT INTO telegram_topics (telegram_chat_id, message_thread_id, topic_name, first_seen_at, last_seen_at) VALUES (:chat_id,:thread_id,:topic_name,:first_seen_at,:last_seen_at) ON DUPLICATE KEY UPDATE topic_name=CASE WHEN VALUES(topic_name) IS NULL OR VALUES(topic_name)='' OR VALUES(topic_name) LIKE 'Topic #%' THEN topic_name ELSE VALUES(topic_name) END, last_seen_at=VALUES(last_seen_at), updated_at=CURRENT_TIMESTAMP");$stmt->execute([':chat_id'=>$chatId,':thread_id'=>normalizeThreadId($threadId),':topic_name'=>$topicName,':first_seen_at'=>$seenAt,':last_seen_at'=>$seenAt]);}
function getKnownTopicName(PDO $pdo,$chatId,$threadId){$stmt=$pdo->prepare("SELECT topic_name FROM telegram_topics WHERE telegram_chat_id=:chat_id AND message_thread_id=:thread_id LIMIT 1");$stmt->execute([':chat_id'=>$chatId,':thread_id'=>normalizeThreadId($threadId)]);$row=$stmt->fetch();return$row['topic_name']??null;}
function detectMessageType(array $message,$text){if(isset($message['photo']))return'photo';if(isset($message['video']))return'video';if(isset($message['document']))return'document';if(isset($message['voice']))return'voice';if(isset($message['audio']))return'audio';if(isset($message['sticker']))return'sticker';if(isset($message['forum_topic_created']))return'topic_created';if(isset($message['forum_topic_edited']))return'topic_edited';if(isset($message['forum_topic_closed']))return'topic_closed';if(isset($message['forum_topic_reopened']))return'topic_reopened';if($text!==null)return'text';return'other';}
function detectTopicName(array $message){$paths=[['forum_topic_created','name'],['forum_topic_edited','name'],['reply_to_message','forum_topic_created','name'],['reply_to_message','forum_topic_edited','name'],['reply_to_message','reply_to_message','forum_topic_created','name'],['reply_to_message','reply_to_message','forum_topic_edited','name']];foreach($paths as $path){$value=arrayPath($message,$path);if(is_string($value)&&trim($value)!=='')return trim($value);}return null;}
function arrayPath(array $array,array $path){$value=$array;foreach($path as $key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return$value;}
function extractLargestPhoto(array $message){if(empty($message['photo'])||!is_array($message['photo']))return null;$photos=$message['photo'];usort($photos,function($a,$b){return(($b['file_size']??0)<=>($a['file_size']??0));});return$photos[0]??null;}
function buildStoredMessageText($captionOrText,$messageType,$imageAnalysisText){$parts=[];if($captionOrText!==null&&trim((string)$captionOrText)!=='')$parts[]=trim((string)$captionOrText);if($messageType==='photo')$parts[]='[photo]';if($imageAnalysisText)$parts[]="[Analisa gambar]\n".trim($imageAnalysisText);return empty($parts)?null:implode("\n\n",$parts);}
function detectCategory($text){if(!$text)return null;$lower=mb_strtolower($text,'UTF-8');$categories=['omset'=>['omset','revenue','pendapatan','total jual','penjualan hari ini','total','subtotal'],'order'=>['order','pesanan','booking','pesan','beli','checkout','customer','item'],'komplain'=>['komplain','complain','keluhan','kecewa','marah','refund','retur'],'stok'=>['stok','stock','habis','ready','kosong','restock','produksi'],'pembayaran'=>['transfer','qris','cash','tunai','bayar','payment','lunas','utang'],'pengiriman'=>['kirim','delivery','gojek','grab','kurir','ongkir'],'news'=>['info','berita','pengumuman','urgent','penting']];foreach($categories as $category=>$keywords){foreach($keywords as $keyword){if(mb_strpos($lower,$keyword)!==false)return$category;}}return'umum';}
function saveTelegramMessage(PDO $pdo,array $data){$exists=null;if($data['telegram_chat_id']!==null&&$data['telegram_message_id']!==null){$stmtExists=$pdo->prepare("SELECT id FROM telegram_messages WHERE telegram_chat_id=:chat_id AND telegram_message_id=:message_id LIMIT 1");$stmtExists->execute([':chat_id'=>$data['telegram_chat_id'],':message_id'=>$data['telegram_message_id']]);$exists=$stmtExists->fetch();}if($exists){$stmt=$pdo->prepare("UPDATE telegram_messages SET telegram_update_id=:telegram_update_id, telegram_chat_id=:telegram_chat_id, telegram_message_id=:telegram_message_id, message_thread_id=:message_thread_id, topic_name=:topic_name, sender_id=:sender_id, sender_name=:sender_name, sender_username=:sender_username, message_text=:message_text, message_date=:message_date, message_type=:message_type, media_file_id=COALESCE(:media_file_id,media_file_id), media_file_unique_id=COALESCE(:media_file_unique_id,media_file_unique_id), media_file_path=COALESCE(:media_file_path,media_file_path), media_local_path=COALESCE(:media_local_path,media_local_path), media_public_url=COALESCE(:media_public_url,media_public_url), image_analysis_text=COALESCE(:image_analysis_text,image_analysis_text), image_analysis_status=COALESCE(:image_analysis_status,image_analysis_status), image_analysis_error=COALESCE(:image_analysis_error,image_analysis_error), analyzed_at=COALESCE(:analyzed_at,analyzed_at), detected_category=:detected_category, raw_payload=:raw_payload WHERE id=:id");$params=bindMessageParams($data);$params[':id']=$exists['id'];$stmt->execute($params);return'updated';}$stmt=$pdo->prepare("INSERT INTO telegram_messages (telegram_update_id,telegram_chat_id,telegram_message_id,message_thread_id,topic_name,sender_id,sender_name,sender_username,message_text,message_date,message_type,media_file_id,media_file_unique_id,media_file_path,media_local_path,media_public_url,image_analysis_text,image_analysis_status,image_analysis_error,analyzed_at,detected_category,raw_payload) VALUES (:telegram_update_id,:telegram_chat_id,:telegram_message_id,:message_thread_id,:topic_name,:sender_id,:sender_name,:sender_username,:message_text,:message_date,:message_type,:media_file_id,:media_file_unique_id,:media_file_path,:media_local_path,:media_public_url,:image_analysis_text,:image_analysis_status,:image_analysis_error,:analyzed_at,:detected_category,:raw_payload)");$stmt->execute(bindMessageParams($data));return'inserted';}
function bindMessageParams(array $data){return[':telegram_update_id'=>$data['telegram_update_id']??null,':telegram_chat_id'=>$data['telegram_chat_id']??null,':telegram_message_id'=>$data['telegram_message_id']??null,':message_thread_id'=>normalizeThreadId($data['message_thread_id']??0),':topic_name'=>$data['topic_name']??null,':sender_id'=>$data['sender_id']??null,':sender_name'=>$data['sender_name']??null,':sender_username'=>$data['sender_username']??null,':message_text'=>$data['message_text']??null,':message_date'=>$data['message_date']??null,':message_type'=>$data['message_type']??'other',':media_file_id'=>$data['media_file_id']??null,':media_file_unique_id'=>$data['media_file_unique_id']??null,':media_file_path'=>$data['media_file_path']??null,':media_local_path'=>$data['media_local_path']??null,':media_public_url'=>$data['media_public_url']??null,':image_analysis_text'=>$data['image_analysis_text']??null,':image_analysis_status'=>$data['image_analysis_status']??null,':image_analysis_error'=>$data['image_analysis_error']??null,':analyzed_at'=>$data['analyzed_at']??null,':detected_category'=>$data['detected_category']??null,':raw_payload'=>$data['raw_payload']??null];}
