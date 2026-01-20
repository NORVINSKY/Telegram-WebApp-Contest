<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use VotingBracket\Database;

/**
 * Скрипт установки базы данных
 * Создает все необходимые таблицы для работы приложения
 */

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Voting Bracket</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #28a745;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #dc3545;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #17a2b8;
            margin: 20px 0;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        code { font-family: 'Courier New', monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Voting Bracket - Installation</h1>
        
<?php

try {
    echo "<div class='info'>Starting installation process...</div>\n";
    
    // Получаем экземпляр базы данных
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    echo "<div class='info'>Database connection established successfully.</div>\n";
    
    // SQL запросы для создания таблиц
    $sqlQueries = [
        // Таблица кандидатов (фотографий)
        'candidates' => "
            CREATE TABLE IF NOT EXISTS candidates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT NULL,
                image_path TEXT NOT NULL,
                wins INTEGER DEFAULT 0,
                matches INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ",
        
        // Таблица пользователей Telegram
        'users' => "
            CREATE TABLE IF NOT EXISTS users (
                tg_id INTEGER PRIMARY KEY,
                username TEXT NULL,
                full_name TEXT NULL,
                last_vote_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ",
        
        // Таблица голосований
        'votes' => "
            CREATE TABLE IF NOT EXISTS votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_tg_id INTEGER NOT NULL,
                winner_id INTEGER NOT NULL,
                loser_id INTEGER NOT NULL,
                comment TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_tg_id) REFERENCES users(tg_id) ON DELETE CASCADE,
                FOREIGN KEY (winner_id) REFERENCES candidates(id) ON DELETE CASCADE,
                FOREIGN KEY (loser_id) REFERENCES candidates(id) ON DELETE CASCADE
            )
        ",
    ];
    
    // Создаем индексы для оптимизации запросов
    $indexQueries = [
        "CREATE INDEX IF NOT EXISTS idx_candidates_wins ON candidates(wins DESC)",
        "CREATE INDEX IF NOT EXISTS idx_candidates_active ON candidates(is_active)",
        "CREATE INDEX IF NOT EXISTS idx_votes_user ON votes(user_tg_id)",
        "CREATE INDEX IF NOT EXISTS idx_votes_winner ON votes(winner_id)",
        "CREATE INDEX IF NOT EXISTS idx_votes_created ON votes(created_at)",
    ];
    
    echo "<h2>Creating tables...</h2>\n";
    
    // Создаем таблицы
    foreach ($sqlQueries as $tableName => $sql) {
        try {
            $pdo->exec($sql);
            echo "<div class='success'>✓ Table '<strong>{$tableName}</strong>' created successfully.</div>\n";
        } catch (Exception $e) {
            throw new Exception("Failed to create table '{$tableName}': " . $e->getMessage());
        }
    }
    
    echo "<h2>Creating indexes...</h2>\n";
    
    // Создаем индексы
    foreach ($indexQueries as $indexSql) {
        try {
            $pdo->exec($indexSql);
        } catch (Exception $e) {
            throw new Exception("Failed to create index: " . $e->getMessage());
        }
    }
    
    echo "<div class='success'>✓ All indexes created successfully.</div>\n";
    
    // Проверяем созданные таблицы
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll();
    
    echo "<h2>Database structure:</h2>\n";
    echo "<pre><code>";
    foreach ($tables as $table) {
        echo "- {$table['name']}\n";
    }
    echo "</code></pre>\n";
    
    echo "<div class='success'><strong>✓ Installation completed successfully!</strong></div>\n";
    echo "<div class='info'>You can now start using the Voting Bracket application.</div>\n";
    
    // Выводим информацию о следующих шагах
    echo "<h2>Next steps:</h2>\n";
    echo "<ul>";
    echo "<li>Configure your Telegram Bot Token in <code>config/config.php</code></li>";
    echo "<li>Upload candidate images to <code>public/uploads/</code> directory</li>";
    echo "<li>Add candidates through the admin panel</li>";
    echo "<li>Start collecting votes!</li>";
    echo "</ul>\n";
    
} catch (Exception $e) {
    echo "<div class='error'><strong>Installation failed!</strong><br><br>";
    echo "Error: " . htmlspecialchars($e->getMessage());
    echo "</div>\n";
    
    echo "<h3>Troubleshooting:</h3>\n";
    echo "<ul>";
    echo "<li>Check that the <code>/db/</code> directory exists and is writable</li>";
    echo "<li>Verify PHP PDO SQLite extension is installed</li>";
    echo "<li>Check file permissions (755 for directories, 644 for files)</li>";
    echo "</ul>\n";
}

?>
    </div>
</body>
</html>