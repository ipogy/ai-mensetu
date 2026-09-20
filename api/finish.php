<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/bootstrap.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? null;

    if (!$sessionId) {
        jsonResponse(['error' => 'Missing session_id'], 400);
    }

    $pdo = getDb();

    // 企業情報も含めてセッション・候補者情報を取得
    $stmt = $pdo->prepare("
        SELECT s.*, c.name, c.email, c.entry_sheet_data, c.id AS candidate_id,
               comp.name AS company_name
        FROM interview_sessions s 
        JOIN candidates c ON s.candidate_id = c.id 
        JOIN companies comp ON c.company_id = comp.id
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonResponse(['error' => 'Session not found'], 404);
    }

    $companyName = $session['company_name'];

    // 全ターン取得
    $stmtTurns = $pdo->prepare("
        SELECT turn_number, question_type, question_text, answer_text, turn_score 
        FROM interview_turns 
        WHERE session_id = :sid 
        ORDER BY turn_number ASC
    ");
    $stmtTurns->execute(['sid' => $sessionId]);
    $turns = $stmtTurns->fetchAll();

    $dialogueSummary = "";
    foreach ($turns as $t) {
        $dialogueSummary .= "【ターン{$t['turn_number']}】\n面接官: {$t['question_text']}\n候補者: {$t['answer_text']}\n\n";
    }

    // Gemini による総合評価レポート作成
    $prompt = <<<PROMPT
あなたはプロフェッショナルな採用評価者です。
【{$companyName}】の採用選考において、以下の候補者のエントリーシートと面接対話ログ全体を分析し、総合評価を行ってください。

【候補者情報】
氏名: {$session['name']}
企業名: {$companyName}
エントリーシート:
{$session['entry_sheet_data']}

【対話全ログ】
{$dialogueSummary}

【指示】
1. 100点満点での overall_score を算出してください。
2. strengths（強み）を2〜3点挙げてください。
3. weaknesses（改善点・懸念点）を1〜2点挙げてください。
4. 論理性（logic_summary）、具体性（concreteness_summary）をそれぞれ1文で総括してください。
5. recommendation（選考通過に関する推薦コメント）を記述してください。
PROMPT;

    $schema = [
        "type" => "OBJECT",
        "properties" => [
            "overall_score"        => ["type" => "INTEGER"],
            "strengths"            => ["type" => "ARRAY", "items" => ["type" => "STRING"]],
            "weaknesses"           => ["type" => "ARRAY", "items" => ["type" => "STRING"]],
            "logic_summary"        => ["type" => "STRING"],
            "concreteness_summary" => ["type" => "STRING"],
            "recommendation"       => ["type" => "STRING"]
        ],
        "required" => ["overall_score", "strengths", "weaknesses", "logic_summary", "concreteness_summary", "recommendation"]
    ];

    $evalSummary = callGemini($prompt, $schema);

    $pdo->beginTransaction();

    $stmtUpdateSession = $pdo->prepare("
        UPDATE interview_sessions 
        SET ended_at = CURRENT_TIMESTAMP, evaluation_summary = :summary 
        WHERE id = :id
    ");
    $stmtUpdateSession->execute([
        'summary' => json_encode($evalSummary, JSON_UNESCAPED_UNICODE),
        'id'      => $sessionId
    ]);

    $stmtUpdateCandidate = $pdo->prepare("
        UPDATE candidates 
        SET interview_status = 'completed' 
        WHERE id = :id
    ");
    $stmtUpdateCandidate->execute(['id' => $session['candidate_id']]);

    $pdo->commit();

    // 📧 面接受検完了メール（企業名入り）
    $subject = "【{$companyName}】AI対話型面接の受検完了について";
    $body = <<<MAIL
{$session['name']} 様

【{$companyName}】のAI対話型面接を受検いただき、誠にありがとうございました。
面接の回答データの送信を完了いたしました。

今後の選考結果につきましては、面接データおよびエントリーシートを総合的に判断した上で、
改めて担当者よりご連絡いたします。

引き続きよろしくお願い申し上げます。

────────────────────────────────
{$companyName}
採用担当事務局
MAIL;

    sendAppMail($session['email'], $session['name'], $subject, $body);

    jsonResponse([
        'status'       => 'completed',
        'session_id'   => $sessionId,
        'company_name' => $companyName
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバー内部エラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}