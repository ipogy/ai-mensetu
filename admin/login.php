<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ログアウト処理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['company_logged_in'], $_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['company_code']);
    header('Location: /admin/login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');

    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $company = $stmt->fetch();

    if ($company && password_verify($pass, $company['password_hash'])) {
        if (!$company['is_active']) {
            $error = 'この企業アカウントは現在利用停止されています。システム管理者へお問い合わせください。';
        } else {
            $_SESSION['company_logged_in'] = true;
            $_SESSION['company_id']        = $company['id'];
            $_SESSION['company_name']      = $company['name'];
            $_SESSION['company_code']      = $company['company_code'];
            header('Location: /admin/index.php');
            exit;
        }
    } else {
        $error = 'メールアドレスまたはパスワードが正しくありません。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>企業管理者ログイン - AI面接システム</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #0f172a; }
    .card { background: #ffffff; padding: 2.2rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
    h1 { font-size: 1.3rem; margin-bottom: 1.2rem; text-align: center; color: #2563eb; }
    .form-group { margin-bottom: 1rem; }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
    input { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; }
    .btn { width: 100%; padding: 0.85rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
    .btn:hover { background: #1d4ed8; }
    .error { background: #fee2e2; color: #dc2626; padding: 0.6rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
<div class="card">
  <h1>企業管理者ログイン</h1>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label>企業用メールアドレス</label>
      <input type="email" name="email" required autofocus placeholder="hr@example.com">
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