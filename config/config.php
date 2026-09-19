<?php
declare(strict_types=1);

// ============================================================
// Database configuration
//
// Reads from environment variables first (set on Render, matching
// the Clever Cloud MySQL addon credentials), falling back to local
// defaults for development on XAMPP/local PHP server.
// ============================================================

return [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_name' => getenv('DB_NAME') ?: 'tournament_app',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASSWORD') ?: '',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_charset' => 'utf8mb4',
];
