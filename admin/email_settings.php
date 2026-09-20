<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

$companyId = $_SESSION['company_id'];
$pdo = getDb();

$message = '';
$error = '';

// 企業情報の取得
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $companyId]);
$company = $stmt->fetch();

if (!$company) {
    die('企業アカウントが見つかりません。');
}

$types = [
    'entry_received' => [
        'title' => '1. エントリーシート受付完了メール',
        'desc'  => '候補者がエントリーシート（応募フォーム）を送信した直後に自動送信されます。',
    ],
    'screening_pass' => [
        'title' => '2. 書類選考通過・面接案内メール',
        'desc'  => '管理画面で書類選考を「合格」にした際に送信されます。※ {interview_url} を必ず記載してください。',
    ],
    'screening_fail' => [
        'title' => '3. 書類選考お見送りメール',
        'desc'  => '管理画面で書類選考を「見送り」にした際に送信されます。',
    ],
    'interview_pass' => [
        'title' => '4. 面接選考通過・次回選考案内メール',
        'desc'  => '面接レポート画面で合否判定を「合格」として保存・送信した際に届きます。',
    ],
];

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['templates'] ?? [];
    $cleanTemplates = [];

    foreach ($types as $key => $meta) {
        $cleanTemplates[$key] = [
            'subject' => trim($submitted[$key]['subject'] ?? ''),
            'body'    => trim($submitted[$key]['body'] ?? ''),
        ];
    }

    try {
        $stmtUpdate = $pdo->prepare("UPDATE companies SET email_templates = :tpl WHERE id = :id");
        $stmtUpdate->execute([
            'tpl' => json_encode($cleanTemplates, JSON_UNESCAPED_UNICODE),
            'id'  => $companyId
        ]);
        $company['email_templates'] = json_encode($cleanTemplates, JSON_UNESCAPED_UNICODE);
        $message = 'メールテンプレートの設定を正常に保存しました！';
    } catch (PDOException $e) {
        $error = '保存エラー: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>メール文面設定 - <?= htmlspecialchars($company['name']) ?></title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; padding: 2rem 1rem; color: #1e293b; }
    .container { max-width: 860px; margin: 0 auto; background: #fff; padding: 2.2rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    h1 { font-size: 1.35rem; color: #0f172a; margin-bottom: 0.3rem; }
    .nav-back { display: inline-block; margin-bottom: 1.2rem; color: #2563eb; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .tag-box { background: #eff6ff; border: 1px solid #bfdbfe; padding: 1rem 1.2rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #1e40af; line-height: 1.6; }
    .tag-box code { background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; color: #1e3a8a; }
    .tpl-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; }
    .tpl-title { font-weight: 700; font-size: 1.05rem; color: #0f172a; margin-bottom: 0.25rem; }
    .tpl-desc { font-size: 0.8rem; color: #64748b; margin-bottom: 0.85rem; }
    .form-group { margin-bottom: 0.85rem; }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: #334155; }
    input[type="text"] { width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.92rem; box-sizing: border-box; }
    textarea { width: 100%; height: 130px; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 0.88rem; line-height: 1.55; box-sizing: border-box; }
    .btn-submit { background: #2563eb; color: #fff; border: none; padding: 0.85rem 2.5rem; font-size: 1rem; font-weight: 600; border-radius: 6px; cursor: pointer; display: block; margin: 1.5rem auto 0 auto; }
    .btn-submit:hover { background: #1d4ed8; }
    .msg { background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-weight: 600; font-size: 0.9rem; }
    .err { background: #fee2e2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-weight: 600; font-size: 0.9rem; }
  </style>
</head>
<body>
<div class="container">
  <a href="/admin/index.php" class="nav-back">&larr; ダッシュボードへ戻る</a>
  <h1>企業専用 メールテンプレート設定</h1>
  <p style="font-size:0.88rem; color:#64748b; margin-bottom:1.2rem;">候補者に自動送信されるメールの件名および本文を、貴社のトーンに合わせてカスタマイズできます。</p>

  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="tag-box">
    <strong>💡 使用可能な置換タグ（自動展開されます）:</strong><br>
    ・<code>{candidate_name}</code> : 応募者・候補者の氏名<br>
    ・<code>{company_name}</code> : 貴社名<br>
    ・<code>{interview_url}</code> : 候補者専用のAI面接受検URL（面接案内メール用）
  </div>

  <form method="POST">
    <?php foreach ($types as $key => $meta): ?>
      <?php $tpl = getCompanyEmailTemplate($company, $key); ?>
      <div class="tpl-card">
        <div class="tpl-title"><?= htmlspecialchars($meta['title']) ?></div>
        <div class="tpl-desc"><?= htmlspecialchars($meta['desc']) ?></div>
        <div class="form-group">
          <label>件名</label>
          <input type="text" name="templates[<?= $key ?>][subject]" value="<?= htmlspecialchars($tpl['subject']) ?>" required>
        </div>
        <div class="form-group">
          <label>本文</label>
          <textarea name="templates[<?= $key ?>][body]" required><?= htmlspecialchars($tpl['body']) ?></textarea>
        </div>
      </div>
    <?php endforeach; ?>

    <button type="submit" class="btn-submit">テンプレート設定を保存する</button>
  </form>
</div>
</body>
</html>