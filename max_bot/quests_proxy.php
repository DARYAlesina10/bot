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

// Запрос на pandoroom.org как в bot.php
$ch = curl_init('https://pandoroom.org/pandoroom-api/quests.php?phone=' . urlencode($phone));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 5,
]);

$res = curl_exec($ch);
if ($res === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => 'curl error: ' . $err]);
    exit;
}
curl_close($ch);

// Если вдруг вернули HTML — покажем понятную ошибку
if (stripos($res, '<html') !== false) {
    echo json_encode(['error' => 'Сервер вернул HTML вместо JSON']);
    exit;
}

// Просто пробрасываем оригинальный JSON дальше
echo $res;
