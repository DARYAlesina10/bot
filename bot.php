<?php
// ==========================
//  НАСТРОЙКИ
// ==========================

// Токен бота от BotFather
$token  = '7904822043:AAEXldRBh1UxT3L3Cv2o1IPxJuNWfl9tzSw';
$apiUrl = "https://api.telegram.org/bot{$token}/";

// URL Mini App (index.html с ЛК)
$miniAppUrl = "https://pandoroom.tech/telegramm/index.html?v=" . time(); // как в старом файле

// ID менеджеров (chat_id в Telegram) – сейчас используются только для отдельных уведомлений
$managers = [
    // пример: 346607100,
];

// ID группы/супергруппы, где будет жить техподдержка (ФОРУМ с темами)
define('SUPPORT_CHAT_ID', -1003492911654); // твоя группа CRM Pandoroom ❤ mini

// Файл для хранения связки chat_id → phone
$userStorageFile = __DIR__ . '/users.json';

// Файл для хранения связки user_id ↔ forum topics (message_thread_id)
$supportThreadsFile = __DIR__ . '/support_threads.json';

// Файл связок сообщений клиент ↔ тикет
$messageLinksFile = __DIR__ . '/message_links.json';

// Файл для хранения исходящих сообщений бота клиенту
$botMessagesFile = __DIR__ . '/bot_messages.json';

// Базовый URL API на pandoroom.org
$orgApiBase = 'https://pandoroom.org/pandoroom-api/';


// ==========================
//  ЛОГИ
// ==========================

function logMsg($msg) {
    file_put_contents(__DIR__ . '/bot_log.txt',
        date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}


// ==========================
//  УТИЛИТЫ HTTP
// ==========================

/**
 * Универсальный HTTP-запрос через cURL.
 * $postData:
 *   - null  → GET
 *   - строка или массив → POST
 */
function httpRequest($url, $postData = null, $headers = [], $timeout = 5) {
    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
    ];

    if ($postData !== null) {
        $opts[CURLOPT_POST] = true;
        if (is_array($postData)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postData);
        } else {
            $opts[CURLOPT_POSTFIELDS] = $postData;
        }
    }

    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $opts);

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        logMsg('httpRequest ERROR: ' . $err . ' URL=' . $url);
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    return $result;
}

// Запрос к Telegram API (универсальный)
function tgRequest($method, array $params = []) {
    global $apiUrl;

    $ch = curl_init($apiUrl . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        logMsg('TG CURL ERROR: ' . curl_error($ch));
    } else {
        logMsg('TG RESPONSE: ' . $response);
    }
    curl_close($ch);

    return $response;
}

// Локальное хранилище исходящих сообщений бота клиенту
function bot_messages_load() {
    global $botMessagesFile;
    if (!file_exists($botMessagesFile)) {
        return [];
    }
    $json = file_get_contents($botMessagesFile);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

function bot_messages_save($data) {
    global $botMessagesFile;
    file_put_contents(
        $botMessagesFile,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function log_bot_message($chatId, $messageId, $text, $type = 'system') {
    $data = bot_messages_load();
    $key = (string)$chatId;
    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = [];
    }

    $data[$key][] = [
        'message_id' => (int)$messageId,
        'text'       => (string)$text,
        'type'       => (string)$type, // system | chat
        'created_at' => time(),
    ];

    // Обрезаем до последних 50
    if (count($data[$key]) > 50) {
        $data[$key] = array_slice($data[$key], -50);
    }

    bot_messages_save($data);
}
// Можно вызывать из других скриптов, чтобы положить "внешнее" сообщение в историю
function register_external_bot_message($chatId, $text) {
    // message_id у внешнего сообщения нам не важен, ставим 0
    log_bot_message($chatId, 0, (string)$text, 'system');
}


function get_last_bot_system_messages($chatId, $limit = 3) {
    $data = bot_messages_load();
    $key = (string)$chatId;
    if (!isset($data[$key]) || !is_array($data[$key])) {
        return [];
    }
    $items = $data[$key];

    // фильтруем только system
    $items = array_values(array_filter($items, function($row) {
        return ($row['type'] ?? '') === 'system';
    }));

    // сортировка по created_at (DESC)
    usort($items, function($a, $b) {
        return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
    });

    return array_slice($items, 0, $limit);
}

// Запрос к Telegram API sendMessage (с логированием)
// Запрос к Telegram API sendMessage (с логированием)
function tgSendMessage($chatId, $text, $replyToMessageId = null, $type = 'system', $extraParams = []) {
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];

    if ($replyToMessageId !== null) {
        $params['reply_to_message_id'] = $replyToMessageId;
        $params['allow_sending_without_reply'] = true;
    }

    // Дополнительные параметры (reply_markup и т.п.)
    if (!empty($extraParams) && is_array($extraParams)) {
        $params = array_merge($params, $extraParams);
    }

    $respJson = tgRequest('sendMessage', $params);
    if (!$respJson) {
        return null;
    }
    $resp = json_decode($respJson, true);

    // логируем исходящие сообщения бота клиенту (только личка)
    if (
        is_array($resp)
        && !empty($resp['ok'])
        && !empty($resp['result']['message_id'])
        && (int)$chatId > 0
    ) {
        $mid = (int)$resp['result']['message_id'];
        log_bot_message($chatId, $mid, $text, $type);
    }

    return $resp;
}



// ==========================
//  API: СВОБОДНЫЕ СЛОТЫ КВЕСТОВ ДЛЯ MINI-APP (action=quest_slots)
// ==========================
function apiGetQuestSlots()
{
    header('Content-Type: application/json; charset=utf-8');

    $date   = $_GET['date']   ?? '';
    $time   = $_GET['time']   ?? '';
    $age    = (int)($_GET['age']    ?? 0);
    $people = (int)($_GET['people'] ?? 0);

    if ($date === '' || $time === '' || $age <= 0 || $people <= 0) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // дергаем pandoroom.org/pandoroom-api/free_quests.php
    $data = callPandoroomOrgApi('free_quests.php', [
        'date'   => $date,
        'time'   => $time,
        'age'    => $age,
        'people' => $people,
    ]);

    if (!is_array($data)) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================
//  API: СВОБОДНЫЕ СТОЛЫ ДЛЯ MINI-APP (action=stols)
// ==========================

function apiGetFreeTables()
{
    header('Content-Type: application/json; charset=utf-8');

    // Параметры из mini-app (index.html → bookingLoadTables)
    $date   = $_GET['date']   ?? '';
    $time   = $_GET['time']   ?? '';
    $people = (int)($_GET['people'] ?? 0);
    $age    = (int)($_GET['age']    ?? 0);
    $hall   = $_GET['hall']   ?? '';

    // Быстрая валидация
    if ($date === '' || $time === '' || $people <= 0 || $age <= 0) {
        echo json_encode([
            'message'    => 'Некорректные параметры (дата, время, гости или возраст).',
            'stols'      => [],
            'next_time'  => null,
            'next_stols' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Маппинг обозначений зала
    $zal = '';
    if ($hall === 'kids') {
        $zal = 'kids';
    } elseif ($hall === 'lounge') {
        $zal = 'loung';
    } elseif ($hall === 'cafe') {
        $zal = 'cafe';
    }

    // /svstol.php?dat=...&start=...&stop=...&kol=...&voz=...&zal=...&ok=2
    $svstolUrl = 'https://pandoroom.org/svstol.php'
        . '?dat='   . urlencode($date)
        . '&start=' . urlencode($time)
        . '&stop='  . urlencode($time)
        . '&kol='   . urlencode($people)
        . '&voz='   . urlencode($age)
        . '&zal='   . urlencode($zal)
        . '&ok=2';

    $res = httpRequest($svstolUrl);

    if (!is_string($res) || $res === '') {
        echo json_encode([
            'message'    => 'Не удалось получить данные о столах (svstol.php не отвечает).',
            'stols'      => [],
            'next_time'  => null,
            'next_stols' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = json_decode($res, true);
    if ($data === null) {
        logMsg('SVSTOL JSON ERROR: ' . substr($res, 0, 200));

        echo json_encode([
            'message'    => 'Ошибка формата ответа от svstol.php.',
            'stols'      => [],
            'next_time'  => null,
            'next_stols' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}


// ========================== 
//  HTTP-API ДЛЯ MINI-APP (GET /bot.php?action=...)
//  + лог внешних сообщений (log_external_message) + меню (menu)
// ==========================
if (isset($_GET['action'])) {
    // ОБЯЗАТЕЛЬНО trim — чтобы не поймать "menu " и т.п.
    $action = trim($_GET['action']);

    switch ($action) {
        case 'stols':
            apiGetFreeTables();
            exit;

        case 'quest_slots':
            apiGetQuestSlots();
            exit;

        case 'log_external_message':
            header('Content-Type: application/json; charset=utf-8');

            $chatId = isset($_REQUEST['chat_id']) ? (int)$_REQUEST['chat_id'] : 0;
            $text   = isset($_REQUEST['text']) ? (string)$_REQUEST['text'] : '';

            if ($chatId <= 0 || $text === '') {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'chat_id and text are required',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Пишем сообщение в историю для кнопки "Загрузить сообщения"
            register_external_bot_message($chatId, $text);

            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;

        case 'menu':
            header('Content-Type: application/json; charset=utf-8');

            // 1. Получаем токен iiko
            $urlToken  = 'https://api-ru.iiko.services/api/1/access_token';
            $postData  = ['apiLogin' => '3e2a8252692647d9a40bb92e194dd7ea'];
            $headers   = ['Content-Type: application/json'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_URL, $urlToken);
            curl_setopt($ch, CURLOPT_POST, true);
            $resultToken = curl_exec($ch);
            if ($resultToken === false) {
                echo json_encode(['error' => 'iiko auth failed'], JSON_UNESCAPED_UNICODE);
                curl_close($ch);
                exit;
            }
            curl_close($ch);

            $hj = json_decode($resultToken);
            if (!$hj || empty($hj->token)) {
                echo json_encode(['error' => 'iiko token empty'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $token = $hj->token;

            // 2. Номенклатура
            $urlNomen = 'https://api-ru.iiko.services/api/1/nomenclature';
            $postNomen = [
                "startRevision"  => 1,
                "organizationId" => '0d11942d-de93-4be2-ae24-752307cc186b',
            ];
            $headersNomen = [
                "Authorization: Bearer " . $token,
                "Content-Type: application/json",
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headersNomen);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postNomen, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_URL, $urlNomen);
            curl_setopt($ch, CURLOPT_POST, true);
            $resultNom = curl_exec($ch);
            if ($resultNom === false) {
                echo json_encode(['error' => 'iiko nomenclature failed'], JSON_UNESCAPED_UNICODE);
                curl_close($ch);
                exit;
            }
            curl_close($ch);

            $hjm = json_decode($resultNom);
            if (!$hjm) {
                echo json_encode(['error' => 'bad nomenclature json'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ====== МАССИВЫ ДЛЯ МИНИ-АППА ======
            $torts   = [];
            $show    = [];
            $decor   = [];

            $snacks  = []; // закуски
            $salads  = []; // салаты
            $hot     = []; // горячее
            $garnish = []; // гарниры
            $fries   = []; // фри
            $pizza   = []; // пицца
            $sweets  = []; // сладкое
            $drinks  = []; // напитки

            // ====== 2.1. ТОРТЫ (groups + additionalInfo) ======
            if (!empty($hjm->groups) && is_array($hjm->groups)) {
                foreach ($hjm->groups as $fg) {
                    if (
                        $fg->parentGroup === "6ec5d3c3-c470-43d7-a647-3a5230bcac00" ||
                        $fg->parentGroup === "deed18dd-275d-4e2e-8914-b1f58ffcbfe3"
                    ) {
                        $image = '';
                        if (!empty($fg->imageLinks) && is_array($fg->imageLinks)) {
                            $image = $fg->imageLinks[0];
                        }

                        $variants = [];
                        if (!empty($fg->additionalInfo)) {
                            $variants = json_decode($fg->additionalInfo);
                        }

                        if (is_array($variants)) {
                            foreach ($variants as $r) {
                                $weight = null;

                                if (isset($r->ves)) {
                                    $weight = (float)$r->ves;
                                } elseif (isset($fg->weight)) {
                                    $weight = round((float)$fg->weight, 2);
                                } else {
                                    $n = isset($r->name) ? (string)$r->name : '';
                                    if (preg_match('/([\d\.]+)\s*кг/ui', $n, $m)) {
                                        $weight = (float)$m[1] * 1000;
                                    } elseif (preg_match('/([\d\.]+)\s*г/ui', $n, $m)) {
                                        $weight = (float)$m[1];
                                    }
                                }

                                $price = 0;
                                if (isset($r->cen)) {
                                    $price = (float)$r->cen;
                                }

                                $torts[] = [
                                    'id'     => isset($r->id)   ? $r->id   : null,
                                    'name'   => trim(($fg->name ?? '') . ' ' . ($r->name ?? '')),
                                    'price'  => $price,
                                    'weight' => $weight,
                                    'image'  => $image,
                                ];
                            }
                        }
                    }
                }
            }

            // ====== 2.2. ПРОЧИЕ ПРОДУКТЫ (products по parentGroup) ======
            if (!empty($hjm->products) && is_array($hjm->products)) {
                foreach ($hjm->products as $fg) {
                    $parent = $fg->parentGroup ?? '';
                    $imgs   = !empty($fg->imageLinks) && is_array($fg->imageLinks) ? $fg->imageLinks : [];
                    $image  = $imgs ? $imgs[0] : '';

                    $price = 0;
                    if (!empty($fg->sizePrices) && is_array($fg->sizePrices)) {
                        $dh = $fg->sizePrices;
                        if (!empty($dh[0]->price->currentPrice)) {
                            $price = (float)$dh[0]->price->currentPrice;
                        }
                    }

                    $weight = null;
                    if (isset($fg->weight)) {
                        $weight = round((float)$fg->weight, 2);
                    }

                    $item = [
                        'id'     => $fg->id ?? null,
                        'name'   => $fg->name ?? '',
                        'price'  => $price,
                        'weight' => $weight,
                        'image'  => $image,
                    ];

                    // ШОУ
                    if ($parent === "075c7040-7365-4eba-b19a-bb63bd765ea5" ||
                        $parent === "9ea9f2bf-dc1a-4773-98dd-027683aa7c80"
                    ) {
                        $show[] = $item;
                    }
                    // УКРАШЕНИЯ
                    elseif ($parent === "81aa3f1c-86ff-40d0-b930-b556e2055934" ||
                            $parent === "97fd7bd1-5cd4-43d9-9409-429781209481" ||
                            $parent === "d1bff5fd-89aa-4122-8b57-341249d0b55e"
                    ) {
                        $decor[] = $item;
                    }
                    // ГАРНИРЫ
                    elseif ($parent === "f99c58b2-4ce4-4519-96b6-cf6d038b878f") {
                        $garnish[] = $item;
                    }
                    // СЛАДКОЕ
                    elseif ($parent === "7ac6481c-a7bb-4b93-823f-ecdabb99c320") {
                        $sweets[] = $item;
                    }
                    // ПИЦЦА
                    elseif ($parent === "5282b26a-04b1-4880-ba7d-597c13d361f8") {
                        $pizza[] = $item;
                    }
                    // ФРИ
                    elseif ($parent === "1c1dd7e1-19ca-41a0-835c-ef49dd3b9b1e") {
                        $fries[] = $item;
                    }
                    // ЗАКУСКИ
                    elseif ($parent === "fb24353e-522f-43e1-b268-14556624e97a") {
                        $snacks[] = $item;
                    }
                    // САЛАТЫ
                    elseif ($parent === "88d5e09b-d4f8-4b19-b50c-0cad5c6de81c") {
                        $salads[] = $item;
                    }
                    // ГОРЯЧЕЕ
                    elseif ($parent === "11e86bdd-cac5-4559-80f0-023f95937f8f") {
                        $hot[] = $item;
                    }
                    // НАПИТКИ
                    elseif (
                        $parent === "f54fb156-fe31-4824-ba31-fe38b8c6d7cb" ||
                        $parent === "33ab134e-2ca1-49d2-b4e6-ee0b8d562871" ||
                        $parent === "608c883e-7678-4fa8-9ef1-e127ea8877f2" ||
                        $parent === "89ae1b8f-c8b9-4099-be4e-52a233a287a8" ||
                        $parent === "767fa1a8-4578-499e-ad5b-a3d0dc6ba11f" ||
                        $parent === "bebbdf8e-31e8-4c60-bc46-8da01e6615ac"
                    ) {
                        $drinks[] = $item;
                    }
                }
            }

            echo json_encode(
                [
                    'torts'   => $torts,
                    'show'    => $show,
                    'decor'   => $decor,
                    'snacks'  => $snacks,
                    'salads'  => $salads,
                    'hot'     => $hot,
                    'garnish' => $garnish,
                    'fries'   => $fries,
                    'pizza'   => $pizza,
                    'sweets'  => $sweets,
                    'drinks'  => $drinks,
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;

        default:
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
            exit;
    }
}


// ==========================
//  ЛОКАЛЬНОЕ ХРАНИЛИЩЕ phone ↔ chat_id
// ==========================

function saveUserPhone($chatId, $phone) {
    global $userStorageFile;

    $data = [];
    if (file_exists($userStorageFile)) {
        $json = file_get_contents($userStorageFile);
        $data = json_decode($json, true) ?: [];
    }

    $data[(string)$chatId] = [
        'phone'      => $phone,
        'updated_at' => time(),
    ];

    file_put_contents(
        $userStorageFile,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function getUserPhone($chatId) {
    global $userStorageFile;

    if (!file_exists($userStorageFile)) {
        return null;
    }
    $json = file_get_contents($userStorageFile);
    $data = json_decode($json, true) ?: [];

    return $data[(string)$chatId]['phone'] ?? null;
}

function notifyManagers($text) {
    global $managers;
    foreach ($managers as $adminId) {
        tgRequest('sendMessage', [
            'chat_id' => $adminId,
            'text'    => $text,
        ]);
    }
}


// ==========================
//  ЛОКАЛЬНОЕ ХРАНИЛИЩЕ ТРЕДОВ ПОДДЕРЖКИ (user_id ↔ forum topic / message_thread_id)
// ==========================
//
// структура threads:
// [
//   [
//     'user_id'         => 123,
//     'thread_id'       => 30,
//     'status'          => 'open' | 'closed',
//     'created_at'      => 1234567890,
//     'last_user_msg_at'=> 1234567890,
//     'last_notify_at'  => 1234567890
//   ],
//   ...
// ]

function support_load_store() {
    global $supportThreadsFile;

    if (!file_exists($supportThreadsFile)) {
        return [
            'threads' => [],
        ];
    }

    $json = file_get_contents($supportThreadsFile);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['threads' => []];
    }

    if (!isset($data['threads']) || !is_array($data['threads'])) {
        $data['threads'] = [];
    }

    return $data;
}

function support_save_store($store) {
    global $supportThreadsFile;

    file_put_contents(
        $supportThreadsFile,
        json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

// Последний тред по пользователю (игнорируя старый формат без thread_id)
function support_get_thread_by_user($userId) {
    $store   = support_load_store();
    $threads = $store['threads'];

    for ($i = count($threads) - 1; $i >= 0; $i--) {
        $t = $threads[$i];
        if (!isset($t['thread_id'])) {
            continue;
        }
        if ((int)($t['user_id'] ?? 0) === (int)$userId) {
            return $t;
        }
    }
    return null;
}

// Зарегистрировать новый тред (forum topic = message_thread_id)
function support_register_thread($userId, $threadId) {
    $store = support_load_store();
    $now   = time();

    $store['threads'][] = [
        'user_id'         => (int)$userId,
        'thread_id'       => (int)$threadId,
        'status'          => 'open',
        'created_at'      => $now,
        'last_user_msg_at'=> $now,
        'last_notify_at'  => 0,
    ];

    support_save_store($store);
}

// Обновить мета-инфу по треду (по последнему треду этого пользователя)
function support_update_thread($userId, array $fields) {
    $store   = support_load_store();
    $threads =& $store['threads'];

    for ($i = count($threads) - 1; $i >= 0; $i--) {
        if (!isset($threads[$i]['thread_id'])) {
            continue;
        }
        if ((int)($threads[$i]['user_id'] ?? 0) === (int)$userId) {
            foreach ($fields as $k => $v) {
                $threads[$i][$k] = $v;
            }
            support_save_store($store);
            return;
        }
    }
}

// Найти user_id по message_thread_id (только по новым записям)
function support_find_user_by_thread($threadId) {
    $store = support_load_store();
    foreach ($store['threads'] as $t) {
        if (!isset($t['thread_id'])) {
            continue;
        }
        if ((int)$t['thread_id'] === (int)$threadId) {
            return (int)$t['user_id'];
        }
    }
    return null;
}


// ==========================
//  ХРАНИЛИЩЕ СВЯЗОК СООБЩЕНИЙ (клиент ↔ тикет)
// ==========================

function message_links_load() {
    global $messageLinksFile;

    if (!file_exists($messageLinksFile)) {
        return ['links' => []];
    }

    $json = file_get_contents($messageLinksFile);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['links' => []];
    }

    if (!isset($data['links']) || !is_array($data['links'])) {
        $data['links'] = [];
    }

    return $data;
}

function message_links_save($store) {
    global $messageLinksFile;

    file_put_contents(
        $messageLinksFile,
        json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function message_links_add($userId, $userMessageId, $adminMessageId, $direction) {
    $store = message_links_load();
    if (!isset($store['links']) || !is_array($store['links'])) {
        $store['links'] = [];
    }

    $store['links'][] = [
        'user_id'        => (int)$userId,
        'user_message_id'=> (int)$userMessageId,
        'admin_message_id'=> (int)$adminMessageId,
        'direction'      => (string)$direction, // user_to_admin | admin_to_user
        'created_at'     => time(),
    ];

    // Обрезаем до 2000 последних связок
    if (count($store['links']) > 2000) {
        $store['links'] = array_slice($store['links'], -2000);
    }

    message_links_save($store);
}

// Найти связку по admin_message_id
function message_links_find_by_admin_message($adminMessageId) {
    $store = message_links_load();
    $links = $store['links'] ?? [];
    for ($i = count($links) - 1; $i >= 0; $i--) {
        $l = $links[$i];
        if ((int)($l['admin_message_id'] ?? 0) === (int)$adminMessageId) {
            return $l;
        }
    }
    return null;
}

// Найти связку по user_message_id
function message_links_find_by_user_message($userId, $userMessageId) {
    $store = message_links_load();
    $links = $store['links'] ?? [];
    for ($i = count($links) - 1; $i >= 0; $i--) {
        $l = $links[$i];
        if ((int)($l['user_id'] ?? 0) === (int)$userId &&
            (int)($l['user_message_id'] ?? 0) === (int)$userMessageId) {
            return $l;
        }
    }
    return null;
}

// Маппинг: reply клиента → сообщение в тикете
function mapReplyToAdmin($userId, $repliedUserMessageId) {
    if (!$repliedUserMessageId) return null;

    $store = message_links_load();
    $links = $store['links'] ?? [];

    // Сначала ищем связку admin_to_user (клиент отвечает на бот/менеджера)
    for ($i = count($links) - 1; $i >= 0; $i--) {
        $l = $links[$i];
        if ((int)($l['user_id'] ?? 0) === (int)$userId &&
            (int)($l['user_message_id'] ?? 0) === (int)$repliedUserMessageId &&
            ($l['direction'] ?? '') === 'admin_to_user') {
            return (int)$l['admin_message_id'];
        }
    }

    // На всякий случай — если он отвечает на своё же сообщение
    for ($i = count($links) - 1; $i >= 0; $i--) {
        $l = $links[$i];
        if ((int)($l['user_id'] ?? 0) === (int)$userId &&
            (int)($l['user_message_id'] ?? 0) === (int)$repliedUserMessageId &&
            ($l['direction'] ?? '') === 'user_to_admin') {
            return (int)$l['admin_message_id'];
        }
    }

    return null;
}

// Маппинг: reply менеджера в тикете → сообщение клиента
function mapReplyToUser($userId, $adminMessageId) {
    if (!$adminMessageId) return null;

    $store = message_links_load();
    $links = $store['links'] ?? [];

    for ($i = count($links) - 1; $i >= 0; $i--) {
        $l = $links[$i];
        if ((int)($l['user_id'] ?? 0) === (int)$userId &&
            (int)($l['admin_message_id'] ?? 0) === (int)$adminMessageId) {
            return (int)$l['user_message_id'];
        }
    }

    return null;
}


// ==========================
//  API pandoroom.org
// ==========================

function callPandoroomOrgApi($endpoint, $params = []) {
    global $orgApiBase;

    $url = rtrim($orgApiBase, '/') . '/' . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $res = httpRequest($url);
    if (!is_string($res)) {
        return null;
    }

    $data = json_decode($res, true);
    if ($data === null) {
        logMsg('ORG API JSON ERROR: ' . $res);
    }

    return $data;
}

// Привязка chat_id к пользователю на pandoroom.org
function bindChatToUser($phone, $chatId) {
    if (!$phone || !$chatId) return;
    $res = callPandoroomOrgApi('bind_chat.php', [
        'phone'   => $phone,
        'chat_id' => $chatId,
    ]);
    logMsg('BIND_CHAT RESPONSE: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
}


// ==========================
//  БОНУСЫ
// ==========================

function getBonusInfoByPhone($phone) {
    if (!$phone) return null;

    $data = callPandoroomOrgApi('bonuses.php', ['phone' => $phone]);
    if ($data === null || isset($data['error'])) {
        return null;
    }

    return [
        'balance' => $data['balance'] ?? 0,
        'expires' => $data['expires'] ?? null,
    ];
}


// ==========================
//  ПРАЗДНИК / МЕРОПРИЯТИЯ (drobmen.php на pandoroom.tech)
// ==========================
function getEventInfoByPhone($phone) {
    if (!$phone) return null;

    $payload = json_encode(['da' => (string)$phone], JSON_UNESCAPED_UNICODE);

    $res = httpRequest(
        'https://pandoroom.tech/drobmen.php',
        $payload,
        ['Content-Type: application/json'],
        3
    );

    if (!is_string($res) || $res === '') {
        logMsg('DROBMEN EMPTY OR ERROR');
        return null;
    }

    // Если вдруг вернуло HTML — значит ошибка/редирект
    if (stripos($res, '<html') !== false) {
        logMsg('DROBMEN HTML ERROR: ' . substr($res, 0, 200));
        return null;
    }

    $data = json_decode($res, true);
    if (!$data || empty($data['datas'])) {
        logMsg('DROBMEN JSON DECODE OR EMPTY: ' . $res);
        return null;
    }

    // Небольшой помощник: привести HTML со списками к тексту
    $htmlToLines = function ($html) {
        if (!$html) return '';
        // заменим <p> и <br> на переносы строк
        $html = preg_replace('~<(p|br)[^>]*>~i', "\n", $html);
        $text = strip_tags($html);
        // убираем лишние пробелы
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        // нормализуем переносы строк
        $text = preg_replace("/\n{2,}/u", "\n", $text);
        return trim($text);
    };

    return [
        // ОСНОВНОЕ
        'date'      => $data['datas']  ?? '',
        'time_from' => $data['start']  ?? '',
        'time_to'   => $data['stop']   ?? '',
        'hall'      => trim(($data['zal'] ?? '') . ' ' . ($data['stol'] ?? '')),
        'hall_name' => $data['zal']    ?? '',
        'table'     => $data['stol']   ?? '',
        'branch'    => $data['dep']    ?? '',   // подразделение / филиал

        // КЛИЕНТ
        'customer_name'  => $data['imzak'] ?? '',
        'phone'          => $data['tel']   ?? '',
        'check_id'       => $data['ch']    ?? '',

        // ИМЕНИННИК и ГОСТИ
        'imen'       => $data['imen'] ?? '',
        'age'        => $data['vozr'] ?? '',
        'guests'     => $data['kol']  ?? '',   // общее количество

        // ПРЕДЗАКАЗ ПО КУХНЕ (готовый текст по категориям)
        'preorder_hot'   => $htmlToLines($data['pzgor']  ?? ''), // горячее (горячая кухня)
        'preorder_cold'  => $htmlToLines($data['pzhol']  ?? ''), // холодные закуски
        'preorder_pizza' => $htmlToLines($data['pzpizz'] ?? ''), // пицца
        'preorder_bar'   => $htmlToLines($data['pzbar']  ?? ''), // бар / напитки

        // ФЛАГИ НАЛИЧИЯ БЛОКОВ (шоу, квесты, украшения)
        'has_show'   => !empty($data['sh']),
        'has_quests' => !empty($data['kv']),
        'has_decor'  => !empty($data['uk']),
    ];
}





// ==========================
//  МОИ КВЕСТЫ (подробно, с фото и адресом) – API pandoroom.org
// ==========================

function getQuestsDetailedByPhone($phone) {
    if (!$phone) {
        return ['error' => 'Телефон не указан.'];
    }

    $data = callPandoroomOrgApi('quests.php', ['phone' => $phone]);
    if ($data === null) {
        return ['error' => 'Не удалось связаться с pandoroom.org (quests).'];
    }

    if (isset($data['error'])) {
        return ['error' => 'Ошибка: ' . $data['error']];
    }

    if (!is_array($data) || !$data) {
        return ['error' => 'Квестов по вашему номеру пока нет.'];
    }

    return $data;
}

// Определяем timestamp начала квеста
function getQuestTimestamp($q) {
    $candidates = ['time', 'datetime', 'book_time', 'start'];
    foreach ($candidates as $field) {
        if (!empty($q[$field])) {
            $ts = strtotime($q[$field]);
            if ($ts !== false) return $ts;
        }
    }
    return null;
}


/**
 * ВСЕ квесты: делим на предстоящие и прошедшие
 */
function sendUserQuests($chatId, $phone) {
    $quests = getQuestsDetailedByPhone($phone);

    if (isset($quests['error'])) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '❗ ' . $quests['error'],
        ]);
        return;
    }

    $now = time();
    $upcoming = [];
    $past     = [];

    foreach ($quests as $q) {
        $ts = getQuestTimestamp($q);
        if ($ts === null) {
            $upcoming[] = $q;
            continue;
        }
        if ($ts >= $now) {
            $q['_ts'] = $ts;
            $upcoming[] = $q;
        } else {
            $q['_ts'] = $ts;
            $past[] = $q;
        }
    }

    usort($upcoming, function($a, $b) {
        return ($a['_ts'] ?? 0) <=> ($b['_ts'] ?? 0);
    });
    usort($past, function($a, $b) {
        return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
    });

    if (!$upcoming && !$past) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => 'Квесты по вашему номеру пока не найдены.',
        ]);
        return;
    }

    // ПРЕДСТОЯЩИЕ
    if ($upcoming) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '🔜 Ваши предстоящие квесты:',
        ]);

        foreach ($upcoming as $q) {
            $title    = $q['title']    ?? 'Квест';
            $time     = $q['time']     ?? ($q['datetime'] ?? ($q['book_time'] ?? ''));
            $animator = $q['animator'] ?? '';
            $price    = $q['price']    ?? '';
            $duration = $q['duration'] ?? '';
            $address  = $q['address']  ?? '';
            $image    = $q['image']    ?? '';
            $fotoLink = $q['foto']     ?? '';

            $caption = "🎭 <b>{$title}</b>\n";
            if ($time)     $caption .= "📅 {$time}\n";
            if ($address)  $caption .= "📍 {$address}\n";
            if ($duration) $caption .= "⏱ {$duration}\n";
            if ($price)    $caption .= "💰 {$price} ₽\n";
            if ($animator) $caption .= "🧑‍🎤 Аниматор: {$animator}\n";

            $inlineKeyboard = null;
        if (!empty($fotoLink)) {
    // Фото уже есть — даём ссылку как раньше
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '📸 Посмотреть фото',
                    'url'  => $fotoLink,
                ],
            ],
        ],
    ];
} elseif ($bookingId) {
    // Фото нет — даём кнопку "Запросить фото"
    $inlineKeyboard = [
        'inline_keyboard' => [
            [
                [
                    'text'          => '📸 Запросить фото',
                    'callback_data' => 'quest_request_photos:' . $bookingId,
                ],
            ],
        ],
    ];
}

            if ($image) {
                $params = [
                    'chat_id'    => $chatId,
                    'photo'      => $image,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ];
                if ($inlineKeyboard) {
                    $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
                }
                tgRequest('sendPhoto', $params);
            } else {
                $params = [
                    'chat_id'    => $chatId,
                    'text'       => $caption,
                    'parse_mode' => 'HTML',
                ];
                if ($inlineKeyboard) {
                    $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
                }
                tgRequest('sendMessage', $params);
            }
        }
    }

    // ПРОШЕДШИЕ
    if ($past) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '📜 Ваши прошедшие квесты (последние 5):',
        ]);

        $pastLimited = array_slice($past, 0, 5);

        foreach ($pastLimited as $q) {
            $title    = $q['title']    ?? 'Квест';
            $time     = $q['time']     ?? ($q['datetime'] ?? ($q['book_time'] ?? ''));
            $animator = $q['animator'] ?? '';
            $price    = $q['price']    ?? '';
            $duration = $q['duration'] ?? '';
            $address  = $q['address']  ?? '';
            $image    = $q['image']    ?? '';
            $fotoLink = $q['foto']     ?? '';

            $caption = "🎭 <b>{$title}</b>\n";
            if ($time)     $caption .= "📅 {$time}\n";
            if ($address)  $caption .= "📍 {$address}\n";
            if ($duration) $caption .= "⏱ {$duration}\n";
            if ($price)    $caption .= "💰 {$price} ₽\n";
            if ($animator) $caption .= "🧑‍🎤 Аниматор: {$animator}\n";

            $inlineKeyboard = null;
            $inlineKeyboard = null;
        if (!empty($fotoLink)) {
            // Есть готовая ссылка на фото
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📸 Посмотреть фото',
                            'url'  => $fotoLink,
                        ],
                    ],
                ],
            ];
        } else {
            // Фото нет — даём кнопку «Запросить фото»
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text'          => '📸 Запросить фото',
                            'callback_data' => 'quest_photo_request',
                        ],
                    ],
                ],
            ];
        }


            if ($image) {
                $params = [
                    'chat_id'    => $chatId,
                    'photo'      => $image,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ];
                if ($inlineKeyboard) {
                    $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
                }
                tgRequest('sendPhoto', $params);
            } else {
                $params = [
                    'chat_id'    => $chatId,
                    'text'       => $caption,
                    'parse_mode' => 'HTML',
                ];
                if ($inlineKeyboard) {
                    $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
                }
                tgRequest('sendMessage', $params);
            }
        }
    }
}


/**
 * ТОЛЬКО предстоящие квесты
 */
function sendUserUpcomingQuests($chatId, $phone) {
    $quests = getQuestsDetailedByPhone($phone);

    if (isset($quests['error'])) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '❗ ' . $quests['error'],
        ]);
        return;
    }

    $now = time();
    $upcoming = [];

    foreach ($quests as $q) {
        $ts = getQuestTimestamp($q);
        if ($ts === null) {
            $upcoming[] = $q;
            continue;
        }
        if ($ts >= $now) {
            $q['_ts'] = $ts;
            $upcoming[] = $q;
        }
    }

    if (!$upcoming) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => 'Предстоящих квестов по вашему номеру пока нет 🔍',
        ]);
        return;
    }

    usort($upcoming, function($a, $b) {
        return ($a['_ts'] ?? 0) <=> ($b['_ts'] ?? 0);
    });

    tgRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => '🔜 Ваши предстоящие квесты:',
    ]);

    foreach ($upcoming as $q) {
        $title    = $q['title']    ?? 'Квест';
        $time     = $q['time']     ?? ($q['datetime'] ?? ($q['book_time'] ?? ''));
        $animator = $q['animator'] ?? '';
        $price    = $q['price']    ?? '';
        $duration = $q['duration'] ?? '';
        $address  = $q['address']  ?? '';
        $image    = $q['image']    ?? '';
        $fotoLink = $q['foto']     ?? '';

        $caption = "🎭 <b>{$title}</b>\n";
        if ($time)     $caption .= "📅 {$time}\n";
        if ($address)  $caption .= "📍 {$address}\n";
        if ($duration) $caption .= "⏱ {$duration}\n";
        if ($price)    $caption .= "💰 {$price} ₽\n";
        if ($animator) $caption .= "🧑‍🎤 Аниматор: {$animator}\n";

        $inlineKeyboard = null;
          $inlineKeyboard = null;
        if (!empty($fotoLink)) {
            // Есть готовая ссылка на фото
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📸 Посмотреть фото',
                            'url'  => $fotoLink,
                        ],
                    ],
                ],
            ];
        } else {
            // Фото нет — даём кнопку «Запросить фото»
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text'          => '📸 Запросить фото',
                            'callback_data' => 'quest_photo_request',
                        ],
                    ],
                ],
            ];
        }


        if ($image) {
            $params = [
                'chat_id'    => $chatId,
                'photo'      => $image,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ];
            if ($inlineKeyboard) {
                $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
            }
            tgRequest('sendPhoto', $params);
        } else {
            $params = [
                'chat_id'    => $chatId,
                'text'       => $caption,
                'parse_mode' => 'HTML',
            ];
            if ($inlineKeyboard) {
                $params['reply_markup'] = json_encode($inlineKeyboard, JSON_UNESCAPED_UNICODE);
            }
            tgRequest('sendMessage', $params);
        }
    }
}


// ==========================
//  ПАСПОРТ И ПОДАРКИ (API gifts_passport.php)
// ==========================

function getGiftsAndPassportByPhone($phone) {
    if (!$phone) return ['error' => 'Телефон не указан'];

    $data = callPandoroomOrgApi('gifts.php', ['phone' => $phone]);
    if ($data === null) {
        return ['error' => 'Не удалось связаться с pandoroom.org (gifts)'];
    }

    if (isset($data['error'])) {
        return ['error' => $data['error']];
    }

    return $data;
}

function sendUserPassport($chatId, $phone) {
    $info = getGiftsAndPassportByPhone($phone);

    if (isset($info['error'])) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '❗ ' . $info['error'],
        ]);
        return;
    }

    $level        = $info['passport_level']     ?? 0;
    $questsDone   = $info['quests_done']        ?? 0;
    $nextLevelReq = $info['next_level_quests']  ?? null;
    $nextGiftName = $info['next_gift_name']     ?? null;

    $text = "🛂 ПАСПОРТ ИГРОКА\n\n";
    $text .= "Пройдено квестов: <b>{$questsDone}</b>\n";
    $text .= "Текущий уровень: <b>{$level}</b>\n";

    if ($nextLevelReq !== null) {
        $text .= "\nДо следующего подарка — уровень <b>{$nextLevelReq}</b>";
        if ($nextGiftName) {
            $text .= " (подарок: <b>{$nextGiftName}</b>)";
        }
        $text .= ".";
    }

    tgRequest('sendMessage', [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
}

function sendUserGifts($chatId, $phone) {
    $info = getGiftsAndPassportByPhone($phone);

    if (isset($info['error'])) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => '❗ ' . $info['error'],
        ]);
        return;
    }

    $gifts = $info['gifts'] ?? [];
    if (!$gifts) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => "🎁 У вас пока нет активных подарков.\nНо вы уже на пути к ним!",
        ]);
        return;
    }

    tgRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => '🎁 Ваши подарки:',
    ]);

    foreach ($gifts as $gift) {
        $title       = $gift['title']       ?? 'Подарок';
        $description = $gift['description'] ?? '';
        $image       = $gift['image']       ?? '';
        $expires     = $gift['expires']     ?? '';
        $action      = $gift['action']      ?? '';

        $text = "🎁 <b>{$title}</b>\n";
        if ($description) $text .= "{$description}\n";
        if ($expires)     $text .= "⏰ Действует до: {$expires}\n";

        if ($image) {
            tgRequest('sendPhoto', [
                'chat_id'    => $chatId,
                'photo'      => $image,
                'caption'    => $text,
                'parse_mode' => 'HTML',
            ]);
        } else {
            tgRequest('sendMessage', [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        }

        if ($action) {
            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => "👉 {$action}",
            ]);
        }
    }
}


// ==========================
//  ЖИВОЙ ЧАТ С МЕНЕДЖЕРАМИ (FORUM TOPICS)
// ==========================

/**
 * Вспомогательная: прокинуть конкретное сообщение клиента в тред
 * (текст / фото / документ) + записать связку сообщений.
 */
function support_forward_user_message($userId, $userMessageId, $threadId, $message, $textForHeader) {
    $hasPhoto    = !empty($message['photo']);
    $hasDocument = !empty($message['document']);
    $caption     = trim($message['caption'] ?? '');
    $replyToAdminId = null;

    // reply клиента → reply в треде
    if (!empty($message['reply_to_message']['message_id'])) {
        $replyToAdminId = mapReplyToAdmin($userId, (int)$message['reply_to_message']['message_id']);
    }

    // Кнопка "загрузить сообщения"
    $historyKeyboard = [
        'inline_keyboard' => [
            [
                [
                    'text'          => '📥 Загрузить сообщения',
                    'callback_data' => 'load_history:' . $userId,
                ],
            ],
        ],
    ];

    if ($hasPhoto) {
        $photo = end($message['photo']);
        $fileId = $photo['file_id'];
        $cap = "💬 Сообщение от клиента:\n" . ($caption !== '' ? $caption : '[без подписи]');

        $params = [
            'chat_id'           => SUPPORT_CHAT_ID,
            'message_thread_id' => $threadId,
            'photo'             => $fileId,
            'caption'           => $cap,
            'parse_mode'        => 'HTML',
            'reply_markup'      => json_encode($historyKeyboard, JSON_UNESCAPED_UNICODE),
        ];
        if ($replyToAdminId) {
            $params['reply_to_message_id'] = $replyToAdminId;
            $params['allow_sending_without_reply'] = true;
        }

        $respJson = tgRequest('sendPhoto', $params);
        $resp = $respJson ? json_decode($respJson, true) : null;
        if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
            $adminMsgId = (int)$resp['result']['message_id'];
            message_links_add($userId, $userMessageId, $adminMsgId, 'user_to_admin');
        }
        return;
    }

    if ($hasDocument) {
        $doc = $message['document'];
        $fileId = $doc['file_id'];
        $mime   = $doc['mime_type'] ?? '';
        $label  = stripos($mime, 'pdf') !== false ? 'PDF-файл' : 'документ';
        $cap = "💬 {$label} от клиента:\n" . ($caption !== '' ? $caption : '[без подписи]');

        $params = [
            'chat_id'           => SUPPORT_CHAT_ID,
            'message_thread_id' => $threadId,
            'document'          => $fileId,
            'caption'           => $cap,
            'parse_mode'        => 'HTML',
            'reply_markup'      => json_encode($historyKeyboard, JSON_UNESCAPED_UNICODE),
        ];
        if ($replyToAdminId) {
            $params['reply_to_message_id'] = $replyToAdminId;
            $params['allow_sending_without_reply'] = true;
        }

        $respJson = tgRequest('sendDocument', $params);
        $resp = $respJson ? json_decode($respJson, true) : null;
        if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
            $adminMsgId = (int)$resp['result']['message_id'];
            message_links_add($userId, $userMessageId, $adminMsgId, 'user_to_admin');
        }
        return;
    }

    // Текст / всё остальное как текст
    $supportText = "<b>Сообщение от клиента:</b>\n" . $textForHeader;

    $params = [
        'chat_id'           => SUPPORT_CHAT_ID,
        'message_thread_id' => $threadId,
        'text'              => $supportText,
        'parse_mode'        => 'HTML',
        'reply_markup'      => json_encode($historyKeyboard, JSON_UNESCAPED_UNICODE),
    ];
    if ($replyToAdminId) {
        $params['reply_to_message_id'] = $replyToAdminId;
        $params['allow_sending_without_reply'] = true;
    }

    $respJson = tgRequest('sendMessage', $params);
    $resp = $respJson ? json_decode($respJson, true) : null;
    if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
        $adminMsgId = (int)$resp['result']['message_id'];
        message_links_add($userId, $userMessageId, $adminMsgId, 'user_to_admin');
    }
}

/**
 * Пользователь пишет в ЛС боту — передаём сообщение (текст/фото/док) в форумную тему в группе.
 * Если темы нет, создаём через createForumTopic.
 * Автоответ клиенту шлётся только:
 *   - при создании или повторном открытии диалога
 *   - или если прошло > 2 часов с прошлого автоответа.
 */
function handleUserSupportMessage($message) {
    $chat     = $message['chat'] ?? [];
    $from     = $message['from'] ?? [];
    $chatType = $chat['type'] ?? 'private';

    // работаем только с личными сообщениями клиента
    if ($chatType !== 'private') {
        return;
    }

    $userId         = $chat['id'];
    $userMessageId  = $message['message_id'] ?? 0;

    $hasPhoto    = !empty($message['photo']);
    $hasDocument = !empty($message['document']);
    $rawText     = $message['text'] ?? '';
    $rawText     = is_string($rawText) ? trim($rawText) : '';
    $caption     = trim($message['caption'] ?? '');

    // Человекочитаемый текст для "первого сообщения" и для текстового варианта
    if ($rawText !== '') {
        $textForHeader = $rawText;
    } elseif ($hasPhoto) {
        $textForHeader = $caption !== '' ? $caption : '[фото]';
    } elseif ($hasDocument) {
        $mime = $message['document']['mime_type'] ?? '';
        if (stripos($mime, 'pdf') !== false) {
            $textForHeader = $caption !== '' ? $caption : '[PDF файл]';
        } else {
            $textForHeader = $caption !== '' ? $caption : '[документ]';
        }
    } elseif (!empty($message['sticker'])) {
        $textForHeader = '[стикер]';
    } elseif (!empty($message['voice'])) {
        $textForHeader = '[голосовое сообщение]';
    } else {
        $textForHeader = '[сообщение без текста]';
    }

    $firstName = $from['first_name'] ?? '';
    $lastName  = $from['last_name'] ?? '';
    $name      = trim($firstName . ' ' . $lastName);
    if ($name === '') {
        $name = 'Без имени';
    }

    $username = !empty($from['username']) ? '@' . $from['username'] : '(нет username)';
    $phone    = getUserPhone($userId) ?: '(неизвестен)';

    $now    = time();
    $thread = support_get_thread_by_user($userId);

    $needCreateTopic   = false;
    $needNotifyUser    = false;
    $needShowKeyboard  = false;
    $threadId          = null;

    if (!$thread || empty($thread['thread_id'])) {
        // Новый клиент / нет записи
        $needCreateTopic  = true;
        $needNotifyUser   = true;
        $needShowKeyboard = true;
    } else {
        $threadId    = (int)$thread['thread_id'];
        $status      = $thread['status'] ?? 'open';
        $lastNotify  = (int)($thread['last_notify_at'] ?? 0);

        if ($status === 'closed') {
            // Диалог был закрыт — открываем снова
            $needNotifyUser   = true;
            $needShowKeyboard = true;

            tgRequest('sendMessage', [
                'chat_id'           => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text'              => "Клиент снова написал, диалог переоткрыт 🔁",
            ]);

            support_update_thread($userId, ['status' => 'open']);
        } elseif ($lastNotify === 0 || ($now - $lastNotify) >= 2 * 3600) {
            // Прошло более 2 часов — снова уведомляем клиента и показываем кнопки
            $needNotifyUser   = true;
            $needShowKeyboard = true;
        }
    }

    // Кнопки для менеджера (отдельно, на "Новый диалог")
    $managerKeyboard = [
        'inline_keyboard' => [
            [
                [
                    'text'          => '🎭 Квесты клиента',
                    'callback_data' => 'client_quests:' . $userId,
                ],
                [
                    'text'          => '🎉 Мероприятия',
                    'callback_data' => 'client_event:' . $userId,
                ],
            ],
            [
                [
                    'text'          => '💰 Бонусы',
                    'callback_data' => 'client_bonus:' . $userId,
                ],
                [
                    'text'          => '✅ Закрыть диалог',
                    'callback_data' => 'client_close:' . $userId,
                ],
            ],
        ],
    ];

    // вспомогательная функция: создать новый топик и кинуть туда «Новый диалог»
    $createNewTopic = function() use (
        $userId,
        $name,
        $username,
        $phone,
        $textForHeader,
        $managerKeyboard,
        &$threadId
    ) {
        // проверяем, есть ли предстоящий праздник — если да, добавим 🎂
        $hasEvent = false;
        if ($phone && $phone !== '(неизвестен)') {
            $event = getEventInfoByPhone($phone);
            if ($event && !empty($event['date'])) {
                $hasEvent = true;
            }
        }

        $topicName = $name;
        if ($phone && $phone !== '(неизвестен)') {
            $topicName .= ' (' . $phone . ')';
        }
        if ($hasEvent) {
            $topicName = '🎂 ' . $topicName;
        }

        $respJson = tgRequest('createForumTopic', [
            'chat_id' => SUPPORT_CHAT_ID,
            'name'    => mb_substr($topicName, 0, 128, 'UTF-8'),
        ]);
        $resp = $respJson ? json_decode($respJson, true) : null;

        if (empty($resp['ok']) || empty($resp['result']['message_thread_id'])) {
            logMsg('SUPPORT: createForumTopic failed: ' . $respJson);
            tgSendMessage($userId, "Не получилось открыть диалог с менеджером, попробуйте позже 🙏");
            return false;
        }

        $threadId = (int)$resp['result']['message_thread_id'];

        $supportText = "🆕 <b>Новый диалог с клиентом</b>\n"
            . "Имя: {$name}\n"
            . "Username: {$username}\n"
            . "ID: <code>{$userId}</code>\n"
            . "Телефон: {$phone}\n\n"
            . "<b>Первое сообщение:</b>\n{$textForHeader}";

        tgRequest('sendMessage', [
            'chat_id'           => SUPPORT_CHAT_ID,
            'message_thread_id' => $threadId,
            'text'              => $supportText,
            'parse_mode'        => 'HTML',
            'reply_markup'      => json_encode($managerKeyboard, JSON_UNESCAPED_UNICODE),
        ]);

        support_register_thread($userId, $threadId);
        return true;
    };

    // 1) Если уже есть тред, пробуем использовать его
    if (!$needCreateTopic && $threadId) {
        // просто прокидываем сообщение клиента
        support_forward_user_message($userId, $userMessageId, $threadId, $message, $textForHeader);
    }

    // 2) Если надо — создаём новый топик (в т.ч. после удаления старого)
    if ($needCreateTopic || !$threadId) {
        $ok = $createNewTopic();
        if (!$ok) {
            return;
        }
        // После создания треда ещё раз прокидываем текущее сообщение отдельно,
        // чтобы оно было как самостоятельное сообщение с кнопкой "Загрузить сообщения".
        support_forward_user_message($userId, $userMessageId, $threadId, $message, $textForHeader);
    }

    // Обновляем мету по треду
    support_update_thread($userId, [
        'last_user_msg_at' => $now,
        'status'           => 'open',
    ]);

    // Автоответ клиенту — только если нужно
    if ($needNotifyUser) {
        tgSendMessage($userId, "Ваше сообщение передано менеджеру. Он ответит здесь в чате 🙂");
        support_update_thread($userId, [
            'last_notify_at' => $now,
        ]);
    }
}


/**
 * Сообщение в группе поддержки — если это сообщение в теме форума,
 * отправляем его пользователю по связке message_thread_id → user_id.
 * Поддерживаются текст, фото, документы + reply в обе стороны.
 */
function handleManagerMessage($message) {
    // Работаем только в служебной группе поддержки
    $chat = $message['chat'] ?? [];
    if ((int)($chat['id'] ?? 0) !== (int)SUPPORT_CHAT_ID) {
        return;
    }

    $threadId = $message['message_thread_id'] ?? null;
    if (!$threadId) {
        // Не сообщение в форуме (топике) — игнорируем
        return;
    }

    $userId = support_find_user_by_thread($threadId);
    if (!$userId) {
        return;
    }

    $from        = $message['from'] ?? [];
    $managerName = trim(
        ($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')
    );
    if ($managerName === '') {
        $managerName = 'Менеджер';
    }

    $rawText = $message['text'] ?? '';
    $rawText = is_string($rawText) ? trim($rawText) : '';

    $hasPhoto    = !empty($message['photo']);
    $hasDocument = !empty($message['document']);
    $caption     = trim($message['caption'] ?? '');

    // Учитываем возможные пробелы перед !!
    $trimmed = ltrim((string)$rawText);

    // 🔹 СЛУЖЕБНЫЕ КОММЕНТАРИИ (только текстовые)
    if ($rawText !== '' && mb_strpos($trimmed, '!!') === 0) {
        // убираем "!!"
        $internal = trim(mb_substr($trimmed, 2));
        if ($internal === '') {
            $internal = '(служебный комментарий)';
        }

        // ЯРКОЕ ОФОРМЛЕНИЕ СЛУЖЕБНОГО КОММЕНТАРИЯ
        $formatted = "━━━━━━━━━━━━━━\n"
            . "⚙️ <b>СЛУЖЕБНЫЙ КОММЕНТАРИЙ</b>\n"
            . "👤 {$managerName}\n"
            . "━━━━━━━━━━━━━━\n"
            . $internal;

        // Пытаемся удалить исходное сообщение менеджера
        tgRequest('deleteMessage', [
            'chat_id'    => SUPPORT_CHAT_ID,
            'message_id' => $message['message_id'],
        ]);

        // Отправляем уже красивый служебный коммент от бота
        tgRequest('sendMessage', [
            'chat_id'           => SUPPORT_CHAT_ID,
            'message_thread_id' => $threadId,
            'text'              => $formatted,
            'parse_mode'        => 'HTML',
        ]);

        // На этом всё — клиенту ничего не отправляем
        return;
    }

    // Определяем, есть ли вообще что отправлять клиенту
    if (!$hasPhoto && !$hasDocument && $rawText === '' && $caption === '') {
        return;
    }

    // Reply менеджера к сообщению в тикете
    $replyToUserMsgId = null;
    if (!empty($message['reply_to_message']['message_id'])) {
        $replyToUserMsgId = mapReplyToUser($userId, (int)$message['reply_to_message']['message_id']);
    }

    // Отправляем клиенту в зависимости от типа
    $adminMessageId = (int)($message['message_id'] ?? 0);

    if ($hasPhoto) {
        $photo = end($message['photo']);
        $fileId = $photo['file_id'];

        $cap = $caption !== '' ? $caption : '';
        $cap = "💬 <b>{$managerName}:</b>\n" . ($cap !== '' ? $cap : '[фото]');

        $params = [
            'chat_id'    => $userId,
            'photo'      => $fileId,
            'caption'    => $cap,
            'parse_mode' => 'HTML',
        ];
        if ($replyToUserMsgId) {
            $params['reply_to_message_id'] = $replyToUserMsgId;
            $params['allow_sending_without_reply'] = true;
        }

        $respJson = tgRequest('sendPhoto', $params);
        $resp = $respJson ? json_decode($respJson, true) : null;
        if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
            $userMsgId = (int)$resp['result']['message_id'];
            // лог связки и лог исходящего сообщения (тип chat)
            message_links_add($userId, $userMsgId, $adminMessageId, 'admin_to_user');
            log_bot_message($userId, $userMsgId, $cap, 'chat');
        }

        return;
    }

    if ($hasDocument) {
        $doc = $message['document'];
        $fileId = $doc['file_id'];
        $mime   = $doc['mime_type'] ?? '';
        $label  = stripos($mime, 'pdf') !== false ? 'PDF-файл' : 'документ';

        $cap = $caption !== '' ? $caption : '';
        $cap = "💬 <b>{$managerName}:</b>\n" . ($cap !== '' ? $cap : "[{$label}]");

        $params = [
            'chat_id'    => $userId,
            'document'   => $fileId,
            'caption'    => $cap,
            'parse_mode' => 'HTML',
        ];
        if ($replyToUserMsgId) {
            $params['reply_to_message_id'] = $replyToUserMsgId;
            $params['allow_sending_without_reply'] = true;
        }

        $respJson = tgRequest('sendDocument', $params);
        $resp = $respJson ? json_decode($respJson, true) : null;
        if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
            $userMsgId = (int)$resp['result']['message_id'];
            message_links_add($userId, $userMsgId, $adminMessageId, 'admin_to_user');
            log_bot_message($userId, $userMsgId, $cap, 'chat');
        }

        return;
    }

    // Обычный текст
    $textForClient = "💬 <b>{$managerName}:</b>\n" . $rawText;
    $resp = tgSendMessage($userId, $textForClient, $replyToUserMsgId, 'chat');
    if (is_array($resp) && !empty($resp['ok']) && !empty($resp['result']['message_id'])) {
        $userMsgId = (int)$resp['result']['message_id'];
        message_links_add($userId, $userMsgId, $adminMessageId, 'admin_to_user');
    }
}



/**
 * Обработка нажатий на inline-кнопки в теме поддержки
 */
function handleCallbackQuery($callback) {
    $data    = $callback['data']    ?? '';
    $message = $callback['message'] ?? null;

    if (!$message || !$data) {
        return;
    }

    $chat   = $message['chat'] ?? [];
    $chatId = (int)($chat['id'] ?? 0);

    // ====== ВЕТКА 1. CALLBACK ИЗ ЛИЧКИ С ПОЛЬЗОВАТЕЛЕМ ======
    if ($chatId > 0) {
        // единственное, что нам нужно сейчас — party_edit
        if ($data === 'party_edit') {
            $userId = $callback['from']['id'] ?? null;

            if ($userId) {
                // Сообщение в техподдержку через общий механизм
                if (function_exists('handleUserSupportMessage')) {
                    $fakeMessage = [
                        'chat' => [
                            'id'   => $userId,
                            'type' => 'private',
                        ],
                        'from' => $callback['from'], // тут есть first_name/last_name/username
                        'text' => 'Клиент нажал кнопку «Хочу внести изменения по празднику»',
                    ];
                    handleUserSupportMessage($fakeMessage);
                }

                // Ответ на сам callback (убираем "часики")
                if (!empty($callback['id'])) {
                    tgRequest('answerCallbackQuery', [
                        'callback_query_id' => $callback['id'],
                        'text'              => 'Ваш запрос передан менеджеру 💬',
                        'show_alert'        => false,
                    ]);
                }

                // Сообщение клиенту
                tgRequest('sendMessage', [
                    'chat_id' => $userId,
                    'text'    => 'Мы передали менеджеру, что вы хотите внести изменения в праздник. ' .
                                 'Напишите, пожалуйста, в этом чате, что именно нужно скорректировать 🙂',
                ]);
            }

            return;
        }

        // другие callback’и из лички пока не используем
        return;
    }

    // ====== ВЕТКА 2. CALLBACK ИЗ ГРУППЫ ПОДДЕРЖКИ (как было раньше) ======
    if ($chatId !== (int)SUPPORT_CHAT_ID) {
        return;
    }

    $threadId = $message['message_thread_id'] ?? null;
    if (!$threadId) {
        return;
    }

    // Ответ Telegram'у, чтобы убрать "часики" на кнопке
    if (!empty($callback['id'])) {
        tgRequest('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text'              => '',
            'show_alert'        => false,
        ]);
    }

    $parts  = explode(':', $data, 2);
    $action = $parts[0] ?? '';
    $userId = isset($parts[1]) ? (int)$parts[1] : 0;

    // Если в callback не передали user_id (или он не распарсился),
    // пробуем достать его по message_thread_id. Иначе кнопки в треде
    // менеджеров будут «немыми».
    if (!$userId && $threadId) {
        $mappedUserId = support_find_user_by_thread($threadId);
        if ($mappedUserId) {
            $userId = (int)$mappedUserId;
        }
    }

    if (!$userId) {
        return;
    }

    switch ($action) {
        case 'client_close':
            support_update_thread($userId, ['status' => 'closed']);
            tgRequest('sendMessage', [
                'chat_id'           => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text'              => "Диалог с клиентом закрыт ✅",
            ]);
            break;

        case 'client_quests':
            $phone = getUserPhone($userId);
            if (!$phone) {
                $text = "Телефон клиента не привязан. Попросите его отправить контакт через бота.";
            } else {
                $quests = getQuestsDetailedByPhone($phone);
                if (isset($quests['error'])) {
                    $text = "Квесты клиента: " . $quests['error'];
                } elseif (!$quests) {
                    $text = "Квестов по этому номеру не найдено.";
                } else {
                    $lines = ["Квесты клиента (макс. 5):"];
                    $count = 0;
                    foreach ($quests as $q) {
                        $title  = $q['title'] ?? 'Квест';
                        $time   = $q['time'] ?? ($q['datetime'] ?? ($q['book_time'] ?? ''));
                        $status = $q['status'] ?? '';

                        $line = "• {$title}";
                        if ($time)   $line .= " — {$time}";
                        if ($status) $line .= " ({$status})";

                        $lines[] = $line;
                        $count++;
                        if ($count >= 5) break;
                    }
                    $text = implode("\n", $lines);
                }
            }

            tgRequest('sendMessage', [
                'chat_id'           => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text'              => $text,
            ]);
            break;

        case 'client_event':
            $phone = getUserPhone($userId);
            if (!$phone) {
                $text = "Телефон клиента не привязан. Попросите его отправить контакт через бота.";
            } else {
                $event = getEventInfoByPhone($phone);
                if (!$event) {
                    $text = "Ближайший праздник клиента не найден.";
                } else {
                    $text =
                        "🎉 Праздник клиента:\n\n" .
                        "Дата: {$event['date']}\n" .
                        "Время: {$event['time_from']}–{$event['time_to']}\n" .
                        "Зал / зона: {$event['hall']}\n" .
                        "Гостей: {$event['guests']}\n" .
                        "Именинник: {$event['imen']} ({$event['age']} лет)";
                }
            }

            tgRequest('sendMessage', [
                'chat_id'           => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text'              => $text,
            ]);
            break;

        case 'client_bonus':
            $phone = getUserPhone($userId);
            if (!$phone) {
                $text = "Телефон клиента не привязан. Попросите его отправить контакт через бота.";
            } else {
                $bonus = getBonusInfoByPhone($phone);
                if (!$bonus) {
                    $text = "Не удалось получить информацию о бонусах клиента.";
                } else {
                    $text = "💰 Бонусы клиента: {$bonus['balance']} руб.";
                    if (!empty($bonus['expires'])) {
                        $text .= "\nДействительны до: {$bonus['expires']}";
                    }
                }
            }

            tgRequest('sendMessage', [
                'chat_id'           => SUPPORT_CHAT_ID,
                'message_thread_id' => $threadId,
                'text'              => $text,
            ]);
            break;

        case 'load_history':
            // Загрузка последних 3 исходящих системных сообщений бота клиенту
            $history = get_last_bot_system_messages($userId, 3);

            if (!$history) {
                tgRequest('answerCallbackQuery', [
                    'callback_query_id' => $callback['id'],
                    'text'              => 'Ранее отправленных сообщений бота нет.',
                    'show_alert'        => false,
                ]);
                return;
            }

            tgRequest('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text'              => 'Загружаю последние сообщения бота клиенту…',
                'show_alert'        => false,
            ]);

            foreach ($history as $row) {
                $txt = (string)($row['text'] ?? '');
                if ($txt === '') {
                    $txt = '[пустое сообщение]';
                }
                $out = "🕓 Ранее бот отправлял клиенту:\n" . $txt;

                tgRequest('sendMessage', [
                    'chat_id'                => SUPPORT_CHAT_ID,
                    'message_thread_id'      => $threadId,
                    'text'                   => $out,
                    'parse_mode'             => 'HTML',
                    'reply_to_message_id'    => $message['message_id'],
                    'allow_sending_without_reply' => true,
                ]);
            }

            break;
    }
}


// ==========================
//  ОБРАБОТКА РЕАКЦИЙ (message_reaction)
// ==========================

function handleMessageReaction($mr) {
    $chat = $mr['chat'] ?? [];
    $chatId = $chat['id'] ?? null;
    $messageId = $mr['message_id'] ?? null;

    if ($chatId === null || $messageId === null) {
        return;
    }

    // игнорируем реакции ботов, чтобы не зациклиться
    $user = $mr['user'] ?? null;
    if (is_array($user) && !empty($user['is_bot'])) {
        return;
    }

    $newReactions = $mr['new_reaction'] ?? [];
    if (!is_array($newReactions) || !$newReactions) {
        return;
    }

    // Массив эмодзи
    $reactionPayload = [];
    foreach ($newReactions as $r) {
        if (isset($r['emoji'])) {
            $reactionPayload[] = [
                'type'  => 'emoji',
                'emoji' => $r['emoji'],
            ];
        }
    }

    // Если нечего ставить — выходим
    if (!$reactionPayload) {
        return;
    }

    // 🔹 1. Ставим ТУ ЖЕ реакцию ботом на ЭТО сообщение
    tgRequest('setMessageReaction', [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'reaction'   => json_encode($reactionPayload, JSON_UNESCAPED_UNICODE),
        'is_big'     => false,
    ]);

    // 🔹 2. Синхронизация реакции (без текстовых уведомлений!)

    // реакция менеджера → клиенту
    if ((int)$chatId === (int)SUPPORT_CHAT_ID) {
        $link = message_links_find_by_admin_message($messageId);
        if (!$link) return;

        $userId        = (int)$link['user_id'];
        $userMessageId = (int)$link['user_message_id'];

        // ставим реакцию на клиентское сообщение
        tgRequest('setMessageReaction', [
            'chat_id'    => $userId,
            'message_id' => $userMessageId,
            'reaction'   => json_encode($reactionPayload, JSON_UNESCAPED_UNICODE),
            'is_big'     => false,
        ]);

        return;
    }

    // реакция клиента → в тикет админу
    if ($chatId > 0) {
        $userId = $chatId;

        $link = message_links_find_by_user_message($userId, $messageId);
        if (!$link) return;

        $adminMessageId = (int)$link['admin_message_id'];

        $thread = support_get_thread_by_user($userId);
        $threadId = $thread['thread_id'] ?? null;
        if (!$threadId) return;

        // ставим реакцию на сообщение менеджера
        tgRequest('setMessageReaction', [
            'chat_id'           => SUPPORT_CHAT_ID,
            'message_id'        => $adminMessageId,
            'reaction'          => json_encode($reactionPayload, JSON_UNESCAPED_UNICODE),
            'is_big'            => false,
        ]);
    }
}




// ==========================
//  ЧИТАЕМ UPDATE ОТ TELEGRAM
// ==========================

$rawInput = file_get_contents('php://input');
$update   = json_decode($rawInput, true);
logMsg('UPDATE: ' . $rawInput);

if (!$update) {
    exit('OK');
}

// Обработка реакций
if (isset($update['message_reaction'])) {
    handleMessageReaction($update['message_reaction']);
    exit('OK');
}

// Сначала обрабатываем callback-кнопки из чата поддержки
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    exit('OK');
}


// ==========================
//  ДАННЫЕ ИЗ MINI APP (web_app_data)
// ==========================

if (isset($update['message']['web_app_data'])) {
    $chatId = $update['message']['chat']['id'];
    $data   = json_decode($update['message']['web_app_data']['data'], true);

    if (!$data) {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => 'Не удалось обработать данные из личного кабинета 😕',
        ]);
        exit('OK');
    }

    $action = $data['action'] ?? null;

    switch ($action) {
		
		
		
     case 'want_quest':
    // Дополнительные данные из mini-app (могут быть, а могут и нет)
    $payload = $data['data'] ?? [];

    $phone = getUserPhone($chatId);
    $questTitle   = $payload['quest_title']   ?? '';
    $questTime    = $payload['quest_time']    ?? '';
    $questAddress = $payload['quest_address'] ?? '';
    $kids         = $payload['kids']          ?? '';
    $adults       = $payload['adults']        ?? '';
    $age          = $payload['age']           ?? '';
    $comment      = $payload['comment']       ?? '';

    // ✉️ Текст для менеджеров
    $txt = "❗ Запрос на квест из личного кабинета\n\n";
    if ($phone) {
        $txt .= "Телефон: {$phone}\n";
    }
    $txt .= "ID клиента в Telegram: {$chatId}\n\n";

    if ($questTitle)   $txt .= "Квест: {$questTitle}\n";
    if ($questTime)    $txt .= "Время: {$questTime}\n";
    if ($questAddress) $txt .= "Адрес: {$questAddress}\n";
    if ($kids)         $txt .= "Детей: {$kids}\n";
    if ($adults)       $txt .= "Взрослых: {$adults}\n";
    if ($age)          $txt .= "Возраст: {$age}\n";
    if ($comment)      $txt .= "Комментарий: {$comment}\n";

    notifyManagers($txt);

    // ✅ Добавляем запись в персональный тред поддержки
    if (function_exists('handleUserSupportMessage')) {
        $fakeMessage = [
            'chat' => [
                'id'   => $chatId,
                'type' => 'private',
            ],
            'from' => $update['message']['from'] ?? [
                'first_name' => 'Клиент',
            ],
            'text' => 'Клиент оставил запрос на квест из личного кабинета',
        ];
        handleUserSupportMessage($fakeMessage);
    }

    // Ответ клиенту
    tgRequest('sendMessage', [
        'chat_id' => $chatId,
        'text'    => 'Запрос на квест отправлен менеджеру, он скоро с вами свяжется 👌',
    ]);

    break;


        case 'add_event':
            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Запрос на добавление мероприятия отправлен! 🎉',
            ]);
            notifyManagers("📅 Пользователь хочет добавить мероприятие\nID: $chatId");
            break;

        case 'open_section':
            $section = $data['section'] ?? 'unknown';

            if ($section === 'quests') {
                $phone = getUserPhone($chatId);
                if (!$phone) {
                    tgRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text'    => 'Чтобы показать ваши квесты, сначала поделитесь номером телефона через бота.',
                    ]);
                } else {
                    sendUserQuests($chatId, $phone);
                }
            } elseif ($section === 'party') {
                $phone = getUserPhone($chatId);
                if ($phone) {
                    $event = getEventInfoByPhone($phone);
                    if ($event) {
                        tgRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text'    =>
                                "🎉 Ближайший праздник:\n\n" .
                                "Дата: {$event['date']}\n" .
                                "Время: {$event['time_from']}–{$event['time_to']}\n" .
                                "Зал / зона: {$event['hall']}\n" .
                                "Гостей: {$event['guests']}\n" .
                                "Именинник: {$event['imen']} ({$event['age']} лет)",
                        ]);
                    } else {
                        tgRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text'    => 'Пока нет забронированных праздников 🎈',
                        ]);
                    }
                } else {
                    tgRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text'    => 'Чтобы показать мероприятия, сначала поделитесь номером телефона через бота.',
                    ]);
                }
            } elseif ($section === 'gifts') {
                $phone = getUserPhone($chatId);
                if (!$phone) {
                    tgRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text'    => 'Чтобы показать подарки, сначала поделитесь номером телефона через бота.',
                    ]);
                } else {
                    sendUserGifts($chatId, $phone);
                }
            }
            break;

        case 'open_booking_master':
            notifyManagers("📅 Пользователь открыл мастер бронирования (mini-app)\nID: $chatId");
            break;

        case 'create_event_booking':
            $payload = $data['data'] ?? [];
            $phone   = getUserPhone($chatId);

            $txt = "🎉 Новая заявка на праздник (из mini-app)\n\n";
            if ($phone) $txt .= "Телефон: {$phone}\n";
            $txt .= "Имя именинника: " . ($payload['child_name'] ?? '—') . "\n";
            $txt .= "Возраст: " . ($payload['child_age'] ?? '—') . "\n";
            $txt .= "Дата: " . ($payload['event_date'] ?? '—') . " " . ($payload['event_time'] ?? '') . "\n";
            $txt .= "Детей: " . ($payload['kids'] ?? '—') . "\n";
            $txt .= "Взрослых: " . ($payload['adults'] ?? '—') . "\n";
            $txt .= "Формат: " . ($payload['format'] ?? '—') . "\n";
            $txt .= "Зал: " . ($payload['hall'] ?? '—') . "\n";
            $txt .= "Торт: " . ($payload['cake'] ?? '—') . "\n";
            $txt .= "Шоу: " . ($payload['show'] ?? '—') . "\n";
            $txt .= "Украшения: " . ($payload['decor'] ?? '—') . "\n";
            $txt .= "Комментарий: " . ($payload['comment'] ?? '—') . "\n";

            notifyManagers($txt);

            // ⬇️ добавляем в тред
            if (function_exists('handleUserSupportMessage')) {
                $fakeMessage = [
                    'chat' => [
                        'id'   => $chatId,
                        'type' => 'private',
                    ],
                    'from' => $update['message']['from'] ?? [
                        'first_name' => 'Клиент',
                    ],
                    'text' => 'Заявка на праздник из личного кабинета',
                ];
                handleUserSupportMessage($fakeMessage);
            }

            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Заявка на праздник отправлена менеджеру, он скоро с вами свяжется 🎉',
            ]);
            break;


                case 'request_photos':
            // Запрос фото с квеста из мини-аппа
            $questTitle   = $data['quest_title']   ?? 'Квест';
            $questTime    = $data['quest_time']    ?? '';
            $questAddress = $data['quest_address'] ?? '';
            $phoneFromApp = $data['phone']         ?? null;
            $bookingId    = $data['booking_id']    ?? null;

            // Если телефон не пришёл из mini-app — попробуем взять привязанный
            $phoneForMsg = $phoneFromApp ?: getUserPhone($chatId);

            $txt = "📸 Запрос на фото с квеста\n\n";
            if ($phoneForMsg) {
                $txt .= "Телефон: {$phoneForMsg}\n";
            }
            $txt .= "Квест: {$questTitle}\n";
            if ($questTime) {
                $txt .= "Время: {$questTime}\n";
            }
            if ($questAddress) {
                $txt .= "Адрес: {$questAddress}\n";
            }
            if ($bookingId) {
                $txt .= "ID брони: {$bookingId}\n";
            }
            $txt .= "ID клиента в Telegram: {$chatId}";

            // Отправим менеджерам (если заполнен массив $managers)
            notifyManagers($txt);

            // БОЛЬШЕ НЕ ШЛЁМ В ОБЩИЙ GENERAL
            // Раньше тут был tgRequest('sendMessage' ...) на SUPPORT_CHAT_ID без thread_id

            // Добавим запись в персональный тред поддержки
            if (function_exists('handleUserSupportMessage')) {
                $fakeMessage = [
                    'chat' => [
                        'id'   => $chatId,
                        'type' => 'private',
                    ],
                    'from' => $update['message']['from'] ?? [
                        'first_name' => 'Клиент',
                    ],
                    'text' => 'Клиент запросил фото с квеста: ' . $questTitle . ($questTime ? (' (' . $questTime . ')') : ''),
                ];
                handleUserSupportMessage($fakeMessage);
            }

            // Ответ клиенту в боте
            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Запрос на фото отправлен менеджеру. Как только фото будут готовы, мы пришлём их сюда 📸',
            ]);

            break;






       default:
    $type = $data['type'] ?? '';

    // Поддерживаем и старый формат, и новый
    if ($type === 'event_booking' || $type === 'event_booking_created') {
        $payload = $data['data'] ?? [];
        $phone   = getUserPhone($chatId);

        $txt = "🎉 Новое комплексное бронирование (мастер в mini-app)\n\n";
        if ($phone) $txt .= "Телефон: {$phone}\n";
        $txt .= "ID клиента в Telegram: {$chatId}\n\n";

        $txt .= "Имя именинника: " . ($payload['name'] ?? '—') . "\n";
        $txt .= "Возраст: " . ($payload['age'] ?? '—') . "\n";
        $txt .= "Дата: " . ($payload['date'] ?? '—') . " " . ($payload['time'] ?? '') . "\n";
        $txt .= "Детей: " . ($payload['kids'] ?? '—') . "\n";
        $txt .= "Взрослых: " . ($payload['adults'] ?? '—') . "\n";
        $txt .= "Зал: " . ($payload['hall'] ?? '—') . "\n";

        if (!empty($payload['table'])) {
            $t = $payload['table'];
            $txt .= "\nСтол:\n";
            $txt .= "  ID: " . ($t['id'] ?? '—') . "\n";
            $txt .= "  Зал: " . ($t['zal'] ?? '—') . "\n";
            $txt .= "  Стол: " . ($t['stol'] ?? '—') . "\n";
            $txt .= "  Метка: " . ($t['label'] ?? '—') . "\n";
            $txt .= "  Время слота: " . ($t['vm'] ?? '—') . "\n";
        }

        if (!empty($payload['quest'])) {
            $q = $payload['quest'];
            $txt .= "\nКвест:\n";
            $txt .= "  ID: " . ($q['id'] ?? '—') . "\n";
            $txt .= "  Название: " . ($q['title'] ?? '—') . "\n";
            $txt .= "  Время: " . ($q['time'] ?? '—') . "\n";
            $txt .= "  Цена: " . ($q['price'] ?? '—') . "\n";
            $txt .= "  Длительность: " . ($q['duration'] ?? '—') . "\n";
        }

        if (!empty($payload['menuItems']) && is_array($payload['menuItems'])) {
            $txt .= "\nМеню:\n";
            foreach ($payload['menuItems'] as $item) {
                $txt .= "- " . ($item['type'] ?? 'type') . ": " .
                    ($item['name'] ?? '—') .
                    " × " . ($item['qty'] ?? 0) .
                    " (цена: " . ($item['price'] ?? 0) . ")\n";
            }
        }

        notifyManagers($txt);

        // 📌 Пишем в тред клиента
        if (function_exists('handleUserSupportMessage')) {
            $fakeMessage = [
                'chat' => [
                    'id'   => $chatId,
                    'type' => 'private',
                ],
                'from' => $update['message']['from'] ?? [
                    'first_name' => 'Клиент',
                ],
                'text' => 'Клиент оформил комплексное бронирование через мастер в личном кабинете',
            ];
            handleUserSupportMessage($fakeMessage);
        }

        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => 'Заявка на мероприятие сформирована и отправлена менеджеру 🙌',
        ]);
    }

    break;

    }

    exit('OK');
}


// ==========================
//  ОБЫЧНЫЕ СООБЩЕНИЯ
// ==========================

if (isset($update['message'])) {
    $message = $update['message'];
    $chat    = $message['chat'] ?? [];
    $chatId  = $chat['id'] ?? null;
    $chatType= $chat['type'] ?? 'private';
    $text    = trim($message['text'] ?? '');

    // Сообщение пришло в группу поддержки — это ответ менеджера
    if (($chatType === 'group' || $chatType === 'supergroup') && (int)$chatId === (int)SUPPORT_CHAT_ID) {
        handleManagerMessage($message);
        exit('OK');
    }

    // Ниже логика только для лички с пользователем

    // Клавиатура запроса телефона
    $phoneKeyboard = [
        'keyboard' => [
            [
                [
                    'text'            => 'Отправить номер телефона',
                    'request_contact' => true,
                ],
            ],
        ],
        'resize_keyboard'   => true,
        'one_time_keyboard' => true,
    ];

// Главное меню — личный кабинет + сервисные кнопки
$mainMenu = [
    'keyboard' => [
        ['Личный кабинет'],
        ['Мои квесты', 'Мой праздник'],
        ['Мои бонусы', 'Мои подарки'],
        ['Паспорт игрока', 'Позвать менеджера'],
    ],
    'resize_keyboard' => true,
];



    // 1) Пришёл контакт
   // 1) Пришёл контакт
if (isset($message['contact'])) {
    $phone = $message['contact']['phone_number'];
    $old   = getUserPhone($chatId);

    saveUserPhone($chatId, $phone);
    bindChatToUser($phone, $chatId);


    // Если телефона раньше не было — считаем этого клиента новым
    if (!$old) {
        $from      = $message['from'] ?? [];
        $firstName = $from['first_name'] ?? '';
        $lastName  = $from['last_name'] ?? '';
        $name      = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = 'Без имени';
        }

        $username = !empty($from['username']) ? '@' . $from['username'] : '(нет username)';

        // Сообщение в CRM-группу в раздел general (без message_thread_id)
        $crmText =
            "👤 <b>Новый клиент в боте</b>\n\n" .
            "Имя: {$name}\n" .
            "Username: {$username}\n" .
            "Телефон: {$phone}\n" .
            "ID: <code>{$chatId}</code>";

        tgRequest('sendMessage', [
            'chat_id'    => SUPPORT_CHAT_ID,
            'text'       => $crmText,
            'parse_mode' => 'HTML',
        ]);
    }



    // Имя для мини-аппа
    $from       = $message['from'] ?? [];
    $firstName  = $from['first_name'] ?? '';
    $lastName   = $from['last_name'] ?? '';
    $displayName = trim($firstName . ' ' . $lastName);

    $bonusInfo = getBonusInfoByPhone($phone);
    $eventInfo = getEventInfoByPhone($phone);

    // Аккуратно добавляем параметры к $miniAppUrl (там уже есть ?v=...)
    $url = $miniAppUrl;
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . 'phone=' . urlencode($phone);

    if ($displayName !== '') {
        $url .= '&name=' . urlencode($displayName);
    }

    if ($bonusInfo) {
        $url .= '&bonus=' . urlencode($bonusInfo['balance']);
        if (!empty($bonusInfo['expires'])) {
            $url .= '&bonus_exp=' . urlencode($bonusInfo['expires']);
        }
    }

    if ($eventInfo) {
        $url .= '&event_date=' . urlencode($eventInfo['date']);
        $url .= '&event_from=' . urlencode($eventInfo['time_from']);
        $url .= '&event_to='   . urlencode($eventInfo['time_to']);
        $url .= '&event_hall=' . urlencode($eventInfo['hall']);
    }

    // Reply-клавиатура с кнопкой, которая сразу открывает мини-апп
      // Главное меню с кнопкой открытия личного кабинета
    $mainMenuWithCabinet = [
        'keyboard' => [
            [
                [
                    'text'    => 'Открыть личный кабинет',
                    'web_app' => ['url' => $url],
                ],
            ],
            ['Мои квесты', 'Мой праздник'],
            ['Мои бонусы', 'Мои подарки'],
            ['Паспорт игрока', 'Позвать менеджера'],
        ],
        'resize_keyboard'   => true,
        'one_time_keyboard' => false,
    ];



    if ($old && $old === $phone) {
        tgRequest('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => 'Спасибо, что поделились контактом. Вы можете задавать ваши вопросы прямо здесь в чате, мы обязательно вам ответим 🙂. Забронировать праздник, посмотреть квесты и фотографии вы можете в личном кабинете, перейдя по кнопке в меню.',
            'reply_markup' => json_encode($mainMenuWithCabinet, JSON_UNESCAPED_UNICODE),
        ]);
    } else {
        tgRequest('sendMessage', [
            'chat_id' => $chatId,
            'text'    => "Спасибо, что поделились контактом. Вы можете задавать ваши вопросы прямо здесь в чате, мы обязательно вам ответим 🙂. Забронировать праздник, посмотреть квесты и фотографии вы можете в личном кабинете, перейдя по кнопке в меню.",
        ]);

        tgRequest('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => 'Ваше главное меню 👇',
            'reply_markup' => json_encode($mainMenuWithCabinet, JSON_UNESCAPED_UNICODE),
        ]);
    }



    exit('OK');
}


    // 2) /start — просим телефон
  // 2) /start — приветствие и запрос телефона
if (strpos($text, '/start') === 0) {
    $welcome =
        "Команда Pandoroom приветствует Вас! На связи Pandoroom Bot🧡\n\n" .
        "Здесь вы всегда найдете: личный кабинет, скидки и акции, бронирование " .
        "и быстрый чат с нашими администраторами.\n\n" .
        "Для начала поделитесь, пожалуйста, номером телефона, нажав кнопку ниже:";

    tgRequest('sendMessage', [
        'chat_id'      => $chatId,
        'text'         => $welcome,
        'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
    ]);
    exit('OK');
}


    // 3) Вручную введённый номер — объяснение про кнопку
    if ($text !== '' && preg_match('/^\+?\d[\d\-\s\(\)]{5,}$/u', $text)) {
        tgRequest('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => "Номер нужно отправить через кнопку 🙏\nTelegram сам подставит корректный телефон.\nНажмите «Отправить номер телефона» ниже.",
            'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
        ]);
        exit('OK');
    }

    // 4) Обработка пунктов меню
    switch ($text) {
         case 'Личный кабинет':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Чтобы открыть личный кабинет, сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }

            // Имя пользователя для мини-аппа
            $from        = $message['from'] ?? [];
            $firstName   = $from['first_name'] ?? '';
            $lastName    = $from['last_name'] ?? '';
            $displayName = trim($firstName . ' ' . $lastName);

            $bonusInfo = getBonusInfoByPhone($phone);
            $eventInfo = getEventInfoByPhone($phone);

            $url = $miniAppUrl;
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . 'phone=' . urlencode($phone);

            if ($displayName !== '') {
                $url .= '&name=' . urlencode($displayName);
            }

            if ($bonusInfo) {
                $url .= '&bonus=' . urlencode($bonusInfo['balance']);
                if (!empty($bonusInfo['expires'])) {
                    $url .= '&bonus_exp=' . urlencode($bonusInfo['expires']);
                }
            }

            if ($eventInfo) {
                $url .= '&event_date=' . urlencode($eventInfo['date']);
                $url .= '&event_from=' . urlencode($eventInfo['time_from']);
                $url .= '&event_to='   . urlencode($eventInfo['time_to']);
                $url .= '&event_hall=' . urlencode($eventInfo['hall']);
            }

            $mainMenuWithCabinet = [
                'keyboard' => [
                    [
                        [
                            'text'    => 'Открыть личный кабинет',
                            'web_app' => ['url' => $url],
                        ],
                    ],
                    ['Мои квесты', 'Мой праздник'],
                    ['Мои бонусы', 'Мои подарки'],
                    ['Паспорт игрока', 'Позвать менеджера'],
                ],
                'resize_keyboard'   => true,
                'one_time_keyboard' => false,
            ];

            tgRequest('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => 'Ваш личный кабинет Pandoroom 👇',
                'reply_markup' => json_encode($mainMenuWithCabinet, JSON_UNESCAPED_UNICODE),
            ]);
            break;




        case 'Мои квесты':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }

            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Подгружаю ваши квесты... 🔍',
            ]);
            bindChatToUser($phone, $chatId);
            sendUserQuests($chatId, $phone);
            break;

        case 'Предстоящие квесты':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }

            tgRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Подгружаю предстоящие квесты... 🔍',
            ]);
            bindChatToUser($phone, $chatId);
            sendUserUpcomingQuests($chatId, $phone);
            break;

                         case 'Мой праздник':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }

            $event = getEventInfoByPhone($phone);

            if (!$event) {
                tgRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => 'Пока нет забронированных праздников 🎈',
                ]);
                break;
            }

            $lines = [];

            $lines[] = "🎉 <b>Ваш ближайший праздник</b>\n";

            // заказчик
            if (!empty($event['customer_name']) || !empty($event['phone'])) {
                $c = [];
                if (!empty($event['customer_name'])) {
                    $c[] = $event['customer_name'];
                }
                if (!empty($event['phone'])) {
                    $c[] = $event['phone'];
                }
                $lines[] = "👤 Заказчик: " . implode(', ', $c);
            }

            // дата / время
            $timeStr = trim($event['time_from'] . '–' . $event['time_to'], '– ');
            if (!empty($event['date']) || $timeStr !== '') {
                $line = "📅 Дата: {$event['date']}";
                if ($timeStr !== '') {
                    $line .= " ({$timeStr})";
                }
                $lines[] = $line;
            }

            // филиал / зал / стол
            if (!empty($event['branch'])) {
                $lines[] = "🏬 Филиал: {$event['branch']}";
            }
            if (!empty($event['hall'])) {
                $lines[] = "📍 Зал / стол: {$event['hall']}";
            }

            // именинник и гости
            if (!empty($event['imen'])) {
                $agePart = !empty($event['age']) ? " ({$event['age']} лет)" : '';
                $lines[] = "🎂 Именинник: {$event['imen']}{$agePart}";
            }

            if (!empty($event['guests'])) {
                $lines[] = "👨‍👩‍👧‍👦 Гостей всего: {$event['guests']}";
            }

            // БЛОК: что уже заказано по кухне
            $preLines = [];

            if (!empty($event['preorder_hot'])) {
                $preLines[] = "• Горячие блюда:\n" . $event['preorder_hot'];
            }
            if (!empty($event['preorder_cold'])) {
                $preLines[] = "• Холодные закуски:\n" . $event['preorder_cold'];
            }
            if (!empty($event['preorder_pizza'])) {
                $preLines[] = "• Пицца:\n" . $event['preorder_pizza'];
            }
            if (!empty($event['preorder_bar'])) {
                $preLines[] = "• Бар / напитки:\n" . $event['preorder_bar'];
            }

            if ($preLines) {
                $lines[] = "\n🍽 <b>Предзаказ по кухне:</b>";
                $lines[] = implode("\n\n", $preLines);
            }

            // флажки: шоу / квесты / украшения
            $extra = [];
            if (!empty($event['has_show'])) {
                $extra[] = "шоу-программы добавлены";
            }
            if (!empty($event['has_quests'])) {
                $extra[] = "квесты забронированы";
            }
            if (!empty($event['has_decor'])) {
                $extra[] = "украшения заказаны";
            }

            if ($extra) {
                $lines[] = "\n✨ Дополнительно: " . implode(', ', $extra);
            }

            $lines[] = "\nЕсли хотите что-то изменить в заказе — напишите нам в этом чате, мы всё подкорректируем 🙂";

            $text = implode("\n", $lines);

     // 🔘 Клавиатура: каталоги + "Хочу внести изменения"
            $catalogKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🎂 Торты',
                            'url'  => 'https://pandoroom.org/torts.pdf',
                        ],
                        [
                            'text' => '🍽 Праздничное меню',
                            'url'  => 'https://pandoroom.org/prazdpand.pdf',
                        ],
                    ],
                    [
                        [
                            'text' => '🎈 Украшения',
                            'url'  => 'https://pandoroom.org/dupsysl.pdf',
                        ],
                        [
                            'text' => '🎭 Шоу-программы',
                            'url'  => 'https://pandoroom.org/wp-content/uploads/2024/09/katalog-shou-programmy.pdf',
                        ],
                    ],
                    [
                        [
                            'text'          => '✏️ Хочу внести изменения',
                            'callback_data' => 'party_edit',
                        ],
                    ],
                ],
            ];

            tgRequest('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($catalogKeyboard, JSON_UNESCAPED_UNICODE),
            ]);

            break;



        case 'Мои бонусы':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }

            $bonus = getBonusInfoByPhone($phone);
            if ($bonus) {
                $txt = "💰 Ваши бонусы: {$bonus['balance']} руб.";
                if (!empty($bonus['expires'])) {
                    $txt .= "\nДействительны до: {$bonus['expires']}";
                }
                tgRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => $txt,
                ]);
            } else {
                tgRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => 'Пока не удалось получить информацию о бонусах 😕',
                ]);
            }
            break;

        case 'Мои подарки':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }
            sendUserGifts($chatId, $phone);
            break;

        case 'Паспорт игрока':
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => 'Сначала поделитесь номером телефона:',
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
                break;
            }
            sendUserPassport($chatId, $phone);
            break;

        case 'Позвать менеджера':
            // трактуем как сообщение в поддержку
            $msgForSupport = $message;
            $msgForSupport['text'] = 'Клиент нажал кнопку «Позвать менеджера»';
            handleUserSupportMessage($msgForSupport);
            break;
        default:
            // если телефона ещё нет — сразу просим отправить номер
            $phone = getUserPhone($chatId);
            if (!$phone) {
                tgRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => "Чтобы мы могли связать вас с бронированиями и бонусами, отправьте номер телефона кнопкой ниже:",
                    'reply_markup' => json_encode($phoneKeyboard, JSON_UNESCAPED_UNICODE),
                ]);
            }

            // любое «произвольное» сообщение (включая фото/док) — в поддержку (тред)
            if (function_exists('handleUserSupportMessage')) {
                handleUserSupportMessage($message);
            }



            break;

    }

    exit('OK');
}

exit('OK');
