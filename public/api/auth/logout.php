<?php
declare(strict_types=1);

/**
 * POST /api/auth/logout.php
 */

header('Content-Type: application/json');
session_start();

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

$_SESSION = [];

// Clear the session cookie itself, not just the server-side data.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

respond(200, ['success' => true]);
