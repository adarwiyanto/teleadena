<?php

// config.php
// Konfigurasi database Telegram Summary Agent

date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-4.1-mini');

define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_ADMIN_CHAT_ID', '');

define('APP_ACCESS_TOKEN', 'Dyto*0806');
define('CRON_ACCESS_TOKEN', 'Dyto*0806');

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