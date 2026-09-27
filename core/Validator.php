<?php
declare(strict_types=1);

/**
 * Validator
 *
 * Small, stateless validation helpers. Kept separate from any single
 * endpoint so registration, profile edits, and any future forms can
 * all share the same rules.
 */
class Validator
{
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Username: 3-50 chars, letters/numbers/underscores only.
     * Matches the `users.username` VARCHAR(50) column.
     */
    public static function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{3,50}$/', $username);
    }

    /**
     * In-game username (FC Mobile / eFootball): more permissive than
     * the login username, since in-game names often include spaces,
     * accents, dots, or hyphens. 2-50 chars.
     */
    public static function isValidGameUsername(string $gameUsername): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N} _\-\.]{2,50}$/u', $gameUsername);
    }

    /**
     * Password: at least 8 characters, at least one digit, and at
     * least one symbol (non-alphanumeric character).
     */
    public static function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        $hasDigit = (bool) preg_match('/\d/', $password);
        $hasSymbol = (bool) preg_match('/[^A-Za-z0-9]/', $password);

        return $hasDigit && $hasSymbol;
    }

    public static function passwordRequirementsMessage(): string
    {
        return 'Password must be at least 8 characters and include at least one number and one symbol.';
    }

    /**
     * @return bool true if $role is one of the allowed user roles
     */
    public static function isValidRole(string $role): bool
    {
        return in_array($role, ['host', 'player', 'both'], true);
    }
}
