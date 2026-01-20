<?php
// public/debug_elo_web.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/VoteManager.php';

use VotingBracket\Database;
use VotingBracket\VoteManager;

header('Content-Type: text/plain');

echo "=== ELO DEBUG WEB ===\n";
echo "PHP Version: " . phpversion() . "\n";

$db = Database::getInstance();
$vm = new VoteManager();

// 1. Check raw DB values and types
echo "\n1. Raw DB Values:\n";
$c = $db->queryOne("SELECT id, name, wins, matches, elo_rating FROM candidates LIMIT 1");
if ($c) {
    echo "Candidate: {$c['name']}\n";
    echo "ELO Value: " . var_export($c['elo_rating'], true) . "\n";
    echo "ELO Type: " . gettype($c['elo_rating']) . "\n";
} else {
    echo "No candidates found.\n";
}

// 2. Test calculateElo function
echo "\n2. Test calculateElo(1200, 1200):\n";
try {
    $res = $vm->calculateElo(1200, 1200);
    echo "Result: " . var_export($res, true) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 3. Test Cast Vote logic manually (without committing)
echo "\n3. Simulation:\n";
$db->beginTransaction();
try {
    if ($c) {
        $wElo = (int) ($c['elo_rating'] ?? 1200);
        $lElo = 1200;
        echo "Input ELOs: $wElo (winner), $lElo (loser)\n";

        [$nW, $nL] = $vm->calculateElo($wElo, $lElo);
        echo "Calculated New ELOs: $nW, $nL\n";

        // Try update
        $sql = "UPDATE candidates SET elo_rating = ? WHERE id = ?";
        $rowCount = $db->execute($sql, [$nW, $c['id']]);
        echo "Rows updated: $rowCount\n";

        // Verify update within transaction
        $check = $db->queryOne("SELECT elo_rating FROM candidates WHERE id = ?", [$c['id']]);
        echo "Value in DB txn: " . $check['elo_rating'] . "\n";
    }
} catch (Exception $e) {
    echo "Sim Error: " . $e->getMessage() . "\n";
}
$db->rollback(); // Always rollback test
echo "Rolled back.\n";
