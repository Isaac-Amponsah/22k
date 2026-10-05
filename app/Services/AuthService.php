<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\User;

/**
 * AuthService
 * Signing in, and confining the database connection to whoever is signed in.
 */
final class AuthService {

	/** Failed sign-ins allowed per email, and per address, inside the window. */
	private const MAX_FAILURES_PER_EMAIL      = 5;
	private const MAX_FAILURES_PER_IP_ADDRESS = 20;
	private const FAILURE_WINDOW_MINUTES      = 15;

	private const MIN_PASSWORD_LENGTH = 12;

	/** Verified when the email is unknown, so an unknown email takes as long to refuse as a wrong password. */
	private const TIMING_DECOY_HASH = '$2y$10$MfbAPCBYadZb12R4J4ur1.R21Vym5YtYrH35jgVcJAiC.QxirZPCq';

	/**
	 * Check an email and password.
	 *
	 * @return array The signed-in user (user_id, email, first_name, last_name).
	 * @throws \InvalidArgumentException With a message safe to show: wrong details, or too many attempts.
	 */
	public function attemptSignIn(string $email, string $password, string $ipAddress): array {
		$email = trim($email);

		if (
			LoginAttempt::countRecentForEmail($email, self::FAILURE_WINDOW_MINUTES) >= self::MAX_FAILURES_PER_EMAIL
			|| LoginAttempt::countRecentForIpAddress($ipAddress, self::FAILURE_WINDOW_MINUTES) >= self::MAX_FAILURES_PER_IP_ADDRESS
		) {
			throw new \InvalidArgumentException(
				'Too many failed sign-ins. Wait ' . self::FAILURE_WINDOW_MINUTES . ' minutes and try again.'
			);
		}

		$user            = $email === '' ? null : User::findByEmail($email);
		$passwordMatches = password_verify($password, $user['password_hash'] ?? self::TIMING_DECOY_HASH);

		if ($user === null || !$passwordMatches || !$user['is_active']) {
			LoginAttempt::recordFailure($email, $ipAddress);
			throw new \InvalidArgumentException('The email or password is not right.');
		}

		if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
			User::updatePasswordHash((int) $user['user_id'], password_hash($password, PASSWORD_DEFAULT));
		}
		LoginAttempt::clearForEmail($email);
		LoginAttempt::deleteOlderThan(24 * 60);
		User::recordSignIn((int) $user['user_id']);

		return [
			'user_id'    => (int) $user['user_id'],
			'email'      => $user['email'],
			'first_name' => $user['first_name'],
			'last_name'  => $user['last_name'],
		];
	}

	public function activeUser(int $userId): ?array {
		return User::findActiveById($userId);
	}

	/**
	 * From here on this request's database connection sees only this user's Clients.
	 */
	public function confineConnectionToUser(int $userId): void {
		User::scopeConnectionToUser($userId);
	}

	/**
	 * Create an accountant's account (bin/create-user.php — there is no public sign-up).
	 *
	 * @return int New user_id
	 * @throws \InvalidArgumentException
	 */
	public function createUser(string $email, string $password, string $firstName, string $lastName): int {
		$email = trim($email);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Enter a valid email address.');
		}
		if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
			throw new \InvalidArgumentException('The password needs at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
		}
		if (trim($firstName) === '' || trim($lastName) === '') {
			throw new \InvalidArgumentException('Enter a first and a last name.');
		}
		if (User::findByEmail($email) !== null) {
			throw new \InvalidArgumentException('There is already an account with this email.');
		}

		return User::insertUser($email, password_hash($password, PASSWORD_DEFAULT), trim($firstName), trim($lastName));
	}
}
