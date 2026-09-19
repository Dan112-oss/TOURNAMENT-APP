<?php
declare(strict_types=1);

/**
 * POST /api/tournaments/join.php
 *
 * Body (JSON): { "join_code": "AB12CD" }
 *
 * Player-only (role must be 'player' or 'both'). Registers the
 * current user as a participant in the tournament matching the
 * given join code.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../core/Database.php';

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

if (!in_array($_SESSION['role'] ?? '', ['player', 'both'], true)) {
    respond(403, ['success' => false, 'error' => 'Only player accounts can join tournaments.']);
}

$userId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$joinCode = isset($input['join_code']) ? strtoupper(trim((string) $input['join_code'])) : '';

if ($joinCode === '') {
    respond(400, ['success' => false, 'error' => 'A join_code is required.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT id, name, status, max_participants FROM tournaments WHERE join_code = :join_code LIMIT 1"
    );
    $stmt->execute([':join_code' => $joinCode]);
    $tournament = $stmt->fetch();

    if (!$tournament) {
        respond(404, ['success' => false, 'error' => 'No tournament found with that join code.']);
    }

    if ($tournament['status'] !== 'open_for_registration') {
        respond(409, ['success' => false, 'error' => 'Registration is closed for this tournament.']);
    }

    if ($tournament['max_participants'] !== null) {
        $countStmt = $db->prepare(
            "SELECT COUNT(*) AS active_count FROM participants
             WHERE tournament_id = :tournament_id AND status = 'active'"
        );
        $countStmt->execute([':tournament_id' => $tournament['id']]);
        $activeCount = (int) $countStmt->fetch()['active_count'];

        if ($activeCount >= (int) $tournament['max_participants']) {
            respond(409, ['success' => false, 'error' => 'This tournament is full.']);
        }
    }

    try {
        $insert = $db->prepare(
            "INSERT INTO participants (tournament_id, user_id, status)
             VALUES (:tournament_id, :user_id, 'active')"
        );
        $insert->execute([
            ':tournament_id' => $tournament['id'],
            ':user_id' => $userId,
        ]);
    } catch (PDOException $e) {
        // Unique key (tournament_id, user_id) violation — already joined.
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            respond(409, ['success' => false, 'error' => 'You have already joined this tournament.']);
        }
        throw $e;
    }

    respond(201, [
        'success' => true,
        'participant_id' => (int) $db->lastInsertId(),
        'tournament' => [
            'id' => (int) $tournament['id'],
            'name' => $tournament['name'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('tournaments/join error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
