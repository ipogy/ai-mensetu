<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 企業アカウントとしてログインしていなければ企業ログイン画面へ
if (empty($_SESSION['company_logged_in']) || empty($_SESSION['company_id'])) {
    header('Location: /admin/login.php');
    exit;
}