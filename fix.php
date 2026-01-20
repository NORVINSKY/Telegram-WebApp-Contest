<?php

declare(strict_types=1);

/**
 * Миграция: добавление таблицы tournament_sessions
 * 
 * Эта таблица хранит текущее состояние турнира пользователя
 */

require_once __DIR__ . '/src/Database.php';

use VotingBracket\Database;

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    echo "🔧 Creating tournament_sessions table...\n\n";
    
    // Создаем таблицу для хранения сессий турнира
    $sql = "
        CREATE TABLE IF NOT EXISTS tournament_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_tg_id INTEGER NOT NULL,
            session_data TEXT NOT NULL,
            is_completed INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_tg_id) REFERENCES users(tg_id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($sql);
    echo "✓ Table 'tournament_sessions' created\n\n";
    
    // Создаем индекс для быстрого поиска активных сессий
    $sql = "CREATE INDEX IF NOT EXISTS idx_sessions_user ON tournament_sessions(user_tg_id, is_completed)";
    $pdo->exec($sql);
    echo "✓ Index created\n\n";
    
    // Создаем таблицу для временного хранения голосов в рамках сессии
    $sql = "
        CREATE TABLE IF NOT EXISTS session_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            winner_id INTEGER NOT NULL,
            loser_id INTEGER NOT NULL,
            vote_order INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES tournament_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (winner_id) REFERENCES candidates(id) ON DELETE CASCADE,
            FOREIGN KEY (loser_id) REFERENCES candidates(id) ON DELETE CASCADE
        )
    ";
    
    $pdo->exec($sql);
    echo "✓ Table 'session_votes' created\n\n";
    
    // Индекс для session_votes
    $sql = "CREATE INDEX IF NOT EXISTS idx_session_votes ON session_votes(session_id)";
    $pdo->exec($sql);
    echo "✓ Index for session_votes created\n\n";
    
    echo "✅ Migration completed successfully!\n";
    echo "\nNew tables:\n";
    echo "  - tournament_sessions (stores user tournament state)\n";
    echo "  - session_votes (stores votes until tournament completion)\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}