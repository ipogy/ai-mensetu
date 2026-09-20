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
    $candidateId = $input['candidate_id'] ?? null;

    if (!$candidateId) {
        jsonResponse(['error' => 'candidate_id is required'], 400);
    }

    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = :id");
    $stmt->execute(['id' => $candidateId]);
    $candidate = $stmt->fetch();

    if (!$candidate) {
        jsonResponse(['error' => 'Candidate not found'], 404);
    }

    // 途中復帰チェック（進行中セッションがあるか）
    $stmtExisting = $pdo->prepare("
        SELECT * FROM interview_sessions 
        WHERE candidate_id = :cid AND ended_at IS NULL 
        ORDER BY started_at DESC LIMIT 1
    ");
    $stmtExisting->execute(['cid' => $candidateId]);
    $existingSession = $stmtExisting->fetch();

    if ($existingSession) {
        $sessionId = $existingSession['id'];
        $currentStep = (int)$existingSession['current_step'];

        // ★リロード・やり直し回数をカウントアップ
        $newReloadCount = ((int)($existingSession['reload_count'] ?? 0)) + 1;
        $stmtReload = $pdo->prepare("UPDATE interview_sessions SET reload_count = :rc WHERE id = :id");
        $stmtReload->execute(['rc' => $newReloadCount, 'id' => $sessionId]);

        $stmtTurn = $pdo->prepare("
            SELECT * FROM interview_turns 
            WHERE session_id = :sid AND turn_number = :tnum
        ");
        $stmtTurn->execute(['sid' => $sessionId, 'tnum' => $currentStep]);
        $turn = $stmtTurn->fetch();

        if ($turn) {
            $qType = $turn['question_type'] ?? 'type_b';
            $typeConfig = getQuestionTypeConfig($qType);

            jsonResponse([
                'session_id'       => $sessionId,
                'turn_number'      => $currentStep,
                'question_text'    => $turn['question_text'],
                'question_type'    => $qType,
                'time_limit_sec'   => $typeConfig['seconds'],
                'time_limit_label' => $typeConfig['label'],
                'is_resumed'       => true,
                'reload_count'     => $newReloadCount,
                'audio_url'        => null
            ]);
        }
    }

    // 新規面接開始
    $sessionId = generateUuidV4();
    $turnId    = generateUuidV4();

    $prompt = <<<PROMPT
あなたはプロフェッショナルな採用面接官です。
面接が始まった最初の第1問目です。

候補者氏名: {$candidate['name']}

【指示】
1. 面接の挨拶をしつつ、まずは1分程度で簡単な自己紹介をお願いする発話を作成してください。
2. エントリーシートの個別具体的な内容にはまだ触れないでください。
3. 候補者の緊張をほぐす丁寧な口調（60〜90文字程度）にしてください。
4. 質問タイプ (question_type) は "type_a" または "type_b" を選定してください。
PROMPT;

    $schema = [
        "type" => "OBJECT",
        "properties" => [
            "question_type" => ["type" => "STRING", "enum" => ["type_a", "type_b"]],
            "question_text" => ["type" => "STRING"]
        ],
        "required" => ["question_type", "question_text"]
    ];

    $aiRes = callGemini($prompt, $schema);
    $questionText = $aiRes['question_text'] ?? "{$candidate['name']}さん、本日はよろしくお願いいたします。まずは1分程度で簡単に自己紹介をお願いできますでしょうか？";
    $questionType = $aiRes['question_type'] ?? 'type_b';

    $typeConfig = getQuestionTypeConfig($questionType);

    $pdo->beginTransaction();
    $stmtSession = $pdo->prepare("INSERT INTO interview_sessions (id, candidate_id, current_step, reload_count) VALUES (:id, :cid, 1, 0)");
    $stmtSession->execute(['id' => $sessionId, 'cid' => $candidateId]);

    $stmtTurn = $pdo->prepare("INSERT INTO interview_turns (id, session_id, turn_number, question_type, question_text, question_audio_filename) VALUES (:id, :sid, 1, 'main', :qtext, NULL)");
    $stmtTurn->execute([
        'id'    => $turnId,
        'sid'   => $sessionId,
        'qtext' => $questionText
    ]);

    $stmtStatus = $pdo->prepare("UPDATE candidates SET interview_status = 'in_progress' WHERE id = :id");
    $stmtStatus->execute(['id' => $candidateId]);
    $pdo->commit();

    jsonResponse([
        'session_id'       => $sessionId,
        'turn_number'      => 1,
        'question_text'    => $questionText,
        'question_type'    => $questionType,
        'time_limit_sec'   => $typeConfig['seconds'],
        'time_limit_label' => $typeConfig['label'],
        'is_resumed'       => false,
        'reload_count'     => 0,
        'audio_url'        => null
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバー内部エラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}