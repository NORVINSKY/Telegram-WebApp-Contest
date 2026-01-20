<?php
require_once __DIR__ . '/src/Database.php';
$db = VotingBracket\Database::getInstance();
$info = $db->query("PRAGMA table_info(session_votes)");
print_r($info);
