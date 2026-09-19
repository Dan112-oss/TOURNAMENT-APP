<?php
declare(strict_types=1);

/**
 * GET /api/matches/list.php?tournament_id=123[&round=2]
 *
 * Public — no auth required. Returns every fixture for a tournament,
 * grouped by round, with both players' usernames and the latest
 * approved score attached. Works for both round_robin (flat rounds)
 * and knockout (bracket rounds, using bracket_position for ordering).
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../core/Database.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$tournamentId = isset($_GET['tournament_id']) ? (int) $_GET['tournament_id'] : 0;
$roundFilter = isset($_GET['round']) ? (int) $_GET['round'] : null;

if ($tournamentId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid tournament_id is required.']);
}

try {
    $db = Database::getConnection();

    $tournamentStmt = $db->prepare('SELECT id, name, format FROM tournaments WHERE id = :id');
    $tournamentStmt->execute([':id' => $tournamentId]);
    $tournament = $tournamentStmt->fetch();

    if (!$tournament) {
        respond(404, ['success' => false, 'error' => 'Tournament not found.']);
    }

    $sql =
        "SELECT
            m.id, m.round, m.bracket_position, m.status, m.scheduled_deadline,
            m.resolution_type, m.winner_id,
            p1.id AS player1_participant_id, u1.username AS player1_username,
            p2.id AS player2_participant_id, u2.username AS player2_username,
            latest.score_player1, latest.score_player2
         FROM matches m
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
         WHERE m.tournament_id = :tournament_id";

    $params = [':tournament_id' => $tournamentId];

    if ($roundFilter !== null) {
        $sql .= ' AND m.round = :round';
        $params[':round'] = $roundFilter;
    }

    $sql .= ' ORDER BY m.round ASC, m.bracket_position ASC, m.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $matchesByRound = [];
    foreach ($rows as $row) {
        $round = (int) $row['round'];

        $matchesByRound[$round][] = [
            'id' => (int) $row['id'],
            'round' => $round,
            'bracket_position' => $row['bracket_position'] !== null ? (int) $row['bracket_position'] : null,
            'status' => $row['status'],
            'scheduled_deadline' => $row['scheduled_deadline'],
            'resolution_type' => $row['resolution_type'],
            'player1' => $row['player1_participant_id'] !== null ? [
                'participant_id' => (int) $row['player1_participant_id'],
                'username' => $row['player1_username'],
            ] : null,
            'player2' => $row['player2_participant_id'] !== null ? [
                'participant_id' => (int) $row['player2_participant_id'],
                'username' => $row['player2_username'],
            ] : null,
            'score_player1' => $row['score_player1'] !== null ? (int) $row['score_player1'] : null,
            'score_player2' => $row['score_player2'] !== null ? (int) $row['score_player2'] : null,
            'winner_id' => $row['winner_id'] !== null ? (int) $row['winner_id'] : null,
        ];
    }

    // Re-key as a list of { round, matches } so JSON key order (and
    // JS consumers) don't have to rely on numeric object key ordering.
    $rounds = [];
    foreach ($matchesByRound as $round => $matches) {
        $rounds[] = ['round' => $round, 'matches' => $matches];
    }

    respond(200, [
        'success' => true,
        'tournament' => [
            'id' => (int) $tournament['id'],
            'name' => $tournament['name'],
            'format' => $tournament['format'],
        ],
        'rounds' => $rounds,
    ]);
} catch (Throwable $e) {
    error_log('matches/list error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
