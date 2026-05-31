<?php

function requireAccessToken()
{
    if (!defined('APP_ACCESS_TOKEN') || trim(APP_ACCESS_TOKEN) === '') {
        http_response_code(500);
        echo 'APP_ACCESS_TOKEN belum diatur.';
        exit;
    }

    $token = $_GET['token'] ?? $_POST['token'] ?? '';

    if (!hash_equals(APP_ACCESS_TOKEN, $token)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

function requireCronToken()
{
    if (!defined('CRON_ACCESS_TOKEN') || trim(CRON_ACCESS_TOKEN) === '') {
        http_response_code(500);
        echo 'CRON_ACCESS_TOKEN belum diatur.';
        exit;
    }

    $token = $_GET['token'] ?? $_POST['token'] ?? '';

    if (!hash_equals(CRON_ACCESS_TOKEN, $token)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}