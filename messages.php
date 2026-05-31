<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAccessToken();

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');
$token = $_GET['token'] ?? $_POST['token'] ?? '';

try {
    $pdo = db();
    $groupId = $_GET['group_id'] ?? '';
    $category = $_GET['category'] ?? '';
    $topicThreadId = $_GET['topic_thread_id'] ?? '';
    $messageType = $_GET['message_type'] ?? '';
    $date = $_GET['date'] ?? date('Y-m-d');

    $where = [];
    $params = [];
    if ($groupId !== '') { $where[] = 'm.telegram_chat_id = :group_id'; $params[':group_id'] = $groupId; }
    if ($category !== '') { $where[] = 'm.detected_category = :category'; $params[':category'] = $category; }
    if ($topicThreadId !== '') {
        if ((string)$topicThreadId === '0') $where[] = '(m.message_thread_id IS NULL OR m.message_thread_id = 0)';
        else { $where[] = 'm.message_thread_id = :thread_id'; $params[':thread_id'] = $topicThreadId; }
    }
    if ($messageType !== '') { $where[] = 'm.message_type = :message_type'; $params[':message_type'] = $messageType; }
    if ($date !== '') { $where[] = 'DATE(m.message_date) = :date'; $params[':date'] = $date; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $groups = $pdo->query("SELECT telegram_chat_id, group_name, group_type FROM telegram_groups WHERE is_active = 1 ORDER BY group_name ASC")->fetchAll();

    $topicSql = "SELECT t.*, g.group_name FROM telegram_topics t LEFT JOIN telegram_groups g ON g.telegram_chat_id=t.telegram_chat_id";
    $topicParams = [];
    if ($groupId !== '') { $topicSql .= " WHERE t.telegram_chat_id=:group_id"; $topicParams[':group_id']=$groupId; }
    $topicSql .= " ORDER BY g.group_name ASC, t.message_thread_id ASC";
    $stmtTopics = $pdo->prepare($topicSql); $stmtTopics->execute($topicParams); $topics = $stmtTopics->fetchAll();

    $stmt = $pdo->prepare("\n        SELECT m.*, g.group_name\n        FROM telegram_messages m\n        LEFT JOIN telegram_groups g ON g.telegram_chat_id = m.telegram_chat_id\n        {$whereSql}\n        ORDER BY m.message_date DESC, m.id DESC\n        LIMIT 500\n    ");
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $stmtStats = $pdo->prepare("\n        SELECT detected_category, COUNT(*) AS total\n        FROM telegram_messages m\n        {$whereSql}\n        GROUP BY detected_category\n        ORDER BY total DESC\n    ");
    $stmtStats->execute($params);
    $stats = $stmtStats->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Error</h3><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function u($token, array $params = []) { return '?' . http_build_query(array_merge(['token' => $token], $params)); }
function categoryBadge($category)
{
    $labels = ['omset'=>'Omset','order'=>'Order','komplain'=>'Komplain','stok'=>'Stok','pembayaran'=>'Pembayaran','pengiriman'=>'Pengiriman','news'=>'News','umum'=>'Umum'];
    $category = $category ?: 'tanpa kategori';
    return $labels[$category] ?? $category;
}
function topicLabel($m)
{
    if (!empty($m['topic_name'])) return $m['topic_name'];
    if (!empty($m['message_thread_id'])) return 'Topic #' . $m['message_thread_id'];
    return 'General / Topik Umum';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Telegram Adena - Messages</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f5f5f5;color:#222;margin:0;padding:20px}.container{max-width:1280px;margin:auto}h1{margin-bottom:4px}.subtitle,.muted{color:#667085;font-size:12px}.card{background:#fff;border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06)}form{display:flex;flex-wrap:wrap;gap:10px;align-items:end}label{font-size:13px;color:#555;display:block;margin-bottom:4px}select,input[type=date],button,a.button{padding:9px 10px;border-radius:7px;border:1px solid #ccc;font-size:14px;background:#fff}button,a.button{background:#222;color:#fff;border:none;cursor:pointer;text-decoration:none;display:inline-block}.reset{color:#555;text-decoration:none;font-size:14px}.stats{display:flex;flex-wrap:wrap;gap:8px}.stat{background:#f0f0f0;border-radius:999px;padding:7px 12px;font-size:14px}table{width:100%;border-collapse:collapse;background:#fff;font-size:14px}th,td{padding:10px;border-bottom:1px solid #eee;vertical-align:top}th{text-align:left;background:#fafafa;color:#555}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;font-size:12px;color:#333;margin:2px}.message{white-space:pre-wrap;line-height:1.45;max-width:520px}.img-preview{max-width:180px;max-height:100px;border-radius:8px;border:1px solid #ddd}.analysis{background:#f8fafc;border-left:3px solid #94a3b8;padding:8px;border-radius:6px;margin-top:8px}.empty{padding:30px;text-align:center;color:#777}@media(max-width:768px){body{padding:10px}table{font-size:12px}.desktop-only{display:none}}
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Telegram Messages</h1>
        <div class="subtitle">Pesan mentah + topik + media + hasil analisa gambar.</div>
        <p><a class="button" href="summary.php<?= e(u($token)) ?>">Dashboard Summary</a></p>
    </div>

    <div class="card">
        <form method="get">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div><label>Tanggal</label><input type="date" name="date" value="<?= e($date) ?>"></div>
            <div><label>Group</label><select name="group_id"><option value="">Semua group</option><?php foreach($groups as $g): ?><option value="<?= e($g['telegram_chat_id']) ?>" <?= (string)$groupId===(string)$g['telegram_chat_id']?'selected':'' ?>><?= e($g['group_name'] ?: $g['telegram_chat_id']) ?></option><?php endforeach; ?></select></div>
            <div><label>Topik</label><select name="topic_thread_id"><option value="">Semua topik</option><?php foreach($topics as $t): ?><option value="<?= e($t['message_thread_id']) ?>" <?= (string)$topicThreadId===(string)$t['message_thread_id']?'selected':'' ?>><?= e(($t['group_name'] ? $t['group_name'].' / ' : '').($t['topic_name'] ?: ('Topic #'.$t['message_thread_id']))) ?></option><?php endforeach; ?></select></div>
            <div><label>Kategori</label><select name="category"><option value="">Semua kategori</option><?php foreach(['omset','order','stok','pembayaran','komplain','pengiriman','news','umum'] as $cat): ?><option value="<?= e($cat) ?>" <?= $category===$cat?'selected':'' ?>><?= e(categoryBadge($cat)) ?></option><?php endforeach; ?></select></div>
            <div><label>Tipe</label><select name="message_type"><option value="">Semua</option><?php foreach(['text'=>'Teks','photo'=>'Foto','document'=>'Dokumen','video'=>'Video','other'=>'Lainnya'] as $val=>$label): ?><option value="<?= e($val) ?>" <?= $messageType===$val?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <button type="submit">Filter</button><a class="reset" href="messages.php<?= e(u($token)) ?>">Reset</a>
        </form>
    </div>

    <div class="card">
        <div class="stats"><?php foreach($stats as $s): ?><span class="stat"><?= e(categoryBadge($s['detected_category'])) ?>: <?= e($s['total']) ?></span><?php endforeach; if(empty($stats)): ?><span class="muted">Belum ada data.</span><?php endif; ?></div>
    </div>

    <div class="card">
        <?php if(empty($messages)): ?><div class="empty">Tidak ada pesan sesuai filter.</div><?php else: ?>
        <table>
            <thead><tr><th>Waktu</th><th>Group / Topik</th><th>Pengirim</th><th>Tipe</th><th>Kategori</th><th>Pesan & Analisa</th><th>Media</th></tr></thead>
            <tbody>
            <?php foreach($messages as $m): ?>
                <tr>
                    <td><?= e($m['message_date']) ?><br><span class="muted">Msg ID: <?= e($m['telegram_message_id']) ?></span></td>
                    <td><b><?= e($m['group_name'] ?: $m['telegram_chat_id']) ?></b><br><span class="badge"><?= e(topicLabel($m)) ?></span><br><span class="muted">Thread: <?= e($m['message_thread_id'] ?? 0) ?></span></td>
                    <td><?= e($m['sender_name']) ?><br><span class="muted">@<?= e($m['sender_username']) ?></span></td>
                    <td><span class="badge"><?= e($m['message_type']) ?></span><?php if($m['image_analysis_status']): ?><br><span class="badge">image: <?= e($m['image_analysis_status']) ?></span><?php endif; ?></td>
                    <td><span class="badge"><?= e(categoryBadge($m['detected_category'])) ?></span></td>
                    <td><div class="message"><?= e($m['message_text']) ?></div><?php if(!empty($m['image_analysis_error'])): ?><div class="analysis"><b>Error image:</b><br><?= e($m['image_analysis_error']) ?></div><?php endif; ?></td>
                    <td><?php if(!empty($m['media_public_url'])): ?><a href="<?= e($m['media_public_url']) ?>" target="_blank"><img class="img-preview" src="<?= e($m['media_public_url']) ?>" alt="media"></a><?php elseif(!empty($m['media_local_path'])): ?><span class="muted"><?= e(basename($m['media_local_path'])) ?></span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
