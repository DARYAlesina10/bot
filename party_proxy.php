<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$phone = $_GET['phone'] ?? '';
$phone = preg_replace('/\D+/', '', $phone);

if (!$phone) {
    echo json_encode(['error' => 'Не передан параметр phone']);
    exit;
}

$payload = json_encode(['da' => $phone]);

$ch = curl_init('https://pandoroom.tech/drobmen.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
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
    echo json_encode(['error' => 'party api returned HTML']);
    exit;
}

echo $res;
