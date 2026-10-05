<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * User Model
 * The accountants who sign in. Not Client data: a user owns Clients, a Client never owns a user.
 */
final class User {

	public static function findByEmail(string $email): ?array {
		return Database::fetchOne('SELECT * FROM users WHERE LOWER(email) = LOWER(?)', [$email]);
	}

	/** An active user without the password hash, or null. */
	public static function findActiveById(int $userId): ?array {
		return Database::fetchOne(
			'SELECT user_id, email, first_name, last_name FROM users WHERE user_id = ? AND is_active = TRUE',
			[$userId]
		);
	}

	public static function insertUser(string $email, string $passwordHash, string $firstName, string $lastName): int {
		return Database::insert('users', [
			'email'         => $email,
			'password_hash' => $passwordHash,
			'first_name'    => $firstName,
			'last_name'     => $lastName,
		], 'user_id');
	}

	public static function updatePasswordHash(int $userId, string $passwordHash): void {
		Database::query(
			'UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?',
			[$passwordHash, $userId]
		);
	}

	public static function recordSignIn(int $userId): void {
		Database::query('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?', [$userId]);
	}

	/**
	 * Confine this database connection to one user: the row-level security policy on clients then
	 * shows only that user's Clients.
	 */
	public static function scopeConnectionToUser(int $userId): void {
		Database::setConnectionSetting('app.user_id', (string) $userId);
	}
}
