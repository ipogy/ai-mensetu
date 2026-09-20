<?php
declare(strict_types=1);

// エラー表示を強制ON
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/auth.php';

    $bootstrapPath = realpath(__DIR__ . '/../api/bootstrap.php');
    if (!$bootstrapPath || !file_exists($bootstrapPath)) {
        throw new RuntimeException("bootstrap.php が見つかりません: " . __DIR__ . '/../api/bootstrap.php');
    }
    require_once $bootstrapPath;

    $pdo = getDb();
    $message = '';
    $error = '';

    // 企業新規登録
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_company') {
        $code  = trim($_POST['company_code'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = trim($_POST['password'] ?? '');

        if (!$code || !$name || !$email || !$pass) {
            $error = 'すべての項目を入力してください。';
        } elseif (!preg_match('/^[a-z0-9\-_]+$/i', $code)) {
            $error = '企業コードは半角英数字、ハイフン、アンダースコアのみ使用可能です。';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO companies (id, company_code, name, email, password_hash, is_active)
                    VALUES (:id, :code, :name, :email, :pass, 1)
                ");
                $stmt->execute([
                    'id'    => generateUuidV4(),
                    'code'  => strtolower($code),
                    'name'  => $name,
                    'email' => $email,
                    'pass'  => password_hash($pass, PASSWORD_DEFAULT),
                ]);
                $message = "企業「{$name}」を正常に発行しました！";
            } catch (PDOException $e) {
                $error = '登録エラー（コードまたはメールが既に使われています）: ' . $e->getMessage();
            }
        }
    }

    // 企業状態切り替え（有効/停止）
    if (isset($_GET['toggle_id'])) {
        $toggleId = $_GET['toggle_id'];
        $stmt = $pdo->prepare("UPDATE companies SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
        $stmt->execute(['id' => $toggleId]);
        header('Location: index.php');
        exit;
    }

    // 企業一覧と統計取得（SQLの strict GROUP BY エラーを防止する安全なクエリ）
    $stmt = $pdo->query("
        SELECT c.id, c.company_code, c.name, c.email, c.is_active, c.created_at,
               COALESCE(stats.total_candidates, 0) AS total_candidates,
               COALESCE(stats.completed_interviews, 0) AS completed_interviews
        FROM companies c
        LEFT JOIN (
            SELECT company_id,
                   COUNT(id) AS total_candidates,
                   SUM(CASE WHEN interview_status = 'completed' THEN 1 ELSE 0 END) AS completed_interviews
            FROM candidates
            GROUP BY company_id
        ) stats ON c.id = stats.company_id
        ORDER BY c.created_at DESC
    ");
    $companies = $stmt->fetchAll();

    $baseUrl = rtrim($_ENV['APP_BASE_URL'] ?? 'https://ai-mensetu.ipogy.org', '/');

} catch (\Throwable $e) {
    echo "<div style='background:#fee2e2;color:#991b1b;padding:1.5rem;margin:1rem;border-radius:8px;font-family:monospace;'>";
    echo "<h3>[Dashboard Error] 500エラーの詳細:</h3>";
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
  <title>利用企業管理 - Super Admin</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; padding: 2rem 1rem; color: #1e293b; }
    .container { max-width: 1200px; margin: 0 auto; }
    .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
    .card { background: #fff; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    h1 { font-size: 1.4rem; color: #0f172a; }
    h2 { font-size: 1.15rem; margin-bottom: 1rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
    input { width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.88rem; }
    th { background: #f1f5f9; font-weight: 600; color: #475569; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; display: inline-block; }
    .active { background: #bbf7d0; color: #166534; }
    .inactive { background: #fee2e2; color: #991b1b; }
    .btn { padding: 6px 12px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-primary { background: #0284c7; color: #fff; }
    .btn-danger { background: #dc2626; color: #fff; }
    .btn-secondary { background: #64748b; color: #fff; }
    .msg { background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-weight: 600; }
    .err { background: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <div class="header-row">
    <h1>システム全体管理 (Super Admin) - 利用企業ポータル</h1>
    <a href="login.php?action=logout" class="btn btn-danger">ログアウト</a>
  </div>

  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <h2>新規企業アカウント発行</h2>
    <form method="POST">
      <input type="hidden" name="action" value="create_company">
      <div class="form-grid">
        <div>
          <label>企業名</label>
          <input type="text" name="name" required placeholder="例: トヨタ自動車株式会社">
        </div>
        <div>
          <label>企業コード (英数字URL用)</label>
          <input type="text" name="company_code" required placeholder="例: toyota">
        </div>
        <div>
          <label>企業ログインID (Email)</label>
          <input type="email" name="email" required placeholder="hr@example.com">
        </div>
        <div>
          <label>初期パスワード</label>
          <input type="text" name="password" required placeholder="password123">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">企業を発行・有効化する</button>
    </form>
  </div>

  <div class="card">
    <h2>契約企業一覧 (<?= count($companies) ?>社)</h2>
    <div style="overflow-x: auto;">
      <table>
        <thead>
          <tr>
            <th>企業名 / コード</th>
            <th>ログインID (Email)</th>
            <th>状態</th>
            <th>応募者数</th>
            <th>面接完了数</th>
            <th>専用エントリーURL</th>
            <th>登録日時</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($companies as $c): ?>
          <?php $entryUrl = "{$baseUrl}/entry.html?company=" . urlencode($c['company_code']); ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($c['name']) ?></strong><br>
              <span style="font-size:0.75rem; color:#64748b;">code: <?= htmlspecialchars($c['company_code']) ?></span>
            </td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td>
              <span class="badge <?= $c['is_active'] ? 'active' : 'inactive' ?>">
                <?= $c['is_active'] ? '利用中' : '停止中' ?>
              </span>
            </td>
            <td><strong><?= (int)$c['total_candidates'] ?></strong> 名</td>
            <td><strong><?= (int)$c['completed_interviews'] ?></strong> 件</td>
            <td>
              <button class="btn btn-secondary" onclick="copyText('<?= $entryUrl ?>')">URLコピー</button>
            </td>
            <td><?= htmlspecialchars($c['created_at']) ?></td>
            <td>
              <a href="?toggle_id=<?= urlencode($c['id']) ?>" class="btn <?= $c['is_active'] ? 'btn-danger' : 'btn-primary' ?>" onclick="return confirm('状態を切り替えますか？');">
                <?= $c['is_active'] ? '停止' : '再開' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    alert('企業専用エントリーURLをコピーしました:\n' + text);
  }).catch(() => {
    prompt('URL:', text);
  });
}
</script>
</body>
</html>