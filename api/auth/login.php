<?php
declare(strict_types=1);

/**
 * POST /api/auth/login.php
 *
 * Body (JSON): { "email": "...", "password": "..." }
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

$input = json_decode(file_get_contents('php://input'), true);

$email = isset($input['email']) ? trim((string) $input['email']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';

if ($email === '' || $password === '') {
    respond(400, ['success' => false, 'error' => 'Email and password are required.']);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare('SELECT id, username, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Use a generic error for both "no such user" and "wrong password"
    // so login failures don't reveal which emails are registered.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['success' => false, 'error' => 'Invalid email or password.']);
    }

    if (!(bool) $user['is_active']) {
        respond(403, ['success' => false, 'error' => 'This account has been deactivated.']);
    }

    // Regenerate the session ID on login to prevent session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
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
    error_log('auth/login error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
