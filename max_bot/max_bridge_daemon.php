<?php

declare(strict_types=1);

/**
 * MAX <-> Telegram CRM bridge daemon.
 *
 * Run: php max_bridge_daemon.php
 */

const SUPPORT_CHAT_ID = -1003304052055;
const MAP_FILE = __DIR__ . '/max_telegram_threads.json';
const STATE_FILE = __DIR__ . '/max_bridge_state.json';

class MaxApiClient
{
    private string $token;
    private string $baseUrl;
    private string $version;

    public function __construct(string $token, string $baseUrl = 'https://platform-api.max.ru/', string $version = '1.2.5')
    {
        if ($token === '') {
            throw new InvalidArgumentException('Empty MAX token');
        }
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->version = $version;
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $query = array_merge($query, ['v' => $this->version]);
        $url = $this->baseUrl . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: ' . $this->token,
            'Content-Type: application/json',
            'User-Agent: max-bridge-php/' . $this->version,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('MAX network error: ' . $err);
        }

        $data = json_decode($raw, true);
        if ($code !== 200) {
            throw new RuntimeException('MAX API error HTTP ' . $code . ': ' . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $raw));
        }

        return is_array($data) ? $data : [];
    }

    public function sendTextToUser(int $userId, string $text, ?array $buttons = null): void
    {
        $body = [
            'text' => $text,
            'notify' => true,
        ];
        if ($buttons) {
            $body['attachments'] = [[
                'type' => 'inline_keyboard',
                'payload' => ['buttons' => $buttons],
            ]];
        }
        $this->request('POST', 'messages', ['user_id' => $userId], $body);
    }

    public function getUpdates(?string $marker): array
    {
        $query = ['limit' => 50, 'timeout' => 30, 'types' => 'message_created,message_callback'];
        if ($marker !== null && $marker !== '') {
            $query['marker'] = $marker;
        }
        return $this->request('GET', 'updates', $query);
    }
}

class TelegramClient
{
    private string $token;
    private string $api;

    public function __construct(string $token)
    {
        if ($token === '') {
            throw new InvalidArgumentException('Empty Telegram token');
        }
        $this->token = $token;
        $this->api = 'https://api.telegram.org/bot' . $token . '/';
    }

    public function request(string $method, array $params = []): array
    {
        $ch = curl_init($this->api . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Telegram network error: ' . $err);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            throw new RuntimeException('Telegram API error: ' . $raw);
        }
        return $data;
    }

    public function createTopic(string $name): int
    {
        $r = $this->request('createForumTopic', ['chat_id' => SUPPORT_CHAT_ID, 'name' => mb_substr($name, 0, 128)]);
        return (int)$r['result']['message_thread_id'];
    }
}

function loadJson(string $file, array $default): array
{
    if (!is_file($file)) return $default;
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

function saveJson(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function keyboardRows(): array
{
    return [
        [['type' => 'message', 'text' => 'Личный кабинет']],
        [['type' => 'message', 'text' => 'Мои квесты'], ['type' => 'message', 'text' => 'Мой праздник']],
        [['type' => 'message', 'text' => 'Мои бонусы'], ['type' => 'message', 'text' => 'Мои подарки']],
        [['type' => 'message', 'text' => 'Скачать приглашение']],
        [['type' => 'message', 'text' => 'Паспорт игрока'], ['type' => 'message', 'text' => 'Позвать менеджера']],
    ];
}

function ensureThread(TelegramClient $tg, array &$map, int $maxUserId, string $displayName): int
{
    $k = (string)$maxUserId;
    if (!empty($map['by_user'][$k]['thread_id'])) {
        return (int)$map['by_user'][$k]['thread_id'];
    }
    $thread = $tg->createTopic('MAX ' . $displayName . ' (' . $maxUserId . ')');
    $map['by_user'][$k] = ['thread_id' => $thread, 'name' => $displayName];
    $map['by_thread'][(string)$thread] = ['user_id' => $maxUserId];
    return $thread;
}

$maxToken = getenv('MAX_BOT_TOKEN') ?: '';
$tgToken = getenv('TELEGRAM_CRM_BOT_TOKEN') ?: '';
if ($maxToken === '' || $tgToken === '') {
    fwrite(STDERR, "Set MAX_BOT_TOKEN and TELEGRAM_CRM_BOT_TOKEN\n");
    exit(1);
}

$max = new MaxApiClient($maxToken, getenv('MAX_API_URL') ?: 'https://platform-api.max.ru/');
$tg = new TelegramClient($tgToken);
$map = loadJson(MAP_FILE, ['by_user' => [], 'by_thread' => []]);
$state = loadJson(STATE_FILE, ['max_marker' => null, 'tg_offset' => 0]);

while (true) {
    try {
        // MAX -> Telegram CRM
        $resp = $max->getUpdates($state['max_marker'] ?? null);
        $state['max_marker'] = $resp['marker'] ?? ($state['max_marker'] ?? null);
        foreach (($resp['updates'] ?? []) as $u) {
            if (($u['update_type'] ?? '') !== 'message_created') continue;
            $msg = $u['message'] ?? [];
            $sender = $msg['sender'] ?? [];
            $userId = (int)($sender['user_id'] ?? 0);
            if ($userId <= 0) continue;

            $text = trim((string)($msg['body']['text'] ?? ''));
            if ($text === '') continue;

            $name = (string)($sender['name'] ?? ('User ' . $userId));
            $threadId = ensureThread($tg, $map, $userId, $name);

            $tg->request('sendMessage', [
                'chat_id' => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text' => "🟣 <b>MAX</b> {$name} (<code>{$userId}</code>):\n" . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'parse_mode' => 'HTML',
            ]);

            $max->sendTextToUser($userId, 'Ваше сообщение передано менеджеру ✅', keyboardRows());
        }

        // Telegram CRM -> MAX
        $tgUpd = $tg->request('getUpdates', [
            'offset' => (int)$state['tg_offset'],
            'timeout' => 1,
            'allowed_updates' => json_encode(['message']),
        ]);
        foreach (($tgUpd['result'] ?? []) as $upd) {
            $state['tg_offset'] = ((int)$upd['update_id']) + 1;
            $m = $upd['message'] ?? null;
            if (!$m) continue;
            if ((int)($m['chat']['id'] ?? 0) !== SUPPORT_CHAT_ID) continue;
            $threadId = (int)($m['message_thread_id'] ?? 0);
            if ($threadId <= 0) continue;
            $target = $map['by_thread'][(string)$threadId]['user_id'] ?? null;
            if (!$target) continue;

            $text = trim((string)($m['text'] ?? ''));
            if ($text === '' || $text === '//') continue;

            $max->sendTextToUser((int)$target, "💬 <b>Команда Pandoroom:</b>\n" . $text, keyboardRows());
        }

        saveJson(MAP_FILE, $map);
        saveJson(STATE_FILE, $state);
    } catch (Throwable $e) {
        error_log('[MAX-BRIDGE] ' . $e->getMessage());
        usleep(500000);
    }
}
