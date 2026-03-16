<?php

declare(strict_types=1);
require_once __DIR__ . '/max_api.php';

const TG_SUPPORT_CHAT_ID = -1003304052055;
const TG_BOT_TOKEN = '7854808857:AAHmleyDhVZvpBrQXG1YiVbMl9gBfXak1xY';
const THREAD_MAP_FILE = __DIR__ . '/max_telegram_threads.json';
const USERS_FILE = __DIR__ . '/max_users.json';
const MSG_LOG_FILE = __DIR__ . '/max_messages_log.json';

function tgRequest(string $method, array $params): array {
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('TG curl: ' . $err);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ok'])) throw new RuntimeException('TG API error: ' . $raw);
    return $data;
}

function loadJson(string $f, array $default): array {
    if (!is_file($f)) return $default;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}
function saveJson(string $f, array $d): void { file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); }

function normalizeText($s): string {
    $s = is_string($s) ? trim($s) : '';
    return preg_replace('/\s+/u', ' ', $s) ?: '';
}

function buildWelcomeAttachments(): array {
    $buttons = [
        [
            ['type' => 'message', 'text' => '🎮 Квесты'],
            ['type' => 'message', 'text' => '📋 Меню'],
            ['type' => 'message', 'text' => '🗂 Каталоги'],
        ],
        [
            ['type' => 'message', 'text' => '🎉 Праздник'],
            ['type' => 'link', 'text' => '👤 Личный кабинет', 'url' => 'https://pandoroom.tech/telegramm/index.html'],
        ],
        [
            ['type' => 'link', 'text' => '🍽 Праздничное меню', 'url' => 'https://pandoroom.org/prazdpand.pdf'],
            ['type' => 'link', 'text' => '🎂 Торты', 'url' => 'https://pandoroom.org/torts.pdf'],
        ],
        [
            ['type' => 'link', 'text' => '🎈 Украшения', 'url' => 'https://pandoroom.org/dupsysl.pdf'],
            ['type' => 'link', 'text' => '🎭 Шоу-программы', 'url' => 'https://pandoroom.org/wp-content/uploads/2024/09/katalog-shou-programmy.pdf'],
        ],
        [
            ['type' => 'message', 'text' => '👨‍💼 Позвать менеджера'],
            ['type' => 'message', 'text' => '📱 Поделиться телефоном'],
        ],
    ];

    return [[
        'type' => 'inline_keyboard',
        'payload' => ['buttons' => $buttons],
    ]];
}

function buildWelcomeText(): string {
    return "Привет! Я бот Pandoroom 👋\n\nЯ помогу выбрать квест, собрать меню и подготовить праздник.\nЖми кнопки ниже 👇";
}

function extractReplyTarget(array $update): ?array {
    $msg = $update['message'] ?? null;
    if (!is_array($msg)) return null;

    $recipient = $msg['recipient'] ?? [];
    $chatType  = $recipient['chat_type'] ?? '';
    $sender = $msg['sender'] ?? [];
    $senderUserId = $sender['user_id'] ?? null;
    $chatId = $recipient['chat_id'] ?? null;

    if ($chatType === 'dialog' && $senderUserId) return ['type' => 'user', 'id' => (int)$senderUserId];
    if ($chatId) return ['type' => 'chat', 'id' => (int)$chatId];
    if ($senderUserId) return ['type' => 'user', 'id' => (int)$senderUserId];
    return null;
}

function ensureThread(int $externalUserId, string $name, ?string $phone = null): int {
    $map = loadJson(THREAD_MAP_FILE, ['by_user' => [], 'by_thread' => []]);
    $key = (string)$externalUserId;
    if (!empty($map['by_user'][$key]['thread_id'])) return (int)$map['by_user'][$key]['thread_id'];

    $topicName = 'MAX ' . $name . ' (' . $externalUserId . ')';
    if ($phone) $topicName .= ' [' . $phone . ']';

    $r = tgRequest('createForumTopic', ['chat_id' => TG_SUPPORT_CHAT_ID, 'name' => mb_substr($topicName, 0, 128)]);
    $threadId = (int)$r['result']['message_thread_id'];

    $map['by_user'][$key] = ['thread_id' => $threadId, 'name' => $name, 'phone' => $phone];
    $map['by_thread'][(string)$threadId] = ['user_id' => $externalUserId];
    saveJson(THREAD_MAP_FILE, $map);

    return $threadId;
}

function logMaxUser(int $userId, string $name, ?string $phone = null): void {
    $users = loadJson(USERS_FILE, []);
    $users[(string)$userId] = ['name' => $name, 'phone' => $phone, 'updated_at' => time()];
    saveJson(USERS_FILE, $users);
}

function logMessage(array $entry): void {
    $log = loadJson(MSG_LOG_FILE, []);
    $log[] = $entry + ['ts' => time()];
    if (count($log) > 2000) $log = array_slice($log, -2000);
    saveJson(MSG_LOG_FILE, $log);
}

function forwardToTelegram(int $externalUserId, string $name, string $text, ?string $phone = null): void {
    $threadId = ensureThread($externalUserId, $name, $phone);

    $out = "🟣 <b>MAX</b> {$name} (<code>{$externalUserId}</code>)\n";
    if ($phone) $out .= "Телефон: {$phone}\n";
    $out .= "\n{$text}";

    tgRequest('sendMessage', [
        'chat_id' => TG_SUPPORT_CHAT_ID,
        'message_thread_id' => $threadId,
        'text' => $out,
        'parse_mode' => 'HTML',
    ]);
}

$secretHeader = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
if (defined('MAX_WEBHOOK_SECRET') && MAX_WEBHOOK_SECRET && $secretHeader !== '' && $secretHeader !== MAX_WEBHOOK_SECRET) {
    maxLog('BAD SECRET: ' . $secretHeader);
    http_response_code(403);
    exit('forbidden');
}

$raw = file_get_contents('php://input');
maxLog('UPDATE: ' . $raw);
$update = json_decode($raw, true);
if (!is_array($update)) exit('ok');

if (($update['update_type'] ?? '') === 'message_callback') {
    $callback = $update['callback'] ?? [];
    $callbackId = $callback['callback_id'] ?? null;
    if ($callbackId) maxAnswerCallback((string)$callbackId, null, 'Готово ✅');
    exit('ok');
}

if (($update['update_type'] ?? '') === 'message_created') {
    $target = extractReplyTarget($update);
    if (!$target) exit('ok');

    $msg = $update['message'] ?? [];
    $body = $msg['body'] ?? [];
    $sender = $msg['sender'] ?? [];
    $externalUserId = (int)($sender['user_id'] ?? 0);
    $name = (string)($sender['name'] ?? ('User ' . $externalUserId));

    $text = normalizeText($body['text'] ?? '');
    $lower = mb_strtolower($text);

    $send = function(string $textOut, ?array $attachments = null) use ($target) {
        if ($target['type'] === 'user') return maxSendMessageToUser($target['id'], $textOut, $attachments, true, null);
        return maxSendMessageToChat($target['id'], $textOut, $attachments, true, null);
    };

    if ($text === '' || $lower === '/start' || $lower === 'старт' || $lower === 'меню' || $lower === 'menu') {
        $send(buildWelcomeText(), buildWelcomeAttachments());
        exit('ok');
    }

    if ($lower === '📱 поделиться телефоном' || $lower === 'поделиться телефоном') {
        $send('Отправьте номер в формате +79991234567. После этого менеджер подтвердит телефон в CRM.');
        exit('ok');
    }

    if (preg_match('/^\+?\d[\d\s\-\(\)]{8,}$/u', $text)) {
        $phone = preg_replace('/\D+/', '', $text);
        logMaxUser($externalUserId, $name, $phone);
        forwardToTelegram($externalUserId, $name, 'Клиент отправил телефон для подтверждения: ' . $phone, $phone);
        $send('Спасибо! Телефон отправлен менеджеру на подтверждение ✅', buildWelcomeAttachments());
        exit('ok');
    }

    if ($lower === '👨‍💼 позвать менеджера' || $lower === 'позвать менеджера') {
        logMaxUser($externalUserId, $name, null);
        forwardToTelegram($externalUserId, $name, 'Клиент нажал кнопку «Позвать менеджера»');
        $send('Передали ваш запрос менеджеру ✅', buildWelcomeAttachments());
        exit('ok');
    }

    if ($lower === 'квесты' || $lower === '🎮 квесты') {
        $send('Квесты 👇 Выберите нужный раздел в меню.', buildWelcomeAttachments());
        exit('ok');
    }

    if ($lower === 'праздник' || $lower === '🎉 праздник') {
        $send('Праздник 👇 Торты, меню, украшения и шоу — в кнопках ниже.', buildWelcomeAttachments());
        exit('ok');
    }

    if ($lower === 'каталоги' || $lower === '🗂 каталоги' || $lower === 'меню' || $lower === '📋 меню') {
        $send('Каталоги и меню Pandoroom 👇', buildWelcomeAttachments());
        exit('ok');
    }

    if ($externalUserId > 0 && $text !== '') {
        logMaxUser($externalUserId, $name, null);
        forwardToTelegram($externalUserId, $name, $text);
        logMessage(['direction' => 'max_to_tg', 'user_id' => $externalUserId, 'name' => $name, 'text' => $text]);
        $send('Сообщение передано менеджеру ✅', buildWelcomeAttachments());
        exit('ok');
    }

    $send('Принял ✅ Напишите «меню», чтобы открыть кнопки.', buildWelcomeAttachments());
    exit('ok');
}

exit('ok');
