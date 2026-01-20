<?php

declare(strict_types=1);

namespace VotingBracket;

use PDO;
use PDOException;
use Exception;

/**
 * Класс-обертка для работы с базой данных SQLite
 * Реализует паттерн Singleton
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;
    private array $config;

    /**
     * Приватный конструктор для реализации Singleton
     * 
     * @throws Exception
     */
    private function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        
        try {
            $dbPath = $this->config['DB_PATH'];
            $dbDir = dirname($dbPath);
            
            // Создаем директорию для БД, если её нет
            if (!is_dir($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new Exception("Failed to create database directory: {$dbDir}");
                }
            }
            
            // Подключаемся к SQLite
            $this->connection = new PDO(
                'sqlite:' . $dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            // Включаем поддержку внешних ключей в SQLite
            $this->connection->exec('PRAGMA foreign_keys = ON');
            
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Получить экземпляр Database (Singleton)
     * 
     * @return self
     * @throws Exception
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Получить объект PDO соединения
     * 
     * @return PDO
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Выполнить запрос и вернуть все строки
     * 
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Выполнить запрос и вернуть одну строку
     * 
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Выполнить INSERT/UPDATE/DELETE запрос
     * 
     * @param string $sql
     * @param array $params
     * @return int Количество затронутых строк
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Execution failed: " . $e->getMessage());
        }
    }

    /**
     * Получить ID последней вставленной записи
     * 
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Начать транзакцию
     * 
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Подтвердить транзакцию
     * 
     * @return bool
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * Откатить транзакцию
     * 
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    /**
     * Запретить клонирование (Singleton)
     */
    private function __clone()
    {
    }

    /**
     * Запретить десериализацию (Singleton)
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}