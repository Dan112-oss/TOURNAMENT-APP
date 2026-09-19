<?php
declare(strict_types=1);

/**
 * GET /api/participants/list.php?tournament_id=123
 *
 * Public — no auth required (same pattern as matches/list.php).
 * Returns every participant in a tournament with their username,
 * status, missed-match count, and join date.
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

    $tournamentStmt = $db->prepare('SELECT id FROM tournaments WHERE id = :id');
    $tournamentStmt->execute([':id' => $tournamentId]);

    if (!$tournamentStmt->fetch()) {
        respond(404, ['success' => false, 'error' => 'Tournament not found.']);
    }

    $stmt = $db->prepare(
        "SELECT p.id, p.status, p.missed_match_count, p.joined_at, u.username
         FROM participants p
         INNER JOIN users u ON u.id = p.user_id
         WHERE p.tournament_id = :tournament_id
         ORDER BY p.joined_at ASC"
    );
    $stmt->execute([':tournament_id' => $tournamentId]);
    $rows = $stmt->fetchAll();

    $participants = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'status' => $row['status'],
            'missed_match_count' => (int) $row['missed_match_count'],
            'joined_at' => $row['joined_at'],
        ];
    }, $rows);

    respond(200, ['success' => true, 'participants' => $participants]);
} catch (Throwable $e) {
    error_log('participants/list error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
