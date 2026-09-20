<?php
declare(strict_types=1);
require_once __DIR__ . '/api/bootstrap.php';

$type = $_GET['type'] ?? '';
$file = basename($_GET['file'] ?? '');
$sessionId = $_GET['session_id'] ?? '';

if (!$file || !$sessionId) {
    http_response_code(400);
    exit('Bad Request');
}

// 簡易セッション検証
$pdo = getDb();
$stmt = $pdo->prepare("SELECT id FROM interview_sessions WHERE id = :id");
$stmt->execute(['id' => $sessionId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    exit('Unauthorized');
}

$baseDir = ($type === 'uploads') ? __DIR__ . '/../storage/uploads' : __DIR__ . '/../storage/tts';
$filePath = realpath($baseDir . '/' . $file);

if (!$filePath || !file_exists($filePath)) {
    http_response_code(404);
    exit('Not Found');
}

$mime = ($type === 'uploads') ? 'audio/webm' : 'audio/mpeg';
header("Content-Type: {$mime}");
header("Content-Length: " . filesize($filePath));
readfile($filePath);
exit;