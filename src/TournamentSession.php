<?php

declare(strict_types=1);

namespace VotingBracket;

use Exception;

/**
 * Класс для управления сессиями турнира
 * Предотвращает накрутку голосов через перезагрузку страницы
 */
class TournamentSession
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Получить или создать активную сессию для пользователя
     * 
     * @param int $userTgId
     * @return array|null Данные сессии или null если турнир уже завершен
     */
    public function getOrCreateSession(int $userTgId): ?array
    {
        // Проверяем, есть ли завершенная сессия
        $completed = $this->db->queryOne(
            "SELECT id FROM tournament_sessions WHERE user_tg_id = ? AND is_completed = 1",
            [$userTgId]
        );

        if ($completed) {
            return null; // Турнир уже завершен
        }

        // Ищем активную сессию
        $session = $this->db->queryOne(
            "SELECT * FROM tournament_sessions WHERE user_tg_id = ? AND is_completed = 0",
            [$userTgId]
        );

        if ($session) {
            return $session;
        }

        // Создаем новую сессию
        $initialData = json_encode([
            'started_at' => time(),
            'total_candidates' => 0,
            'votes_count' => 0
        ]);

        $sql = "INSERT INTO tournament_sessions (user_tg_id, session_data) VALUES (?, ?)";
        $this->db->execute($sql, [$userTgId, $initialData]);

        $sessionId = (int) $this->db->lastInsertId();

        return $this->db->queryOne(
            "SELECT * FROM tournament_sessions WHERE id = ?",
            [$sessionId]
        );
    }

    /**
     * Сбросить активную сессию пользователя (удалить или пометить как отмененную)
     * 
     * @param int $userTgId
     * @return void
     */
    public function resetActiveSession(int $userTgId): void
    {
        // Удаляем ЛЮБУЮ активную (не завершенную) сессию пользователя
        $session = $this->db->queryOne(
            "SELECT id FROM tournament_sessions WHERE user_tg_id = ? AND is_completed = 0",
            [$userTgId]
        );

        if ($session) {
            $this->db->execute("DELETE FROM session_votes WHERE session_id = ?", [$session['id']]);
            $this->db->execute("DELETE FROM tournament_sessions WHERE id = ?", [$session['id']]);
        }
    }

    /**
     * Добавить голос в сессию (БЕЗ записи в основную таблицу votes)
     * 
     * @param int $sessionId
     * @param int $winnerId
     * @param int $loserId
     * @param int $voteOrder Порядковый номер голоса
     * @return int ID записи
     */
    public function addVoteToSession(int $sessionId, int $winnerId, int $loserId, int $voteOrder, ?string $comment = null): int
    {
        $sql = "
            INSERT INTO session_votes (session_id, winner_id, loser_id, vote_order, comment)
            VALUES (?, ?, ?, ?, ?)
        ";

        $this->db->execute($sql, [$sessionId, $winnerId, $loserId, $voteOrder, $comment]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Получить количество голосов в сессии
     * 
     * @param int $sessionId
     * @return int
     */
    public function getSessionVotesCount(int $sessionId): int
    {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM session_votes WHERE session_id = ?",
            [$sessionId]
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Завершить турнир и перенести все голоса в основную таблицу
     * 
     * @param int $sessionId
     * @param int $userTgId
     * @param string|null $finalComment Комментарий к финалу
     * @return bool
     * @throws Exception
     */
    public function completeTournament(int $sessionId, int $userTgId, ?string $finalComment = null): bool
    {
        $this->db->beginTransaction();

        try {
            // Получаем все голоса из сессии
            $sessionVotes = $this->db->query(
                "SELECT * FROM session_votes WHERE session_id = ? ORDER BY vote_order ASC",
                [$sessionId]
            );

            if (empty($sessionVotes)) {
                throw new Exception("No votes found in session");
            }

            $voteManager = new VoteManager();

            // Переносим каждый голос в основную таблицу
            foreach ($sessionVotes as $index => $vote) {
                // Используем сохраненный комментарий голоса, или финальный для последнего
                $comment = $vote['comment'] ?? null;
                if (empty($comment) && $index === count($sessionVotes) - 1) {
                    $comment = $finalComment;
                }

                // Записываем голос напрямую через VoteManager
                // Но БЕЗ повторной проверки rate limit
                $sql = "
                    INSERT INTO votes (user_tg_id, winner_id, loser_id, comment)
                    VALUES (?, ?, ?, ?)
                ";

                $this->db->execute($sql, [
                    $userTgId,
                    $vote['winner_id'],
                    $vote['loser_id'],
                    $comment
                ]);

                // === ELO RATING UPDATE ===
                // Получаем текущие данные кандидатов
                $winner = $this->db->queryOne("SELECT elo_rating FROM candidates WHERE id = ?", [$vote['winner_id']]);
                $loser = $this->db->queryOne("SELECT elo_rating FROM candidates WHERE id = ?", [$vote['loser_id']]);

                $winnerElo = (int) ($winner['elo_rating'] ?? 1200);
                $loserElo = (int) ($loser['elo_rating'] ?? 1200);

                // FINAL MATCH BONUS: Use higher K-factor (60) for the last game
                // This ensures the tournament winner gets a visible boost
                $kFactor = ($index === count($sessionVotes) - 1) ? 60 : 32;

                // Вычисляем новые рейтинги
                [$newWinnerElo, $newLoserElo] = $voteManager->calculateElo($winnerElo, $loserElo, $kFactor);

                // Обновляем статистику победителя
                $this->db->execute(
                    "UPDATE candidates SET wins = wins + 1, matches = matches + 1, elo_rating = ? WHERE id = ?",
                    [$newWinnerElo, $vote['winner_id']]
                );

                // Обновляем статистику проигравшего
                $this->db->execute(
                    "UPDATE candidates SET matches = matches + 1, elo_rating = ? WHERE id = ?",
                    [$newLoserElo, $vote['loser_id']]
                );
            }

            // Обновляем last_vote_at у пользователя
            $this->db->execute(
                "UPDATE users SET last_vote_at = CURRENT_TIMESTAMP WHERE tg_id = ?",
                [$userTgId]
            );

            // Помечаем сессию как завершенную
            $sql = "
                UPDATE tournament_sessions 
                SET is_completed = 1, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ";
            $this->db->execute($sql, [$sessionId]);

            // Подтверждаем транзакцию
            $this->db->commit();

            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception("Failed to complete tournament: " . $e->getMessage());
        }
    }

    /**
     * Обновить данные сессии
     * 
     * @param int $sessionId
     * @param array $data
     * @return bool
     */
    public function updateSessionData(int $sessionId, array $data): bool
    {
        $jsonData = json_encode($data);

        $sql = "UPDATE tournament_sessions SET session_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $affected = $this->db->execute($sql, [$jsonData, $sessionId]);

        return $affected > 0;
    }

    /**
     * Получить данные сессии
     * 
     * @param int $sessionId
     * @return array|null
     */
    public function getSessionData(int $sessionId): ?array
    {
        $session = $this->db->queryOne(
            "SELECT session_data FROM tournament_sessions WHERE id = ?",
            [$sessionId]
        );

        if (!$session) {
            return null;
        }

        return json_decode($session['session_data'], true);
    }

    /**
     * Проверить, завершен ли турнир для пользователя
     * 
     * @param int $userTgId
     * @return bool
     */
    public function isTournamentCompleted(int $userTgId): bool
    {
        $result = $this->db->queryOne(
            "SELECT id FROM tournament_sessions WHERE user_tg_id = ? AND is_completed = 1",
            [$userTgId]
        );

        return $result !== null;
    }

    /**
     * Удалить незавершенные сессии старше N часов (cleanup)
     * 
     * @param int $hoursOld
     * @return int Количество удаленных сессий
     */
    public function cleanupOldSessions(int $hoursOld = 24): int
    {
        $sql = "
            DELETE FROM tournament_sessions 
            WHERE is_completed = 0 
            AND datetime(created_at) < datetime('now', '-{$hoursOld} hours')
        ";

        return $this->db->execute($sql);
    }
}