<?php
declare(strict_types=1);

/**
 * POST /api/results/submit.php  (multipart/form-data)
 *
 * Fields: match_id, score1, score2, proof (image file, required)
 *
 * Either player in a match can submit first. This creates a
 * match_results row in 'pending_confirmation' status and moves the
 * match to 'awaiting_confirmation'. The opponent must then confirm
 * or dispute it (see confirm.php / dispute.php).
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../core/Database.php';

const MAX_PROOF_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const UPLOAD_DIR = __DIR__ . '/../../public/uploads/proofs/';

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
$matchId = isset($_POST['match_id']) ? (int) $_POST['match_id'] : 0;
$score1 = isset($_POST['score1']) ? filter_var($_POST['score1'], FILTER_VALIDATE_INT) : false;
$score2 = isset($_POST['score2']) ? filter_var($_POST['score2'], FILTER_VALIDATE_INT) : false;

if ($matchId <= 0) {
    respond(400, ['success' => false, 'error' => 'A valid match_id is required.']);
}

if ($score1 === false || $score2 === false || $score1 < 0 || $score2 < 0) {
    respond(400, ['success' => false, 'error' => 'score1 and score2 must be non-negative integers.']);
}

if (empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    respond(400, ['success' => false, 'error' => 'A proof screenshot is required.']);
}

$proof = $_FILES['proof'];

if ($proof['size'] > MAX_PROOF_BYTES) {
    respond(400, ['success' => false, 'error' => 'Proof image must be under 5MB.']);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $proof['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
    respond(400, ['success' => false, 'error' => 'Proof image must be JPEG, PNG, or WEBP.']);
}

try {
    $db = Database::getConnection();

    // Confirm the match exists and this user is one of its two
    // participants (via their participants row in this tournament).
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

    if ($match['player1_id'] === null || $match['player2_id'] === null) {
        respond(409, ['success' => false, 'error' => 'This match does not have both players assigned yet.']);
    }

    if ((int) $match['player1_user_id'] !== $userId && (int) $match['player2_user_id'] !== $userId) {
        respond(403, ['success' => false, 'error' => 'You are not a participant in this match.']);
    }

    if ($match['status'] !== 'pending') {
        respond(409, ['success' => false, 'error' => 'A result has already been submitted for this match.']);
    }

    // Score order in the submission always maps to player1/player2,
    // regardless of who submits.
    $participantId = ((int) $match['player1_user_id'] === $userId) ? (int) $match['player1_id'] : (int) $match['player2_id'];

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $extension = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    };
    $filename = sprintf('match_%d_%s.%s', $matchId, bin2hex(random_bytes(8)), $extension);
    $destination = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($proof['tmp_name'], $destination)) {
        respond(500, ['success' => false, 'error' => 'Failed to save proof image.']);
    }

    $relativePath = 'uploads/proofs/' . $filename;

    $db->beginTransaction();

    $insert = $db->prepare(
        "INSERT INTO match_results (match_id, submitted_by, score_player1, score_player2, proof_image_path, status)
         VALUES (:match_id, :submitted_by, :score1, :score2, :proof_path, 'pending_confirmation')"
    );
    $insert->execute([
        ':match_id' => $matchId,
        ':submitted_by' => $userId,
        ':score1' => $score1,
        ':score2' => $score2,
        ':proof_path' => $relativePath,
    ]);

    $update = $db->prepare("UPDATE matches SET status = 'awaiting_confirmation' WHERE id = :id");
    $update->execute([':id' => $matchId]);

    $db->commit();

    respond(201, [
        'success' => true,
        'result_id' => (int) $db->lastInsertId(),
        'submitted_by_participant_id' => $participantId,
        'status' => 'pending_confirmation',
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('results/submit error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
