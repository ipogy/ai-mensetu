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

    $sessionId     = $_POST['session_id'] ?? null;
    $turnNumber    = (int)($_POST['turn_number'] ?? 0);
    $durationSec   = isset($_POST['duration_sec']) ? (int)$_POST['duration_sec'] : null;
    $integrityData = $_POST['integrity_data'] ?? null; // ★不正監視ログ

    if (!$sessionId || !$turnNumber) {
        jsonResponse(['error' => 'Missing session_id or turn_number'], 400);
    }

    $pdo = getDb();

    $stmt = $pdo->prepare("
        SELECT s.*, c.entry_sheet_data, c.name 
        FROM interview_sessions s 
        JOIN candidates c ON s.candidate_id = c.id 
        WHERE s.id = :id
    ");
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonResponse(['error' => 'Session not found'], 404);
    }

    // 不正監視ログの更新（存在する場合）
    if ($integrityData) {
        $stmtIntegrity = $pdo->prepare("UPDATE interview_sessions SET integrity_log = :ilog WHERE id = :id");
        $stmtIntegrity->execute([
            'ilog' => is_string($integrityData) ? $integrityData : json_encode($integrityData, JSON_UNESCAPED_UNICODE),
            'id'   => $sessionId
        ]);
    }

    $uploadDir = realpath(__DIR__ . '/../storage/uploads') ?: (__DIR__ . '/../storage/uploads');
    $answerAudioFilename = null;

    if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
        $answerAudioFilename = "answer_{$sessionId}_{$turnNumber}.webm";
        $answerFilePath = "{$uploadDir}/{$answerAudioFilename}";
        @move_uploaded_file($_FILES['audio_file']['tmp_name'], $answerFilePath);
    }

    $answerText = '';
    if (!empty($_POST['user_text']) && trim($_POST['user_text']) !== '') {
        $answerText = trim($_POST['user_text']);
    } elseif ($answerAudioFilename && file_exists("{$uploadDir}/{$answerAudioFilename}")) {
        $answerText = speechToText("{$uploadDir}/{$answerAudioFilename}");
    }

    if ($answerText === '') {
        $answerText = '（無音・認識不可）';
    }

    $stmtTurns = $pdo->prepare("
        SELECT turn_number, question_type, question_text, answer_text 
        FROM interview_turns 
        WHERE session_id = :sid 
        ORDER BY turn_number ASC
    ");
    $stmtTurns->execute(['sid' => $sessionId]);
    $turnsHistory = $stmtTurns->fetchAll();

    $dialogueHistory = "";
    $currentQuestionText = "";
    foreach ($turnsHistory as $t) {
        if ((int)$t['turn_number'] === $turnNumber) {
            $currentQuestionText = $t['question_text'];
            continue;
        }
        $dialogueHistory .= "【ターン{$t['turn_number']}】\n面接官: {$t['question_text']}\n候補者: {$t['answer_text']}\n\n";
    }

    $prompt = <<<PROMPT
あなたは優秀な採用面接官です。
候補者のエントリーシートと、これまでの対話履歴を踏まえ、候補者の直前の最新回答を深く分析して次の問いかけと質問タイプを動的に決定してください。

【候補者情報】
氏名: {$session['name']}
エントリーシート:
{$session['entry_sheet_data']}

【これまでの会話履歴】
{$dialogueHistory}

【直前のやり取り（現在のターン {$turnNumber}）】
面接官の質問: {$currentQuestionText}
★候補者の最新の回答:
「{$answerText}」

【指示事項】
1. 候補者の最新回答「{$answerText}」を分析してください。
2. 回答が抽象的な場合は action を "follow_up" とし、発言キーワードを直接引用しながら深掘りする質問を作成してください。
3. 十分具体的な場合は action を "next_theme" とし、自然に話を繋ぎESの別項目や新たなテーマへ展開する質問を作成してください。
4. 全体の対話が5ターン以上、または十分な人物評価が完了したと判断した場合は is_finished を true にしてください。
5. 質問文（question_text）は丁寧な口調（80〜120文字程度）にしてください。
6. 質問の重み・深さに応じて question_type を必ず以下から1つ選定してください:
   - "type_a": 簡潔な要約、事実確認、端的な回答を求める場合（30秒目安）
   - "type_b": 行動のプロセス、工夫点、困難の乗り越え方など標準的な深掘りの場合（60秒目安）
   - "type_c": 志望動機、価値観、大きなエピソード全体をじっくり語らせる場合（120秒目安）
PROMPT;

    $schema = [
        "type" => "OBJECT",
        "properties" => [
            "is_finished"   => ["type" => "BOOLEAN"],
            "action"        => ["type" => "STRING", "enum" => ["follow_up", "next_theme"]],
            "question_type" => ["type" => "STRING", "enum" => ["type_a", "type_b", "type_c"]],
            "question_text" => ["type" => "STRING"],
            "internal_rating" => [
                "type" => "OBJECT",
                "properties" => [
                    "logic_score"        => ["type" => "INTEGER"],
                    "concreteness_score" => ["type" => "INTEGER"],
                    "note"               => ["type" => "STRING"]
                ],
                "required" => ["logic_score", "concreteness_score", "note"]
            ]
        ],
        "required" => ["is_finished", "action", "question_type", "question_text", "internal_rating"]
    ];

    $aiRes = callGemini($prompt, $schema);

    $stmtUpdateTurn = $pdo->prepare("
        UPDATE interview_turns 
        SET answer_text = :atext, 
            answer_audio_filename = :afilename, 
            duration_sec = :dsec,
            turn_score = :score 
        WHERE session_id = :sid AND turn_number = :tnum
    ");
    $stmtUpdateTurn->execute([
        'atext'     => $answerText,
        'afilename' => $answerAudioFilename,
        'dsec'      => $durationSec,
        'score'     => json_encode($aiRes['internal_rating'], JSON_UNESCAPED_UNICODE),
        'sid'       => $sessionId,
        'tnum'      => $turnNumber
    ]);

    $isFinished = (bool)($aiRes['is_finished'] ?? false);

    if ($isFinished || $turnNumber >= 5) {
        jsonResponse([
            'session_id'       => $sessionId,
            'is_completed'     => true,
            'recognized_text'  => $answerText,
            'next_turn_number' => null,
            'question_text'    => '面接は以上となります。本日はご参加いただき誠にありがとうございました。',
            'question_type'    => null,
            'time_limit_sec'   => 0,
            'time_limit_label' => '',
            'audio_url'        => null
        ]);
    }

    $nextTurnNumber   = $turnNumber + 1;
    $nextTurnId       = generateUuidV4();
    $nextQuestionText = $aiRes['question_text'];
    $nextQType        = $aiRes['question_type'] ?? 'type_b';
    $typeConfig       = getQuestionTypeConfig($nextQType);

    $stmtNextTurn = $pdo->prepare("
        INSERT INTO interview_turns (id, session_id, turn_number, question_type, question_text, question_audio_filename) 
        VALUES (:id, :sid, :tnum, :qtype, :qtext, NULL)
    ");
    $stmtNextTurn->execute([
        'id'    => $nextTurnId,
        'sid'   => $sessionId,
        'tnum'  => $nextTurnNumber,
        'qtype' => ($aiRes['action'] === 'follow_up' ? 'follow_up' : 'main'),
        'qtext' => $nextQuestionText
    ]);

    $stmtUpdateSession = $pdo->prepare("UPDATE interview_sessions SET current_step = :step WHERE id = :id");
    $stmtUpdateSession->execute(['step' => $nextTurnNumber, 'id' => $sessionId]);

    jsonResponse([
        'session_id'       => $sessionId,
        'is_completed'     => false,
        'recognized_text'  => $answerText,
        'next_turn_number' => $nextTurnNumber,
        'question_text'    => $nextQuestionText,
        'question_type'    => $nextQType,
        'time_limit_sec'   => $typeConfig['seconds'],
        'time_limit_label' => $typeConfig['label'],
        'audio_url'        => null
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバー内部エラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}