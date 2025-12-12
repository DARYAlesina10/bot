<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$phone = $_GET['phone'] ?? '';
$phone = preg_replace('/\D+/', '', $phone);

if (!$phone) {
    echo json_encode(['error' => 'Не передан параметр phone']);
    exit;
}

$url = 'https://pandoroom.org/pandoroom-api/gifts.php?phone=' . urlencode($phone);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
]);

$res = curl_exec($ch);
if ($res === false) {
    echo json_encode(['error' => 'curl error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

if (stripos($res, '<html') !== false) {
    echo json_encode(['error' => 'Сервер pandoroom.org вернул HTML, а не JSON']);
    exit;
}

echo $res;
