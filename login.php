<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');
startDashboardSession();

if (isDashboardLoggedIn()) {
    header('Location: summary.php');
    exit;
}

$error = null;
$username = trim($_POST['username'] ?? '');
$next = $_GET['next'] ?? $_POST['next'] ?? 'summary.php';
if (!is_string($next) || preg_match('/^https?:\/\//i', $next)) {
    $next = 'summary.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM telegram_dashboard_users WHERE username = :username AND is_active = 1 LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $pdo->prepare("UPDATE telegram_dashboard_users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id")->execute([':id' => $user['id']]);
            loginDashboardUser($user);
            header('Location: ' . $next);
            exit;
        }
        $error = 'Username atau password salah.';
    } catch (Throwable $e) {
        $error = 'Gagal login: ' . $e->getMessage();
    }
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Login Dashboard Telegram Adena</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;color:#222;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:24px;width:100%;max-width:420px}h1{margin:0 0 6px}.muted{color:#667085;font-size:13px;margin-bottom:18px}label{display:block;margin:12px 0 6px;font-size:13px;color:#555}input{box-sizing:border-box;width:100%;padding:11px 12px;border:1px solid #ccc;border-radius:9px;font-size:15px}button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:9px;background:#111;color:#fff;font-size:15px;cursor:pointer}.alert{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;border-radius:9px;padding:10px;margin-bottom:12px}.hint{font-size:12px;color:#667085;margin-top:14px;line-height:1.5}
</style>
</head>
<body>
<div class="card">
    <h1>Login Dashboard</h1>
    <div class="muted">Telegram Adena Summary</div>
    <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <label>Username</label>
        <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <button type="submit">Masuk</button>
    </form>
    <div class="hint">Untuk membuat/reset user, kirim command <b>/dashboard_user</b> ke Telegram bot.</div>
</div>
</body>
</html>
