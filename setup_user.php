<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null;
$success = null;
$tokenRow = null;

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    if (!is_string($token) || trim($token) === '') {
        throw new RuntimeException('Token setup tidak ditemukan. Kirim /dashboard_user ke Telegram bot untuk membuat link baru.');
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM telegram_dashboard_setup_tokens WHERE token = :token AND used_at IS NULL AND expires_at >= NOW() LIMIT 1");
    $stmt->execute([':token' => $token]);
    $tokenRow = $stmt->fetch();
    if (!$tokenRow) {
        throw new RuntimeException('Token setup tidak valid, sudah dipakai, atau sudah expired. Kirim /dashboard_user ke Telegram bot untuk membuat link baru.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            throw new RuntimeException('Username minimal 3 karakter dan hanya boleh huruf, angka, titik, strip, atau underscore.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password minimal 8 karakter.');
        }
        if ($password !== $password2) {
            throw new RuntimeException('Konfirmasi password tidak sama.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        $stmtUser = $pdo->prepare("\n            INSERT INTO telegram_dashboard_users (username, password_hash, is_active)\n            VALUES (:username, :password_hash, 1)\n            ON DUPLICATE KEY UPDATE\n                password_hash = VALUES(password_hash),\n                is_active = 1,\n                updated_at = NOW()\n        ");
        $stmtUser->execute([':username' => $username, ':password_hash' => $hash]);
        $pdo->prepare("UPDATE telegram_dashboard_setup_tokens SET used_at = NOW() WHERE id = :id")->execute([':id' => $tokenRow['id']]);
        $pdo->commit();

        $success = 'User dashboard berhasil dibuat/direset. Silakan login.';
        $tokenRow = null;
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Setup User Dashboard Telegram Adena</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;color:#222;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:24px;width:100%;max-width:460px}h1{margin:0 0 6px}.muted{color:#667085;font-size:13px;margin-bottom:18px}label{display:block;margin:12px 0 6px;font-size:13px;color:#555}input{box-sizing:border-box;width:100%;padding:11px 12px;border:1px solid #ccc;border-radius:9px;font-size:15px}button,a.button{display:inline-block;text-align:center;box-sizing:border-box;width:100%;margin-top:18px;padding:12px;border:0;border-radius:9px;background:#111;color:#fff;font-size:15px;cursor:pointer;text-decoration:none}.alert{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;border-radius:9px;padding:10px;margin-bottom:12px}.ok{background:#dcfce7;border:1px solid #86efac;color:#14532d;border-radius:9px;padding:10px;margin-bottom:12px}.hint{font-size:12px;color:#667085;margin-top:14px;line-height:1.5}
</style>
</head>
<body>
<div class="card">
    <h1>Setup User Dashboard</h1>
    <div class="muted">Buat atau reset username/password dashboard.</div>
    <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if($success): ?><div class="ok"><?= e($success) ?></div><a class="button" href="login.php">Login Dashboard</a><?php endif; ?>
    <?php if($tokenRow): ?>
    <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required autofocus>
        <label>Password baru</label>
        <input type="password" name="password" autocomplete="new-password" required>
        <label>Ulangi password</label>
        <input type="password" name="password2" autocomplete="new-password" required>
        <button type="submit">Simpan User</button>
    </form>
    <div class="hint">Link ini satu kali pakai dan akan expired otomatis. Password tidak pernah dikirim lewat Telegram.</div>
    <?php endif; ?>
</div>
</body>
</html>
