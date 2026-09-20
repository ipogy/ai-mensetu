<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$companyId   = $_SESSION['company_id'];
$candidateId = $_POST['candidate_id'] ?? '';
$action      = $_POST['action'] ?? ''; // 'pass', 'fail', 'repass'

if (!$candidateId || !in_array($action, ['pass', 'fail', 'repass'], true)) {
    http_response_code(400);
    exit('Invalid parameters');
}

$pdo = getDb();

// 企業情報の取得（メール設定を含む）
$stmtComp = $pdo->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$stmtComp->execute(['id' => $companyId]);
$company = $stmtComp->fetch();

if (!$company) {
    http_response_code(404);
    exit('Company not found');
}

$companyName = $company['name'];

// 自社候補者か厳格に検証
$stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = :id AND company_id = :cid");
$stmt->execute(['id' => $candidateId, 'cid' => $companyId]);
$candidate = $stmt->fetch();

if (!$candidate) {
    http_response_code(404);
    exit('Candidate not found');
}

$baseUrl = rtrim($_ENV['APP_BASE_URL'] ?? 'https://ai-mensetu.ipogy.org', '/');
$interviewUrl = "{$baseUrl}/index.html?candidate_id={$candidateId}";

$vars = [
    'candidate_name' => $candidate['name'],
    'company_name'   => $companyName,
    'interview_url'  => $interviewUrl,
];

if ($action === 'pass') {
    // 1. 通常の書類選考合格処理
    $stmtUpdate = $pdo->prepare("UPDATE candidates SET interview_status = 'ready' WHERE id = :id");
    $stmtUpdate->execute(['id' => $candidateId]);

    $tpl = getCompanyEmailTemplate($company, 'screening_pass');
    $subject = renderEmailTemplate($tpl['subject'], $vars);
    $body    = renderEmailTemplate($tpl['body'], $vars);

    sendAppMail($candidate['email'], $candidate['name'], $subject, $body);

} elseif ($action === 'repass') {
    // 2. 誤操作救済：復活（お詫び＆選考通過・面接案内）
    $stmtUpdate = $pdo->prepare("UPDATE candidates SET interview_status = 'ready' WHERE id = :id");
    $stmtUpdate->execute(['id' => $candidateId]);

    // お詫びメールは重要度の高い定型文面で安全に送信
    $subject = "【重要・お詫びと訂正】選考結果に関する訂正および面接のご案内【{$companyName}】";
    $body = <<<MAIL
{$candidate['name']} 様

拝啓
貴殿におかれましては益々ご清祥のこととお慶び申し上げます。
【{$companyName}】採用担当窓口でございます。

先ほどお送りいたしましたエントリーシート選考結果のメールにつきまして、
弊社のシステム管理・運用上の不手際により、
誤って選考見送りのご案内をお送りしてしまいました。

多大なるご不安とご迷惑をおかけいたしましたことを、心より深くお詫び申し上げます。

慎重に選考を行いました結果、貴殿におかれましては【書類選考を通過】とさせていただいております。
ぜひ次の選考ステップといたしまして、【AI対話型面接】にお進みいただきたく存じます。

大変恐れ入りますが、以下の専用URLより面接の受検をお願い申し上げます。

────────────────────────────────
■ AI対話型面接 受検用URL
────────────────────────────────
{$interviewUrl}

※ ご注意事項
・静かな環境をご準備いただき、ブラウザ（Google Chrome / Microsoft Edge 推奨）でアクセスしてください。
・カメラ・マイクのアクセス許可を求められますので「許可」を選択してください。
・所要時間は10〜15分程度です。

今後は再発防止に努め、管理体制の徹底を図ってまいります。
{$candidate['name']} 様のご受検を心よりお待ち申し上げております。

敬具

────────────────────────────────
{$companyName}
採用担当窓口
MAIL;

    sendAppMail($candidate['email'], $candidate['name'], $subject, $body);

} else {
    // 3. 通常の見送り処理
    $stmtUpdate = $pdo->prepare("UPDATE candidates SET interview_status = 'es_failed' WHERE id = :id");
    $stmtUpdate->execute(['id' => $candidateId]);

    $tpl = getCompanyEmailTemplate($company, 'screening_fail');
    $subject = renderEmailTemplate($tpl['subject'], $vars);
    $body    = renderEmailTemplate($tpl['body'], $vars);

    sendAppMail($candidate['email'], $candidate['name'], $subject, $body);
}

header('Location: /admin/index.php');
exit;