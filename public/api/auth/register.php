<?php
declare(strict_types=1);

/**
 * POST /api/auth/register.php
 *
 * Body (JSON): { "username": "...", "email": "...", "password": "...", "role": "host"|"player"|"both" }
 *
 * Creates the account and immediately logs the user in (starts a
 * session) — no email verification step, per project requirements.
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../../core/Validator.php';

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

$username = isset($input['username']) ? trim((string) $input['username']) : '';
$email = isset($input['email']) ? trim((string) $input['email']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$role = isset($input['role']) ? (string) $input['role'] : '';

$errors = [];

if (!Validator::isValidUsername($username)) {
    $errors['username'] = 'Username must be 3-50 characters, letters/numbers/underscores only.';
}

if (!Validator::isValidEmail($email)) {
    $errors['email'] = 'A valid email address is required.';
}

if (!Validator::isStrongPassword($password)) {
    $errors['password'] = Validator::passwordRequirementsMessage();
}

if (!Validator::isValidRole($role)) {
    $errors['role'] = "Role must be one of: 'host', 'player', 'both'.";
}

if (!empty($errors)) {
    respond(422, ['success' => false, 'errors' => $errors]);
}

try {
    $db = Database::getConnection();

    // Check uniqueness before insert so we can return a clear,
    // field-specific error rather than a generic DB failure.
    $check = $db->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $check->execute([':username' => $username, ':email' => $email]);
    $existing = $check->fetch();

    if ($existing) {
        respond(409, ['success' => false, 'error' => 'That username or email is already registered.']);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role)
         VALUES (:username, :email, :password_hash, :role)"
    );
    $insert->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => $role,
    ]);

    $userId = (int) $db->lastInsertId();

    // Log the user in immediately.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;

    respond(201, [
        'success' => true,
        'user' => [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'role' => $role,
        ],
    ]);
} catch (Throwable $e) {
    error_log('auth/register error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'An unexpected error occurred.']);
}
