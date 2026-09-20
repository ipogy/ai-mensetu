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

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        jsonResponse(['error' => '無効なJSONリクエストです。'], 400);
    }

    $name        = trim($input['name'] ?? '');
    $email       = trim($input['email'] ?? '');
    $companyCode = trim($input['company_code'] ?? 'default');
    $esData      = $input['entry_sheet_data'] ?? null;

    if (!$name || !$email || !$esData) {
        jsonResponse(['error' => '氏名、メールアドレス、ES情報は必須です。'], 400);
    }

    $pdo = getDb();

    // 企業コードから企業レコード（email_templates含む）を取得
    $stmtComp = $pdo->prepare("SELECT * FROM companies WHERE company_code = :code AND is_active = 1 LIMIT 1");
    $stmtComp->execute(['code' => strtolower($companyCode)]);
    $company = $stmtComp->fetch();

    if (!$company) {
        // フォールバック（初期企業）
        $stmtCompDefault = $pdo->query("SELECT * FROM companies WHERE is_active = 1 ORDER BY created_at ASC LIMIT 1");
        $company = $stmtCompDefault->fetch();
        if (!$company) {
            jsonResponse(['error' => '有効な応募先企業が見つかりません。'], 400);
        }
    }

    $companyId   = $company['id'];
    $companyName = $company['name'];

    // 候補者を新規作成
    $candidateId = generateUuidV4();
    $stmtInsert = $pdo->prepare("
        INSERT INTO candidates (id, company_id, name, email, entry_sheet_data, interview_status, final_decision) 
        VALUES (:id, :cid, :name, :email, :es, 'applied', 'unreviewed')
    ");
    $stmtInsert->execute([
        'id'    => $candidateId,
        'cid'   => $companyId,
        'name'  => $name,
        'email' => $email,
        'es'    => json_encode($esData, JSON_UNESCAPED_UNICODE)
    ]);

    // 📧 メール: エントリー受付完了（カスタムテンプレート対応）
    $tpl = getCompanyEmailTemplate($company, 'entry_received');
    $vars = [
        'candidate_name' => $name,
        'company_name'   => $companyName,
        'pr'             => $esData['pr'] ?? '',
        'gakuchika'      => $esData['gakuchika'] ?? '',
        'motivation'     => $esData['motivation'] ?? '',
    ];

    $subject = renderEmailTemplate($tpl['subject'], $vars);
    $body    = renderEmailTemplate($tpl['body'], $vars);

    sendAppMail($email, $name, $subject, $body);

    jsonResponse([
        'status'       => 'success',
        'candidate_id' => $candidateId,
        'company_name' => $companyName,
        'email'        => $email
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバー内部エラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}