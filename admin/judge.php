<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$companyId   = $_SESSION['company_id'];
$companyName = $_SESSION['company_name'] ?? '採用担当事務局';

$candidateId     = $_POST['candidate_id'] ?? '';
$sessionId       = $_POST['session_id'] ?? '';
$finalDecision   = $_POST['final_decision'] ?? 'unreviewed';
$reviewerComment = trim($_POST['reviewer_comment'] ?? '');
$sendMail        = !empty($_POST['send_notification_email']); // メール通知チェック

if (!$candidateId || !$sessionId) {
    http_response_code(400);
    exit('Invalid parameters');
}

$pdo = getDb();

// 企業のカスタムテンプレート取得（存在する場合）
$stmtComp = $pdo->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$stmtComp->execute(['id' => $companyId]);
$company = $stmtComp->fetch();
if ($company && !empty($company['name'])) {
    $companyName = $company['name'];
}

// 自社データであることを確認
$stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = :id AND company_id = :cid");
$stmt->execute(['id' => $candidateId, 'cid' => $companyId]);
$candidate = $stmt->fetch();

if (!$candidate) {
    http_response_code(404);
    exit('Candidate not found');
}

// 判定と社内コメントを保存
$stmtUpdate = $pdo->prepare("
    UPDATE candidates 
    SET final_decision = :decision, reviewer_comment = :comment 
    WHERE id = :id
");
$stmtUpdate->execute([
    'decision' => $finalDecision,
    'comment'  => $reviewerComment,
    'id'       => $candidateId
]);

// 📧 候補者への結果通知メール送信（チェックボックスON時のみ）
if ($sendMail) {
    $vars = [
        'candidate_name' => $candidate['name'],
        'company_name'   => $companyName,
    ];

    if ($finalDecision === 'pass') {
        // カスタム設定があるか確認
        if ($company && function_exists('getCompanyEmailTemplate')) {
            $tpl = getCompanyEmailTemplate($company, 'interview_pass');
            $subject = renderEmailTemplate($tpl['subject'], $vars);
            $body    = renderEmailTemplate($tpl['body'], $vars);
        } else {
            // 標準テンプレート
            $subject = "【{$companyName}】AI対話型面接 選考通過および次回選考のご案内";
            $body = <<<MAIL
{$candidate['name']} 様

拝啓
貴殿におかれましては益々ご清祥のこととお慶び申し上げます。
【{$companyName}】採用担当窓口でございます。

この度は、弊社のAI対話型面接を受検いただき、誠にありがとうございました。

慎重に選考を重ねました結果、貴殿におかれましては【面接選考を通過】となりましたことをご報告申し上げます。

つきましては、次のステップといたしまして「二次選考（担当者面接）」にお進みいただきたく存じます。
日程調整等の詳細につきましては、改めて担当者よりご連絡いたしますので、今しばらくお待ちください。

今後とも何卒よろしくお願い申し上げます。

敬具

────────────────────────────────
{$companyName}
採用担当窓口
MAIL;
        }
        sendAppMail($candidate['email'], $candidate['name'], $subject, $body);

    } elseif ($finalDecision === 'fail') {
        // カスタム設定があるか確認
        if ($company && function_exists('getCompanyEmailTemplate')) {
            $tpl = getCompanyEmailTemplate($company, 'screening_fail');
            $subject = renderEmailTemplate($tpl['subject'], $vars);
            $body    = renderEmailTemplate($tpl['body'], $vars);
        } else {
            // 標準テンプレート
            $subject = "【{$companyName}】選考結果のご案内";
            $body = <<<MAIL
{$candidate['name']} 様

拝啓
貴殿におかれましては益々ご清祥のこととお慶び申し上げます。
【{$companyName}】採用担当窓口でございます。

この度は、弊社のAI対話型面接にご参加いただき、誠にありがとうございました。

ご提出いただきました面接データをもとに慎重に選考を行いました結果、
誠に残念ではございますが、今回はご希望に添えない結果となりました。
多数のご応募の中から弊社に関心をお寄せいただきましたことに、心より感謝申し上げます。

{$candidate['name']} 様の今後のご健勝とご活躍を心よりお祈り申し上げます。

敬具

────────────────────────────────
{$companyName}
採用担当窓口
MAIL;
        }
        sendAppMail($candidate['email'], $candidate['name'], $subject, $body);
    }
}

// レポート画面へ戻る
header('Location: /admin/report.php?session_id=' . urlencode($sessionId) . '&saved=1');
exit;