<?php

declare(strict_types=1);

/**
 * Скрипт для сброса всех голосований (только для разработки)
 * 
 * ВНИМАНИЕ: Этот скрипт удалит:
 * - Все голоса из таблицы votes
 * - Все сессии турниров
 * - Всю статистику кандидатов (wins/matches)
 * 
 * Кандидаты и их фотографии НЕ будут удалены
 */

require_once __DIR__ . '/src/Database.php';

use VotingBracket\Database;

$config = require __DIR__ . '/config/config.php';

// Проверяем DEV_MODE
if (!($config['DEV_MODE'] ?? false)) {
    echo "❌ This script can only be run in DEV_MODE\n";
    echo "Set DEV_MODE = true in config/config.php\n";
    exit(1);
}

echo "⚠️  VOTING RESET SCRIPT\n";
echo "=======================\n\n";
echo "This will DELETE:\n";
echo "  ✗ All votes\n";
echo "  ✗ All tournament sessions\n";
echo "  ✗ All candidate statistics\n\n";
echo "This will KEEP:\n";
echo "  ✓ All candidates\n";
echo "  ✓ All uploaded images\n";
echo "  ✓ All users\n\n";

// Интерактивное подтверждение (только если запущено из CLI)
if (php_sapi_name() === 'cli') {
    echo "Are you sure? Type 'yes' to continue: ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if ($line !== 'yes') {
        echo "Cancelled.\n";
        exit(0);
    }
}

try {
    $db = Database::getInstance();

    echo "\n🔧 Starting reset process...\n\n";

    // 1. Удаляем все голоса
    $votesCount = $db->execute("DELETE FROM votes");
    echo "✓ Deleted {$votesCount} votes\n";

    // 2. Удаляем session_votes
    if ($db->queryOne("SELECT name FROM sqlite_master WHERE type='table' AND name='session_votes'")) {
        $sessionVotesCount = $db->execute("DELETE FROM session_votes");
        echo "✓ Deleted {$sessionVotesCount} session votes\n";
    }

    // 3. Удаляем tournament_sessions
    if ($db->queryOne("SELECT name FROM sqlite_master WHERE type='table' AND name='tournament_sessions'")) {
        $sessionsCount = $db->execute("DELETE FROM tournament_sessions");
        echo "✓ Deleted {$sessionsCount} tournament sessions\n";
    }

    // 4. Сбрасываем статистику кандидатов
    $db->execute("UPDATE candidates SET wins = 0, matches = 0, elo_rating = 1200");
    echo "✓ Reset all candidate statistics\n";

    // 5. Обновляем last_vote_at у пользователей
    $db->execute("UPDATE users SET last_vote_at = CURRENT_TIMESTAMP");
    echo "✓ Updated user timestamps\n";

    echo "\n✅ Reset completed successfully!\n";
    echo "\nYou can now test voting from scratch.\n";

} catch (Exception $e) {
    echo "\n❌ Reset failed: " . $e->getMessage() . "\n";
    exit(1);
}