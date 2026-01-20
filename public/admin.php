<?php

declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Candidate.php';
require_once __DIR__ . '/../src/AdminStats.php';

use VotingBracket\Candidate;
use VotingBracket\AdminStats;

// DEBUG MODE
if ($config['DEV_MODE']) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// === АВТОРИЗАЦИЯ ===
$isAuthorized = false;

// Проверяем токен в URL
if (isset($_GET['token']) && $_GET['token'] === $config['ADMIN_TOKEN']) {
    $_SESSION['is_admin'] = true;
    // Редирект без токена в URL для безопасности
    header('Location: admin.php');
    exit;
}

// Проверяем сессию
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $isAuthorized = true;
}

// Если не авторизован - показываем 403
if (!$isAuthorized) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>403 Forbidden</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-5">
            <div class="alert alert-danger">
                <h1>🔒 Access Denied</h1>
                <p>You don't have permission to access this page.</p>
                <p class="mb-0">Please use the correct admin token in URL: <code>admin.php?token=YOUR_TOKEN</code></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// === ОБРАБОТКА ДЕЙСТВИЙ ===
$message = null;
$messageType = 'success';

try {
    $candidate = new Candidate();
    $stats = new AdminStats();

    // Добавление кандидата
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            throw new Exception("Name is required");
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Image is required");
        }

        $id = $candidate->add($name, $description, $_FILES['image']);
        $message = "Candidate '{$name}' added successfully! (ID: {$id})";
    }

    // Удаление кандидата
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $candidateData = $candidate->getById($id);
        
        if ($candidateData) {
            $candidate->delete($id);
            $message = "Candidate '{$candidateData['name']}' deleted successfully!";
        } else {
            throw new Exception("Candidate not found");
        }
        
        // Редирект чтобы убрать параметры из URL
        header('Location: admin.php?tab=manage&deleted=1');
        exit;
    }

    // Выход из админки
    if (isset($_GET['action']) && $_GET['action'] === 'logout') {
        session_destroy();
        header('Location: admin.php');
        exit;
    }


// DEV MODE: Сброс всех голосов и сессий
    if (isset($_GET['action']) && $_GET['action'] === 'reset_votes' && $config['DEV_MODE']) {
        try {
            $db = VotingBracket\Database::getInstance();
            
            // Удаляем все голоса
            $db->execute("DELETE FROM votes");
            
            // Удаляем все сессии
            $db->execute("DELETE FROM tournament_sessions");
            $db->execute("DELETE FROM session_votes");
            
            // Сбрасываем статистику кандидатов
            $db->execute("UPDATE candidates SET wins = 0, matches = 0, elo_rating = 1200");
            
            // Обновляем last_vote_at у пользователей
            $db->execute("UPDATE users SET last_vote_at = CURRENT_TIMESTAMP");
            
            $message = "✅ All votes, sessions and statistics have been reset!";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Failed to reset: " . $e->getMessage();
            $messageType = 'danger';
        }
        
        header('Location: admin.php?tab=stats&reset=1');
        exit;
    }

} catch (Exception $e) {
    $message = $e->getMessage();
    $messageType = 'danger';
}

// Определяем активную вкладку
$activeTab = $_GET['tab'] ?? 'stats';

// Получаем данные для отображения
$candidates = $candidate->getAll(false);
$tierList = $candidate->getTierList();
$overallStats = $stats->getOverallStats();

// Пагинация для логов
$perPage = 50; // Записей на странице
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalLogs = $stats->getLogsCount();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
$currentPage = min($currentPage, $totalPages); // Не выходим за пределы
$offset = ($currentPage - 1) * $perPage;
$logs = $stats->getLogs($perPage, $offset);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Voting Bracket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,.08);
        }
        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card .card-body {
            padding: 1.5rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .candidate-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
        }
        .tier-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
        }
        .log-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
        }
        .has-comment {
            background-color: #fff3cd;
        }
        .tier-rank {
            font-size: 1.5rem;
            font-weight: bold;
            color: #6c757d;
        }
        .winrate-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .winrate-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s;
        }
        .preview-image {
            max-width: 200px;
            margin-top: 10px;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-shield-lock"></i> Voting Bracket Admin
            </span>
            <div>
                <span class="text-light me-3">
                    <i class="bi bi-person-circle"></i> Admin
                </span>
                <a href="?action=logout" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Сообщения -->
        <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Навигация по вкладкам -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'stats' ? 'active' : '' ?>" href="?tab=stats">
                    <i class="bi bi-bar-chart"></i> Statistics
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'manage' ? 'active' : '' ?>" href="?tab=manage">
                    <i class="bi bi-images"></i> Manage Candidates
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'tierlist' ? 'active' : '' ?>" href="?tab=tierlist">
                    <i class="bi bi-trophy"></i> Tier List
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'logs' ? 'active' : '' ?>" href="?tab=logs">
                    <i class="bi bi-clock-history"></i> Vote Logs
                </a>
            </li>
        </ul>

        <!-- === ВКЛАДКА: СТАТИСТИКА === -->
        <?php if ($activeTab === 'stats'): ?>
        <div class="row">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-value"><?= $overallStats['total_candidates'] ?></div>
                        <div>Total Candidates</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="card-body">
                        <div class="stat-value"><?= $overallStats['total_users'] ?></div>
                        <div>Total Users</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="card-body">
                        <div class="stat-value"><?= $overallStats['total_votes'] ?></div>
                        <div>Total Votes</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="card-body">
                        <div class="stat-value"><?= $overallStats['votes_24h'] ?></div>
                        <div>Votes (24h)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">




        <?php if ($config['DEV_MODE']): ?>
        
            <div class="col-12">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <i class="bi bi-exclamation-triangle-fill"></i> Danger Zone (DEV_MODE)
                    </div>
                    <div class="card-body">
                        <h5 class="card-title text-danger">Reset All Data</h5>
                        <p class="card-text">
                            This action will delete <strong>ALL votes</strong>, <strong>tournament sessions</strong>, and reset <strong>candidate statistics</strong>. 
                            <br>Candidates and images will NOT be deleted.
                        </p>
                        <a href="?action=reset_votes" 
                           class="btn btn-outline-danger"
                           onclick="return confirm('⚠️ ARE YOU SURE?\n\nThis will delete ALL voting data and cannot be undone!')">
                            <i class="bi bi-trash"></i> Reset All Votes & Stats
                        </a>
                    </div>
                </div>
            </div>
       
        <?php endif; ?>

        </div>
        </div></div>

        <?php endif; ?>

        <!-- === ВКЛАДКА: УПРАВЛЕНИЕ === -->
        <?php if ($activeTab === 'manage'): ?>
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-plus-circle"></i> Add New Candidate
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="addForm">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="mb-3">
                                <label class="form-label">Image *</label>
                                <input type="file" class="form-control" name="image" accept="image/*" required id="imageInput">
                                <div class="form-text">Max size: 5MB. Formats: JPEG, PNG, WEBP</div>
                                <img id="imagePreview" class="preview-image" style="display: none;">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" name="name" required maxlength="255">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" maxlength="1000"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload"></i> Upload Candidate
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-list"></i> All Candidates (<?= count($candidates) ?>)
                    </div>
                    <div class="card-body">
                        <?php if (empty($candidates)): ?>
                        <p class="text-muted text-center py-4">No candidates yet. Add your first one!</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Image</th>
                                        <th>Name</th>

                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($candidates as $c): ?>
                                    <tr>
                                        <td><?= $c['id'] ?></td>
                                        <td>
                                            <img src="../<?= htmlspecialchars($c['image_path']) ?>" 
                                                 class="candidate-img" 
                                                 alt="<?= htmlspecialchars($c['name']) ?>">
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                                            <?php if ($c['description']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(substr($c['description'], 0, 50)) ?><?= strlen($c['description']) > 50 ? '...' : '' ?></small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($c['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?tab=manage&action=delete&id=<?= $c['id'] ?>" 
                                               class="btn btn-danger btn-sm"
                                               onclick="return confirm('Delete <?= htmlspecialchars($c['name']) ?>?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- === ВКЛАДКА: TIER LIST === -->
        <?php if ($activeTab === 'tierlist'): ?>
        <div class="card">
            <div class="card-header bg-warning">
                <i class="bi bi-trophy-fill"></i> Tier List (Sorted by ELO)
            </div>
            <div class="card-body">
                <?php if (empty($tierList)): ?>
                <p class="text-muted text-center py-4">No voting data yet</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="60">#</th>
                                <th>Candidate</th>
                                <th width="100">ELO</th>
                                <th width="150">Wins / Matches</th>
                                <th width="200">WinRate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tierList as $index => $item): ?>
                            <tr>
                                <td>
                                    <span class="tier-rank"><?= $index + 1 ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <img src="../<?= htmlspecialchars($item['image_path']) ?>" 
                                             class="tier-img me-3" 
                                             alt="<?= htmlspecialchars($item['name']) ?>">
                                        <div>
                                            <strong><?= htmlspecialchars($item['name']) ?></strong>
                                            <?php if ($item['description']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(substr($item['description'], 0, 60)) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= $item['elo_rating'] ?? 1200 ?></strong>
                                </td>
                                <td>
                                    <strong><?= $item['wins'] ?></strong> / <?= $item['matches'] ?>
                                </td>
                                <td>
                                    <div class="winrate-bar mb-1">
                                        <div class="winrate-fill" style="width: <?= $item['winrate'] ?>%"></div>
                                    </div>
                                    <small><strong><?= $item['winrate'] ?>%</strong></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- === ВКЛАДКА: ЛОГИ === -->
        <?php if ($activeTab === 'logs'): ?>
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-clock-history"></i> Vote Logs
                </span>
                <span class="badge bg-light text-dark">
                    <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalLogs)) ?> 
                    of <?= number_format($totalLogs) ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($logs)): ?>
                <p class="text-muted text-center py-4">No votes yet</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th width="140">Date/Time</th>
                                <th width="150">User</th>
                                <th>Winner</th>
                                <th>Loser</th>
                                <th>Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr class="<?= !empty($log['comment']) ? 'has-comment' : '' ?>">
                                <td>
                                    <small><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($log['username']): ?>
                                    <a href="https://t.me/<?= htmlspecialchars($log['username']) ?>" target="_blank">
                                        @<?= htmlspecialchars($log['username']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted"><?= htmlspecialchars($log['full_name'] ?: 'User #' . $log['tg_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if ($log['winner_image']): ?>
                                        <img src="../<?= htmlspecialchars($log['winner_image']) ?>" 
                                             class="log-img me-2" 
                                             alt="">
                                        <?php endif; ?>
                                        <strong class="text-success"><?= htmlspecialchars($log['winner_name'] ?: 'Unknown') ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted"><?= htmlspecialchars($log['loser_name'] ?: 'Unknown') ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($log['comment'])): ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-chat-quote"></i>
                                    </span>
                                    <?= htmlspecialchars($log['comment']) ?>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Vote logs pagination" class="mt-4">
                    <ul class="pagination justify-content-center mb-0">
                        <!-- First & Previous -->
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?tab=logs&page=1" aria-label="First">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?tab=logs&page=<?= $currentPage - 1 ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php
                        // Calculate page range to display
                        $range = 2; // Pages before and after current
                        $startPage = max(1, $currentPage - $range);
                        $endPage = min($totalPages, $currentPage + $range);
                        
                        // Show first page if not in range
                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=logs&page=1">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">…</span>
                            </li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Page numbers -->
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?tab=logs&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php 
                        // Show last page if not in range
                        if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled">
                                <span class="page-link">…</span>
                            </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=logs&page=<?= $totalPages ?>"><?= $totalPages ?></a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Next & Last -->
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?tab=logs&page=<?= $currentPage + 1 ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?tab=logs&page=<?= $totalPages ?>" aria-label="Last">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Превью изображения перед загрузкой
        document.getElementById('imageInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('imagePreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>