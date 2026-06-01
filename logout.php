<?php
require_once __DIR__ . '/auth.php';
logoutDashboardUser();
header('Location: login.php');
exit;
