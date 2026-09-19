<?php
declare(strict_types=1);

/**
 * POST /api/participants/update.php
 *
 * Body (JSON): { "participant_id": 45, "status": "active"|"disqualified"|"withdrawn" }
 *
 * Host-only — the requester must host the tournament this
 * participant belongs to. There's no hard delete for participants
 * (matches already reference them); "Remove" in the UI maps to
 * status = 'withdrawn', and a host can reverse a mistake by setting
 * a participant back to 'active'.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../core/Database.php';

const PARTICIPANT_STATUSES = ['active', 'disqualified', 'withdrawn'];

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
$participantId = isset($input['participant_id']) ? (int) $input['participant_id'] : 0;
$status = isset($input['status']) ? (string) $input['status'] : '';

if ($participantId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid participant_id is required.']);
}

if (!in_array($status, PARTICIPANT_STATUSES, true)) {
    respond(400, ['success' => false, 'error' => 'status must be one of: ' . implode(', ', PARTICIPANT_STATUSES) . '.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT p.id, t.host_id
         FROM participants p
         INNER JOIN tournaments t ON t.id = p.tournament_id
         WHERE p.id = :participant_id"
    );
    $stmt->execute([':participant_id' => $participantId]);
    $participant = $stmt->fetch();

    if (!$participant) {
        respond(404, ['success' => false, 'error' => 'Participant not found.']);
    }

    if ((int) $participant['host_id'] !== (int) $_SESSION['user_id']) {
        respond(403, ['success' => false, 'error' => 'Only the tournament host can manage participants.']);
    }

    $update = $db->prepare('UPDATE participants SET status = :status WHERE id = :id');
    $update->execute([':status' => $status, ':id' => $participantId]);

    respond(200, ['success' => true, 'participant_id' => $participantId, 'status' => $status]);
} catch (Throwable $e) {
    error_log('participants/update error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
