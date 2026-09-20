<?php
declare(strict_types=1);

// エラー表示を強制ON
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

try {
    // 確実にパスを解決
    $bootstrapPath = realpath(__DIR__ . '/../api/bootstrap.php');
    if (!$bootstrapPath || !file_exists($bootstrapPath)) {
        throw new RuntimeException("bootstrap.php が見つかりません: " . __DIR__ . '/../api/bootstrap.php');
    }
    require_once $bootstrapPath;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'logout') {
        unset($_SESSION['super_admin_logged_in'], $_SESSION['super_admin_user']);
        header('Location: login.php');
        exit;
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');

        // .env から取得、未設定時のデフォルト値も用意
        $expectedUser = $_ENV['SUPER_ADMIN_USER'] ?? 'superadmin';
        $expectedPass = $_ENV['SUPER_ADMIN_PASS'] ?? 'supersecret2026';

        if ($user !== '' && $user === $expectedUser && $pass === $expectedPass) {
            $_SESSION['super_admin_logged_in'] = true;
            $_SESSION['super_admin_user'] = $user;
            header('Location: index.php');
            exit;
        } else {
            $error = 'IDまたはパスワードが正しくありません。';
        }
    }
} catch (\Throwable $e) {
    echo "<div style='background:#fee2e2;color:#991b1b;padding:1.5rem;margin:1rem;border-radius:8px;font-family:monospace;'>";
    echo "<h3>[Login Error] 500エラーの詳細:</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " (Line: " . $e->getLine() . ")</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>システム管理者ログイン - Super Admin</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #f8fafc; padding: 1rem; }
    .card { background: #1e293b; padding: 2.2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); width: 100%; max-width: 400px; }
    h1 { font-size: 1.3rem; margin-bottom: 1.2rem; text-align: center; color: #38bdf8; }
    .form-group { margin-bottom: 1rem; }
    label { display: block; font-size: 0.85rem; margin-bottom: 0.3rem; color: #94a3b8; font-weight: 600; }
    input { width: 100%; padding: 0.75rem; border: 1px solid #334155; border-radius: 6px; font-size: 1rem; background: #0f172a; color: #fff; }
    input:focus { outline: none; border-color: #38bdf8; }
    .btn { width: 100%; padding: 0.85rem; background: #0284c7; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: bold; cursor: pointer; margin-top: 0.5rem; transition: background 0.2s; }
    .btn:hover { background: #0369a1; }
    .error { background: #7f1d1d; color: #fecaca; padding: 0.7rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem; text-align: center; }
  </style>
</head>
<body>
<div class="card">
  <h1>システム全体管理<br><span style="font-size: 0.9rem; color: #94a3b8;">(Super Admin)</span></h1>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label>管理者ID</label>
      <input type="text" name="username" required autofocus placeholder="superadmin">
    </div>
    <div class="form-group">
      <label>パスワード</label>
      <input type="password" name="password" required placeholder="••••••••">
    </div>
    <button type="submit" class="btn">ログイン</button>
  </form>
</div>
</body>
</html>