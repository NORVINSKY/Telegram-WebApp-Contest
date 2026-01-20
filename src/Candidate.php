<?php

declare(strict_types=1);

namespace VotingBracket;

use Exception;

/**
 * Класс для работы с кандидатами (фотографиями)
 */
class Candidate
{
    private Database $db;
    private array $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    /**
     * Добавить нового кандидата
     *
     * @param string $name Имя кандидата
     * @param string $description Описание
     * @param array $file Массив $_FILES['image']
     * @return int ID добавленного кандидата
     * @throws Exception
     */
    public function add(string $name, string $description, array $file): int
    {
        // Валидация имени
        if (empty(trim($name))) {
            throw new Exception("Name cannot be empty");
        }

        // Проверка загрузки файла
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception("No file uploaded or upload error occurred");
        }

        // Проверка ошибок загрузки
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error: " . $this->getUploadErrorMessage($file['error']));
        }

        // Проверка размера файла
        if ($file['size'] > $this->config['MAX_UPLOAD_SIZE']) {
            $maxSizeMB = $this->config['MAX_UPLOAD_SIZE'] / (1024 * 1024);
            throw new Exception("File size exceeds maximum allowed size of {$maxSizeMB}MB");
        }

        // Проверка MIME типа
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $this->config['ALLOWED_IMAGE_TYPES'])) {
            throw new Exception("Invalid file type. Allowed types: JPEG, PNG, WEBP");
        }

        // Создаем директорию для загрузок, если её нет
        $uploadDir = $this->config['UPLOAD_PATH'];
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        // Генерируем уникальное имя файла
        $extension = $this->getExtensionFromMime($mimeType);
        $filename = uniqid('img_', true) . '_' . time() . '.' . $extension;
        $uploadPath = $uploadDir . $filename;

        // Перемещаем загруженный файл
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception("Failed to move uploaded file");
        }

        // Абсолютный путь от корня домена для хранения в БД
        $dbPath = '/uploads/' . $filename;

        // Добавляем запись в БД
        try {
            $sql = "INSERT INTO candidates (name, description, image_path) VALUES (?, ?, ?)";
            $this->db->execute($sql, [$name, $description, $dbPath]);

            return (int) $this->db->lastInsertId();
        } catch (Exception $e) {
            // Удаляем файл, если не удалось создать запись в БД
            if (file_exists($uploadPath)) {
                unlink($uploadPath);
            }
            throw new Exception("Failed to save candidate to database: " . $e->getMessage());
        }
    }

    /**
     * Получить всех кандидатов
     *
     * @param bool $activeOnly Только активные кандидаты
     * @return array
     */
    public function getAll(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM candidates";

        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY created_at DESC";

        return $this->db->query($sql);
    }

    /**
     * Получить кандидата по ID
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM candidates WHERE id = ?";
        return $this->db->queryOne($sql, [$id]);
    }

    /**
     * Удалить кандидата
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function delete(int $id): bool
    {
        // Получаем информацию о кандидате
        $candidate = $this->getById($id);

        if (!$candidate) {
            throw new Exception("Candidate not found");
        }

        // Формируем полный путь к файлу
        // Если в БД хранится /uploads/xxx.jpg, то физически файл в UPLOAD_PATH
        $imagePath = $this->config['UPLOAD_PATH'] . basename($candidate['image_path']);

        if (file_exists($imagePath)) {
            if (!unlink($imagePath)) {
                throw new Exception("Failed to delete image file");
            }
        }

        // Удаляем запись из БД
        $sql = "DELETE FROM candidates WHERE id = ?";
        $affected = $this->db->execute($sql, [$id]);

        return $affected > 0;
    }

    /**
     * Получить Tier List (рейтинг по винрейту)
     *
     * @return array
     */
    public function getTierList(): array
    {
        $sql = "
            SELECT 
                id,
                name,
                description,
                image_path,
                wins,
                matches,
                elo_rating,
                CASE 
                    WHEN matches > 0 THEN ROUND((wins * 100.0 / matches), 2)
                    ELSE 0 
                END as winrate
            FROM candidates
            WHERE is_active = 1
            ORDER BY elo_rating DESC, winrate DESC, wins DESC
        ";

        return $this->db->query($sql);
    }

    /**
     * Обновить счетчики после голосования
     *
     * @param int $winnerId
     * @param int $loserId
     * @return void
     */
    public function updateStats(int $winnerId, int $loserId): void
    {
        // Увеличиваем победы и матчи победителю
        $sql = "UPDATE candidates SET wins = wins + 1, matches = matches + 1 WHERE id = ?";
        $this->db->execute($sql, [$winnerId]);

        // Увеличиваем только матчи проигравшему
        $sql = "UPDATE candidates SET matches = matches + 1 WHERE id = ?";
        $this->db->execute($sql, [$loserId]);
    }

    /**
     * Получить расширение файла по MIME типу
     *
     * @param string $mimeType
     * @return string
     */
    private function getExtensionFromMime(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        return $mimeMap[$mimeType] ?? 'jpg';
    }

    /**
     * Получить текстовое описание ошибки загрузки
     *
     * @param int $errorCode
     * @return string
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}