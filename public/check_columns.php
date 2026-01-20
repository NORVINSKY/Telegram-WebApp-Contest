<?php
require_once __DIR__ . '/../src/Database.php';
use VotingBracket\Database;

$db = Database::getInstance();
$result = $db->query("PRAGMA table_info(session_votes)");

echo "Columns in session_votes:\n";
foreach ($result as $col) {
    echo "- " . $col['name'] . " (" . $col['type'] . ")\n";
}
