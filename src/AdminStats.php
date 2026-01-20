<?php

declare(strict_types=1);

namespace VotingBracket;

/**
 * Класс для работы со статистикой админ-панели
 */
class AdminStats
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Получить общее количество логов голосований
     *
     * @return int
     */
    public function getLogsCount(): int
    {
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM votes");
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Получить логи голосований с полной информацией
     *
     * @param int $limit Количество записей (0 = все)
     * @param int $offset Смещение для пагинации
     * @return array
     */
    public function getLogs(int $limit = 100, int $offset = 0): array
    {
        $sql = "
            SELECT 
                v.id,
                v.created_at,
                v.comment,
                u.tg_id,
                u.username,
                u.full_name,
                w.id as winner_id,
                w.name as winner_name,
                w.image_path as winner_image,
                l.id as loser_id,
                l.name as loser_name,
                l.image_path as loser_image
            FROM votes v
            LEFT JOIN users u ON v.user_tg_id = u.tg_id
            LEFT JOIN candidates w ON v.winner_id = w.id
            LEFT JOIN candidates l ON v.loser_id = l.id
            ORDER BY v.created_at DESC
        ";

        if ($limit > 0) {
            $sql .= " LIMIT " . $limit . " OFFSET " . $offset;
        }

        return $this->db->query($sql);
    }

    /**
     * Получить общую статистику
     *
     * @return array
     */
    public function getOverallStats(): array
    {
        $stats = [];

        // Общее количество кандидатов
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM candidates WHERE is_active = 1");
        $stats['total_candidates'] = $result['count'] ?? 0;

        // Общее количество пользователей
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM users");
        $stats['total_users'] = $result['count'] ?? 0;

        // Общее количество голосов
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM votes");
        $stats['total_votes'] = $result['count'] ?? 0;

        // Голосов за последние 24 часа
        $result = $this->db->queryOne("
            SELECT COUNT(*) as count 
            FROM votes 
            WHERE created_at >= datetime('now', '-1 day')
        ");
        $stats['votes_24h'] = $result['count'] ?? 0;

        // Голосов за последние 7 дней
        $result = $this->db->queryOne("
            SELECT COUNT(*) as count 
            FROM votes 
            WHERE created_at >= datetime('now', '-7 days')
        ");
        $stats['votes_7d'] = $result['count'] ?? 0;

        // Самый активный пользователь
        $result = $this->db->queryOne("
            SELECT 
                u.username,
                u.full_name,
                COUNT(v.id) as vote_count
            FROM votes v
            LEFT JOIN users u ON v.user_tg_id = u.tg_id
            GROUP BY v.user_tg_id
            ORDER BY vote_count DESC
            LIMIT 1
        ");
        $stats['top_user'] = $result;

        // Кандидат с наибольшим винрейтом
        $result = $this->db->queryOne("
            SELECT 
                name,
                wins,
                matches,
                ROUND((wins * 100.0 / matches), 2) as winrate
            FROM candidates
            WHERE is_active = 1 AND matches >= 5
            ORDER BY winrate DESC, wins DESC
            LIMIT 1
        ");
        $stats['top_candidate'] = $result;

        return $stats;
    }

    /**
     * Получить топ кандидатов по количеству побед
     *
     * @param int $limit
     * @return array
     */
    public function getTopWinners(int $limit = 10): array
    {
        $sql = "
            SELECT 
                id,
                name,
                image_path,
                wins,
                matches,
                CASE 
                    WHEN matches > 0 THEN ROUND((wins * 100.0 / matches), 2)
                    ELSE 0 
                END as winrate
            FROM candidates
            WHERE is_active = 1
            ORDER BY wins DESC
            LIMIT ?
        ";

        return $this->db->query($sql, [$limit]);
    }

    /**
     * Получить активность пользователей
     *
     * @param int $limit
     * @return array
     */
    public function getUserActivity(int $limit = 20): array
    {
        $sql = "
            SELECT 
                u.tg_id,
                u.username,
                u.full_name,
                COUNT(v.id) as vote_count,
                MAX(v.created_at) as last_vote_at
            FROM users u
            LEFT JOIN votes v ON u.tg_id = v.user_tg_id
            GROUP BY u.tg_id
            ORDER BY vote_count DESC
            LIMIT ?
        ";

        return $this->db->query($sql, [$limit]);
    }

    /**
     * Получить статистику комментариев
     *
     * @return array
     */
    public function getCommentStats(): array
    {
        $stats = [];

        // Всего комментариев
        $result = $this->db->queryOne("
            SELECT COUNT(*) as count 
            FROM votes 
            WHERE comment IS NOT NULL AND comment != ''
        ");
        $stats['total_comments'] = $result['count'] ?? 0;

        // Процент голосов с комментариями
        $result = $this->db->queryOne("SELECT COUNT(*) as count FROM votes");
        $totalVotes = $result['count'] ?? 1;
        $stats['comment_percentage'] = $totalVotes > 0
            ? round(($stats['total_comments'] / $totalVotes) * 100, 2)
            : 0;

        // Последние комментарии
        $stats['recent_comments'] = $this->db->query("
            SELECT 
                v.comment,
                v.created_at,
                u.username,
                w.name as winner_name
            FROM votes v
            LEFT JOIN users u ON v.user_tg_id = u.tg_id
            LEFT JOIN candidates w ON v.winner_id = w.id
            WHERE v.comment IS NOT NULL AND v.comment != ''
            ORDER BY v.created_at DESC
            LIMIT 10
        ");

        return $stats;
    }

    /**
     * Получить статистику по датам (для графиков)
     *
     * @param int $days Количество дней назад
     * @return array
     */
    public function getVotesByDate(int $days = 30): array
    {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM votes
            WHERE created_at >= datetime('now', '-{$days} days')
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ";

        return $this->db->query($sql);
    }
}