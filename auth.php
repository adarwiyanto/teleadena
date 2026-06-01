<?php

function startDashboardSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function hasValidAccessToken()
{
    if (!defined('APP_ACCESS_TOKEN') || trim(APP_ACCESS_TOKEN) === '') {
        return false;
    }
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    return is_string($token) && $token !== '' && hash_equals(APP_ACCESS_TOKEN, $token);
}

function isDashboardLoggedIn()
{
    startDashboardSession();
    return !empty($_SESSION['telegram_dashboard_user_id']);
}

function requireAccessToken()
{
    if (hasValidAccessToken()) {
        return;
    }

    if (isDashboardLoggedIn()) {
        return;
    }

    $next = $_SERVER['REQUEST_URI'] ?? 'summary.php';
    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

function requireDashboardLogin()
{
    requireAccessToken();
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

function currentDashboardUser()
{
    startDashboardSession();
    return [
        'id' => $_SESSION['telegram_dashboard_user_id'] ?? null,
        'username' => $_SESSION['telegram_dashboard_username'] ?? null,
    ];
}

function loginDashboardUser(array $user)
{
    startDashboardSession();
    session_regenerate_id(true);
    $_SESSION['telegram_dashboard_user_id'] = $user['id'];
    $_SESSION['telegram_dashboard_username'] = $user['username'];
}

function logoutDashboardUser()
{
    startDashboardSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function dashboardUrl($path, array $params = [])
{
    if (hasValidAccessToken()) {
        $params = array_merge(['token' => $_GET['token'] ?? $_POST['token'] ?? ''], $params);
    }
    $query = $params ? ('?' . http_build_query($params)) : '';
    return $path . $query;
}
