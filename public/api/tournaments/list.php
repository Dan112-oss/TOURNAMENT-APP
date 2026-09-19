<?php
declare(strict_types=1);

/**
 * GET /api/tournaments/list.php?game_type=FC+Mobile&status=open_for_registration&limit=20&offset=0
 *
 * Public — no auth required. Browsable directory of tournaments.
 * 'draft' tournaments are never listable here (host-only, private
 * until registration opens).
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../core/Database.php';

const DEFAULT_LIMIT = 20;
const MAX_LIMIT = 50;
const LISTABLE_STATUSES = ['open_for_registration', 'in_progress', 'completed', 'cancelled'];

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$gameType = isset($_GET['game_type']) ? (string) $_GET['game_type'] : null;
$status = isset($_GET['status']) ? (string) $_GET['status'] : null;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : DEFAULT_LIMIT;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

$errors = [];

if ($gameType !== null && !in_array($gameType, ['FC Mobile', 'eFootball'], true)) {
    $errors['game_type'] = "game_type must be 'FC Mobile' or 'eFootball'.";
}

if ($status !== null && !in_array($status, LISTABLE_STATUSES, true)) {
    $errors['status'] = 'status must be one of: ' . implode(', ', LISTABLE_STATUSES) . '.';
}

if ($limit < 1 || $limit > MAX_LIMIT) {
    $limit = DEFAULT_LIMIT;
}

if ($offset < 0) {
    $offset = 0;
}

if (!empty($errors)) {
    respond(422, ['success' => false, 'errors' => $errors]);
}

try {
    $db = Database::getConnection();

    $conditions = ["t.status != 'draft'"];
    $params = [];

    if ($gameType !== null) {
        $conditions[] = 't.game_type = :game_type';
        $params[':game_type'] = $gameType;
    }

    if ($status !== null) {
        $conditions[] = 't.status = :status';
        $params[':status'] = $status;
    }

    $whereClause = implode(' AND ', $conditions);

    $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM tournaments t WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['total'];

    $sql =
        "SELECT
            t.id, t.name, t.game_type, t.format, t.max_participants, t.start_date, t.status, t.created_at,
            u.username AS host_username,
            (SELECT COUNT(*) FROM participants pt WHERE pt.tournament_id = t.id AND pt.status = 'active') AS active_participant_count
         FROM tournaments t
         INNER JOIN users u ON u.id = t.host_id
         WHERE {$whereClause}
         ORDER BY t.created_at DESC
         LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $tournaments = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'game_type' => $row['game_type'],
            'format' => $row['format'],
            'host_username' => $row['host_username'],
            'max_participants' => $row['max_participants'] !== null ? (int) $row['max_participants'] : null,
            'active_participant_count' => (int) $row['active_participant_count'],
            'start_date' => $row['start_date'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    respond(200, [
        'success' => true,
        'tournaments' => $tournaments,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ],
    ]);
} catch (Throwable $e) {
    error_log('tournaments/list error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
