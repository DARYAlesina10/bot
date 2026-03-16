<?php

declare(strict_types=1);

const MAX_API_BASE = 'https://platform-api.max.ru/';
const MAX_API_VERSION = '1.2.5';
const MAX_LOG_FILE = __DIR__ . '/max_webhook.log';

function maxToken(): string {
    $token = getenv('MAX_BOT_TOKEN') ?: '';
    if ($token === '') {
        throw new RuntimeException('MAX_BOT_TOKEN is empty');
    }
    return $token;
}

function maxLog(string $msg): void {
    file_put_contents(MAX_LOG_FILE, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

function maxRequest(string $method, string $path, array $query = [], ?array $body = null): array {
    $query = array_merge($query, ['v' => MAX_API_VERSION]);
    $url = rtrim(MAX_API_BASE, '/') . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'Authorization: ' . maxToken(),
        'Content-Type: application/json',
        'User-Agent: max-bot-php/' . MAX_API_VERSION,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 40,
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

function maxSendMessageToUser(int $userId, string $text, ?array $attachments = null, bool $notify = true, ?string $format = null): array {
    $body = ['text' => $text, 'notify' => $notify];
    if ($attachments) $body['attachments'] = $attachments;
    if ($format) $body['format'] = $format;
    return maxRequest('POST', 'messages', ['user_id' => $userId], $body);
}

function maxSendMessageToChat(int $chatId, string $text, ?array $attachments = null, bool $notify = true, ?string $format = null): array {
    $body = ['text' => $text, 'notify' => $notify];
    if ($attachments) $body['attachments'] = $attachments;
    if ($format) $body['format'] = $format;
    return maxRequest('POST', 'messages', ['chat_id' => $chatId], $body);
}

function maxAnswerCallback(string $callbackId, ?array $message = null, ?string $notification = null): array {
    $body = [];
    if ($message !== null) $body['message'] = $message;
    if ($notification !== null) $body['notification'] = $notification;
    return maxRequest('POST', 'answers', ['callback_id' => $callbackId], $body);
}
