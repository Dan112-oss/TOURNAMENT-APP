<?php
declare(strict_types=1);

/**
 * GET /api/tournaments/my-participations.php
 *
 * Player-only. Returns every tournament the logged-in user has
 * joined, including their own participant status (active,
 * disqualified, withdrawn) alongside the tournament's status.
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

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

if (!in_array($_SESSION['role'] ?? '', ['player', 'both'], true)) {
    respond(403, ['success' => false, 'error' => 'Only player accounts can view this.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT
            p.id AS participant_id, p.status AS participant_status, p.joined_at,
            t.id AS tournament_id, t.name, t.game_type, t.format, t.status AS tournament_status,
            t.max_participants, t.start_date,
            (SELECT COUNT(*) FROM participants pt WHERE pt.tournament_id = t.id AND pt.status = 'active') AS active_participant_count
         FROM participants p
         INNER JOIN tournaments t ON t.id = p.tournament_id
         WHERE p.user_id = :user_id
         ORDER BY p.joined_at DESC"
    );
    $stmt->execute([':user_id' => (int) $_SESSION['user_id']]);
    $rows = $stmt->fetchAll();

    $participations = array_map(static function (array $row): array {
        return [
            'participant_id' => (int) $row['participant_id'],
            'participant_status' => $row['participant_status'],
            'joined_at' => $row['joined_at'],
            'tournament' => [
                'id' => (int) $row['tournament_id'],
                'name' => $row['name'],
                'game_type' => $row['game_type'],
                'format' => $row['format'],
                'status' => $row['tournament_status'],
                'max_participants' => $row['max_participants'] !== null ? (int) $row['max_participants'] : null,
                'active_participant_count' => (int) $row['active_participant_count'],
                'start_date' => $row['start_date'],
            ],
        ];
    }, $rows);

    respond(200, ['success' => true, 'participations' => $participations]);
} catch (Throwable $e) {
    error_log('tournaments/my-participations error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
