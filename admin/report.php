<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    die('session_id is required');
}

$pdo = getDb();
$companyId = $_SESSION['company_id'] ?? '';

// 自社候補者のセッションであることを確認
$stmt = $pdo->prepare("
    SELECT s.*, c.id AS candidate_id, c.name, c.email, c.final_decision, c.reviewer_comment, c.company_id
    FROM interview_sessions s 
    JOIN candidates c ON s.candidate_id = c.id 
    WHERE s.id = :id AND c.company_id = :cid
");
$stmt->execute(['id' => $sessionId, 'cid' => $companyId]);
$session = $stmt->fetch();

if (!$session) {
    die('面接データが見つかりません。アクセス権限限をご確認ください。');
}

$summary   = json_decode($session['evaluation_summary'] ?? '{}', true);
$integrity = json_decode($session['integrity_log'] ?? '{}', true);

$stmtTurns = $pdo->prepare("SELECT * FROM interview_turns WHERE session_id = :sid ORDER BY turn_number ASC");
$stmtTurns->execute(['sid' => $sessionId]);
$turns = $stmtTurns->fetchAll();

$reloadCount = (int)($session['reload_count'] ?? 0);
$isSaved = isset($_GET['saved']) && $_GET['saved'] === '1';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>面接評価レポート - <?= htmlspecialchars($session['name']) ?></title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; padding: 2rem 1rem; color: #1e293b; }
    .container { max-width: 960px; margin: 0 auto; }
    .card { background: #fff; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    h1, h2, h3, h4 { margin-top: 0; }
    .score-badge { font-size: 2.2rem; font-weight: bold; color: #2563eb; }
    .turn-box { border-left: 4px solid #3b82f6; padding: 1rem; margin-bottom: 1.25rem; background: #f8fafc; border-radius: 0 8px 8px 0; }
    .audio-player { margin-top: 0.5rem; display: block; width: 100%; }
    .meta-box { display: flex; gap: 0.75rem; margin-top: 0.75rem; flex-wrap: wrap; }
    .meta-item { background: #e2e8f0; padding: 0.35rem 0.75rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
    .meta-time { background: #fef3c7; color: #92400e; }
    
    .badge-clean { background: #dcfce7; color: #166534; padding: 0.4rem 0.8rem; border-radius: 6px; font-weight: bold; font-size: 0.85rem; }
    .badge-warn { background: #fee2e2; color: #991b1b; padding: 0.4rem 0.8rem; border-radius: 6px; font-weight: bold; font-size: 0.85rem; }
    .success-alert { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 0.85rem 1.2rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; }

    /* ガイド・免責バナー */
    .disclaimer-banner {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      padding: 0.9rem 1.1rem;
      margin-bottom: 1.25rem;
      font-size: 0.84rem;
      color: #1e40af;
      line-height: 1.6;
    }

    .integrity-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }
    .integrity-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 0.9rem;
    }
    .integrity-label { font-size: 0.8rem; color: #64748b; margin-bottom: 0.25rem; font-weight: 600; }
    .integrity-val { font-size: 1.1rem; font-weight: 700; color: #0f172a; }
    .val-warn { color: #dc2626 !important; }
    .val-ok { color: #16a34a !important; }

    .guideline-box {
      margin-top: 1rem;
      background: #fffbeb;
      border: 1px solid #fde68a;
      border-radius: 6px;
      padding: 0.75rem 1rem;
      font-size: 0.8rem;
      color: #92400e;
      line-height: 1.5;
    }

    textarea { width: 100%; height: 85px; margin-top: 0.4rem; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; box-sizing: border-box; }
    select, button { padding: 0.6rem 1rem; border-radius: 6px; font-size: 0.9rem; }
    .btn-submit { background: #2563eb; color: #fff; border: none; font-weight: 600; cursor: pointer; margin-top: 0.8rem; padding: 0.75rem 1.5rem; }
    .btn-submit:hover { background: #1d4ed8; }
  </style>
</head>
<body>
<div class="container">
  <a href="/admin/index.php" style="text-decoration: none; color: #2563eb; font-weight: 600;">&larr; 候補者一覧へ戻る</a>
  
  <div style="display: flex; justify-content: space-between; align-items: center; margin: 1rem 0; flex-wrap: wrap; gap: 0.5rem;">
    <h1><?= htmlspecialchars($session['name']) ?> 様 - 面接評価レポート</h1>
    <div>
      <?php if ($reloadCount > 0): ?>
        <span class="badge-warn">⚠️ 面接やり直し (リロード): <?= $reloadCount ?>回</span>
      <?php else: ?>
        <span class="badge-clean">✔ やり直しなし</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isSaved): ?>
    <div class="success-alert">✔ 判定結果と社内コメントを正常に保存しました。</div>
  <?php endif; ?>

  <!-- 人事向け免責・運用指針バナー -->
  <div class="disclaimer-banner">
    <strong>【人事担当者への運用指針】</strong><br>
    本画面のAIスコアおよび各種不正検知指標は、一次選考の判断材料として提供される参考値です。回線トラブルや環境要因による誤検知の可能性を考慮し、最終的な合否判断は対話音声およびES内容を含めて総合的にご判断ください。
  </div>

  <!-- 受検環境 & 不正監視インテグリティカード -->
  <div class="card">
    <h2>🚨 受検環境 & AI映像監視（インテグリティ判定）</h2>
    <?php if ($integrity): ?>
      <div style="font-size:0.8rem; color:#64748b; margin-bottom: 0.5rem;">
        受検規約同意日時: <?= !empty($integrity['consentTimestamp']) ? htmlspecialchars($integrity['consentTimestamp']) : '記録なし' ?>
      </div>

      <div class="integrity-grid">
        <!-- AI 視線・顔向き逸脱 -->
        <div class="integrity-card">
          <div class="integrity-label">AI 視線・顔向きの逸脱率</div>
          <?php $gazeRatio = (int)($integrity['gazeDeviationRatio'] ?? 0); ?>
          <div class="integrity-val <?= $gazeRatio >= 35 ? 'val-warn' : 'val-ok' ?>">
            <?= $gazeRatio ?> %
            <span style="font-size:0.75rem; font-weight:normal; color:#64748b;">(累計 <?= (int)($integrity['gazeAwayDurationSec'] ?? 0) ?>秒)</span>
          </div>
          <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">
            <?= $gazeRatio >= 35 ? '⚠️ カンペ・別モニター凝視の疑い' : '✔ 正面を向いて回答' ?>
          </div>
        </div>

        <!-- 顔消失・フレームアウト -->
        <div class="integrity-card">
          <div class="integrity-label">顔の消失 (フレームアウト / 離席)</div>
          <?php $lostCount = (int)($integrity['faceLostCount'] ?? 0); ?>
          <div class="integrity-val <?= $lostCount > 0 ? 'val-warn' : 'val-ok' ?>">
            <?= $lostCount ?> 回
            <span style="font-size:0.75rem; font-weight:normal; color:#64748b;">(<?= (int)($integrity['faceLostDurationSec'] ?? 0) ?>秒)</span>
          </div>
          <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">
            <?= $lostCount > 0 ? '⚠️ カメラ外への離脱検知' : '✔ 画面内を常時維持' ?>
          </div>
        </div>

        <!-- 複数人映り込み -->
        <div class="integrity-card">
          <div class="integrity-label">複数人の映り込み (影武者・補助)</div>
          <?php $multiFaces = (int)($integrity['multipleFacesCount'] ?? 0); ?>
          <div class="integrity-val <?= $multiFaces > 0 ? 'val-warn' : 'val-ok' ?>">
            <?= $multiFaces > 0 ? "⚠️ 検知あり ({$multiFaces}回)" : '✔ 1名のみ' ?>
          </div>
        </div>

        <!-- タブ切り替え -->
        <div class="integrity-card">
          <div class="integrity-label">タブ切り替え / フォーカス外れ</div>
          <div class="integrity-val <?= ($integrity['blurCount'] ?? 0) > 0 ? 'val-warn' : '' ?>">
            <?= (int)($integrity['blurCount'] ?? 0) ?> 回
            <span style="font-size:0.75rem; font-weight:normal; color:#64748b;">(離脱 <?= (int)($integrity['tabAwayDurationSec'] ?? 0) ?>秒)</span>
          </div>
        </div>

        <!-- フルスクリーン解除 -->
        <div class="integrity-card">
          <div class="integrity-label">全画面表示（フルスクリーン）解除</div>
          <div class="integrity-val <?= ($integrity['fullscreenExitCount'] ?? 0) > 0 ? 'val-warn' : '' ?>">
            <?= (int)($integrity['fullscreenExitCount'] ?? 0) ?> 回
          </div>
        </div>

        <!-- 仮想カメラ -->
        <div class="integrity-card">
          <div class="integrity-label">カメラ偽装（OBS等仮想カメラ）</div>
          <div class="integrity-val <?= !empty($integrity['isVirtualCamera']) ? 'val-warn' : 'val-ok' ?>">
            <?= !empty($integrity['isVirtualCamera']) ? '⚠️ 検出あり' : '✔ 実機カメラ' ?>
          </div>
        </div>

        <!-- サブモニター -->
        <div class="integrity-card">
          <div class="integrity-label">サブモニター（マルチ画面）</div>
          <div class="integrity-val <?= !empty($integrity['isMultiMonitor']) ? 'val-warn' : '' ?>">
            <?= !empty($integrity['isMultiMonitor']) ? '⚠️ 接続中' : '1画面' ?>
          </div>
        </div>

        <!-- コピペ試行 -->
        <div class="integrity-card">
          <div class="integrity-label">コピペ（貼り付け）試行回数</div>
          <div class="integrity-val <?= ($integrity['pasteAttemptCount'] ?? 0) > 0 ? 'val-warn' : '' ?>">
            <?= (int)($integrity['pasteAttemptCount'] ?? 0) ?> 回
          </div>
        </div>
      </div>

      <div class="guideline-box">
        <strong>⚠️ 不正判定時の推奨対応：</strong><br>
        「視線逸脱率35%以上」または「タブ離脱3回以上」が記録されている場合、該当ターンの回答音声を確認してください。棒読み感や不自然な言い淀みがある場合は、次段階の対面面接での再確認をお勧めします。
      </div>
    <?php else: ?>
      <p style="color: #64748b; font-size: 0.9rem;">監視ログは記録されていません。</p>
    <?php endif; ?>
  </div>

  <!-- AI 総合評価 -->
  <div class="card">
    <h2>AI 総合評価</h2>
    <?php if ($summary): ?>
      <div style="display: flex; align-items: center; gap: 2rem; margin-bottom: 1rem; flex-wrap: wrap;">
        <div>
          <div style="font-size: 0.85rem; color: #64748b;">総合スコア</div>
          <div class="score-badge"><?= $summary['overall_score'] ?? '-' ?> <span style="font-size: 1rem; color: #64748b;">/ 100</span></div>
        </div>
        <div style="flex: 1; min-width: 250px;">
          <strong>推薦・フィードバック:</strong>
          <p style="margin-top: 0.25rem; font-size: 0.95rem; line-height: 1.5;"><?= nl2br(htmlspecialchars($summary['recommendation'] ?? '')) ?></p>
        </div>
      </div>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
        <div>
          <h4>高く評価された強み</h4>
          <ul>
            <?php foreach (($summary['strengths'] ?? []) as $s): ?>
              <li><?= htmlspecialchars($s) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div>
          <h4>課題・懸念点</h4>
          <ul>
            <?php foreach (($summary['weaknesses'] ?? []) as $w): ?>
              <li><?= htmlspecialchars($w) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php else: ?>
      <p style="color: #64748b;">面接終了後のAI評価集計待ち、または面接未完了です。</p>
    <?php endif; ?>
  </div>

  <!-- 最終合否判定 -->
  <div class="card">
    <h3>面接官・人事 最終判定</h3>
    <form action="judge.php" method="POST" onsubmit="return confirm('合否判定および社内メモを保存しますか？');">
      <input type="hidden" name="candidate_id" value="<?= htmlspecialchars($session['candidate_id']) ?>">
      <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['id']) ?>">
      <div>
        <label style="font-weight: 600;">合否判定: </label>
        <select name="final_decision">
          <option value="unreviewed" <?= $session['final_decision'] === 'unreviewed' ? 'selected' : '' ?>>未審査</option>
          <option value="pass" <?= $session['final_decision'] === 'pass' ? 'selected' : '' ?>>合格 (Pass)</option>
          <option value="fail" <?= $session['final_decision'] === 'fail' ? 'selected' : '' ?>>不合格 (Fail)</option>
          <option value="hold" <?= $session['final_decision'] === 'hold' ? 'selected' : '' ?>>保留 (Hold)</option>
        </select>
      </div>

      <!-- ★候補者へのメール通知チェック -->
      <div style="margin-top: 0.8rem;">
        <label style="font-size: 0.88rem; cursor: pointer; display: flex; align-items: center; gap: 6px;">
          <input type="checkbox" name="send_notification_email" value="1">
          <strong>保存と同時に候補者へ結果通知メールを送信する</strong>
        </label>
      </div>

      <div style="margin-top: 1rem;">
        <label style="font-weight: 600;">面接官コメント・社内メモ (非公開):</label>
        <textarea name="reviewer_comment"><?= htmlspecialchars($session['reviewer_comment'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn-submit">判定を確定・保存する</button>
    </form>
  </div>

  <!-- 対話ログ & 音声 -->
  <div class="card">
    <h2>対話ターンログ & 音声</h2>
    <?php foreach ($turns as $t): ?>
      <?php $score = json_decode($t['turn_score'] ?? '{}', true); ?>
      <div class="turn-box">
        <h3>ターン <?= $t['turn_number'] ?> (<?= htmlspecialchars($t['question_type']) ?>)</h3>
        <p><strong>面接官:</strong> <?= htmlspecialchars($t['question_text']) ?></p>

        <p style="margin-top: 0.75rem;"><strong>候補者回答:</strong> <?= htmlspecialchars($t['answer_text'] ?? '（回答なし）') ?></p>
        <?php if ($t['answer_audio_filename']): ?>
          <audio class="audio-player" controls src="/audio.php?type=uploads&file=<?= urlencode($t['answer_audio_filename']) ?>&session_id=<?= urlencode($sessionId) ?>"></audio>
        <?php endif; ?>

        <div class="meta-box">
          <?php if ($t['duration_sec'] !== null): ?>
            <div class="meta-item meta-time">⏱ 回答時間: <?= (int)$t['duration_sec'] ?>秒</div>
          <?php endif; ?>
          <?php if ($score): ?>
            <div class="meta-item">論理スコア: <?= $score['logic_score'] ?? '-' ?> / 5</div>
            <div class="meta-item">具体性スコア: <?= $score['concreteness_score'] ?? '-' ?> / 5</div>
            <div class="meta-item">メモ: <?= htmlspecialchars($score['note'] ?? '') ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>