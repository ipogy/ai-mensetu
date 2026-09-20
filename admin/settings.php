<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

$companyId = $_SESSION['company_id'];
$pdo = getDb();

$message = '';
$error = '';

// 最新の企業情報を取得
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $companyId]);
$company = $stmt->fetch();

if (!$company) {
    die('企業アカウント情報が見つかりません。');
}

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $curPass  = trim($_POST['current_password'] ?? '');
    $newPass  = trim($_POST['new_password'] ?? '');
    $newPassC = trim($_POST['new_password_confirm'] ?? '');

    if (!$name || !$email) {
        $error = '企業名とメールアドレスは必須です。';
    } else {
        // 現在のパスワード検証
        if (!password_verify($curPass, $company['password_hash'])) {
            $error = '現在のパスワードが正しくありません。';
        } else {
            // パスワード変更がある場合のチェック
            $updatePassword = false;
            if ($newPass !== '') {
                if (strlen($newPass) < 6) {
                    $error = '新しいパスワードは6文字以上で入力してください。';
                } elseif ($newPass !== $newPassC) {
                    $error = '新しいパスワード（確認用）が一致しません。';
                } else {
                    $updatePassword = true;
                }
            }

            if (!$error) {
                try {
                    // 他社とメールアドレスが被っていないかチェック
                    $stmtCheck = $pdo->prepare("SELECT id FROM companies WHERE email = :email AND id != :id LIMIT 1");
                    $stmtCheck->execute(['email' => $email, 'id' => $companyId]);
                    if ($stmtCheck->fetch()) {
                        $error = 'そのメールアドレスは既に他のアカウントで使用されています。';
                    } else {
                        if ($updatePassword) {
                            $stmtUp = $pdo->prepare("
                                UPDATE companies 
                                SET name = :name, email = :email, password_hash = :pass 
                                WHERE id = :id
                            ");
                            $stmtUp->execute([
                                'name'  => $name,
                                'email' => $email,
                                'pass'  => password_hash($newPass, PASSWORD_DEFAULT),
                                'id'    => $companyId
                            ]);
                        } else {
                            $stmtUp = $pdo->prepare("
                                UPDATE companies 
                                SET name = :name, email = :email 
                                WHERE id = :id
                            ");
                            $stmtUp->execute([
                                'name'  => $name,
                                'email' => $email,
                                'id'    => $companyId
                            ]);
                        }

                        // セッション情報も同期更新
                        $_SESSION['company_name'] = $name;

                        // 画面表示用データ更新
                        $company['name'] = $name;
                        $company['email'] = $email;

                        $message = '設定を正常に更新・保存しました。';
                    }
                } catch (PDOException $e) {
                    $error = '更新エラー: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>企業アカウント設定 - <?= htmlspecialchars($company['name']) ?></title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; padding: 2rem 1rem; color: #1e293b; }
    .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 2.2rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    h1 { font-size: 1.35rem; margin-bottom: 0.5rem; color: #0f172a; }
    p.desc { font-size: 0.85rem; color: #64748b; margin-bottom: 1.5rem; }
    .nav-back { display: inline-block; margin-bottom: 1.2rem; color: #2563eb; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
    .form-group { margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.35rem; }
    input[type="text"], input[type="email"], input[type="password"] {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      font-size: 0.95rem;
      box-sizing: border-box;
    }
    input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
    .section-divider { border-top: 1px solid #e2e8f0; margin: 1.75rem 0 1.25rem 0; padding-top: 1rem; }
    .btn-submit {
      width: 100%;
      background: #2563eb;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 0.85rem;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 1rem;
    }
    .btn-submit:hover { background: #1d4ed8; }
    .msg { background: #dcfce7; color: #166534; padding: 0.8rem; border-radius: 6px; margin-bottom: 1.2rem; font-size: 0.9rem; font-weight: 600; }
    .err { background: #fee2e2; color: #991b1b; padding: 0.8rem; border-radius: 6px; margin-bottom: 1.2rem; font-size: 0.9rem; font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <a href="/admin/index.php" class="nav-back">&larr; ダッシュボードへ戻る</a>
  <h1>企業アカウント設定</h1>
  <p class="desc">企業名や連絡用メールアドレス、ログインパスワードを変更できます。</p>

  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST">
    <div class="form-group">
      <label>企業コード (変更不可)</label>
      <input type="text" value="<?= htmlspecialchars($company['company_code']) ?>" disabled>
      <small style="color:#64748b; font-size:0.75rem;">※URLの識別コード（?company=...）はシステム管理者が管理しています。</small>
    </div>

    <div class="form-group">
      <label>企業名（求職者へのメール・画面に表示されます）</label>
      <input type="text" name="name" required value="<?= htmlspecialchars($company['name']) ?>">
    </div>

    <div class="form-group">
      <label>企業ログイン用メールアドレス</label>
      <input type="email" name="email" required value="<?= htmlspecialchars($company['email']) ?>">
    </div>

    <div class="section-divider">
      <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">パスワード変更（変更する場合のみ入力）</h3>
      <small style="color: #64748b; display: block; margin-bottom: 1rem;">パスワードを変更しない場合は空欄のままにしてください。</small>

      <div class="form-group">
        <label>新しいパスワード</label>
        <input type="password" name="new_password" placeholder="6文字以上で入力">
      </div>

      <div class="form-group">
        <label>新しいパスワード（確認用）</label>
        <input type="password" name="new_password_confirm" placeholder="もう一度入力してください">
      </div>
    </div>

    <div class="section-divider">
      <div class="form-group">
        <label style="color:#b91c1c;">現在のパスワード（本人確認のため必須）*</label>
        <input type="password" name="current_password" required placeholder="現在のパスワードを入力してください">
      </div>
    </div>

    <button type="submit" class="btn-submit">変更内容を保存する</button>
  </form>
</div>
</body>
</html>