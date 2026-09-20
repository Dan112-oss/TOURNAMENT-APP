<?php
declare(strict_types=1);

/**
 * GET /api/matches/my-matches.php
 *
 * Player-only. Returns every match, across every tournament the
 * logged-in user participates in, where they're one of the two
 * players — normalized to their perspective so the frontend doesn't
 * have to re-derive "which side am I" from raw player1/player2 data.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../../core/Database.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

if (!in_array($_SESSION['role'] ?? '', ['player', 'both'], true)) {
    respond(403, ['success' => false, 'error' => 'Only player accounts can view this.']);
}

$userId = (int) $_SESSION['user_id'];

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT
            m.id, m.round, m.status, m.scheduled_deadline, m.resolution_type, m.winner_id,
            t.id AS tournament_id, t.name AS tournament_name, t.format AS tournament_format,
            p1.id AS player1_participant_id, u1.id AS player1_user_id, u1.username AS player1_username,
            p2.id AS player2_participant_id, u2.id AS player2_user_id, u2.username AS player2_username,
            latest.score_player1, latest.score_player2,
            active_result.score_player1 AS pending_score_player1,
            active_result.score_player2 AS pending_score_player2,
            active_result.status AS pending_status,
            active_result.submitted_by AS pending_submitted_by
         FROM matches m
         INNER JOIN tournaments t ON t.id = m.tournament_id
         LEFT JOIN participants p1 ON p1.id = m.player1_id
         LEFT JOIN users u1 ON u1.id = p1.user_id
         LEFT JOIN participants p2 ON p2.id = m.player2_id
         LEFT JOIN users u2 ON u2.id = p2.user_id
         LEFT JOIN (
            SELECT mr.match_id, mr.score_player1, mr.score_player2
            FROM match_results mr
            INNER JOIN (
                SELECT match_id, MAX(id) AS max_id
                FROM match_results
                WHERE status IN ('confirmed', 'host_resolved')
                GROUP BY match_id
            ) latest_ids ON latest_ids.match_id = mr.match_id AND latest_ids.max_id = mr.id
         ) latest ON latest.match_id = m.id
         LEFT JOIN (
            SELECT mr.match_id, mr.score_player1, mr.score_player2, mr.submitted_by, mr.status
            FROM match_results mr
            INNER JOIN (
                SELECT match_id, MAX(id) AS max_id
                FROM match_results
                WHERE status IN ('pending_confirmation', 'disputed')
                GROUP BY match_id
            ) active_ids ON active_ids.match_id = mr.match_id AND active_ids.max_id = mr.id
         ) active_result ON active_result.match_id = m.id
         WHERE u1.id = :user_id OR u2.id = :user_id2
         ORDER BY (m.scheduled_deadline IS NULL), m.scheduled_deadline ASC, m.id ASC"
    );
    $stmt->execute([':user_id' => $userId, ':user_id2' => $userId]);
    $rows = $stmt->fetchAll();

    $matches = array_map(static function (array $row) use ($userId): array {
        $isPlayer1 = (int) $row['player1_user_id'] === $userId;

        $me = $isPlayer1
            ? ['participant_id' => (int) $row['player1_participant_id'], 'username' => $row['player1_username']]
            : ['participant_id' => (int) $row['player2_participant_id'], 'username' => $row['player2_username']];

        $opponentParticipantId = $isPlayer1 ? $row['player2_participant_id'] : $row['player1_participant_id'];
        $opponentUsername = $isPlayer1 ? $row['player2_username'] : $row['player1_username'];
        $opponent = $opponentParticipantId !== null
            ? ['participant_id' => (int) $opponentParticipantId, 'username' => $opponentUsername]
            : null;

        $pendingSubmission = null;
        if ($row['pending_status'] !== null) {
            $pendingSubmission = [
                'score_player1' => (int) $row['pending_score_player1'],
                'score_player2' => (int) $row['pending_score_player2'],
                'status' => $row['pending_status'],
                'submitted_by_is_me' => (int) $row['pending_submitted_by'] === $userId,
            ];
        }

        return [
            'id' => (int) $row['id'],
            'round' => (int) $row['round'],
            'status' => $row['status'],
            'scheduled_deadline' => $row['scheduled_deadline'],
            'resolution_type' => $row['resolution_type'],
            'tournament' => [
                'id' => (int) $row['tournament_id'],
                'name' => $row['tournament_name'],
                'format' => $row['tournament_format'],
            ],
            'is_player1' => $isPlayer1,
            'me' => $me,
            'opponent' => $opponent,
            'score_player1' => $row['score_player1'] !== null ? (int) $row['score_player1'] : null,
            'score_player2' => $row['score_player2'] !== null ? (int) $row['score_player2'] : null,
            'winner_id' => $row['winner_id'] !== null ? (int) $row['winner_id'] : null,
            'pending_submission' => $pendingSubmission,
        ];
    }, $rows);

    respond(200, ['success' => true, 'matches' => $matches]);
} catch (Throwable $e) {
    error_log('matches/my-matches error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
