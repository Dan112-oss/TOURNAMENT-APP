<?php
declare(strict_types=1);

/**
 * POST /api/results/dispute.php
 *
 * Body (JSON): { "match_id": 123, "reason": "optional text" }
 *
 * The opponent (not the submitter) flags a pending result as
 * incorrect. This does NOT resolve anything — it marks the match
 * 'disputed' so the host can step in via /api/matches/resolve.php.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

$userId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$matchId = isset($input['match_id']) ? (int) $input['match_id'] : 0;
$reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

if ($matchId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid match_id is required.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT m.id, m.status, m.player1_id, m.player2_id, p1.user_id AS player1_user_id, p2.user_id AS player2_user_id
         FROM matches m
         LEFT JOIN participants p1 ON p1.id = m.player1_id
         LEFT JOIN participants p2 ON p2.id = m.player2_id
         WHERE m.id = :match_id"
    );
    $stmt->execute([':match_id' => $matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        respond(404, ['success' => false, 'error' => 'Match not found.']);
    }

    if ((int) $match['player1_user_id'] !== $userId && (int) $match['player2_user_id'] !== $userId) {
        respond(403, ['success' => false, 'error' => 'You are not a participant in this match.']);
    }

    if ($match['status'] !== 'awaiting_confirmation') {
        respond(409, ['success' => false, 'error' => 'This match has no pending result to dispute.']);
    }

    $resultStmt = $db->prepare(
        "SELECT id, submitted_by FROM match_results
         WHERE match_id = :match_id AND status = 'pending_confirmation'
         ORDER BY submitted_at DESC
         LIMIT 1"
    );
    $resultStmt->execute([':match_id' => $matchId]);
    $result = $resultStmt->fetch();

    if (!$result) {
        respond(409, ['success' => false, 'error' => 'No pending result found for this match.']);
    }

    if ((int) $result['submitted_by'] === $userId) {
        respond(403, ['success' => false, 'error' => 'You cannot dispute a result you submitted yourself.']);
    }

    $db->beginTransaction();

    $updateResult = $db->prepare("UPDATE match_results SET status = 'disputed' WHERE id = :id");
    $updateResult->execute([':id' => $result['id']]);

    $updateMatch = $db->prepare("UPDATE matches SET status = 'disputed' WHERE id = :id");
    $updateMatch->execute([':id' => $matchId]);

    $db->commit();

    respond(200, [
        'success' => true,
        'match_id' => $matchId,
        'status' => 'disputed',
        'reason' => $reason,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('results/dispute error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
