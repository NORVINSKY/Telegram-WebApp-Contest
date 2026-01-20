<?php

declare(strict_types=1);

namespace VotingBracket;

use Exception;

/**
 * Класс для аутентификации Telegram WebApp
 * 
 * Проверяет подлинность данных, полученных от Telegram WebApp
 * согласно официальной документации:
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramAuth
{
    private array $config;
    private bool $devMode;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->devMode = $this->config['DEV_MODE'] ?? false;
    }

    /**
     * Валидировать initData от Telegram WebApp
     * 
     * @param string $initData Строка initData от Telegram
     * @return array|false Массив данных или false если валидация провалилась
     */
    public function validate(string $initData)
    {
        // В режиме разработки возвращаем тестовые данные
        if ($this->devMode) {
            return $this->getTestUser();
        }

        try {
            // Проверяем наличие bot token
            if (empty($this->config['TG_BOT_TOKEN'])) {
                throw new Exception("TG_BOT_TOKEN is not configured");
            }

            // Парсим query string
            parse_str($initData, $data);

            if (!isset($data['hash'])) {
                return false;
            }

            // Извлекаем и удаляем hash из данных
            $receivedHash = $data['hash'];
            unset($data['hash']);

            // Сортируем ключи по алфавиту
            ksort($data);

            // Создаем data-check-string
            $dataCheckArray = [];
            foreach ($data as $key => $value) {
                $dataCheckArray[] = $key . '=' . $value;
            }
            $dataCheckString = implode("\n", $dataCheckArray);

            // Вычисляем секретный ключ
            // secret_key = HMAC_SHA256(<bot_token>, "WebAppData")
            $secretKey = hash_hmac('sha256', $this->config['TG_BOT_TOKEN'], 'WebAppData', true);

            // Вычисляем hash от data-check-string
            $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

            // Сравниваем хеши
            if (!hash_equals($calculatedHash, $receivedHash)) {
                return false;
            }

            // Проверяем auth_date (данные не должны быть старше 1 часа)
            if (isset($data['auth_date'])) {
                $authDate = (int) $data['auth_date'];
                $currentTime = time();

                // Если данные старше 1 часа - отклоняем
                if (($currentTime - $authDate) > 3600) {
                    return false;
                }
            }

            return $data;

        } catch (Exception $e) {
            error_log("TelegramAuth validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Распарсить данные пользователя из initData
     * 
     * @param string $initData Строка initData от Telegram
     * @return array Массив данных пользователя
     * @throws Exception
     */
    public function parseUser(string $initData): array
    {
        // В режиме разработки возвращаем тестового пользователя
        if ($this->devMode) {
            return [
                'id' => 1,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'language_code' => 'en',
            ];
        }

        parse_str($initData, $data);

        if (!isset($data['user'])) {
            throw new Exception("User data not found in initData");
        }

        // Декодируем JSON с данными пользователя
        $userData = json_decode($data['user'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse user data: " . json_last_error_msg());
        }

        // Валидация обязательных полей
        if (!isset($userData['id'])) {
            throw new Exception("User ID not found in user data");
        }

        return $userData;
    }

    /**
     * Быстрая валидация и извлечение пользователя (комбо метод)
     * 
     * @param string $initData
     * @return array|false Массив с данными пользователя или false
     */
    public function validateAndGetUser(string $initData)
    {
        // Сначала валидируем данные
        $validationResult = $this->validate($initData);

        if ($validationResult === false) {
            return false;
        }

        // В dev mode validate уже вернул тестовые данные
        if ($this->devMode) {
            return $validationResult;
        }

        // Извлекаем данные пользователя
        try {
            return $this->parseUser($initData);
        } catch (Exception $e) {
            error_log("Failed to parse user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверить, включен ли режим разработки
     * 
     * @return bool
     */
    public function isDevMode(): bool
    {
        return $this->devMode;
    }

    /**
     * Получить тестовые данные пользователя для dev mode
     * 
     * @return array
     */
    private function getTestUser(): array
    {
        return [
            'id' => 1,
            'first_name' => 'Test',
            'last_name' => 'User',
            'username' => 'testuser',
            'language_code' => 'en',
            'auth_date' => time(),
        ];
    }

    /**
     * Сгенерировать тестовый initData для dev mode
     * 
     * @param int $userId
     * @param string $username
     * @return string
     */
    public static function generateTestInitData(int $userId = 1, string $username = 'testuser'): string
    {
        $user = [
            'id' => $userId,
            'first_name' => 'Test',
            'last_name' => 'User',
            'username' => $username,
            'language_code' => 'en',
        ];

        $data = [
            'user' => json_encode($user),
            'auth_date' => time(),
            'hash' => 'test_hash_in_dev_mode',
        ];

        return http_build_query($data);
    }
}