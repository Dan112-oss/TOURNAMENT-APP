<?php
declare(strict_types=1);

/**
 * POST /api/matches/resolve.php
 *
 * Body (JSON):
 *   { "match_id": 123, "resolution": "normal", "score1": 3, "score2": 1 }
 *   { "match_id": 123, "resolution": "walkover", "winner_participant_id": 45 }
 *   { "match_id": 123, "resolution": "void" }
 *
 * Host-only. Covers three cases:
 *  - normal: host enters/corrects the actual score (e.g. resolving a dispute)
 *  - walkover: one player didn't show/submit — host awards the win
 *  - void: neither player showed/submitted — no points either side
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/MatchResolver.php';

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
$resolution = isset($input['resolution']) ? (string) $input['resolution'] : '';

if ($matchId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid match_id is required.']);
}

if (!in_array($resolution, ['normal', 'walkover', 'void'], true)) {
    respond(400, ['success' => false, 'error' => "resolution must be one of: 'normal', 'walkover', 'void'."]);
}

try {
    $db = Database::getConnection();

    // Verify the requester hosts the tournament this match belongs to.
    $stmt = $db->prepare(
        "SELECT m.id, m.player1_id, m.player2_id, m.status, t.host_id
         FROM matches m
         INNER JOIN tournaments t ON t.id = m.tournament_id
         WHERE m.id = :match_id"
    );
    $stmt->execute([':match_id' => $matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        respond(404, ['success' => false, 'error' => 'Match not found.']);
    }

    if ((int) $match['host_id'] !== $userId) {
        respond(403, ['success' => false, 'error' => 'Only the tournament host can resolve matches.']);
    }

    if (in_array($match['status'], ['completed', 'forfeited', 'void'], true)) {
        respond(409, ['success' => false, 'error' => 'This match has already been resolved.']);
    }

    $resolver = new MatchResolver($db);

    switch ($resolution) {
        case 'normal':
            $score1 = isset($input['score1']) ? filter_var($input['score1'], FILTER_VALIDATE_INT) : false;
            $score2 = isset($input['score2']) ? filter_var($input['score2'], FILTER_VALIDATE_INT) : false;

            if ($score1 === false || $score2 === false || $score1 < 0 || $score2 < 0) {
                respond(400, ['success' => false, 'error' => 'score1 and score2 must be non-negative integers.']);
            }

            $resolver->applyNormalResult($matchId, $score1, $score2, 'host_override');
            break;

        case 'walkover':
            $winnerParticipantId = isset($input['winner_participant_id']) ? (int) $input['winner_participant_id'] : 0;

            if ($winnerParticipantId <= 0) {
                respond(400, ['success' => false, 'error' => 'A valid winner_participant_id is required for a walkover.']);
            }

            $resolver->applyWalkover($matchId, $winnerParticipantId);
            break;

        case 'void':
            $resolver->applyVoid($matchId);
            break;
    }

    // If there's an outstanding match_results row (e.g. from a dispute),
    // mark it host_resolved so the review trail stays accurate.
    $updateResults = $db->prepare(
        "UPDATE match_results SET status = 'host_resolved', reviewed_by = :reviewed_by, reviewed_at = NOW()
         WHERE match_id = :match_id AND status IN ('pending_confirmation', 'disputed')"
    );
    $updateResults->execute([
        ':reviewed_by' => $userId,
        ':match_id' => $matchId,
    ]);

    respond(200, ['success' => true, 'match_id' => $matchId, 'resolution' => $resolution]);
} catch (InvalidArgumentException $e) {
    respond(400, ['success' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    respond(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('matches/resolve error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
