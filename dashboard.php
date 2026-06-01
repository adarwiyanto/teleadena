<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireDashboardLogin();
header('Location: ' . dashboardUrl('summary.php'));
exit;
