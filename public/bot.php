<?php

declare(strict_types=1);

// Подключаем конфигурацию
$config = require __DIR__ . '/../config/config.php';

// Функция для отправки запросов к Telegram API
function sendTelegram($method, $data, $token = null)
{
    global $config;
    $token = $token ?? $config['TG_BOT_TOKEN'];

    if (empty($token)) {
        return ['ok' => false, 'description' => 'Token not provided'];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для локальной разработки, на проде лучше true

    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['ok' => false, 'description' => 'Curl error: ' . $error];
    }

    return json_decode($result, true);
}

// Определение текущего URL (Base URL)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
// Удаляем bot.php из пути, чтобы получить корень приложения
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl =  "https://" . $host . $scriptDir;
// Убираем trailing slash если есть, чтобы было красиво
$baseUrl = rtrim($baseUrl, '/');


// === РЕЖИМ УСТАНОВКИ WEBHOOK ===
if (isset($_GET['setup']) && $_GET['setup'] === 'webhook') {
    header('Content-Type: application/json');

    $webhookUrl = $baseUrl . '/bot.php';

    $response = sendTelegram('setWebhook', [
        'url' => $webhookUrl
    ]);

    echo json_encode([
        'action' => 'setWebhook',
        'url' => $webhookUrl,
        'response' => $response
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// === ОБРАБОТКА ВХОДЯЩЕГО webhook ===
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    // Если открыли файл в браузере просто так
    echo "Telegram Bot Endpoint is Active.";
    exit;
}

// Обработка сообщений
if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'] ?? '';

    // Команда /start
    if ($text === '/start') {
        // Ссылка на Web App отправляется БЕЗ bot.php
        $webAppUrl = $baseUrl . '/index.html'; // или просто $baseUrl, если index.html дефолтный

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🚀 Голосовать',
                        'web_app' => ['url' => $webAppUrl]
                    ]
                ]
            ]
        ];

        sendTelegram('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Привет! Готов выбрать лучшую девочку этого чата?\nЖми на кнопку ниже, чтобы начать!",
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}
