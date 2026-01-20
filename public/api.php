<?php

declare(strict_types=1);

// Отключаем вывод ошибок в production
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Candidate.php';
require_once __DIR__ . '/../src/TelegramAuth.php';
require_once __DIR__ . '/../src/VoteManager.php';
require_once __DIR__ . '/../src/TournamentSession.php';

use VotingBracket\Candidate;
use VotingBracket\TelegramAuth;
use VotingBracket\VoteManager;
use VotingBracket\TournamentSession;

// Установка заголовков
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Отправить JSON ответ
 */
function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Отправить ошибку
 */
function sendError(string $message, int $statusCode = 400): void
{
    sendJson(['error' => $message], $statusCode);
}

/**
 * Получить JSON из тела запроса
 */
function getJsonInput(): ?array
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return null;
    }

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON: ' . json_last_error_msg());
    }

    return $data;
}

/**
 * Преобразовать путь к изображению в полный URL
 */
function getImageUrl(string $imagePath): string
{
    // Если путь уже начинается с /, это абсолютный путь от корня
    if (strpos($imagePath, '/') === 0) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host . $imagePath;
    }

    // Старый формат ../uploads/xxx.jpg - конвертируем
    $filename = basename($imagePath);
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host . '/uploads/' . $filename;
}

// Получаем action
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    sendError('Action parameter is required');
}

try {
    $config = require __DIR__ . '/../config/config.php';

    if ($config['DEV_MODE']) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }
    $candidate = new Candidate();
    $voteManager = new VoteManager();
    $telegramAuth = new TelegramAuth();
    $sessionManager = new TournamentSession();

    // ========================================
    // ACTION: get_candidates
    // ========================================
    if ($action === 'get_candidates') {
        $candidates = $candidate->getAll(true);

        // Добавляем полные URL к изображениям
        foreach ($candidates as &$c) {
            $c['image_url'] = getImageUrl($c['image_path']);
        }

        sendJson([
            'success' => true,
            'candidates' => $candidates,
            'total' => count($candidates),
            'dev_mode' => $config['DEV_MODE'] ?? false,
        ]);
    }

    // ========================================
    // ACTION: get_random_pair
    // ========================================
    if ($action === 'get_random_pair') {
        $pair = $voteManager->getRandomPair();

        if (!$pair) {
            sendError('Not enough active candidates. Need at least 2.', 404);
        }

        // Добавляем полные URL
        foreach ($pair as &$c) {
            $c['image_url'] = getImageUrl($c['image_path']);
        }

        sendJson([
            'success' => true,
            'pair' => $pair,
        ]);
    }

    // ========================================
    // ACTION: get_tierlist
    // ========================================
    if ($action === 'get_tierlist') {
        $tierlist = $candidate->getTierList();

        // Добавляем полные URL
        foreach ($tierlist as &$c) {
            $c['image_url'] = getImageUrl($c['image_path']);
        }

        sendJson([
            'success' => true,
            'tierlist' => $tierlist,
            'total' => count($tierlist),
        ]);
    }

    // ========================================
    // ACTION: start_tournament
    // ========================================
    if ($action === 'start_tournament') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendError('POST method required', 405);
        }

        $input = getJsonInput();
        // If no input, it's an error, but we still need to initialize variables
        if (!$input) {
            sendError('Request body is required');
        }

        $initData = $input['initData'] ?? $input['init_data'] ?? null;
        $reset = $input['reset'] ?? false;

        if (!$initData) {
            sendError('initData is required', 400);
        }

        // Валидация пользователя
        $userData = $telegramAuth->validateAndGetUser($initData);
        if ($userData === false) {
            sendError('Unauthorized: Invalid Telegram data', 403);
        }

        // Синхронизация пользователя
        $userId = $voteManager->syncUser($userData);

        // Если запрошен сброс - удаляем старую сессию
        if ($reset) {
            $sessionManager->resetActiveSession($userId);
        }

        // Получаем или создаем сессию
        $session = $sessionManager->getOrCreateSession($userId);

        if ($session === null) {
            sendError('Tournament already completed', 400);
        }

        // Получаем сохраненное состояние (если есть)
        $sessionData = $sessionManager->getSessionData((int) $session['id']);

        sendJson([
            'success' => true,
            'session_id' => $session['id'],
            'session_data' => $sessionData,
            'message' => 'Tournament session started or resumed'
        ]);
    }

    // ========================================
    // ACTION: save_tournament_state
    // ========================================
    if ($action === 'save_tournament_state') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendError('POST method required', 405);
        }

        $input = getJsonInput();
        if (!$input) {
            sendError('Request body is required');
        }

        $initData = $input['initData'] ?? null;
        $sessionId = $input['session_id'] ?? null;
        $state = $input['state'] ?? null;

        if (!$initData || !$sessionId || !$state) {
            sendError('initData, session_id and state are required');
        }

        $sessionId = (int) $sessionId;

        // Валидация пользователя
        $userData = $telegramAuth->validateAndGetUser($initData);
        if ($userData === false) {
            sendError('Unauthorized: Invalid Telegram data', 403);
        }

        // Сохраняем состояние
        $success = $sessionManager->updateSessionData($sessionId, $state);

        if (!$success) {
            sendError('Failed to save state', 500);
        }

        sendJson([
            'success' => true,
            'message' => 'State saved'
        ]);
    }

    // ========================================
    // ACTION: vote (теперь сохраняет в сессию)
    // ========================================
    if ($action === 'vote') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendError('POST method required', 405);
        }

        $input = getJsonInput();
        if (!$input) {
            sendError('Request body is required');
        }

        $initData = $input['initData'] ?? $input['init_data'] ?? null;
        $sessionId = $input['session_id'] ?? null;
        $winnerId = $input['winner_id'] ?? $input['winnerId'] ?? null;
        $loserId = $input['loser_id'] ?? $input['loserId'] ?? null;
        $voteOrder = $input['vote_order'] ?? null;

        if (!$initData) {
            sendError('initData is required');
        }

        if (!$sessionId) {
            sendError('session_id is required');
        }

        if (!$winnerId || !$loserId) {
            sendError('winner_id and loser_id are required');
        }

        if ($voteOrder === null) {
            sendError('vote_order is required');
        }

        $winnerId = (int) $winnerId;
        $loserId = (int) $loserId;
        $sessionId = (int) $sessionId;
        $voteOrder = (int) $voteOrder;

        $voteOrder = (int) $voteOrder;
        $comment = $input['comment'] ?? null;

        // Валидация пользователя
        $userData = $telegramAuth->validateAndGetUser($initData);
        if ($userData === false) {
            sendError('Unauthorized: Invalid Telegram data', 403);
        }

        // Проверяем кандидатов
        $winner = $candidate->getById($winnerId);
        $loser = $candidate->getById($loserId);

        if (!$winner || !$loser) {
            sendError('Invalid candidate IDs', 400);
        }

        if (!$winner['is_active'] || !$loser['is_active']) {
            sendError('Candidate is not active', 400);
        }

        // Сохраняем голос в сессию (НЕ в основную таблицу)
        $voteId = $sessionManager->addVoteToSession($sessionId, $winnerId, $loserId, $voteOrder, $comment);

        sendJson([
            'success' => true,
            'vote_id' => $voteId,
            'session_id' => $sessionId,
            'message' => 'Vote recorded in session'
        ]);
    }

    // ========================================
    // ACTION: complete_tournament
    // ========================================
    if ($action === 'complete_tournament') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendError('POST method required', 405);
        }

        $input = getJsonInput();
        if (!$input) {
            sendError('Request body is required');
        }

        $initData = $input['initData'] ?? null;
        $sessionId = $input['session_id'] ?? null;
        $comment = $input['comment'] ?? null;

        if (!$initData) {
            sendError('initData is required');
        }

        if (!$sessionId) {
            sendError('session_id is required');
        }

        $sessionId = (int) $sessionId;

        // Валидация пользователя
        $userData = $telegramAuth->validateAndGetUser($initData);
        if ($userData === false) {
            sendError('Unauthorized: Invalid Telegram data', 403);
        }

        $userTgId = $voteManager->syncUser($userData);

        // Завершаем турнир и переносим все голоса
        $success = $sessionManager->completeTournament($sessionId, $userTgId, $comment);

        if (!$success) {
            sendError('Failed to complete tournament', 500);
        }

        sendJson([
            'success' => true,
            'message' => 'Tournament completed successfully'
        ]);
    }

    // ========================================
    // ACTION: get_user_stats
    // ========================================
    if ($action === 'get_user_stats') {
        $initData = $_GET['initData'] ?? null;

        // Support POST/JSON input as well
        if (!$initData) {
            $input = getJsonInput();
            $initData = $input['initData'] ?? $input['init_data'] ?? null;
        }

        if (!$initData) {
            sendError('initData is required');
        }

        // Валидация и получение пользователя
        $userData = $telegramAuth->validateAndGetUser($initData);

        if ($userData === false) {
            sendError('Unauthorized: Invalid Telegram data', 403);
        }

        // Синхронизируем пользователя (создаем, если нет)
        $voteManager->syncUser($userData);

        // Проверяем, завершен ли турнир
        $isCompleted = $sessionManager->isTournamentCompleted($userData['id']);

        // Если турнир завершен - возвращаем статистику из основной таблицы
        if ($isCompleted) {
            $stats = $voteManager->getUserStats($userData['id']);
            $history = $voteManager->getUserVoteHistory($userData['id'], 10);

            sendJson([
                'success' => true,
                'tournament_completed' => true,
                'user' => $stats,
                'recent_votes' => $history,
            ]);
        } else {
            // Турнир не завершен - возвращаем данные сессии
            $session = $sessionManager->getOrCreateSession($userData['id']);

            if ($session) {
                $votesCount = $sessionManager->getSessionVotesCount((int) $session['id']);

                sendJson([
                    'success' => true,
                    'tournament_completed' => false,
                    'session_id' => $session['id'],
                    'votes_in_session' => $votesCount,
                    'user' => [
                        'tg_id' => $userData['id'],
                        'username' => $userData['username'] ?? null,
                        'total_votes' => $votesCount
                    ]
                ]);
            } else {
                sendError('Session not found', 404);
            }
        }
    }

    // ========================================
    // ACTION: get_matchup_stats
    // ========================================
    if ($action === 'get_matchup_stats') {
        $candidateId1 = (int) ($_GET['candidate1'] ?? 0);
        $candidateId2 = (int) ($_GET['candidate2'] ?? 0);

        if (!$candidateId1 || !$candidateId2) {
            sendError('candidate1 and candidate2 parameters are required');
        }

        $stats = $voteManager->getMatchupStats($candidateId1, $candidateId2);

        sendJson([
            'success' => true,
            'matchup' => $stats,
        ]);
    }

    // ========================================
    // ACTION: health_check
    // ========================================
    if ($action === 'health') {
        sendJson([
            'success' => true,
            'status' => 'OK',
            'timestamp' => date('Y-m-d H:i:s'),
            'dev_mode' => $config['DEV_MODE'] ?? false,
            'php_version' => PHP_VERSION,
        ]);
    }

    // ========================================
    // ACTION: test_auth (только для DEV_MODE)
    // ========================================
    if ($action === 'test_auth') {
        if (!($config['DEV_MODE'] ?? false)) {
            sendError('This endpoint is only available in DEV_MODE', 403);
        }

        $testInitData = TelegramAuth::generateTestInitData();

        sendJson([
            'success' => true,
            'message' => 'Test initData generated',
            'initData' => $testInitData,
            'note' => 'Use this initData in your API requests during development',
        ]);
    }

    // Неизвестный action
    sendError('Unknown action: ' . $action, 404);

} catch (Exception $e) {
    // Логируем ошибку
    error_log("API Error [{$action}]: " . $e->getMessage());

    // В режиме разработки показываем полный стек
    if (isset($config['DEV_MODE']) && $config['DEV_MODE']) {
        sendJson([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    } else {
        sendError('Internal server error', 500);
    }
}