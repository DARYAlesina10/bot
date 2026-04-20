<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://pandoroom.org',
    'https://www.pandoroom.org',
    'https://tgbotum145.ru',
    'https://www.tgbotum145.ru',
];
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // fallback, чтобы виджет мог работать при нестандартном домене сайта
    header('Access-Control-Allow-Origin: *');
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('UTC');

const WEBCHAT_SUPPORT_CHAT_ID = -1003304052055;
const WEBCHAT_BOT_TOKEN = '7854808857:AAHmleyDhVZvpBrQXG1YiVbMl9gBfXak1xY';
const WEBCHAT_THREAD_MAP_FILE = __DIR__ . '/webchat_data/thread_map.json';
const WEBCHAT_SESSIONS_FILE = __DIR__ . '/webchat_data/sessions.json';
const WEBCHAT_MESSAGES_DIR = __DIR__ . '/webchat_data/messages';
const WEBCHAT_UPLOADS_DIR = __DIR__ . '/webchat_data/uploads';

function wcEnsureStorage()
{
    if (!is_dir(dirname(WEBCHAT_THREAD_MAP_FILE))) {
        @mkdir(dirname(WEBCHAT_THREAD_MAP_FILE), 0777, true);
    }
    if (!is_dir(WEBCHAT_MESSAGES_DIR)) {
        @mkdir(WEBCHAT_MESSAGES_DIR, 0777, true);
    }
    if (!is_dir(WEBCHAT_UPLOADS_DIR)) {
        @mkdir(WEBCHAT_UPLOADS_DIR, 0777, true);
    }
}

function wcReadJson($file, $default = [])
{
    if (!file_exists($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

function wcWriteJson($file, $data)
{
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function wcSessionFile($sessionId)
{
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$sessionId);
    return WEBCHAT_MESSAGES_DIR . '/' . $safe . '.json';
}

function wcTelegramRequest($method, array $params)
{
    $url = 'https://api.telegram.org/bot' . WEBCHAT_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'description' => $err];
    }

    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'description' => 'invalid telegram response'];
    }
    return $decoded;
}

function wcEnsureThread($sessionId, $name = 'Гость сайта')
{
    $map = wcReadJson(WEBCHAT_THREAD_MAP_FILE, ['by_session' => []]);
    $bySession = $map['by_session'] ?? [];
    if (!empty($bySession[$sessionId]['thread_id'])) {
        return (int)$bySession[$sessionId]['thread_id'];
    }

    $topicName = '🌐 Live chat ' . $name . ' [ext: web_' . $sessionId . ']';
    $create = wcTelegramRequest('createForumTopic', [
        'chat_id' => WEBCHAT_SUPPORT_CHAT_ID,
        'name' => mb_substr($topicName, 0, 128, 'UTF-8'),
    ]);
    if (empty($create['ok']) || empty($create['result']['message_thread_id'])) {
        return null;
    }

    $threadId = (int)$create['result']['message_thread_id'];
    $map['by_session'][$sessionId] = [
        'thread_id' => $threadId,
        'updated_at' => time(),
    ];
    wcWriteJson(WEBCHAT_THREAD_MAP_FILE, $map);

    return $threadId;
}

function wcAppendMessage($sessionId, $direction, $text)
{
    $file = wcSessionFile($sessionId);
    $messages = wcReadJson($file, []);
    $messages[] = [
        'id' => (int)(microtime(true) * 1000),
        'direction' => $direction,
        'type' => 'text',
        'text' => (string)$text,
        'created_at' => time(),
    ];
    wcWriteJson($file, $messages);
    return end($messages);
}

function wcAppendImageMessage($sessionId, $direction, $imageUrl, $caption = '')
{
    $file = wcSessionFile($sessionId);
    $messages = wcReadJson($file, []);
    $messages[] = [
        'id' => (int)(microtime(true) * 1000),
        'direction' => $direction,
        'type' => 'image',
        'text' => (string)$caption,
        'image_url' => (string)$imageUrl,
        'created_at' => time(),
    ];
    wcWriteJson($file, $messages);
    return end($messages);
}

function wcAppendDocumentMessage($sessionId, $direction, $fileUrl, $caption = '', $fileName = '')
{
    $file = wcSessionFile($sessionId);
    $messages = wcReadJson($file, []);
    $messages[] = [
        'id' => (int)(microtime(true) * 1000),
        'direction' => $direction,
        'type' => 'document',
        'text' => (string)$caption,
        'file_url' => (string)$fileUrl,
        'file_name' => (string)$fileName,
        'created_at' => time(),
    ];
    wcWriteJson($file, $messages);
    return end($messages);
}

function wcBuildPublicBaseUrl()
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($scriptDir ?: '');
}

function wcShouldSendOffHoursAutoReply()
{
    $tz = new DateTimeZone('Asia/Vladivostok');
    $now = new DateTime('now', $tz);
    $h = (int)$now->format('G');
    return ($h >= 18 || $h < 10);
}

wcEnsureStorage();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'init') {
    $sessionId = $_GET['session_id'] ?? $_POST['session_id'] ?? '';
    if ($sessionId === '') {
        $sessionId = bin2hex(random_bytes(8));
    }
    $name = trim((string)($_GET['name'] ?? $_POST['name'] ?? 'Гость сайта'));

    $sessions = wcReadJson(WEBCHAT_SESSIONS_FILE, ['sessions' => []]);
    $sessions['sessions'][$sessionId] = [
        'name' => $name,
        'updated_at' => time(),
    ];
    wcWriteJson(WEBCHAT_SESSIONS_FILE, $sessions);

    echo json_encode(['ok' => true, 'session_id' => $sessionId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'send') {
    $sessionId = (string)($_POST['session_id'] ?? $_GET['session_id'] ?? '');
    $text = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
    $name = trim((string)($_POST['name'] ?? $_GET['name'] ?? 'Гость сайта'));
    $upload = $_FILES['file'] ?? ($_FILES['image'] ?? null);
    $hasFile = !empty($upload['tmp_name']);
    if ($sessionId === '' || ($text === '' && !$hasFile)) {
        echo json_encode(['ok' => false, 'error' => 'session_id and text or file are required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $threadId = wcEnsureThread($sessionId, $name);
    if (!$threadId) {
        echo json_encode(['ok' => false, 'error' => 'cannot create support thread'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $send = null;

    if ($hasFile) {
        $tmp = $upload['tmp_name'];
        $originalName = (string)($upload['name'] ?? 'file');
        $mime = (string)($upload['type'] ?? '');
        $isImage = stripos($mime, 'image/') === 0;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') $ext = 'jpg';
        $fileName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId) . '_' . time() . '.' . $ext;
        $target = WEBCHAT_UPLOADS_DIR . '/' . $fileName;
        if (!@move_uploaded_file($tmp, $target)) {
            echo json_encode(['ok' => false, 'error' => 'cannot store image'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $publicUrl = wcBuildPublicBaseUrl() . '/webchat_data/uploads/' . $fileName;
        if ($isImage) {
            wcAppendImageMessage($sessionId, 'visitor', $publicUrl, $text);
            $send = wcTelegramRequest('sendPhoto', [
                'chat_id' => WEBCHAT_SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'photo' => $publicUrl,
                'caption' => "🌐 Фото из live-чата\nSession: web_{$sessionId}\nИмя: {$name}" . ($text !== '' ? "\n\n{$text}" : ''),
            ]);
        } else {
            wcAppendDocumentMessage($sessionId, 'visitor', $publicUrl, $text, $originalName);
            $send = wcTelegramRequest('sendDocument', [
                'chat_id' => WEBCHAT_SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'document' => $publicUrl,
                'caption' => "🌐 Документ из live-чата\nSession: web_{$sessionId}\nИмя: {$name}\nФайл: {$originalName}" . ($text !== '' ? "\n\n{$text}" : ''),
            ]);
        }
    } else {
        wcAppendMessage($sessionId, 'visitor', $text);
        $send = wcTelegramRequest('sendMessage', [
            'chat_id' => WEBCHAT_SUPPORT_CHAT_ID,
            'message_thread_id' => $threadId,
            'text' => "🌐 Сообщение из live-чата\nSession: web_{$sessionId}\nИмя: {$name}\n\n{$text}",
        ]);
    }

    if (wcShouldSendOffHoursAutoReply()) {
        $sessions = wcReadJson(WEBCHAT_SESSIONS_FILE, ['sessions' => []]);
        $lastAuto = (int)($sessions['sessions'][$sessionId]['last_auto_reply_at'] ?? 0);
        if ($lastAuto === 0 || (time() - $lastAuto) > 1800) {
            $autoText = "Спасибо за сообщение 💛 Сейчас нерабочее время (с 18:00 до 10:00). Оставьте, пожалуйста, номер телефона — мы свяжемся с вами утром. Либо напишите в Telegram-бот: https://t.me/PandoroomBot";
            wcAppendMessage($sessionId, 'operator', $autoText);
            $sessions['sessions'][$sessionId]['last_auto_reply_at'] = time();
            $sessions['sessions'][$sessionId]['updated_at'] = time();
            wcWriteJson(WEBCHAT_SESSIONS_FILE, $sessions);
        }
    }

    echo json_encode([
        'ok' => !empty($send['ok']),
        'thread_id' => $threadId,
        'telegram' => $send,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'history') {
    $sessionId = (string)($_GET['session_id'] ?? '');
    if ($sessionId === '') {
        echo json_encode(['ok' => false, 'error' => 'session_id is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $messages = wcReadJson(wcSessionFile($sessionId), []);
    echo json_encode(['ok' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'poll') {
    $sessionId = (string)($_GET['session_id'] ?? '');
    $lastId = (int)($_GET['last_id'] ?? 0);
    if ($sessionId === '') {
        echo json_encode(['ok' => false, 'error' => 'session_id is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $messages = wcReadJson(wcSessionFile($sessionId), []);
    $out = [];
    foreach ($messages as $m) {
        if (($m['direction'] ?? '') !== 'operator') {
            continue;
        }
        if ((int)($m['id'] ?? 0) <= $lastId) {
            continue;
        }
        $out[] = $m;
    }

    echo json_encode(['ok' => true, 'messages' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => false,
    'error' => 'unknown action',
], JSON_UNESCAPED_UNICODE);
