<?php
// telegramm/quests_proxy.php

header('Content-Type: application/json; charset=utf-8');
// если хочешь, можно ограничить Origin, но пока так:
header('Access-Control-Allow-Origin: *');

$phone = $_GET['phone'] ?? '';
$phone = preg_replace('/\D+/', '', $phone); // только цифры

if (!$phone) {
    echo json_encode(['error' => 'Не передан параметр phone']);
    exit;
}

// Запрос с приоритетом старого API домена (pandoroom.tech), затем fallback.
$res = null;
$lastCurlError = '';
foreach ([
    'https://pandoroom.tech/pandoroom-api/quests.php',
    'https://pandoroom.org/pandoroom-api/quests.php',
    'https://tgbotum145.ru/pandoroom-api/quests.php',
] as $baseUrl) {
    $ch = curl_init($baseUrl . '?phone=' . urlencode($phone));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 5,
    ]);

    $candidate = curl_exec($ch);
    if ($candidate === false) {
        $lastCurlError = curl_error($ch);
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    if (!is_string($candidate) || trim($candidate) === '') {
        continue;
    }

    if (stripos($candidate, '<html') !== false) {
        continue;
    }

    $decoded = json_decode($candidate, true);
    if (!is_array($decoded)) {
        continue;
    }

    $res = $candidate;
    break;
}

if (!is_string($res) || $res === '') {
    echo json_encode(['error' => 'curl error: ' . ($lastCurlError ?: 'empty response from quests api')]);
    exit;
}

// Если вдруг вернули HTML — покажем понятную ошибку
// Просто пробрасываем оригинальный JSON дальше
echo $res;
