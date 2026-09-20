<?php
declare(strict_types=1);

// エラーを画面に表示
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($currentScript !== 'login.php') {
    if (empty($_SESSION['super_admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }
}