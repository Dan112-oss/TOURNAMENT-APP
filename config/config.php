<?php
declare(strict_types=1);

// ============================================================
// Database configuration
//
// Reads from environment variables — checks Clever Cloud's default
// MySQL addon names first (MYSQL_ADDON_HOST etc., set automatically
// when you link the addon), then falls back to custom DB_* names,
// then to local defaults for XAMPP/local development.
// ============================================================

return [
    'db_host' => getenv('MYSQL_ADDON_HOST') ?: (getenv('DB_HOST') ?: 'localhost'),
    'db_name' => getenv('MYSQL_ADDON_DB') ?: (getenv('DB_NAME') ?: 'tournament_app'),
    'db_user' => getenv('MYSQL_ADDON_USER') ?: (getenv('DB_USER') ?: 'root'),
    'db_pass' => getenv('MYSQL_ADDON_PASSWORD') ?: (getenv('DB_PASSWORD') ?: ''),
    'db_port' => getenv('MYSQL_ADDON_PORT') ?: (getenv('DB_PORT') ?: '3306'),
    'db_charset' => 'utf8mb4',
];