<?php
// debug_completion.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/VoteManager.php';
require_once __DIR__ . '/src/TournamentSession.php';

use VotingBracket\Database;
use VotingBracket\TournamentSession;

echo "<h3>Debug: Testing Tournament Completion</h3>";

$db = Database::getInstance();
$sessionManager = new TournamentSession();

// 1. Create a dummy user
$testUserId = 999999;
$db->execute("INSERT OR REPLACE INTO users (tg_id, username, first_name) VALUES (?, 'debug_user', 'Debug User')", [$testUserId]);

// 2. Create a dummy session
$sessionData = json_encode(['queue' => [], 'roundNum' => 1]);
$db->execute("DELETE FROM tournament_sessions WHERE user_tg_id = ?", [$testUserId]);
$db->execute("INSERT INTO tournament_sessions (user_tg_id, session_data, is_completed) VALUES (?, ?, 0)", [$testUserId, $sessionData]);
$sessionId = (int) $db->lastInsertId();

echo "Created Session ID: $sessionId<br>";

// 3. Get 2 candidates
$candidates = $db->query("SELECT id, elo_rating FROM candidates LIMIT 2");
if (count($candidates) < 2) {
    die("Not enough candidates in DB");
}
$c1 = $candidates[0];
$c2 = $candidates[1];

echo "Candidate 1 (ID {$c1['id']}) ELO: " . ($c1['elo_rating'] ?? 1200) . "<br>";
echo "Candidate 2 (ID {$c2['id']}) ELO: " . ($c2['elo_rating'] ?? 1200) . "<br>";

// 4. Add a dummy vote
echo "Adding vote: C1 beats C2<br>";
$sessionManager->addVoteToSession($sessionId, $c1['id'], $c2['id'], 1, "Debug vote");

// 5. Complete tournament
echo "Completing tournament...<br>";
try {
    $result = $sessionManager->completeTournament($sessionId, $testUserId, "Debug Final");
    if ($result) {
        echo "<b style='color:green'>Success!</b><br>";
    } else {
        echo "<b style='color:red'>Failed (bool false)</b><br>";
    }
} catch (Exception $e) {
    echo "<b style='color:red'>Exception: " . $e->getMessage() . "</b><br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 6. Check results
$c1New = $db->queryOne("SELECT elo_rating, matches FROM candidates WHERE id = ?", [$c1['id']]);
$c2New = $db->queryOne("SELECT elo_rating, matches FROM candidates WHERE id = ?", [$c2['id']]);

echo "Candidate 1 New ELO: " . ($c1New['elo_rating'] ?? 0) . " (Diff: " . (($c1New['elo_rating'] ?? 0) - ($c1['elo_rating'] ?? 1200)) . ")<br>";
echo "Candidate 2 New ELO: " . ($c2New['elo_rating'] ?? 0) . " (Diff: " . (($c2New['elo_rating'] ?? 0) - ($c2['elo_rating'] ?? 1200)) . ")<br>";

if (($c1New['elo_rating'] ?? 0) == ($c1['elo_rating'] ?? 1200)) {
    echo "<b style='color:red'>ELO DID NOT CHANGE!</b>";
} else {
    echo "<b style='color:green'>ELO Updated!</b>";
}

// Cleanup
//$db->execute("DELETE FROM tournament_sessions WHERE id = ?", [$sessionId]);
//$db->execute("DELETE FROM session_votes WHERE session_id = ?", [$sessionId]);
