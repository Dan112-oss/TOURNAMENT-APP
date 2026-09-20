<?php
declare(strict_types=1);

/**
 * GET /api/tournaments/get.php?id=123
 * GET /api/tournaments/get.php?join_code=AB12CD
 *
 * Public — no auth required. Returns tournament details for the
 * public tournament view page. join_code is only included in the
 * response if the requester is logged in as the tournament's host
 * (it's the invite mechanism, not public info).
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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$joinCode = isset($_GET['join_code']) ? strtoupper(trim((string) $_GET['join_code'])) : '';

if ($id <= 0 && $joinCode === '') {
    respond(400, ['success' => false, 'error' => 'Either id or join_code is required.']);
}

try {
    $db = Database::getConnection();

    if ($id > 0) {
        $stmt = $db->prepare(
            "SELECT t.*, u.username AS host_username
             FROM tournaments t
             INNER JOIN users u ON u.id = t.host_id
             WHERE t.id = :id"
        );
        $stmt->execute([':id' => $id]);
    } else {
        $stmt = $db->prepare(
            "SELECT t.*, u.username AS host_username
             FROM tournaments t
             INNER JOIN users u ON u.id = t.host_id
             WHERE t.join_code = :join_code"
        );
        $stmt->execute([':join_code' => $joinCode]);
    }

    $tournament = $stmt->fetch();

    if (!$tournament) {
        respond(404, ['success' => false, 'error' => 'Tournament not found.']);
    }

    $countStmt = $db->prepare(
        "SELECT COUNT(*) AS active_count FROM participants
         WHERE tournament_id = :tournament_id AND status = 'active'"
    );
    $countStmt->execute([':tournament_id' => $tournament['id']]);
    $activeCount = (int) $countStmt->fetch()['active_count'];

    $isHost = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $tournament['host_id'];

    $response = [
        'id' => (int) $tournament['id'],
        'name' => $tournament['name'],
        'game_type' => $tournament['game_type'],
        'format' => $tournament['format'],
        'description' => $tournament['description'],
        'rules' => json_decode($tournament['rules_json'] ?? '{}', true) ?: [],
        'max_participants' => $tournament['max_participants'] !== null ? (int) $tournament['max_participants'] : null,
        'active_participant_count' => $activeCount,
        'start_date' => $tournament['start_date'],
        'status' => $tournament['status'],
        'host' => [
            'id' => (int) $tournament['host_id'],
            'username' => $tournament['host_username'],
        ],
        'created_at' => $tournament['created_at'],
    ];

    if ($isHost) {
        $response['join_code'] = $tournament['join_code'];
    }

    respond(200, ['success' => true, 'tournament' => $response]);
} catch (Throwable $e) {
    error_log('tournaments/get error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
