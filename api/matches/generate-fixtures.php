<?php
declare(strict_types=1);

/**
 * POST /api/matches/generate-fixtures.php
 *
 * Generates fixtures for a tournament (round-robin or knockout,
 * depending on the tournament's format) and inserts them into the
 * `matches` table.
 *
 * Body (JSON): { "tournament_id": 123 }
 *
 * Authorization: the logged-in user must be the tournament's host.
 * Note: this is the same code path the scheduled cron job will call
 * once registration closes / start_date is reached — this endpoint
 * exists so it can also be triggered directly (e.g. by an admin
 * tool) while that cron job is built separately.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/FixtureGenerator.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

$input = json_decode(file_get_contents('php://input'), true);
$tournamentId = isset($input['tournament_id']) ? (int) $input['tournament_id'] : 0;

if ($tournamentId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid tournament_id is required.']);
}

try {
    $db = Database::getConnection();

    // Verify the requester is the host of this tournament before
    // touching anything.
    $stmt = $db->prepare('SELECT host_id, status FROM tournaments WHERE id = :id');
    $stmt->execute([':id' => $tournamentId]);
    $tournament = $stmt->fetch();

    if (!$tournament) {
        respond(404, ['success' => false, 'error' => 'Tournament not found.']);
    }

    if ((int) $tournament['host_id'] !== (int) $_SESSION['user_id']) {
        respond(403, ['success' => false, 'error' => 'Only the tournament host can generate fixtures.']);
    }

    if (in_array($tournament['status'], ['in_progress', 'completed'], true)) {
        respond(409, ['success' => false, 'error' => 'Fixtures have already been generated for this tournament.']);
    }

    $generator = new FixtureGenerator($db);
    $matches = $generator->generate($tournamentId);

    // Registration is now closed and fixtures exist — move the
    // tournament into the in_progress state.
    $update = $db->prepare('UPDATE tournaments SET status = :status WHERE id = :id');
    $update->execute([':status' => 'in_progress', ':id' => $tournamentId]);

    respond(200, [
        'success' => true,
        'matches_created' => count($matches),
        'matches' => $matches,
    ]);
} catch (InvalidArgumentException $e) {
    respond(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    respond(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('generate-fixtures error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
