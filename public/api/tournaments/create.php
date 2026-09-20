<?php
declare(strict_types=1);

/**
 * POST /api/tournaments/create.php
 *
 * Body (JSON):
 * {
 *   "name": "...", "game_type": "FC Mobile"|"eFootball", "format": "round_robin"|"knockout",
 *   "description": "...", "max_participants": 8, "start_date": "2026-10-01",
 *   "legs": 1|2,                     // round_robin only
 *   "no_show_policy": "walkover"|"void"|"manual",
 *   "grace_period_hours": 24,
 *   "max_missed_matches": 2
 * }
 *
 * Host-only (role must be 'host' or 'both'). Knockout tournaments
 * require max_participants to be set and be a power of 2 — no byes.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../../core/Database.php';

const JOIN_CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // ambiguous chars (0/O, 1/I/L) removed
const JOIN_CODE_LENGTH = 6;
const JOIN_CODE_MAX_ATTEMPTS = 5;

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function isPowerOfTwo(int $n): bool
{
    return $n >= 2 && ($n & ($n - 1)) === 0;
}

function generateJoinCode(): string
{
    $code = '';
    for ($i = 0; $i < JOIN_CODE_LENGTH; $i++) {
        $code .= JOIN_CODE_CHARS[random_int(0, strlen(JOIN_CODE_CHARS) - 1)];
    }
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

if (!in_array($_SESSION['role'] ?? '', ['host', 'both'], true)) {
    respond(403, ['success' => false, 'error' => 'Only host accounts can create tournaments.']);
}

$input = json_decode(file_get_contents('php://input'), true);

$name = isset($input['name']) ? trim((string) $input['name']) : '';
$gameType = isset($input['game_type']) ? (string) $input['game_type'] : '';
$format = isset($input['format']) ? (string) $input['format'] : '';
$description = isset($input['description']) ? trim((string) $input['description']) : null;
$maxParticipants = isset($input['max_participants']) ? filter_var($input['max_participants'], FILTER_VALIDATE_INT) : null;
$startDate = isset($input['start_date']) ? trim((string) $input['start_date']) : null;

$errors = [];

if ($name === '' || mb_strlen($name) > 150) {
    $errors['name'] = 'Name is required and must be 150 characters or fewer.';
}

if (!in_array($gameType, ['FC Mobile', 'eFootball'], true)) {
    $errors['game_type'] = "game_type must be 'FC Mobile' or 'eFootball'.";
}

if (!in_array($format, ['round_robin', 'knockout'], true)) {
    $errors['format'] = "format must be 'round_robin' or 'knockout'.";
}

if ($maxParticipants !== null && ($maxParticipants === false || $maxParticipants < 2)) {
    $errors['max_participants'] = 'max_participants must be an integer of at least 2.';
}

if ($startDate !== null && $startDate !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$date || $date->format('Y-m-d') !== $startDate) {
        $errors['start_date'] = 'start_date must be a valid date in YYYY-MM-DD format.';
    }
} else {
    $startDate = null;
}

// Knockout tournaments must declare a power-of-2 cap up front — no
// byes are supported, so this can't be left open-ended.
if ($format === 'knockout') {
    if ($maxParticipants === null || $maxParticipants === false) {
        $errors['max_participants'] = 'Knockout tournaments require max_participants to be set.';
    } elseif (!isPowerOfTwo((int) $maxParticipants)) {
        $errors['max_participants'] = 'Knockout tournaments require max_participants to be a power of 2 (2, 4, 8, 16, 32...).';
    }
}

// Build rules_json from the host-configurable policy fields.
$legs = 1;
if ($format === 'round_robin' && isset($input['legs'])) {
    $legsInput = filter_var($input['legs'], FILTER_VALIDATE_INT);
    if ($legsInput !== 1 && $legsInput !== 2) {
        $errors['legs'] = 'legs must be 1 (single) or 2 (double/home-and-away).';
    } else {
        $legs = $legsInput;
    }
}

$noShowPolicy = isset($input['no_show_policy']) ? (string) $input['no_show_policy'] : 'manual';
if (!in_array($noShowPolicy, ['walkover', 'void', 'manual'], true)) {
    $errors['no_show_policy'] = "no_show_policy must be one of: 'walkover', 'void', 'manual'.";
}

$gracePeriodHours = isset($input['grace_period_hours']) ? filter_var($input['grace_period_hours'], FILTER_VALIDATE_INT) : 24;
if ($gracePeriodHours === false || $gracePeriodHours < 0) {
    $errors['grace_period_hours'] = 'grace_period_hours must be a non-negative integer.';
}

$maxMissedMatches = null;
if (isset($input['max_missed_matches']) && $input['max_missed_matches'] !== null) {
    $maxMissedMatches = filter_var($input['max_missed_matches'], FILTER_VALIDATE_INT);
    if ($maxMissedMatches === false || $maxMissedMatches < 0) {
        $errors['max_missed_matches'] = 'max_missed_matches must be a non-negative integer.';
    }
}

if (!empty($errors)) {
    respond(422, ['success' => false, 'errors' => $errors]);
}

$rulesJson = json_encode([
    'legs' => $format === 'round_robin' ? $legs : null,
    'no_show_policy' => $noShowPolicy,
    'grace_period_hours' => $gracePeriodHours,
    'max_missed_matches' => $maxMissedMatches,
]);

try {
    $db = Database::getConnection();

    // Generate a unique join code, retrying on the rare collision.
    $joinCode = null;
    for ($attempt = 0; $attempt < JOIN_CODE_MAX_ATTEMPTS; $attempt++) {
        $candidate = generateJoinCode();
        $check = $db->prepare('SELECT id FROM tournaments WHERE join_code = :code LIMIT 1');
        $check->execute([':code' => $candidate]);
        if (!$check->fetch()) {
            $joinCode = $candidate;
            break;
        }
    }

    if ($joinCode === null) {
        respond(500, ['success' => false, 'error' => 'Could not generate a unique join code. Please try again.']);
    }

    $insert = $db->prepare(
        "INSERT INTO tournaments
            (host_id, name, game_type, format, description, rules_json, join_code, max_participants, start_date, status)
         VALUES
            (:host_id, :name, :game_type, :format, :description, :rules_json, :join_code, :max_participants, :start_date, 'open_for_registration')"
    );
    $insert->execute([
        ':host_id' => (int) $_SESSION['user_id'],
        ':name' => $name,
        ':game_type' => $gameType,
        ':format' => $format,
        ':description' => $description,
        ':rules_json' => $rulesJson,
        ':join_code' => $joinCode,
        ':max_participants' => $maxParticipants,
        ':start_date' => $startDate,
    ]);

    $tournamentId = (int) $db->lastInsertId();

    respond(201, [
        'success' => true,
        'tournament' => [
            'id' => $tournamentId,
            'name' => $name,
            'game_type' => $gameType,
            'format' => $format,
            'join_code' => $joinCode,
            'max_participants' => $maxParticipants,
            'start_date' => $startDate,
            'status' => 'open_for_registration',
        ],
    ]);
} catch (Throwable $e) {
    error_log('tournaments/create error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
