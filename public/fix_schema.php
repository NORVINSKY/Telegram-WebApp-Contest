<?php
require_once __DIR__ . '/../src/Database.php';
use VotingBracket\Database;

$db = Database::getInstance();

try {
    $db->execute("ALTER TABLE session_votes ADD COLUMN comment TEXT");
    echo "Successfully added 'comment' column to session_votes table.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
