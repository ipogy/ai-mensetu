<?php
declare(strict_types=1);

// エラー設定
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Composer オートロード読み込み（存在する場合）
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// .env 手動パーサー（Dotenv パッケージ未インストールの環境でも動作保証）
(function () {
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) {
        return;
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
})();

/**
 * データベース接続取得 (PDO Singleton)
 */
function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $name = $_ENV['DB_NAME'] ?? 'ss690056_aimensetu';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    $port = $_ENV['DB_PORT'] ?? '3306';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new RuntimeException('データベース接続に失敗しました: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * UUID v4 生成
 */
function generateUuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * JSON レスポンス出力
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 質問タイプ設定取得
 */
function getQuestionTypeConfig(string $type): array {
    $configs = [
        'type_a' => ['seconds' => 30,  'label' => '回答目安: 30秒 (簡潔に)'],
        'type_b' => ['seconds' => 60,  'label' => '回答目安: 1分 (標準)'],
        'type_c' => ['seconds' => 120, 'label' => '回答目安: 2分 (詳細に)'],
    ];
    return $configs[$type] ?? $configs['type_b'];
}

/**
 * Gemini API 呼び出し
 */
function callGemini(string $prompt, ?array $responseSchema = null): array {
    $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    if (!$apiKey) {
        throw new RuntimeException('GEMINI_API_KEY が設定されていません。');
    }

    $model = $_ENV['GEMINI_MODEL'] ?? 'gemini-1.5-flash';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $generationConfig = [
        'temperature'     => 0.4,
        'responseMimeType'=> 'application/json',
    ];

    if ($responseSchema !== null) {
        $generationConfig['responseSchema'] = $responseSchema;
    }

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => $generationConfig,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 45,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("Gemini API 通信エラー: {$curlErr}");
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("Gemini API エラー (HTTP {$httpCode}): {$response}");
    }

    $resData = json_decode($response, true);
    $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $parsed  = json_decode($rawText, true);

    return is_array($parsed) ? $parsed : [];
}

/**
 * 音声ファイル 文字起こし (Speech-to-Text)
 */
function speechToText(string $audioFilePath): string {
    if (!file_exists($audioFilePath)) {
        return '';
    }

    $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    if (!$apiKey) {
        return '';
    }

    $model = 'gemini-1.5-flash';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $audioData = base64_encode(file_get_contents($audioFilePath));

    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => 'audio/webm',
                            'data'     => $audioData
                        ]
                    ],
                    [
                        'text' => 'この音声を日本語で一言一句正確に文字起こししてください。前置きや解説は不要で、書き起こしたテキストのみを出力してください。'
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response ?: '', true);
    return trim($resData['candidates'][0]['content']['parts'][0]['text'] ?? '');
}

/**
 * 企業設定のメールテンプレート取得
 */
function getCompanyEmailTemplate(array $company, string $type): array {
    $customTemplates = json_decode($company['email_templates'] ?? '{}', true);

    $defaults = [
        'entry_received' => [
            'subject' => '【{company_name}】エントリーシート受付完了のお知らせ',
            'body'    => "{candidate_name} 様\n\nこの度は【{company_name}】の求人にご応募いただき、誠にありがとうございます。\nエントリーシートの提出を受け付けいたしました。\n\n順次書類選考を実施いたします。\n通過された方には別途【AI対話型面接】の受検URLをお送りいたします。\n\n────────────────\n{company_name}\n採用担当窓口"
        ],
        'screening_pass' => [
            'subject' => '【{company_name}】書類選考通過・AI対話型面接のご案内',
            'body'    => "{candidate_name} 様\n\nこの度は【{company_name}】にご応募いただきありがとうございます。\n書類選考の結果、ぜひ次のステップとして【AI対話型面接】にお進みいただきたく存じます。\n\n■ 受検用URL:\n{interview_url}\n\n所要時間は10〜15分程度です。リラックスしてご受検ください。\n\n────────────────\n{company_name}\n採用担当窓口"
        ],
        'screening_fail' => [
            'subject' => '【{company_name}】選考結果のご案内',
            'body'    => "{candidate_name} 様\n\nこの度は【{company_name}】にご応募いただき誠にありがとうございました。\n慎重に選考を重ねました結果、今回はご希望に添えない結果となりました。\n何卒ご了承いただけますようお願い申し上げます。\n\n────────────────\n{company_name}\n採用担当窓口"
        ],
        'interview_pass' => [
            'subject' => '【{company_name}】AI対話型面接 選考通過および次回選考のご案内',
            'body'    => "{candidate_name} 様\n\nこの度は弊社のAI対話型面接を受検いただき、誠にありがとうございました。\n\n選考の結果、貴殿におかれましては【面接選考を通過】となりましたことをご報告申し上げます。\n次回選考の詳細につきましては追ってご連絡いたします。\n\n────────────────\n{company_name}\n採用担当窓口"
        ],
    ];

    $tpl = $customTemplates[$type] ?? $defaults[$type] ?? ['subject' => '', 'body' => ''];
    return [
        'subject' => !empty($tpl['subject']) ? $tpl['subject'] : $defaults[$type]['subject'],
        'body'    => !empty($tpl['body']) ? $tpl['body'] : $defaults[$type]['body'],
    ];
}

/**
 * プレースホルダー置換
 */
function renderEmailTemplate(string $text, array $variables): string {
    foreach ($variables as $key => $val) {
        $text = str_replace('{' . $key . '}', (string)$val, $text);
    }
    return $text;
}

/**
 * メール送信関数 (mb_send_mail)
 */
function sendAppMail(string $toEmail, string $toName, string $subject, string $body): bool {
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');

    $fromMail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@ai-mensetu.ipogy.org';
    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'AI面接選考事務局';

    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
    $headers = [
        "From: {$encodedFromName} <{$fromMail}>",
        "Reply-To: {$fromMail}",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ];

    return @mb_send_mail($toEmail, $subject, $body, implode("\r\n", $headers));
}