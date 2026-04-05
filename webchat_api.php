<?php

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('UTC');

const WEBCHAT_SUPPORT_CHAT_ID = -1003304052055;
const WEBCHAT_BOT_TOKEN = '7854808857:AAHmleyDhVZvpBrQXG1YiVbMl9gBfXak1xY';
const WEBCHAT_THREAD_MAP_FILE = __DIR__ . '/webchat_data/thread_map.json';
const WEBCHAT_SESSIONS_FILE = __DIR__ . '/webchat_data/sessions.json';
const WEBCHAT_MESSAGES_DIR = __DIR__ . '/webchat_data/messages';

function wcEnsureStorage()
{
    if (!is_dir(dirname(WEBCHAT_THREAD_MAP_FILE))) {
        @mkdir(dirname(WEBCHAT_THREAD_MAP_FILE), 0777, true);
    }
    if (!is_dir(WEBCHAT_MESSAGES_DIR)) {
        @mkdir(WEBCHAT_MESSAGES_DIR, 0777, true);
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
        'text' => (string)$text,
        'created_at' => time(),
    ];
    wcWriteJson($file, $messages);
    return end($messages);
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
    if ($sessionId === '' || $text === '') {
        echo json_encode(['ok' => false, 'error' => 'session_id and text are required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    wcAppendMessage($sessionId, 'visitor', $text);
    $threadId = wcEnsureThread($sessionId, $name);
    if (!$threadId) {
        echo json_encode(['ok' => false, 'error' => 'cannot create support thread'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $send = wcTelegramRequest('sendMessage', [
        'chat_id' => WEBCHAT_SUPPORT_CHAT_ID,
        'message_thread_id' => $threadId,
        'text' => "🌐 Сообщение из live-чата\nSession: web_{$sessionId}\nИмя: {$name}\n\n{$text}",
    ]);

    echo json_encode([
        'ok' => !empty($send['ok']),
        'thread_id' => $threadId,
        'telegram' => $send,
    ], JSON_UNESCAPED_UNICODE);
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
