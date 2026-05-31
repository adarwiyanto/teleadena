<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireAccessToken();

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = db();

    $groupId = $_GET['group_id'] ?? '';
    $category = $_GET['category'] ?? '';
    $date = $_GET['date'] ?? date('Y-m-d');

    $where = [];
    $params = [];

    if ($groupId !== '') {
        $where[] = 'm.telegram_chat_id = :group_id';
        $params[':group_id'] = $groupId;
    }

    if ($category !== '') {
        $where[] = 'm.detected_category = :category';
        $params[':category'] = $category;
    }

    if ($date !== '') {
        $where[] = 'DATE(m.message_date) = :date';
        $params[':date'] = $date;
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

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
        ORDER BY m.message_date DESC, m.id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $stmtStats = $pdo->prepare("
        SELECT 
            detected_category,
            COUNT(*) AS total
        FROM telegram_messages m
        {$whereSql}
        GROUP BY detected_category
        ORDER BY total DESC
    ");
    $stmtStats->execute($params);
    $stats = $stmtStats->fetchAll();

} catch (Throwable $e) {
    http_response_code(500);
    echo '<h3>Error</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function categoryBadge($category)
{
    $category = $category ?: 'tanpa kategori';

    $labels = [
        'omset' => 'Omset',
        'order' => 'Order',
        'komplain' => 'Komplain',
        'stok' => 'Stok',
        'pembayaran' => 'Pembayaran',
        'pengiriman' => 'Pengiriman',
        'news' => 'News',
        'umum' => 'Umum',
    ];

    return $labels[$category] ?? $category;
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Telegram Summary Agent - Messages</title>
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
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            margin-bottom: 4px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 20px;
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

        select, input[type="date"], button, a.button {
            padding: 9px 10px;
            border-radius: 7px;
            border: 1px solid #ccc;
            font-size: 14px;
            background: #fff;
        }

        button, a.button {
            background: #222;
            color: #fff;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        a.reset {
            color: #555;
            text-decoration: none;
            margin-left: 6px;
            font-size: 14px;
        }

        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .stat {
            background: #f0f0f0;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 14px;
        }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        th {
            text-align: left;
            background: #fafafa;
            color: #555;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef2ff;
            font-size: 12px;
            color: #333;
        }

        .message {
            white-space: pre-wrap;
            line-height: 1.45;
        }

        .muted {
            color: #777;
            font-size: 12px;
        }

        .empty {
            padding: 30px;
            text-align: center;
            color: #777;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            table, thead, tbody, th, td, tr {
                display: block;
            }

            thead {
                display: none;
            }

            tr {
                margin-bottom: 12px;
                border-bottom: 2px solid #eee;
                padding-bottom: 8px;
            }

            td {
                border-bottom: none;
                padding: 6px 10px;
            }

            td::before {
                content: attr(data-label);
                display: block;
                font-weight: bold;
                color: #555;
                margin-bottom: 2px;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <h1>Telegram Summary Agent</h1>
    <div class="subtitle">
        Halaman cek pesan masuk dari grup Telegram.
    </div>

    <div class="card">
        <form method="get">
            <div>
                <label>Tanggal</label>
                <input type="date" name="date" value="<?= e($date) ?>">
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
                <label>Kategori</label>
                <select name="category">
                    <option value="">Semua kategori</option>
                    <?php
                    $categories = ['omset', 'order', 'komplain', 'stok', 'pembayaran', 'pengiriman', 'news', 'umum'];
                    foreach ($categories as $cat):
                    ?>
                        <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                            <?= e(categoryBadge($cat)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit">Filter</button>
                <a class="reset" href="messages.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <strong>Ringkasan cepat</strong>
        <div class="stats" style="margin-top: 10px;">
            <div class="stat">Total pesan: <?= count($messages) ?></div>

            <?php foreach ($stats as $stat): ?>
                <div class="stat">
                    <?= e(categoryBadge($stat['detected_category'])) ?>:
                    <?= e($stat['total']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <?php if (empty($messages)): ?>
            <div class="empty">
                Belum ada pesan sesuai filter ini.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Grup</th>
                        <th>Pengirim</th>
                        <th>Kategori</th>
                        <th>Pesan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td data-label="Waktu">
                                <?= e($msg['message_date']) ?>
                            </td>
                            <td data-label="Grup">
                                <?= e($msg['group_name'] ?: $msg['telegram_chat_id']) ?>
                                <div class="muted"><?= e($msg['telegram_chat_id']) ?></div>
                            </td>
                            <td data-label="Pengirim">
                                <?= e($msg['sender_name'] ?: '-') ?>
                                <?php if (!empty($msg['sender_username'])): ?>
                                    <div class="muted">@<?= e($msg['sender_username']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Kategori">
                                <span class="badge"><?= e(categoryBadge($msg['detected_category'])) ?></span>
                            </td>
                            <td data-label="Pesan">
                                <div class="message"><?= e($msg['message_text'] ?: '[' . $msg['message_type'] . ']') ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>