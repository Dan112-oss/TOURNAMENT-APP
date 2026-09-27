<?php
declare(strict_types=1);

/**
 * POST /api/results/detect-score.php (multipart/form-data)
 * Field: image (the proof screenshot)
 *
 * Best-effort scoreline detection using Tesseract OCR, used to
 * auto-fill the score inputs on the submission form. This is NOT
 * authoritative — game HUD fonts/layouts vary a lot and Tesseract
 * is built for regular printed text, not stylized game UI, so
 * accuracy will be inconsistent. The player always sees the
 * detected value in an editable field and must still confirm it
 * before submitting; this endpoint never writes anything to the
 * database itself.
 *
 * Always responds 200 with detected either a {score1, score2}
 * object or null — detection failures are treated as "couldn't
 * detect," not hard errors, so a flaky OCR setup never blocks the
 * actual result submission flow.
 */

header('Content-Type: application/json');
session_start();

const MAX_IMAGE_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

/**
 * Very simple heuristic: look for two 1-2 digit numbers separated by
 * a dash or colon, which is how most football scorelines render
 * (e.g. "2 - 0", "2:0"). Best-effort only.
 */
function extractScoreFromText(string $text): ?array
{
    if (preg_match('/(\d{1,2})\s*[-:]\s*(\d{1,2})/', $text, $matches)) {
        return [
            'score1' => (int) $matches[1],
            'score2' => (int) $matches[2],
        ];
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'error' => 'Authentication required.']);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    respond(200, ['success' => true, 'detected' => null]);
}

$image = $_FILES['image'];

if ($image['size'] > MAX_IMAGE_BYTES) {
    respond(200, ['success' => true, 'detected' => null]);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $image['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, ALLOWED_IMAGE_MIME_TYPES, true)) {
    respond(200, ['success' => true, 'detected' => null]);
}

$extension = match ($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
};

$tempPath = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(8)) . '.' . $extension;
$detected = null;

if (move_uploaded_file($image['tmp_name'], $tempPath)) {
    try {
        $escapedPath = escapeshellarg($tempPath);
        $command = "tesseract {$escapedPath} stdout --psm 6 2>/dev/null";
        $output = shell_exec($command);

        if (is_string($output)) {
            $detected = extractScoreFromText($output);
        }
    } catch (Throwable $e) {
        error_log('results/detect-score error: ' . $e->getMessage());
        $detected = null;
    } finally {
        @unlink($tempPath);
    }
}

respond(200, ['success' => true, 'detected' => $detected]);
