<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireCronToken();

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = db();

    $period = $_GET['period'] ?? 'today';
    $groupId = $_GET['group_id'] ?? '';

    [$dateStart, $dateEnd, $periodLabel] = resolvePeriod($period);

    $where = [
        'm.message_date >= :date_start',
        'm.message_date <= :date_end'
    ];

    $params = [
        ':date_start' => $dateStart,
        ':date_end' => $dateEnd,
    ];

    if ($groupId !== '') {
        $where[] = 'm.telegram_chat_id = :group_id';
        $params[':group_id'] = $groupId;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            g.group_name
        FROM telegram_messages m
        LEFT JOIN telegram_groups g 
            ON g.telegram_chat_id = m.telegram_chat_id
        {$whereSql}
        ORDER BY m.message_date ASC, m.id ASC
        LIMIT 3000
    ");
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $summary = buildLocalSummary($messages, $periodLabel, $dateStart, $dateEnd);

    $text = formatTelegramSummary($summary);

    sendTelegramMessage(TELEGRAM_ADMIN_CHAT_ID, $text);

    echo '<h3>OK</h3>';
    echo '<pre>Summary berhasil dikirim ke Telegram pribadi.</pre>';

} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Error</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function resolvePeriod($period)
{
    $today = new DateTime('today', new DateTimeZone('Asia/Jakarta'));

    if ($period === 'yesterday') {
        $start = (clone $today)->modify('-1 day')->format('Y-m-d 00:00:00');
        $end = (clone $today)->modify('-1 day')->format('Y-m-d 23:59:59');
        return [$start, $end, 'Kemarin'];
    }

    if ($period === 'week') {
        $start = (clone $today)->modify('-6 days')->format('Y-m-d 00:00:00');
        $end = (clone $today)->format('Y-m-d 23:59:59');
        return [$start, $end, '7 Hari Terakhir'];
    }

    $start = $today->format('Y-m-d 00:00:00');
    $end = $today->format('Y-m-d 23:59:59');
    return [$start, $end, 'Hari Ini'];
}

function buildLocalSummary(array $messages, $periodLabel, $dateStart, $dateEnd)
{
    $summary = [
        'period_label' => $periodLabel,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
        'total_messages' => count($messages),
        'groups' => [],
        'categories' => [],
        'orders' => [],
        'omset' => [],
        'stock' => [],
        'complaints' => [],
        'payments' => [],
        'delivery' => [],
        'news' => [],
        'important_messages' => [],
    ];

    foreach ($messages as $msg) {
        $groupName = $msg['group_name'] ?: ('Group ' . $msg['telegram_chat_id']);
        $category = $msg['detected_category'] ?: 'umum';
        $text = trim((string)($msg['message_text'] ?? ''));
        $sender = $msg['sender_name'] ?: '-';
        $time = $msg['message_date'] ?: '-';

        if (!isset($summary['groups'][$groupName])) {
            $summary['groups'][$groupName] = [
                'total' => 0,
                'categories' => [],
                'important' => [],
            ];
        }

        $summary['groups'][$groupName]['total']++;

        if (!isset($summary['groups'][$groupName]['categories'][$category])) {
            $summary['groups'][$groupName]['categories'][$category] = 0;
        }
        $summary['groups'][$groupName]['categories'][$category]++;

        if (!isset($summary['categories'][$category])) {
            $summary['categories'][$category] = 0;
        }
        $summary['categories'][$category]++;

        if ($text === '') {
            continue;
        }

        $item = [
            'time' => $time,
            'group' => $groupName,
            'sender' => $sender,
            'category' => $category,
            'text' => $text,
        ];

        if (isImportant($text)) {
            $summary['important_messages'][] = $item;
            $summary['groups'][$groupName]['important'][] = $item;
        }

        if ($category === 'order' || containsAny($text, ['order', 'pesan', 'pesanan', 'booking', 'beli', 'checkout'])) {
            $summary['orders'][] = $item;
        }

        if ($category === 'omset' || containsAny($text, ['omset', 'pendapatan', 'revenue', 'total jual', 'penjualan'])) {
            $summary['omset'][] = $item;
        }

        if ($category === 'stok' || containsAny($text, ['stok', 'stock', 'habis', 'kosong', 'ready', 'restock', 'produksi'])) {
            $summary['stock'][] = $item;
        }

        if ($category === 'komplain' || containsAny($text, ['komplain', 'complain', 'keluhan', 'refund', 'retur', 'kecewa', 'marah', 'telat', 'terlambat'])) {
            $summary['complaints'][] = $item;
        }

        if ($category === 'pembayaran' || containsAny($text, ['transfer', 'qris', 'cash', 'tunai', 'bayar', 'payment', 'lunas'])) {
            $summary['payments'][] = $item;
        }

        if ($category === 'pengiriman' || containsAny($text, ['kirim', 'delivery', 'kurir', 'gojek', 'grab', 'ongkir', 'pickup'])) {
            $summary['delivery'][] = $item;
        }

        if ($category === 'news' || containsAny($text, ['info', 'berita', 'pengumuman', 'urgent', 'penting'])) {
            $summary['news'][] = $item;
        }
    }

    return $summary;
}

function formatTelegramSummary(array $summary)
{
    $lines = [];

    $lines[] = "📌 RANGKUMAN TELEGRAM - " . $summary['period_label'];
    $lines[] = "Periode: " . $summary['date_start'] . " s.d. " . $summary['date_end'];
    $lines[] = "";

    $lines[] = "📊 GAMBARAN UMUM";
    $lines[] = "Total pesan: " . $summary['total_messages'];
    $lines[] = "Total grup aktif terbaca: " . count($summary['groups']);
    $lines[] = "Order: " . count($summary['orders']);
    $lines[] = "Omset: " . count($summary['omset']);
    $lines[] = "Stok/produksi: " . count($summary['stock']);
    $lines[] = "Komplain/risiko: " . count($summary['complaints']);
    $lines[] = "";

    $lines[] = "🏷️ KATEGORI";
    if (empty($summary['categories'])) {
        $lines[] = "- Tidak ada kategori.";
    } else {
        foreach ($summary['categories'] as $cat => $total) {
            $lines[] = "- " . ucfirst($cat) . ": " . $total;
        }
    }
    $lines[] = "";

    $lines[] = "👥 RINGKASAN PER GRUP";
    if (empty($summary['groups'])) {
        $lines[] = "- Belum ada pesan pada periode ini.";
    } else {
        foreach ($summary['groups'] as $groupName => $group) {
            arsort($group['categories']);
            $dominant = [];

            foreach (array_slice($group['categories'], 0, 3, true) as $cat => $total) {
                $dominant[] = $cat . " (" . $total . ")";
            }

            $lines[] = "- " . $groupName . ": " . $group['total'] . " pesan";
            $lines[] = "  Kategori dominan: " . implode(", ", $dominant);
            $lines[] = "  Pesan penting: " . count($group['important']);
        }
    }
    $lines[] = "";

    $lines[] = "⚠️ ACTION ITEM";
    if (count($summary['complaints']) > 0) {
        $lines[] = "- [Tinggi] Review komplain/customer bermasalah: " . count($summary['complaints']) . " item.";
    }
    if (count($summary['stock']) > 0) {
        $lines[] = "- [Sedang] Cek stok dan kebutuhan produksi/restock: " . count($summary['stock']) . " item.";
    }
    if (count($summary['payments']) > 0) {
        $lines[] = "- [Sedang] Verifikasi pembayaran/transfer/QRIS: " . count($summary['payments']) . " item.";
    }
    if (count($summary['orders']) > 0) {
        $lines[] = "- [Sedang] Rekap order dan status fulfillment: " . count($summary['orders']) . " item.";
    }
    if (count($summary['delivery']) > 0) {
        $lines[] = "- [Rendah] Cek pengiriman/kurir/ongkir: " . count($summary['delivery']) . " item.";
    }
    if (
        count($summary['complaints']) === 0 &&
        count($summary['stock']) === 0 &&
        count($summary['payments']) === 0 &&
        count($summary['orders']) === 0 &&
        count($summary['delivery']) === 0
    ) {
        $lines[] = "- Tidak ada action item spesifik.";
    }
    $lines[] = "";

    $lines[] = "🧾 ORDER TERDETEKSI";
    appendItems($lines, $summary['orders'], 5);

    $lines[] = "";
    $lines[] = "💰 OMSET TERDETEKSI";
    appendItems($lines, $summary['omset'], 5);

    $lines[] = "";
    $lines[] = "📦 STOK / PRODUKSI";
    appendItems($lines, $summary['stock'], 5);

    $lines[] = "";
    $lines[] = "🚨 KOMPLAIN / RISIKO";
    appendItems($lines, $summary['complaints'], 5);

    $lines[] = "";
    $lines[] = "🔎 PESAN PENTING";
    appendItems($lines, $summary['important_messages'], 8);

    $lines[] = "";
    $lines[] = "Link dashboard:";
    $lines[] = "https://adena.co.id/telegram/summary.php?token=" . APP_ACCESS_TOKEN;

    $text = implode("\n", $lines);

    // Telegram sendMessage limit sekitar 4096 karakter.
    // Kita potong aman.
    if (mb_strlen($text, 'UTF-8') > 3900) {
        $text = mb_substr($text, 0, 3900, 'UTF-8') . "\n\n[Ringkasan dipotong. Buka dashboard untuk detail lengkap.]";
    }

    return $text;
}

function appendItems(array &$lines, array $items, $limit = 5)
{
    if (empty($items)) {
        $lines[] = "- Tidak ada data.";
        return;
    }

    foreach (array_slice($items, 0, $limit) as $item) {
        $shortText = trim($item['text']);

        if (mb_strlen($shortText, 'UTF-8') > 120) {
            $shortText = mb_substr($shortText, 0, 120, 'UTF-8') . "...";
        }

        $lines[] = "- [" . $item['group'] . "] " . $item['sender'] . ": " . $shortText;
    }

    if (count($items) > $limit) {
        $lines[] = "- +" . (count($items) - $limit) . " item lain.";
    }
}

function sendTelegramMessage($chatId, $text)
{
    if (!defined('TELEGRAM_BOT_TOKEN') || trim(TELEGRAM_BOT_TOKEN) === '') {
        throw new Exception('TELEGRAM_BOT_TOKEN belum diisi di config.php');
    }

    if (!defined('TELEGRAM_ADMIN_CHAT_ID') || trim((string)TELEGRAM_ADMIN_CHAT_ID) === '') {
        throw new Exception('TELEGRAM_ADMIN_CHAT_ID belum diisi di config.php');
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Curl Telegram error: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($json['ok'])) {
        $message = $json['description'] ?? $response;
        throw new Exception('Telegram sendMessage error: ' . $message);
    }

    return true;
}

function containsAny($text, array $keywords)
{
    $lower = mb_strtolower($text, 'UTF-8');

    foreach ($keywords as $keyword) {
        if (mb_strpos($lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return true;
        }
    }

    return false;
}

function isImportant($text)
{
    return containsAny($text, [
        'urgent',
        'penting',
        'segera',
        'komplain',
        'complain',
        'refund',
        'retur',
        'stok habis',
        'kosong',
        'omset',
        'order',
        'pesanan',
        'transfer',
        'qris',
        'belum dibayar',
        'telat',
        'terlambat',
        'produksi lagi',
        'butuh',
        'masalah',
        'error',
        'gagal'
    ]);
}