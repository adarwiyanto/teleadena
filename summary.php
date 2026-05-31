<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAccessToken();

date_default_timezone_set('Asia/Jakarta');

$aiError = null;
$aiSummary = null;

try {
    $pdo = db();

    $period = $_GET['period'] ?? 'today';
    $groupId = $_GET['group_id'] ?? '';
    $mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'local');

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

    $stmtGroups = $pdo->query("
        SELECT telegram_chat_id, group_name, group_type
        FROM telegram_groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");
    $groups = $stmtGroups->fetchAll();

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

    $localSummary = buildLocalSummary($messages, $periodLabel, $dateStart, $dateEnd);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'ai') {
        if (empty($messages)) {
            $aiError = 'Tidak ada pesan pada periode/filter ini.';
        } elseif (!defined('OPENAI_API_KEY') || trim(OPENAI_API_KEY) === '') {
            $aiError = 'OPENAI_API_KEY belum diisi di config.php. Summary lokal tetap tersedia.';
        } else {
            try {
                $aiSummary = generateAiSummary($messages, $periodLabel, $dateStart, $dateEnd);

                $targetGroupName = null;
                if ($groupId !== '') {
                    foreach ($groups as $g) {
                        if ((string)$g['telegram_chat_id'] === (string)$groupId) {
                            $targetGroupName = $g['group_name'];
                            break;
                        }
                    }
                }

                $stmtSave = $pdo->prepare("
                    INSERT INTO telegram_summaries
                        (
                            summary_period,
                            telegram_chat_id,
                            group_name,
                            date_start,
                            date_end,
                            summary_text,
                            key_actions
                        )
                    VALUES
                        (
                            :summary_period,
                            :telegram_chat_id,
                            :group_name,
                            :date_start,
                            :date_end,
                            :summary_text,
                            :key_actions
                        )
                ");

                $stmtSave->execute([
                    ':summary_period' => $period,
                    ':telegram_chat_id' => $groupId !== '' ? $groupId : null,
                    ':group_name' => $targetGroupName,
                    ':date_start' => $dateStart,
                    ':date_end' => $dateEnd,
                    ':summary_text' => $aiSummary,
                    ':key_actions' => extractKeyActionsPlain($aiSummary),
                ]);

            } catch (Throwable $e) {
                $aiError = $e->getMessage();
            }
        }
    }

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
        return [$start, $end, '7 hari terakhir'];
    }

    $start = $today->format('Y-m-d 00:00:00');
    $end = $today->format('Y-m-d 23:59:59');
    return [$start, $end, 'Hari ini'];
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
        'important_messages' => [],
        'orders' => [],
        'omset' => [],
        'stock' => [],
        'complaints' => [],
        'payments' => [],
        'delivery' => [],
        'news' => [],
        'action_items' => [],
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

    $summary['action_items'] = makeActionItems($summary);

    return $summary;
}

function generateAiSummary(array $messages, $periodLabel, $dateStart, $dateEnd)
{
    $grouped = [];

    foreach ($messages as $msg) {
        $groupName = $msg['group_name'] ?: $msg['telegram_chat_id'];

        if (!isset($grouped[$groupName])) {
            $grouped[$groupName] = [];
        }

        $sender = $msg['sender_name'] ?: '-';
        $category = $msg['detected_category'] ?: 'umum';
        $time = $msg['message_date'] ?: '-';
        $text = trim((string)($msg['message_text'] ?: '[' . $msg['message_type'] . ']'));

        if ($text === '') {
            continue;
        }

        $grouped[$groupName][] = "[{$time}] ({$category}) {$sender}: {$text}";
    }

    $conversationText = '';

    foreach ($grouped as $groupName => $lines) {
        $conversationText .= "\n\n=== GRUP: {$groupName} ===\n";
        $conversationText .= implode("\n", array_slice($lines, 0, 500));
    }

    if (mb_strlen($conversationText, 'UTF-8') > 60000) {
        $conversationText = mb_substr($conversationText, 0, 60000, 'UTF-8') . "\n\n[Data dipangkas karena terlalu panjang]";
    }

    $systemPrompt = <<<PROMPT
Anda adalah Telegram Summary Agent untuk pemilik bisnis.

Tugas:
1. Ringkas percakapan grup Telegram secara padat dan berguna.
2. Fokus pada news, omset, order, stok, pembayaran, pengiriman, komplain, masalah operasional, peluang bisnis, dan pesan yang perlu ditindaklanjuti.
3. Jangan mengarang data.
4. Bila angka tidak jelas, tulis "tidak disebutkan jelas".
5. Pisahkan fakta dari interpretasi.
6. Tulis dalam Bahasa Indonesia.

Format output wajib:

RANGKUMAN TELEGRAM - [PERIODE]

1. GAMBARAN UMUM
- ...

2. RINGKASAN PER GRUP
Nama Grup:
- Topik utama:
- Order/omset:
- Komplain/risiko:
- Hal yang perlu diperhatikan:

3. OMSET / ORDER / STOK
- ...

4. KOMPLAIN DAN MASALAH OPERASIONAL
- ...

5. NEWS / INFO PENTING
- ...

6. ACTION ITEM UNTUK DOKTER
- [Prioritas tinggi] ...
- [Prioritas sedang] ...
- [Prioritas rendah] ...

7. PESAN YANG PERLU DIBALAS
- ...

8. CATATAN AKHIR
- ...
PROMPT;

    $userPrompt = "Periode: {$periodLabel}\nRentang waktu: {$dateStart} sampai {$dateEnd}\n\nData chat Telegram:\n{$conversationText}";

    $payload = [
        'model' => OPENAI_MODEL,
        'input' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => $userPrompt
            ]
        ],
        'temperature' => 0.2,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $json['error']['message'] ?? $response;
        throw new Exception('OpenAI API error: ' . $message);
    }

    if (isset($json['output_text'])) {
        return trim($json['output_text']);
    }

    $texts = [];

    if (!empty($json['output']) && is_array($json['output'])) {
        foreach ($json['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (isset($content['text'])) {
                        $texts[] = $content['text'];
                    }
                }
            }
        }
    }

    if (!empty($texts)) {
        return trim(implode("\n", $texts));
    }

    return 'Ringkasan AI berhasil dibuat, tetapi format respons tidak terbaca.';
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

function makeActionItems(array $summary)
{
    $actions = [];

    if (count($summary['complaints']) > 0) {
        $actions[] = [
            'priority' => 'Tinggi',
            'text' => 'Review dan follow-up komplain/customer bermasalah.',
            'count' => count($summary['complaints'])
        ];
    }

    if (count($summary['stock']) > 0) {
        $actions[] = [
            'priority' => 'Sedang',
            'text' => 'Cek stok dan kebutuhan produksi/restock.',
            'count' => count($summary['stock'])
        ];
    }

    if (count($summary['payments']) > 0) {
        $actions[] = [
            'priority' => 'Sedang',
            'text' => 'Verifikasi pembayaran/transfer/QRIS yang disebut di grup.',
            'count' => count($summary['payments'])
        ];
    }

    if (count($summary['orders']) > 0) {
        $actions[] = [
            'priority' => 'Sedang',
            'text' => 'Rekap order/pesanan dan pastikan status fulfillment.',
            'count' => count($summary['orders'])
        ];
    }

    if (count($summary['delivery']) > 0) {
        $actions[] = [
            'priority' => 'Rendah',
            'text' => 'Cek pengiriman, kurir, ongkir, dan keterlambatan delivery.',
            'count' => count($summary['delivery'])
        ];
    }

    if (empty($actions)) {
        $actions[] = [
            'priority' => 'Rendah',
            'text' => 'Tidak ada action item spesifik berdasarkan keyword lokal.',
            'count' => 0
        ];
    }

    return $actions;
}

function extractKeyActionsPlain($summaryText)
{
    $lines = explode("\n", (string)$summaryText);
    $capture = false;
    $actions = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if (stripos($trim, 'ACTION ITEM') !== false) {
            $capture = true;
            continue;
        }

        if ($capture && preg_match('/^\d+\./', $trim)) {
            break;
        }

        if ($capture && $trim !== '') {
            $actions[] = $trim;
        }
    }

    return implode("\n", $actions);
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function showItems(array $items, $limit = 10)
{
    if (empty($items)) {
        echo '<div class="muted">Tidak ada data.</div>';
        return;
    }

    echo '<ul class="item-list">';

    foreach (array_slice($items, 0, $limit) as $item) {
        echo '<li>';
        echo '<div><strong>' . e($item['group']) . '</strong> <span class="muted">[' . e($item['time']) . ']</span></div>';
        echo '<div class="muted">Pengirim: ' . e($item['sender']) . ' · Kategori: ' . e($item['category']) . '</div>';
        echo '<div>' . e($item['text']) . '</div>';
        echo '</li>';
    }

    echo '</ul>';

    if (count($items) > $limit) {
        echo '<div class="muted">+' . (count($items) - $limit) . ' item lain tidak ditampilkan.</div>';
    }
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Telegram Summary Agent</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #222;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1150px;
            margin: auto;
        }

        h1 {
            margin-bottom: 4px;
        }

        h2 {
            margin-top: 0;
            font-size: 20px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 20px;
        }

        .topnav {
            margin-bottom: 12px;
        }

        .topnav a {
            color: #222;
            text-decoration: none;
            margin-right: 12px;
            font-size: 14px;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
        }

        label {
            font-size: 13px;
            color: #555;
            display: block;
            margin-bottom: 4px;
        }

        select, button {
            padding: 9px 10px;
            border-radius: 7px;
            border: 1px solid #ccc;
            font-size: 14px;
            background: #fff;
        }

        button {
            background: #222;
            color: #fff;
            border: none;
            cursor: pointer;
        }

        .button-local {
            background: #222;
        }

        .button-ai {
            background: #0f766e;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .stat {
            background: #fff;
            border-radius: 10px;
            padding: 14px;
            border: 1px solid #eee;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .stat-value {
            font-size: 26px;
            font-weight: bold;
        }

        .stat-label {
            color: #666;
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            background: #eef2ff;
            border-radius: 999px;
            font-size: 13px;
            margin: 3px;
        }

        .priority-high {
            background: #ffe9e9;
        }

        .priority-medium {
            background: #fff6d8;
        }

        .priority-low {
            background: #eaf7ea;
        }

        .muted {
            color: #777;
            font-size: 13px;
        }

        .item-list {
            padding-left: 20px;
            margin-top: 8px;
        }

        .item-list li {
            margin-bottom: 12px;
            line-height: 1.45;
        }

        .summary-box {
            white-space: pre-wrap;
            line-height: 1.55;
            font-size: 15px;
        }

        .error-box {
            background: #fff3f3;
            color: #8a1f1f;
            border: 1px solid #ffc9c9;
            padding: 12px;
            border-radius: 8px;
        }

        .success-box {
            background: #effaf5;
            color: #14532d;
            border: 1px solid #bbf7d0;
            padding: 12px;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            text-align: left;
            padding: 9px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        th {
            background: #fafafa;
            color: #555;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="topnav">
        <a href="messages.php">← Lihat Pesan</a>
        <a href="summary.php">Summary</a>
    </div>

    <h1>Telegram Summary Agent</h1>
    <div class="subtitle">
        Summary lokal selalu tersedia. Summary AI aktif bila OpenAI API dan billing tersedia.
    </div>

    <div class="card">
        <form method="get">
            <div>
                <label>Periode</label>
                <select name="period">
                    <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Hari ini</option>
                    <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>Kemarin</option>
                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>7 hari terakhir</option>
                </select>
            </div>

            <div>
                <label>Grup</label>
                <select name="group_id">
                    <option value="">Semua grup</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e($group['telegram_chat_id']) ?>"
                            <?= ((string)$groupId === (string)$group['telegram_chat_id']) ? 'selected' : '' ?>>
                            <?= e($group['group_name'] ?: $group['telegram_chat_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button class="button-local" type="submit">Tampilkan Summary Lokal</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="mode" value="ai">
            <button class="button-ai" type="submit">Buat Summary AI</button>
        </form>
        <div class="muted" style="margin-top: 8px;">
            Jika AI gagal karena quota/billing, summary lokal di bawah tetap dapat digunakan.
        </div>
    </div>

    <?php if ($aiError): ?>
        <div class="card">
            <div class="error-box">
                <strong>Summary AI gagal:</strong><br>
                <?= e($aiError) ?><br><br>
                Summary lokal tetap ditampilkan di bawah.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($aiSummary): ?>
        <div class="card">
            <div class="success-box">
                Summary AI berhasil dibuat dan disimpan ke database.
            </div>
        </div>

        <div class="card">
            <h2>Summary AI</h2>
            <div class="summary-box"><?= e($aiSummary) ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Rangkuman Lokal - <?= e($localSummary['period_label']) ?></h2>
        <div class="muted">
            Rentang: <?= e($localSummary['date_start']) ?> s.d. <?= e($localSummary['date_end']) ?>
        </div>
    </div>

    <div class="grid">
        <div class="stat">
            <div class="stat-value"><?= e($localSummary['total_messages']) ?></div>
            <div class="stat-label">Total pesan</div>
        </div>

        <div class="stat">
            <div class="stat-value"><?= e(count($localSummary['orders'])) ?></div>
            <div class="stat-label">Pesan order</div>
        </div>

        <div class="stat">
            <div class="stat-value"><?= e(count($localSummary['omset'])) ?></div>
            <div class="stat-label">Pesan omset</div>
        </div>

        <div class="stat">
            <div class="stat-value"><?= e(count($localSummary['complaints'])) ?></div>
            <div class="stat-label">Komplain/risiko</div>
        </div>
    </div>

    <div class="card">
        <h2>Distribusi Kategori</h2>
        <?php if (empty($localSummary['categories'])): ?>
            <div class="muted">Belum ada kategori.</div>
        <?php else: ?>
            <?php foreach ($localSummary['categories'] as $cat => $total): ?>
                <span class="badge"><?= e($cat) ?>: <?= e($total) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Ringkasan Per Grup</h2>

        <?php if (empty($localSummary['groups'])): ?>
            <div class="muted">Belum ada pesan pada periode ini.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Grup</th>
                        <th>Total Pesan</th>
                        <th>Kategori Dominan</th>
                        <th>Pesan Penting</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($localSummary['groups'] as $groupName => $group): ?>
                        <?php
                        arsort($group['categories']);
                        $dominant = [];
                        foreach (array_slice($group['categories'], 0, 3, true) as $cat => $total) {
                            $dominant[] = $cat . ' (' . $total . ')';
                        }
                        ?>
                        <tr>
                            <td><?= e($groupName) ?></td>
                            <td><?= e($group['total']) ?></td>
                            <td><?= e(implode(', ', $dominant)) ?></td>
                            <td><?= e(count($group['important'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Action Item Lokal</h2>
        <?php foreach ($localSummary['action_items'] as $action): ?>
            <?php
            $class = 'priority-low';
            if ($action['priority'] === 'Tinggi') {
                $class = 'priority-high';
            } elseif ($action['priority'] === 'Sedang') {
                $class = 'priority-medium';
            }
            ?>
            <div class="badge <?= e($class) ?>">
                [<?= e($action['priority']) ?>] <?= e($action['text']) ?>
                <?php if ($action['count'] > 0): ?>
                    — <?= e($action['count']) ?> item
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Order / Pesanan</h2>
        <?php showItems($localSummary['orders'], 10); ?>
    </div>

    <div class="card">
        <h2>Omset / Penjualan</h2>
        <?php showItems($localSummary['omset'], 10); ?>
    </div>

    <div class="card">
        <h2>Stok / Produksi</h2>
        <?php showItems($localSummary['stock'], 10); ?>
    </div>

    <div class="card">
        <h2>Komplain / Risiko Operasional</h2>
        <?php showItems($localSummary['complaints'], 10); ?>
    </div>

    <div class="card">
        <h2>Pembayaran</h2>
        <?php showItems($localSummary['payments'], 10); ?>
    </div>

    <div class="card">
        <h2>Pengiriman</h2>
        <?php showItems($localSummary['delivery'], 10); ?>
    </div>

    <div class="card">
        <h2>News / Info Penting</h2>
        <?php showItems($localSummary['news'], 10); ?>
    </div>

    <div class="card">
        <h2>Pesan Penting Terdeteksi</h2>
        <?php showItems($localSummary['important_messages'], 20); ?>
    </div>

</div>

</body>
</html>