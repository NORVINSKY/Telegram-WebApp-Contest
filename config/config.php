<?php

declare(strict_types=1);

/**
 * Конфигурация приложения "Турнир фотографий"
 */

return [
    // Токен для административных операций
    'ADMIN_TOKEN' => 'jmek4etq',

    // Токен Telegram бота (заполняется пользователем)
   // 'TG_BOT_TOKEN' => '8573058650:AAF840axSAmkkAq35drO4jIp0hktrmyPgms',
    'TG_BOT_TOKEN' => '8547659110:AAE6XGtkrpiiSwBUnlaa3pIYst8gkFwzsDg',

    // Абсолютный путь к файлу базы данных SQLite
    'DB_PATH' => __DIR__ . '/../db/database.sqlite',

    // Путь к папке для загрузки изображений (в корне проекта)
    'UPLOAD_PATH' => __DIR__ . '/../uploads/',

    // Максимальный размер загружаемого файла (в байтах)
    'MAX_UPLOAD_SIZE' => 5 * 1024 * 1024, // 5MB

    // Разрешенные форматы изображений
    'ALLOWED_IMAGE_TYPES' => ['image/jpeg', 'image/png', 'image/webp'],

    // Режим разработки (отключает проверку Telegram подписи)
    'DEV_MODE' => false,
];