<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireCronToken();

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

try {
    if (!defined('TELEGRAM_BOT_TOKEN') || trim(TELEGRAM_BOT_TOKEN) === '') throw new RuntimeException('TELEGRAM_BOT_TOKEN belum diisi.');
    if (!defined('TELEGRAM_ADMIN_CHAT_ID') || trim((string)TELEGRAM_ADMIN_CHAT_ID) === '') throw new RuntimeException('TELEGRAM_ADMIN_CHAT_ID belum diisi.');

    $pdo = db();
    $period = $_GET['period'] ?? 'today';
    [$dateStart, $dateEnd, $periodLabel] = resolvePeriod($period);

    $stmt = $pdo->prepare("\n        SELECT m.*, g.group_name\n        FROM telegram_messages m\n        LEFT JOIN telegram_groups g ON g.telegram_chat_id = m.telegram_chat_id\n        WHERE m.message_date >= :date_start AND m.message_date <= :date_end\n        ORDER BY m.message_date ASC, m.id ASC\n        LIMIT 5000\n    ");
    $stmt->execute([':date_start' => $dateStart, ':date_end' => $dateEnd]);
    $messages = $stmt->fetchAll();

    $summary = buildSummary($messages, $periodLabel, $dateStart, $dateEnd);
    $text = formatTelegramSummary($summary);
    sendTelegramMessage(TELEGRAM_ADMIN_CHAT_ID, $text);

    echo 'OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . htmlspecialchars($e->getMessage());
}

function resolvePeriod($period)
{
    $now = new DateTime('now');
    if ($period === 'yesterday') {$s=(new DateTime('yesterday'))->setTime(0,0,0);$e=(new DateTime('yesterday'))->setTime(23,59,59);return[$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),'Kemarin'];}
    if ($period === '7days') {$s=(new DateTime('-6 days'))->setTime(0,0,0);$e=$now->setTime(23,59,59);return[$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),'7 Hari Terakhir'];}
    $s=(new DateTime('today'))->setTime(0,0,0);$e=(new DateTime('today'))->setTime(23,59,59);return[$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),'Hari Ini'];
}

function buildSummary(array $messages, $periodLabel, $dateStart, $dateEnd)
{
    $s = ['period_label'=>$periodLabel,'date_start'=>$dateStart,'date_end'=>$dateEnd,'total'=>count($messages),'photos'=>0,'image_done'=>0,'groups'=>[],'topics'=>[],'orders'=>0,'omset'=>0,'stock'=>0,'payments'=>0,'complaints'=>0,'images'=>[]];
    foreach($messages as $m){
        $g=$m['group_name'] ?: $m['telegram_chat_id']; $t=$m['topic_name'] ?: (!empty($m['message_thread_id'])?'Topic #'.$m['message_thread_id']:'General / Topik Umum'); $text=mb_strtolower((string)($m['message_text']??''),'UTF-8');
        if(!isset($s['groups'][$g]))$s['groups'][$g]=['total'=>0,'photos'=>0,'topics'=>[]]; $s['groups'][$g]['total']++; $s['groups'][$g]['topics'][$t]=($s['groups'][$g]['topics'][$t]??0)+1;
        $key=$g.' / '.$t; if(!isset($s['topics'][$key]))$s['topics'][$key]=['total'=>0,'photos'=>0]; $s['topics'][$key]['total']++;
        if(($m['message_type']??'')==='photo'){ $s['photos']++; $s['groups'][$g]['photos']++; $s['topics'][$key]['photos']++; $s['images'][]=trim(mb_substr(preg_replace('/\s+/',' ',(string)($m['message_text']??'')),0,160)); }
        if(($m['image_analysis_status']??'')==='done')$s['image_done']++;
        if(has($text,['order','pesanan','customer','checkout']))$s['orders']++;
        if(has($text,['omset','revenue','pendapatan','total jual','subtotal','total']))$s['omset']++;
        if(has($text,['stok','stock','restock','produksi','habis','kosong']))$s['stock']++;
        if(has($text,['transfer','qris','cash','tunai','bayar','payment','lunas','utang']))$s['payments']++;
        if(has($text,['komplain','refund','retur','telat','keluhan']))$s['complaints']++;
    }
    uasort($s['topics'],fn($a,$b)=>$b['total']<=>$a['total']); return $s;
}
function has($text,array $arr){foreach($arr as $a)if(mb_strpos($text,$a)!==false)return true;return false;}

function formatTelegramSummary(array $s)
{
    $lines=[];$lines[]="📌 RANGKUMAN TELEGRAM ADENA - ".$s['period_label'];$lines[]="Periode: ".$s['date_start']." s.d. ".$s['date_end'];$lines[]="";
    $lines[]="📊 GAMBARAN UMUM";$lines[]="Total pesan: ".$s['total'];$lines[]="Foto/gambar: ".$s['photos'];$lines[]="Gambar berhasil dianalisa: ".$s['image_done'];$lines[]="Topik aktif terbaca: ".count($s['topics']);$lines[]="";
    $lines[]="👥 PER GROUP"; if(!$s['groups'])$lines[]="- Tidak ada pesan."; foreach($s['groups'] as $g=>$row){$lines[]="- {$g}: {$row['total']} pesan, {$row['photos']} foto"; foreach($row['topics'] as $topic=>$ct){$lines[]="  · {$topic}: {$ct} pesan";}}
    $lines[]="";$lines[]="🏷️ PER TOPIK TERATAS"; foreach(array_slice($s['topics'],0,10,true) as $topic=>$row){$lines[]="- {$topic}: {$row['total']} pesan, {$row['photos']} foto";}
    $lines[]="";$lines[]="🧾 OPERASIONAL";$lines[]="Order/pesanan: ".$s['orders'];$lines[]="Omset/penjualan: ".$s['omset'];$lines[]="Pembayaran: ".$s['payments'];$lines[]="Stok/produksi: ".$s['stock'];$lines[]="Komplain/risiko: ".$s['complaints'];
    if($s['images']){$lines[]="";$lines[]="🖼️ FOTO/STRUK";foreach(array_slice($s['images'],0,8) as $img){$lines[]="- ".($img ?: '[photo tanpa teks analisa]');}}
    $lines[]="";$lines[]="Dashboard: buka summary.php dengan token dashboard.";
    $text=implode("\n",$lines); return mb_strlen($text)>3900?mb_substr($text,0,3900).'...':$text;
}

function sendTelegramMessage($chatId, $text)
{
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = ['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>null,'disable_web_page_preview'=>true];
    if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($raw===false||$code>=400)throw new RuntimeException('Gagal kirim Telegram: '.$code.' '.$raw);return;}
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($payload),'timeout'=>30]]);$raw=file_get_contents($url,false,$context);if($raw===false)throw new RuntimeException('Gagal kirim Telegram.');
}
