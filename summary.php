<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAccessToken();

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$aiError = null;
$aiSummary = null;

try {
    $pdo = db();

    $period = $_GET['period'] ?? 'today';
    $groupId = $_GET['group_id'] ?? '';
    $topicThreadId = $_GET['topic_thread_id'] ?? '';
    $messageType = $_GET['message_type'] ?? '';
    $mode = $_POST['mode'] ?? ($_GET['mode'] ?? 'local');

    [$dateStart, $dateEnd, $periodLabel] = resolvePeriod($period);

    [$whereSql, $params] = buildMessageWhere($dateStart, $dateEnd, $groupId, $topicThreadId, $messageType);
    $groups = fetchGroups($pdo);
    $topics = fetchTopics($pdo, $groupId);
    $messages = fetchMessages($pdo, $whereSql, $params, 5000);
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
                        if ((string)$g['telegram_chat_id'] === (string)$groupId) $targetGroupName = $g['group_name'];
                    }
                }
                $stmtSave = $pdo->prepare("\n                    INSERT INTO telegram_summaries\n                        (summary_period, telegram_chat_id, group_name, date_start, date_end, summary_text, key_actions)\n                    VALUES\n                        (:summary_period, :telegram_chat_id, :group_name, :date_start, :date_end, :summary_text, :key_actions)\n                ");
                $stmtSave->execute([
                    ':summary_period' => $period,
                    ':telegram_chat_id' => $groupId !== '' ? $groupId : null,
                    ':group_name' => $targetGroupName,
                    ':date_start' => $dateStart,
                    ':date_end' => $dateEnd,
                    ':summary_text' => $aiSummary,
                    ':key_actions' => null,
                ]);
            } catch (Throwable $e) {
                $aiError = 'Gagal membuat AI summary: ' . $e->getMessage();
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Error</h3><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function u($token, array $params = []) { return '?' . http_build_query(array_merge(['token' => $token], $params)); }

function resolvePeriod($period)
{
    $now = new DateTime('now');
    if ($period === 'yesterday') {
        $start = (new DateTime('yesterday'))->setTime(0, 0, 0);
        $end = (new DateTime('yesterday'))->setTime(23, 59, 59);
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Kemarin'];
    }
    if ($period === '7days') {
        $start = (new DateTime('-6 days'))->setTime(0, 0, 0);
        $end = $now->setTime(23, 59, 59);
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), '7 Hari Terakhir'];
    }
    if ($period === 'month') {
        $start = (new DateTime('first day of this month'))->setTime(0, 0, 0);
        $end = (new DateTime('last day of this month'))->setTime(23, 59, 59);
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Bulan Ini'];
    }
    $start = (new DateTime('today'))->setTime(0, 0, 0);
    $end = (new DateTime('today'))->setTime(23, 59, 59);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Hari Ini'];
}

function buildMessageWhere($dateStart, $dateEnd, $groupId, $topicThreadId, $messageType)
{
    $where = ['m.message_date >= :date_start', 'm.message_date <= :date_end'];
    $params = [':date_start' => $dateStart, ':date_end' => $dateEnd];
    if ($groupId !== '') { $where[] = 'm.telegram_chat_id = :group_id'; $params[':group_id'] = $groupId; }
    if ($topicThreadId !== '') {
        if ((string)$topicThreadId === '0') $where[] = '(m.message_thread_id IS NULL OR m.message_thread_id = 0)';
        else { $where[] = 'm.message_thread_id = :thread_id'; $params[':thread_id'] = $topicThreadId; }
    }
    if ($messageType !== '') { $where[] = 'm.message_type = :message_type'; $params[':message_type'] = $messageType; }
    return ['WHERE ' . implode(' AND ', $where), $params];
}

function fetchGroups(PDO $pdo)
{
    return $pdo->query("SELECT telegram_chat_id, group_name, group_type FROM telegram_groups WHERE is_active = 1 ORDER BY group_name ASC")->fetchAll();
}

function fetchTopics(PDO $pdo, $groupId = '')
{
    $sql = "SELECT t.*, g.group_name FROM telegram_topics t LEFT JOIN telegram_groups g ON g.telegram_chat_id=t.telegram_chat_id";
    $params = [];
    if ($groupId !== '') { $sql .= " WHERE t.telegram_chat_id = :group_id"; $params[':group_id'] = $groupId; }
    $sql .= " ORDER BY g.group_name ASC, t.message_thread_id ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}

function fetchMessages(PDO $pdo, $whereSql, array $params, $limit)
{
    $stmt = $pdo->prepare("\n        SELECT m.*, g.group_name\n        FROM telegram_messages m\n        LEFT JOIN telegram_groups g ON g.telegram_chat_id = m.telegram_chat_id\n        {$whereSql}\n        ORDER BY m.message_date ASC, m.id ASC\n        LIMIT " . (int)$limit . "\n    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function buildLocalSummary(array $messages, $periodLabel, $dateStart, $dateEnd)
{
    $summary = [
        'period_label' => $periodLabel, 'date_start' => $dateStart, 'date_end' => $dateEnd,
        'total_messages' => count($messages), 'text_messages' => 0, 'photo_messages' => 0,
        'image_done' => 0, 'image_skipped' => 0, 'image_error' => 0,
        'groups' => [], 'topics' => [], 'categories' => [],
        'orders' => [], 'omset' => [], 'stock' => [], 'complaints' => [], 'payments' => [], 'delivery' => [], 'news' => [], 'images' => [],
    ];
    foreach ($messages as $m) {
        $group = $m['group_name'] ?: (string)$m['telegram_chat_id'];
        $thread = $m['message_thread_id'] ?? 0;
        $topic = $m['topic_name'] ?: (($thread && (int)$thread !== 0) ? 'Topic #' . $thread : 'General / Topik Umum');
        $cat = $m['detected_category'] ?: 'umum';
        $type = $m['message_type'] ?: 'text';
        $text = trim((string)($m['message_text'] ?? ''));
        $preview = mb_substr(preg_replace('/\s+/', ' ', $text), 0, 260);
        $item = ['time' => $m['message_date'], 'group' => $group, 'topic' => $topic, 'sender' => $m['sender_name'], 'text' => $preview, 'type' => $type];

        if ($type === 'photo') $summary['photo_messages']++; else $summary['text_messages']++;
        if ($m['image_analysis_status'] === 'done') $summary['image_done']++;
        if ($m['image_analysis_status'] === 'skipped') $summary['image_skipped']++;
        if ($m['image_analysis_status'] === 'error') $summary['image_error']++;

        if (!isset($summary['groups'][$group])) $summary['groups'][$group] = ['total' => 0, 'photos' => 0, 'topics' => [], 'categories' => [], 'important' => []];
        $summary['groups'][$group]['total']++;
        if ($type === 'photo') $summary['groups'][$group]['photos']++;
        $summary['groups'][$group]['topics'][$topic] = ($summary['groups'][$group]['topics'][$topic] ?? 0) + 1;
        $summary['groups'][$group]['categories'][$cat] = ($summary['groups'][$group]['categories'][$cat] ?? 0) + 1;

        $topicKey = $group . ' / ' . $topic;
        if (!isset($summary['topics'][$topicKey])) $summary['topics'][$topicKey] = ['total' => 0, 'photos' => 0, 'categories' => [], 'important' => []];
        $summary['topics'][$topicKey]['total']++;
        if ($type === 'photo') $summary['topics'][$topicKey]['photos']++;
        $summary['topics'][$topicKey]['categories'][$cat] = ($summary['topics'][$topicKey]['categories'][$cat] ?? 0) + 1;

        $summary['categories'][$cat] = ($summary['categories'][$cat] ?? 0) + 1;
        if ($type === 'photo') $summary['images'][] = $item;

        $lower = mb_strtolower($text, 'UTF-8');
        if ($cat === 'order' || containsAny($lower, ['order', 'pesanan', 'beli', 'checkout', 'customer'])) $summary['orders'][] = $item;
        if ($cat === 'omset' || containsAny($lower, ['omset', 'revenue', 'pendapatan', 'total jual', 'total', 'subtotal'])) $summary['omset'][] = $item;
        if ($cat === 'stok' || containsAny($lower, ['stok', 'stock', 'habis', 'ready', 'kosong', 'restock', 'produksi'])) $summary['stock'][] = $item;
        if ($cat === 'komplain' || containsAny($lower, ['komplain', 'complain', 'keluhan', 'refund', 'retur', 'telat'])) $summary['complaints'][] = $item;
        if ($cat === 'pembayaran' || containsAny($lower, ['transfer', 'qris', 'cash', 'tunai', 'bayar', 'payment', 'lunas', 'utang'])) $summary['payments'][] = $item;
        if ($cat === 'pengiriman' || containsAny($lower, ['kirim', 'delivery', 'kurir', 'gojek', 'grab', 'ongkir', 'pickup'])) $summary['delivery'][] = $item;
        if ($cat === 'news' || containsAny($lower, ['info', 'berita', 'pengumuman', 'urgent', 'penting'])) $summary['news'][] = $item;
    }
    arsort($summary['categories']);
    uasort($summary['topics'], fn($a, $b) => $b['total'] <=> $a['total']);
    uasort($summary['groups'], fn($a, $b) => $b['total'] <=> $a['total']);
    return $summary;
}

function containsAny($text, array $needles)
{
    foreach ($needles as $needle) if (mb_strpos($text, $needle) !== false) return true;
    return false;
}

function generateAiSummary(array $messages, $periodLabel, $dateStart, $dateEnd)
{
    $lines = [];
    foreach (array_slice($messages, -1200) as $m) {
        $group = $m['group_name'] ?: $m['telegram_chat_id'];
        $topic = $m['topic_name'] ?: (($m['message_thread_id'] ?? null) ? 'Topic #' . $m['message_thread_id'] : 'General / Topik Umum');
        $sender = $m['sender_name'] ?: '-';
        $type = $m['message_type'] ?: 'text';
        $text = trim((string)($m['message_text'] ?? ''));
        if ($text === '' && $type === 'photo') $text = '[photo tanpa hasil analisa]';
        $lines[] = '[' . $m['message_date'] . ' | Group: ' . $group . ' | Topic: ' . $topic . ' | Sender: ' . $sender . ' | Type: ' . $type . '] ' . $text;
    }
    $content = implode("\n", $lines);
    if (mb_strlen($content, 'UTF-8') > 55000) $content = mb_substr($content, -55000, null, 'UTF-8');

    $prompt = "Buat rangkuman dashboard operasional dari chat Telegram. Data mencakup semua group dan semua topik/forum, termasuk hasil analisa foto/struk bila ada.\n" .
        "Periode: {$periodLabel} ({$dateStart} s.d. {$dateEnd})\n\n" .
        "Format wajib:\n" .
        "1. Gambaran umum\n2. Ringkasan per group\n3. Ringkasan per topik\n4. Order/pesanan\n5. Omset/penjualan/pembayaran\n6. Stok/produksi\n7. Foto/struk yang terbaca\n8. Risiko/komplain\n9. Action item prioritas\n\n" .
        "Jangan mengarang angka. Jika angka tidak jelas, tulis tidak terbaca/tidak disebutkan.\n\nDATA:\n" . $content;

    $payload = ['model' => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4.1-mini', 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.2];
    $res = postJson('https://api.openai.com/v1/chat/completions', $payload, ['Authorization: Bearer ' . OPENAI_API_KEY, 'Content-Type: application/json']);
    return trim($res['choices'][0]['message']['content'] ?? '');
}

function postJson($url, array $payload, array $headers = [])
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 90]);
        $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $code >= 400) throw new RuntimeException('HTTP error: ' . $code . ' ' . $err . ' ' . substr((string)$raw, 0, 500));
    } else {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 90]]);
        $raw = file_get_contents($url, false, $context);
        if ($raw === false) throw new RuntimeException('HTTP request gagal.');
    }
    $json = json_decode($raw, true); if (!is_array($json)) throw new RuntimeException('Response JSON tidak valid.'); return $json;
}

function renderList(array $items, $limit = 8)
{
    if (empty($items)) return '<div class="muted">Tidak ada item.</div>';
    $html = '<ul class="compact">';
    foreach (array_slice($items, 0, $limit) as $it) {
        $html .= '<li><b>' . e($it['group']) . '</b> / ' . e($it['topic']) . ' — ' . e($it['text']) . '<br><span class="muted">' . e($it['time']) . ' · ' . e($it['sender']) . '</span></li>';
    }
    $html .= '</ul>'; return $html;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Telegram Adena Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;color:#222;margin:0;padding:20px}.container{max-width:1280px;margin:auto}h1{margin:0 0 4px}.subtitle,.muted{color:#667085;font-size:13px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.card{background:#fff;border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 2px 10px rgba(0,0,0,.06)}.metric{font-size:28px;font-weight:bold;margin-top:6px}form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}label{font-size:13px;color:#555;display:block;margin-bottom:4px}select,button,a.button{padding:9px 10px;border-radius:8px;border:1px solid #ccc;background:#fff;font-size:14px}button,a.button{background:#111;color:#fff;border:none;text-decoration:none;cursor:pointer}.reset{color:#555;text-decoration:none;font-size:14px}.badge{display:inline-block;border-radius:999px;padding:4px 8px;background:#eef2ff;font-size:12px;margin:2px}.compact{margin:8px 0 0 18px;padding:0}.compact li{margin-bottom:8px;line-height:1.4}table{width:100%;border-collapse:collapse;font-size:14px}th,td{border-bottom:1px solid #eee;padding:9px;text-align:left;vertical-align:top}pre{white-space:pre-wrap;line-height:1.5;background:#101828;color:#f2f4f7;padding:14px;border-radius:10px;overflow:auto}.alert{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;border-radius:10px;padding:12px;margin-bottom:12px}@media(max-width:900px){.grid,.grid2{grid-template-columns:1fr}body{padding:10px}}
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Telegram Adena Dashboard</h1>
        <div class="subtitle">Rangkuman teks + gambar/foto struk dari semua group dan semua topik.</div>
        <p><a class="button" href="messages.php<?= e(u($token)) ?>">Lihat Pesan</a></p>
    </div>

    <div class="card">
        <form method="get">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div><label>Periode</label><select name="period"><option value="today" <?= $period==='today'?'selected':'' ?>>Hari ini</option><option value="yesterday" <?= $period==='yesterday'?'selected':'' ?>>Kemarin</option><option value="7days" <?= $period==='7days'?'selected':'' ?>>7 hari</option><option value="month" <?= $period==='month'?'selected':'' ?>>Bulan ini</option></select></div>
            <div><label>Group</label><select name="group_id"><option value="">Semua group</option><?php foreach($groups as $g): ?><option value="<?= e($g['telegram_chat_id']) ?>" <?= (string)$groupId===(string)$g['telegram_chat_id']?'selected':'' ?>><?= e($g['group_name'] ?: $g['telegram_chat_id']) ?></option><?php endforeach; ?></select></div>
            <div><label>Topik</label><select name="topic_thread_id"><option value="">Semua topik</option><?php foreach($topics as $t): ?><option value="<?= e($t['message_thread_id']) ?>" <?= (string)$topicThreadId===(string)$t['message_thread_id']?'selected':'' ?>><?= e(($t['group_name'] ? $t['group_name'].' / ' : '').($t['topic_name'] ?: ('Topic #'.$t['message_thread_id']))) ?></option><?php endforeach; ?></select></div>
            <div><label>Tipe</label><select name="message_type"><option value="">Semua</option><option value="text" <?= $messageType==='text'?'selected':'' ?>>Teks</option><option value="photo" <?= $messageType==='photo'?'selected':'' ?>>Foto</option></select></div>
            <button type="submit">Filter</button><a class="reset" href="summary.php<?= e(u($token)) ?>">Reset</a>
        </form>
    </div>

    <div class="grid">
        <div class="card"><div class="muted">Total pesan</div><div class="metric"><?= e($localSummary['total_messages']) ?></div></div>
        <div class="card"><div class="muted">Foto/gambar</div><div class="metric"><?= e($localSummary['photo_messages']) ?></div></div>
        <div class="card"><div class="muted">Gambar dianalisa</div><div class="metric"><?= e($localSummary['image_done']) ?></div></div>
        <div class="card"><div class="muted">Topik terbaca</div><div class="metric"><?= e(count($localSummary['topics'])) ?></div></div>
    </div>

    <div class="grid2">
        <div class="card"><h3>Kategori</h3><?php foreach($localSummary['categories'] as $cat=>$total): ?><span class="badge"><?= e(ucfirst($cat)) ?>: <?= e($total) ?></span><?php endforeach; if(empty($localSummary['categories'])): ?><div class="muted">Belum ada data.</div><?php endif; ?></div>
        <div class="card"><h3>Status Analisa Gambar</h3><span class="badge">Done: <?= e($localSummary['image_done']) ?></span><span class="badge">Skipped: <?= e($localSummary['image_skipped']) ?></span><span class="badge">Error: <?= e($localSummary['image_error']) ?></span></div>
    </div>

    <div class="card"><h3>Ringkasan per Group</h3><table><tr><th>Group</th><th>Pesan</th><th>Foto</th><th>Topik</th><th>Kategori dominan</th></tr><?php foreach($localSummary['groups'] as $name=>$g): arsort($g['categories']); ?><tr><td><?= e($name) ?></td><td><?= e($g['total']) ?></td><td><?= e($g['photos']) ?></td><td><?php foreach($g['topics'] as $tn=>$ct): ?><span class="badge"><?= e($tn) ?>: <?= e($ct) ?></span><?php endforeach; ?></td><td><?php foreach(array_slice($g['categories'],0,3,true) as $cat=>$ct): ?><span class="badge"><?= e($cat) ?>: <?= e($ct) ?></span><?php endforeach; ?></td></tr><?php endforeach; ?></table></div>

    <div class="card"><h3>Ringkasan per Topik</h3><table><tr><th>Group / Topik</th><th>Pesan</th><th>Foto</th><th>Kategori</th></tr><?php foreach($localSummary['topics'] as $name=>$t): arsort($t['categories']); ?><tr><td><?= e($name) ?></td><td><?= e($t['total']) ?></td><td><?= e($t['photos']) ?></td><td><?php foreach(array_slice($t['categories'],0,4,true) as $cat=>$ct): ?><span class="badge"><?= e($cat) ?>: <?= e($ct) ?></span><?php endforeach; ?></td></tr><?php endforeach; ?></table></div>

    <div class="grid2">
        <div class="card"><h3>Order/Pesanan</h3><?= renderList($localSummary['orders']) ?></div>
        <div class="card"><h3>Omset/Pembayaran</h3><?= renderList(array_merge($localSummary['omset'],$localSummary['payments'])) ?></div>
        <div class="card"><h3>Stok/Produksi</h3><?= renderList($localSummary['stock']) ?></div>
        <div class="card"><h3>Komplain/Risiko</h3><?= renderList($localSummary['complaints']) ?></div>
    </div>

    <div class="card"><h3>Foto/Struk Terdeteksi</h3><?= renderList($localSummary['images'], 12) ?></div>

    <div class="card">
        <h3>AI Summary</h3>
        <?php if($aiError): ?><div class="alert"><?= e($aiError) ?></div><?php endif; ?>
        <?php if($aiSummary): ?><pre><?= e($aiSummary) ?></pre><?php else: ?>
            <form method="post"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="mode" value="ai"><button type="submit">Buat AI Summary</button></form>
            <div class="muted">AI membaca teks + hasil analisa gambar dari semua topik sesuai filter.</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
