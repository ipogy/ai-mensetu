<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

$companyId   = $_SESSION['company_id'];
$companyName = $_SESSION['company_name'];
$companyCode = $_SESSION['company_code'];

$pdo = getDb();

$stmt = $pdo->prepare("
    SELECT c.*, s.id AS session_id, s.ended_at 
    FROM candidates c 
    LEFT JOIN (
        SELECT s1.* FROM interview_sessions s1
        INNER JOIN (
            SELECT candidate_id, MAX(started_at) as max_started 
            FROM interview_sessions GROUP BY candidate_id
        ) s2 ON s1.candidate_id = s2.candidate_id AND s1.started_at = s2.max_started
    ) s ON c.id = s.candidate_id 
    WHERE c.company_id = :cid
    ORDER BY c.created_at DESC
");
$stmt->execute(['cid' => $companyId]);
$candidates = $stmt->fetchAll();

$baseUrl = rtrim($_ENV['APP_BASE_URL'] ?? 'https://ai-mensetu.ipogy.org', '/');
$selfEntryUrl = "{$baseUrl}/entry.html?company=" . urlencode($companyCode);

function getStatusBadge(string $status): string {
    $map = [
        'applied'     => ['class' => 'badge-applied',     'label' => '書類選考中'],
        'ready'       => ['class' => 'badge-ready',       'label' => '面接案内済(未受検)'],
        'in_progress' => ['class' => 'badge-in-progress', 'label' => '面接中'],
        'completed'   => ['class' => 'badge-completed',   'label' => '面接完了'],
        'es_failed'   => ['class' => 'badge-failed',      'label' => '書類見送り'],
    ];
    $item = $map[$status] ?? ['class' => 'badge-applied', 'label' => $status];
    return "<span class=\"badge {$item['class']}\">{$item['label']}</span>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($companyName) ?> - 採用管理ダッシュボード</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; padding: 2rem 1rem; color: #1e293b; }
    .container { max-width: 1140px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
    .header-actions { display: flex; align-items: center; gap: 1.25rem; }
    h1 { font-size: 1.35rem; color: #0f172a; margin: 0; }
    .company-banner { background: #eff6ff; border: 1px solid #bfdbfe; padding: 0.85rem 1.2rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
    .banner-buttons { display: flex; gap: 8px; }
    .nav-link { color: #2563eb; text-decoration: none; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; }
    .nav-link:hover { text-decoration: underline; }
    .logout-link { color: #dc2626; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.88rem; }
    th { background: #f1f5f9; font-weight: 600; color: #475569; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; display: inline-block; }
    .badge-applied { background: #fef3c7; color: #92400e; }
    .badge-ready { background: #e0e7ff; color: #3730a3; }
    .badge-in-progress { background: #fed7aa; color: #9a3412; }
    .badge-completed { background: #bbf7d0; color: #166534; }
    .badge-failed { background: #fee2e2; color: #991b1b; }

    .btn { display: inline-block; text-decoration: none; padding: 6px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-success { background: #16a34a; color: #fff; }
    .btn-secondary { background: #64748b; color: #fff; }
    .btn-danger { background: #dc2626; color: #fff; }
    .btn-recover { background: #d97706; color: #fff; }
    .btn-csv { background: #059669; color: #fff; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
    .modal-content { background: #fff; width: 90%; max-width: 600px; padding: 1.5rem; border-radius: 10px; max-height: 80vh; overflow-y: auto; }
    .modal-content h3 { margin-bottom: 1rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem; }
    .es-section { margin-bottom: 1rem; background: #f8fafc; padding: 0.75rem; border-radius: 6px; }
    .es-title { font-weight: bold; color: #334155; margin-bottom: 0.25rem; font-size: 0.85rem; }
  </style>
</head>
<body>
<div class="container">
  <div class="header-row">
    <h1><?= htmlspecialchars($companyName) ?> 様 - 採用管理</h1>
    <div class="header-actions">
      <!-- ★メール文面設定への導線を追加 -->
      <a href="/admin/email_settings.php" class="nav-link">✉️ メール文面設定</a>
      <a href="/admin/settings.php" class="nav-link">⚙️ 企業アカウント設定</a>
      <a href="/admin/login.php?action=logout" class="logout-link">ログアウト</a>
    </div>
  </div>

  <div class="company-banner">
    <div>
      <strong>📌 貴社専用エントリーURL:</strong> <span style="font-family:monospace; color:#1e40af;"><?= $selfEntryUrl ?></span>
    </div>
    <div class="banner-buttons">
      <button class="btn btn-secondary" onclick="copyUrl('<?= $selfEntryUrl ?>')">URLコピー</button>
      <a href="/admin/export_csv.php" class="btn btn-csv">📥 選考データCSV出力</a>
    </div>
  </div>

  <div style="overflow-x: auto;">
    <table>
      <thead>
        <tr>
          <th>応募者情報</th>
          <th>選考状況</th>
          <th>ES確認</th>
          <th>書類選考判定</th>
          <th>面接レポート</th>
          <th>応募日時</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($candidates)): ?>
          <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 2rem;">まだ応募者はありません。上記の専用URLを候補者へ配布してください。</td></tr>
        <?php endif; ?>
        <?php foreach ($candidates as $c): ?>
        <?php 
          $es = json_decode($c['entry_sheet_data'] ?? '{}', true);
          $interviewUrl = "{$baseUrl}/index.html?candidate_id={$c['id']}";
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($c['name']) ?></strong><br>
            <span style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($c['email']) ?></span>
          </td>
          <td><?= getStatusBadge($c['interview_status']) ?></td>
          <td>
            <button class="btn btn-secondary" onclick="viewES(<?= htmlspecialchars(json_encode([
              'name' => $c['name'],
              'es'   => $es
            ]), ENT_QUOTES, 'UTF-8') ?>)">ES閲覧</button>
          </td>
          <td>
            <?php if ($c['interview_status'] === 'applied'): ?>
              <form action="/admin/screening.php" method="POST" style="display:inline;" onsubmit="return confirm('書類選考を合格とし、面接案内メールを送信しますか？');">
                <input type="hidden" name="candidate_id" value="<?= htmlspecialchars($c['id']) ?>">
                <input type="hidden" name="action" value="pass">
                <button type="submit" class="btn btn-success">書類合格 (面接案内)</button>
              </form>
              <form action="/admin/screening.php" method="POST" style="display:inline; margin-left: 4px;" onsubmit="return confirm('書類選考を見送りにしますか？');">
                <input type="hidden" name="candidate_id" value="<?= htmlspecialchars($c['id']) ?>">
                <input type="hidden" name="action" value="fail">
                <button type="submit" class="btn btn-danger">見送り</button>
              </form>
            <?php elseif ($c['interview_status'] === 'es_failed'): ?>
              <form action="/admin/screening.php" method="POST" style="display:inline;" onsubmit="return confirm('【誤操作の救済】\n見送りを取り消し、「お詫びと書類通過・面接案内メール」を候補者に送信しますか？');">
                <input type="hidden" name="candidate_id" value="<?= htmlspecialchars($c['id']) ?>">
                <input type="hidden" name="action" value="repass">
                <button type="submit" class="btn btn-recover">⚠️ 復活（お詫び＆面接案内）</button>
              </form>
            <?php elseif ($c['interview_status'] === 'ready'): ?>
              <button class="btn btn-secondary" onclick="copyUrl('<?= $interviewUrl ?>')">面接URL再コピー</button>
            <?php else: ?>
              <span style="color: #94a3b8; font-size: 0.8rem;">選考済</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($c['session_id']): ?>
              <a href="/admin/report.php?session_id=<?= urlencode($c['session_id']) ?>" class="btn btn-primary">レポート閲覧</a>
            <?php else: ?>
              <span style="color:#94a3b8;">面接未実施</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($c['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="esModal" onclick="closeModal(event)">
  <div class="modal-content" onclick="event.stopPropagation()">
    <h3 id="modalCandidateName">エントリーシート詳細</h3>
    <div class="es-section">
      <div class="es-title">【自己PR】</div>
      <div id="modalPR" style="white-space: pre-wrap; font-size: 0.9rem;"></div>
    </div>
    <div class="es-section">
      <div class="es-title">【学生時代に最も力を入れたこと（ガクチカ）】</div>
      <div id="modalGakuchika" style="white-space: pre-wrap; font-size: 0.9rem;"></div>
    </div>
    <div class="es-section">
      <div class="es-title">【志望動機】</div>
      <div id="modalMotivation" style="white-space: pre-wrap; font-size: 0.9rem;"></div>
    </div>
    <div style="text-align: right; margin-top: 1rem;">
      <button class="btn btn-secondary" onclick="document.getElementById('esModal').style.display = 'none'">閉じる</button>
    </div>
  </div>
</div>

<script>
function viewES(data) {
  document.getElementById('modalCandidateName').innerText = data.name + ' 様 のエントリーシート';
  document.getElementById('modalPR').innerText = data.es.pr || '未記入';
  document.getElementById('modalGakuchika').innerText = data.es.gakuchika || '未記入';
  document.getElementById('modalMotivation').innerText = data.es.motivation || '未記入';
  document.getElementById('esModal').style.display = 'flex';
}

function closeModal(e) {
  if (e.target.id === 'esModal') {
    document.getElementById('esModal').style.display = 'none';
  }
}

function copyUrl(url) {
  navigator.clipboard.writeText(url).then(() => {
    alert('面接用URLをクリップボードにコピーしました:\n' + url);
  }).catch(() => {
    prompt('URL:', url);
  });
}
</script>
</body>
</html>