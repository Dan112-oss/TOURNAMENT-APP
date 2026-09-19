<?php
declare(strict_types=1);

/**
 * GET /api/auth/me.php
 *
 * Returns the currently logged-in user (fresh from the DB, not just
 * the session cache — picks up role changes without requiring
 * re-login). Every page-load auth check across the app should call
 * this rather than reinventing "am I logged in" logic per page.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Not authenticated.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare('SELECT id, username, email, role, is_active FROM users WHERE id = :id');
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(bool) $user['is_active']) {
        // Session points at a user that no longer exists/is inactive.
        $_SESSION = [];
        session_destroy();
        respond(401, ['success' => false, 'error' => 'Not authenticated.']);
    }

    // Keep the session's cached role in sync in case it changed elsewhere.
    $_SESSION['role'] = $user['role'];

    respond(200, [
        'success' => true,
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('auth/me error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
