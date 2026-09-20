<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

$companyId   = $_SESSION['company_id'];
$companyName = $_SESSION['company_name'];

$pdo = getDb();

// 自社候補者の全データを取得（最新セッション情報含む）
$stmt = $pdo->prepare("
    SELECT c.id AS candidate_id, c.name, c.email, c.interview_status, c.final_decision, c.reviewer_comment, c.created_at AS applied_at,
           s.id AS session_id, s.evaluation_summary, s.integrity_log, s.reload_count, s.ended_at
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
$rows = $stmt->fetchAll();

$filename = 'candidates_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Excel文字化け防止のBOM出力
echo "\xEF\xBB\xBF";

$fp = fopen('php://output', 'w');

// CSVヘッダー行
fputcsv($fp, [
    '候補者ID',
    '氏名',
    'メールアドレス',
    '選考状況',
    '最終判定',
    'AI総合スコア',
    '面接やり直し回数',
    '視線逸脱率(%)',
    '視線逸脱秒数',
    '顔消失(離席)回数',
    '複数人検知回数',
    'タブ離脱回数',
    'タブ離脱秒数',
    '全画面解除回数',
    '仮想カメラ検知',
    'サブモニター接続',
    'コピペ試行回数',
    '受検規約同意日時',
    '面接完了日時',
    '面接官コメント',
    '応募日時'
]);

$statusMap = [
    'applied'     => '書類選考中',
    'ready'       => '面接案内済(未受検)',
    'in_progress' => '面接中',
    'completed'   => '面接完了',
    'es_failed'   => '書類見送り'
];

$decisionMap = [
    'unreviewed' => '未審査',
    'pass'       => '合格 (Pass)',
    'fail'       => '不合格 (Fail)',
    'hold'       => '保留 (Hold)'
];

foreach ($rows as $r) {
    $eval = json_decode($r['evaluation_summary'] ?? '{}', true);
    $integ = json_decode($r['integrity_log'] ?? '{}', true);

    fputcsv($fp, [
        $r['candidate_id'],
        $r['name'],
        $r['email'],
        $statusMap[$r['interview_status']] ?? $r['interview_status'],
        $decisionMap[$r['final_decision']] ?? $r['final_decision'],
        $eval['overall_score'] ?? '',
        (int)($r['reload_count'] ?? 0),
        $integ['gazeDeviationRatio'] ?? 0,
        $integ['gazeAwayDurationSec'] ?? 0,
        $integ['faceLostCount'] ?? 0,
        $integ['multipleFacesCount'] ?? 0,
        $integ['blurCount'] ?? 0,
        $integ['tabAwayDurationSec'] ?? 0,
        $integ['fullscreenExitCount'] ?? 0,
        !empty($integ['isVirtualCamera']) ? 'あり(OBS等)' : 'なし',
        !empty($integ['isMultiMonitor']) ? '接続中' : '1画面',
        $integ['pasteAttemptCount'] ?? 0,
        $integ['consentTimestamp'] ?? '',
        $r['ended_at'] ?? '',
        $r['reviewer_comment'] ?? '',
        $r['applied_at']
    ]);
}

fclose($fp);
exit;