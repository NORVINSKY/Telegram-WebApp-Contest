<?php

declare(strict_types=1);

/**
 * Скрипт миграции ELO
 * 
 * 1. Создает столбец elo_rating в таблице candidates (если нет)
 * 2. Сбрасывает все рейтинги до 1200
 * 3. Пересчитывает историю голосов хронологически
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/VoteManager.php';

use VotingBracket\Database;
use VotingBracket\VoteManager;

$config = require __DIR__ . '/../config/config.php';

if (!($config['DEV_MODE'] ?? false) && php_sapi_name() !== 'cli') {
    die("Use CLI or enable DEV_MODE");
}

echo "🔄 Starting ELO Migration...\n\n";

$db = Database::getInstance();
$voteManager = new VoteManager();

// 1. Проверяем/Создаем столбец elo_rating
echo "1. Checking database schema... ";
try {
    $db->execute("SELECT elo_rating FROM candidates LIMIT 1");
    echo "Column 'elo_rating' already exists.\n";
} catch (Exception $e) {
    echo "Adding 'elo_rating' column...\n";
    $db->execute("ALTER TABLE candidates ADD COLUMN elo_rating INTEGER DEFAULT 1200");
    echo "Done.\n";
}

// 2. Сбрасываем текущие рейтинги
echo "2. Resetting candidates to 1200 ELO... ";
$db->execute("UPDATE candidates SET elo_rating = 1200");
echo "Done.\n";

// 3. Получаем всю историю голосов
echo "3. Fetching voting history... ";
$votes = $db->query("SELECT * FROM votes ORDER BY created_at ASC");
echo count($votes) . " votes found.\n";

if (empty($votes)) {
    echo "\n✅ Migration complete! (No votes to process)\n";
    exit;
}

// 4. Пересчитываем рейтинги
echo "4. Recalculating ELO history...\n";

// Кэш рейтингов в памяти, чтобы не долбить БД на каждый апдейт
// id => rating
$ratings = [];
$candidates = $db->query("SELECT id FROM candidates");
foreach ($candidates as $c) {
    $ratings[$c['id']] = 1200;
}

$processed = 0;
foreach ($votes as $vote) {
    $wId = $vote['winner_id'];
    $lId = $vote['loser_id'];

    $wElo = $ratings[$wId] ?? 1200;
    $lElo = $ratings[$lId] ?? 1200;

    [$nW, $nL] = $voteManager->calculateElo($wElo, $lElo);

    $ratings[$wId] = $nW;
    $ratings[$lId] = $nL;

    $processed++;
    if ($processed % 50 === 0) {
        echo ".";
    }
}
echo "\n";

// 5. Сохраняем новые рейтинги в БД
echo "5. Saving new ratings to database...\n";
$db->beginTransaction();
try {
    foreach ($ratings as $id => $elo) {
        $db->execute("UPDATE candidates SET elo_rating = ? WHERE id = ?", [$elo, $id]);
    }
    $db->commit();
    echo "Done.\n";
} catch (Exception $e) {
    $db->rollback();
    echo "❌ Error saving ratings: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ ELO Migration Successfully Completed!\n";
echo "Top 5 Candidates:\n";
$top = $db->query("SELECT name, elo_rating FROM candidates ORDER BY elo_rating DESC LIMIT 5");
foreach ($top as $i => $c) {
    echo ($i + 1) . ". {$c['name']} - {$c['elo_rating']}\n";
}
