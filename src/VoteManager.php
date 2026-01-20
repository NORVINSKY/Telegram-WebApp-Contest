<?php

declare(strict_types=1);

namespace VotingBracket;

use Exception;

/**
 * Класс для управления голосованием
 */
class VoteManager
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Синхронизировать пользователя Telegram с БД
     * 
     * Создает новую запись если пользователя нет,
     * или обновляет существующую
     * 
     * @param array $tgUserData Данные пользователя из Telegram
     * @return int Telegram ID пользователя
     * @throws Exception
     */
    public function syncUser(array $tgUserData): int
    {
        // Валидация обязательных полей
        if (!isset($tgUserData['id'])) {
            throw new Exception("User ID is required");
        }

        $tgId = (int) $tgUserData['id'];
        $username = $tgUserData['username'] ?? null;

        // Формируем полное имя
        $fullName = trim(
            ($tgUserData['first_name'] ?? '') . ' ' . ($tgUserData['last_name'] ?? '')
        );

        if (empty($fullName)) {
            $fullName = null;
        }

        // Проверяем, существует ли пользователь
        $existingUser = $this->db->queryOne(
            "SELECT tg_id FROM users WHERE tg_id = ?",
            [$tgId]
        );

        if ($existingUser) {
            // Обновляем существующего пользователя
            $sql = "
                UPDATE users 
                SET username = ?,
                    full_name = ?,
                    last_vote_at = CURRENT_TIMESTAMP
                WHERE tg_id = ?
            ";

            $this->db->execute($sql, [$username, $fullName, $tgId]);
        } else {
            // Создаем нового пользователя
            $sql = "
                INSERT INTO users (tg_id, username, full_name, last_vote_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ";

            $this->db->execute($sql, [$tgId, $username, $fullName]);
        }

        return $tgId;
    }

    /**
     * Зарегистрировать голос
     * 
     * @param int $userTgId ID пользователя Telegram
     * @param int $winnerId ID кандидата-победителя
     * @param int $loserId ID кандидата-проигравшего
     * @param string|null $comment Комментарий пользователя (опционально)
     * @return int ID созданной записи в таблице votes
     * @throws Exception
     */
    public function castVote(
        int $userTgId,
        int $winnerId,
        int $loserId,
        ?string $comment = null
    ): int {
        // Валидация: нельзя голосовать за один и тот же кандидат
        if ($winnerId === $loserId) {
            throw new Exception("Winner and loser cannot be the same candidate");
        }

        // Проверяем существование кандидатов
        $winner = $this->db->queryOne(
            "SELECT id, is_active, elo_rating FROM candidates WHERE id = ?",
            [$winnerId]
        );

        if (!$winner) {
            throw new Exception("Winner candidate not found");
        }

        if (!$winner['is_active']) {
            throw new Exception("Winner candidate is not active");
        }

        $loser = $this->db->queryOne(
            "SELECT id, is_active, elo_rating FROM candidates WHERE id = ?",
            [$loserId]
        );

        if (!$loser) {
            throw new Exception("Loser candidate not found");
        }

        if (!$loser['is_active']) {
            throw new Exception("Loser candidate is not active");
        }

        // Проверяем существование пользователя
        $user = $this->db->queryOne(
            "SELECT tg_id FROM users WHERE tg_id = ?",
            [$userTgId]
        );

        if (!$user) {
            throw new Exception("User not found. Call syncUser first.");
        }

        // Очищаем комментарий
        if ($comment !== null) {
            $comment = trim($comment);
            if (empty($comment)) {
                $comment = null;
            } else {
                // Ограничиваем длину комментария
                $comment = mb_substr($comment, 0, 500);
            }
        }

        // Начинаем транзакцию
        $this->db->beginTransaction();

        try {
            // Вставляем запись о голосовании
            $sql = "
                INSERT INTO votes (user_tg_id, winner_id, loser_id, comment)
                VALUES (?, ?, ?, ?)
            ";

            $this->db->execute($sql, [$userTgId, $winnerId, $loserId, $comment]);
            $voteId = (int) $this->db->lastInsertId();

            // === ELO RATING UPDATE ===
            // Получаем текущие рейтинги (если их нет, считаем 1200)
            $winnerElo = (int) ($winner['elo_rating'] ?? 1200);
            $loserElo = (int) ($loser['elo_rating'] ?? 1200);

            // Вычисляем новые рейтинги
            [$newWinnerElo, $newLoserElo] = $this->calculateElo($winnerElo, $loserElo);

            // Обновляем статистику победителя
            $sql = "
                UPDATE candidates 
                SET wins = wins + 1, 
                    matches = matches + 1,
                    elo_rating = ?
                WHERE id = ?
            ";
            $this->db->execute($sql, [$newWinnerElo, $winnerId]);

            // Обновляем статистику проигравшего
            $sql = "
                UPDATE candidates 
                SET matches = matches + 1,
                    elo_rating = ?
                WHERE id = ?
            ";
            $this->db->execute($sql, [$newLoserElo, $loserId]);

            // Подтверждаем транзакцию
            $this->db->commit();

            return $voteId;

        } catch (Exception $e) {
            // Откатываем транзакцию в случае ошибки
            $this->db->rollback();
            throw new Exception("Failed to cast vote: " . $e->getMessage());
        }
    }

    /**
     * Вычисление нового рейтинга ELO
     * 
     * @param int $winnerElo Текущий рейтинг победителя
     * @param int $loserElo Текущий рейтинг проигравшего
     * @param int $kFactor Коэффициент K (по умолчанию 32 для всех)
     * @return array [newWinnerElo, newLoserElo]
     */
    public function calculateElo(int $winnerElo, int $loserElo, int $kFactor = 32): array
    {
        // Вычисляем ожидаемый результат (Chance to win)
        // E = 1 / (1 + 10 ^ ((Rb - Ra) / 400))

        $expectedWinner = 1 / (1 + pow(10, ($loserElo - $winnerElo) / 400));
        $expectedLoser = 1 / (1 + pow(10, ($winnerElo - $loserElo) / 400));

        // Вычисляем новые рейтинги
        // R_new = R_old + K * (Actual - Expected)
        // Actual для победителя = 1, для проигравшего = 0

        $newWinnerElo = (int) round($winnerElo + $kFactor * (1 - $expectedWinner));
        $newLoserElo = (int) round($loserElo + $kFactor * (0 - $expectedLoser));

        return [$newWinnerElo, $newLoserElo];
    }

    /**
     * Получить статистику пользователя
     * 
     * @param int $userTgId
     * @return array|null
     */
    public function getUserStats(int $userTgId): ?array
    {
        $sql = "
            SELECT 
                u.tg_id,
                u.username,
                u.full_name,
                u.last_vote_at,
                COUNT(v.id) as total_votes,
                COUNT(DISTINCT DATE(v.created_at)) as active_days
            FROM users u
            LEFT JOIN votes v ON u.tg_id = v.user_tg_id
            WHERE u.tg_id = ?
            GROUP BY u.tg_id
        ";

        return $this->db->queryOne($sql, [$userTgId]);
    }

    /**
     * Получить последние голоса пользователя
     * 
     * @param int $userTgId
     * @param int $limit
     * @return array
     */
    public function getUserVoteHistory(int $userTgId, int $limit = 10): array
    {
        $sql = "
            SELECT 
                v.id,
                v.created_at,
                v.comment,
                w.name as winner_name,
                w.image_path as winner_image,
                l.name as loser_name,
                l.image_path as loser_image
            FROM votes v
            LEFT JOIN candidates w ON v.winner_id = w.id
            LEFT JOIN candidates l ON v.loser_id = l.id
            WHERE v.user_tg_id = ?
            ORDER BY v.created_at DESC
            LIMIT ?
        ";

        return $this->db->query($sql, [$userTgId, $limit]);
    }

    /**
     * Проверить, может ли пользователь голосовать
     * (опционально: можно добавить rate limiting)
     * 
     * @param int $userTgId
     * @return bool
     */
    public function canUserVote(int $userTgId): bool
    {
        // Пример: ограничение - не более 1 голоса в секунду
        $sql = "
            SELECT created_at 
            FROM votes 
            WHERE user_tg_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ";

        $lastVote = $this->db->queryOne($sql, [$userTgId]);

        if (!$lastVote) {
            return true; // Нет предыдущих голосов
        }

        // Проверяем, прошла ли секунда с последнего голоса
        $lastVoteTime = strtotime($lastVote['created_at']);
        $currentTime = time();

        return ($currentTime - $lastVoteTime) >= 1;
    }

    /**
     * Получить пару кандидатов для голосования
     * (случайные два активных кандидата)
     * 
     * @return array|null Массив с двумя кандидатами или null
     */
    public function getRandomPair(): ?array
    {
        $sql = "
            SELECT id, name, description, image_path
            FROM candidates
            WHERE is_active = 1
            ORDER BY RANDOM()
            LIMIT 2
        ";

        $candidates = $this->db->query($sql);

        if (count($candidates) < 2) {
            return null; // Недостаточно кандидатов
        }

        return [
            'candidate1' => $candidates[0],
            'candidate2' => $candidates[1],
        ];
    }

    /**
     * Получить статистику конкретного противостояния
     * 
     * @param int $candidateId1
     * @param int $candidateId2
     * @return array
     */
    public function getMatchupStats(int $candidateId1, int $candidateId2): array
    {
        // Сколько раз candidate1 побеждал candidate2
        $sql = "
            SELECT COUNT(*) as count
            FROM votes
            WHERE winner_id = ? AND loser_id = ?
        ";

        $wins1 = $this->db->queryOne($sql, [$candidateId1, $candidateId2]);

        // Сколько раз candidate2 побеждал candidate1
        $wins2 = $this->db->queryOne($sql, [$candidateId2, $candidateId1]);

        $total = ($wins1['count'] ?? 0) + ($wins2['count'] ?? 0);

        return [
            'candidate1_wins' => $wins1['count'] ?? 0,
            'candidate2_wins' => $wins2['count'] ?? 0,
            'total_matches' => $total,
            'candidate1_winrate' => $total > 0
                ? round(($wins1['count'] / $total) * 100, 2)
                : 0,
        ];
    }
}