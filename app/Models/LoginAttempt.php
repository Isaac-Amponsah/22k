<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * LoginAttempt Model
 * Failed sign-ins, counted per email and per address to slow down password guessing.
 */
final class LoginAttempt {

	public static function recordFailure(string $email, string $ipAddress): void {
		Database::query(
			'INSERT INTO login_attempts (email_lowercase, ip_address) VALUES (LOWER(?), ?)',
			[$email, $ipAddress]
		);
	}

	public static function countRecentForEmail(string $email, int $windowMinutes): int {
		$row = Database::fetchOne(
			"SELECT COUNT(*) AS failure_count FROM login_attempts
			 WHERE email_lowercase = LOWER(?) AND attempted_at > CURRENT_TIMESTAMP - (? * INTERVAL '1 minute')",
			[$email, $windowMinutes]
		);
		return (int) ($row['failure_count'] ?? 0);
	}

	public static function countRecentForEmailAndIpAddress(string $email, string $ipAddress, int $windowMinutes): int {
		$row = Database::fetchOne(
			"SELECT COUNT(*) AS failure_count FROM login_attempts
			 WHERE email_lowercase = LOWER(?) AND ip_address = ? AND attempted_at > CURRENT_TIMESTAMP - (? * INTERVAL '1 minute')",
			[$email, $ipAddress, $windowMinutes]
		);
		return (int) ($row['failure_count'] ?? 0);
	}

	public static function countRecentForIpAddress(string $ipAddress, int $windowMinutes): int {
		$row = Database::fetchOne(
			"SELECT COUNT(*) AS failure_count FROM login_attempts
			 WHERE ip_address = ? AND attempted_at > CURRENT_TIMESTAMP - (? * INTERVAL '1 minute')",
			[$ipAddress, $windowMinutes]
		);
		return (int) ($row['failure_count'] ?? 0);
	}

	public static function clearForEmail(string $email): void {
		Database::query('DELETE FROM login_attempts WHERE email_lowercase = LOWER(?)', [$email]);
	}

	public static function deleteOlderThan(int $ageMinutes): void {
		Database::query(
			"DELETE FROM login_attempts WHERE attempted_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 minute')",
			[$ageMinutes]
		);
	}
}
