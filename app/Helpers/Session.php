<?php

namespace App\Helpers;

/**
 * Session
 * The sign-in session. Holds who is signed in and the CSRF token — never which Client is open:
 * that travels in each request's address.
 */
final class Session {

	private const SIGNED_IN_USER_KEY = 'signed_in_user_id';
	private const CSRF_TOKEN_KEY     = 'csrf_token';
	private const LAST_ACTIVITY_KEY  = 'last_activity_at';

	/** Minutes without a request before the session is dropped. */
	private const IDLE_TIMEOUT_MINUTES = 120;

	public static function start(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}

		session_name('payroll_session');
		session_set_cookie_params([
			'lifetime' => 0,
			'path'     => '/',
			// Staging and production are HTTPS only; the cookie never travels in the clear there.
			'secure'   => self::requestIsHttps() || !Env::isLocal(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		session_start();

		$lastActivityAt = (int) ($_SESSION[self::LAST_ACTIVITY_KEY] ?? 0);
		if ($lastActivityAt > 0 && time() - $lastActivityAt > self::IDLE_TIMEOUT_MINUTES * 60) {
			self::signOut();
		}
		$_SESSION[self::LAST_ACTIVITY_KEY] = time();
	}

	public static function signedInUserId(): ?int {
		$userId = $_SESSION[self::SIGNED_IN_USER_KEY] ?? null;
		return $userId === null ? null : (int) $userId;
	}

	/** A new session id on sign-in, so an id planted before it is worthless. */
	public static function signIn(int $userId): void {
		session_regenerate_id(true);
		$_SESSION[self::SIGNED_IN_USER_KEY] = $userId;
		$_SESSION[self::CSRF_TOKEN_KEY]     = bin2hex(random_bytes(32));
	}

	public static function signOut(): void {
		$_SESSION = [];
		session_regenerate_id(true);
	}

	public static function csrfToken(): string {
		if (empty($_SESSION[self::CSRF_TOKEN_KEY])) {
			$_SESSION[self::CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
		}
		return (string) $_SESSION[self::CSRF_TOKEN_KEY];
	}

	private static function requestIsHttps(): bool {
		return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	}
}
