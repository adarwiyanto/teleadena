<?php

// config.php
// Konfigurasi database Telegram Summary Agent
// Copy file ini menjadi config.php lalu isi kredensial produksi.

date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// OpenAI opsional. Jika kosong, summary lokal tetap jalan dan analisa gambar akan di-skip.
define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-4.1-mini');
define('OPENAI_VISION_MODEL', 'gpt-4.1-mini');

// Telegram Bot
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_ADMIN_CHAT_ID', '');

// Akses dashboard dan cron
define('APP_ACCESS_TOKEN', 'ganti-token-dashboard');
define('CRON_ACCESS_TOKEN', 'ganti-token-cron');

// Upload foto Telegram untuk analisa gambar/struk
define('TELEGRAM_UPLOAD_DIR', __DIR__ . '/uploads/telegram');
define('TELEGRAM_UPLOAD_URL', ''); // contoh: https://domain.com/telegram/uploads/telegram

define('ENABLE_IMAGE_ANALYSIS', true);
define('MAX_IMAGE_ANALYSIS_BYTES', 5 * 1024 * 1024);

define('APP_TIMEZONE', 'Asia/Jakarta');

function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}
