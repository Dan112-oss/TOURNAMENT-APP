<?php
declare(strict_types=1);

/**
 * GET /api/standings/get.php?tournament_id=123
 *
 * Public — no auth required. Returns the current league table,
 * sorted by points, then goal difference, then goals scored.
 * Primarily meaningful for round_robin tournaments; a knockout
 * tournament will simply return an empty table (standings aren't
 * used there — see matches/list.php for bracket progress instead).
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

    $stmt = $db->prepare(
        "SELECT
            s.participant_id,
            u.username,
            s.played, s.wins, s.draws, s.losses,
            s.goals_for, s.goals_against, s.goal_difference, s.points
         FROM standings s
         INNER JOIN participants p ON p.id = s.participant_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE s.tournament_id = :tournament_id
         ORDER BY s.points DESC, s.goal_difference DESC, s.goals_for DESC, u.username ASC"
    );
    $stmt->execute([':tournament_id' => $tournamentId]);
    $rows = $stmt->fetchAll();

    // Attach 1-based position now that the ordering is settled.
    $standings = [];
    foreach ($rows as $index => $row) {
        $standings[] = [
            'position' => $index + 1,
            'participant_id' => (int) $row['participant_id'],
            'username' => $row['username'],
            'played' => (int) $row['played'],
            'wins' => (int) $row['wins'],
            'draws' => (int) $row['draws'],
            'losses' => (int) $row['losses'],
            'goals_for' => (int) $row['goals_for'],
            'goals_against' => (int) $row['goals_against'],
            'goal_difference' => (int) $row['goal_difference'],
            'points' => (int) $row['points'],
        ];
    }

    respond(200, [
        'success' => true,
        'tournament' => [
            'id' => (int) $tournament['id'],
            'name' => $tournament['name'],
            'format' => $tournament['format'],
        ],
        'standings' => $standings,
    ]);
} catch (Throwable $e) {
    error_log('standings/get error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
