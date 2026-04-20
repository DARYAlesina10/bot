<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$telegramBase = 'https://api.telegram.org';
$botToken = getenv('TELEGRAM_BOT_TOKEN');
if (!$botToken) {
    $botToken = '7854808857:AAHmleyDhVZvpBrQXG1YiVbMl9gBfXak1xY';
}

$method = $_GET['method'] ?? $_POST['method'] ?? '';
if ($method === '') {
    echo json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => 'method is required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$params = $_POST;
unset($params['method']);

if (!$params && !empty($_GET)) {
    $params = $_GET;
    unset($params['method']);
}

$url = $telegramBase . '/bot' . $botToken . '/' . $method;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $params,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

if ($error !== '') {
    echo json_encode([
        'ok' => false,
        'error_code' => $info['http_code'] ?? 0,
        'description' => 'curl error: ' . $error,
        'method' => $method,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($response, true);
if (is_array($decoded)) {
    echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => false,
    'error_code' => $info['http_code'] ?? 0,
    'description' => 'invalid telegram response',
    'raw_response' => $response,
    'method' => $method,
], JSON_UNESCAPED_UNICODE);
