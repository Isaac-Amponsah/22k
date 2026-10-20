<?php

namespace App\Helpers;

/**
 * Session
 * The sign-in session. Holds who is signed in and the CSRF token — never which Client is open:
 * that travels in each request's address.
 *
 * PHP locks a session's file for as long as it is open, so two requests of one browser would run one after
 * the other. The session is therefore read and closed as soon as the request starts; the few changes made
 * later (sign-in, sign-out, a new token) reopen it for the rest of that request.
 */
final class Session {

	private const SIGNED_IN_USER_KEY = 'signed_in_user_id';
	private const CSRF_TOKEN_KEY     = 'csrf_token';
	private const LAST_ACTIVITY_KEY  = 'last_activity_at';

	/** Minutes without a request before the session is dropped. */
	private const IDLE_TIMEOUT_MINUTES = 120;

	/** Seconds between writes of the last-activity time: a write on every request is not needed for a 120-minute timeout. */
	private const ACTIVITY_WRITE_INTERVAL_SECONDS = 60;

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
		// Read and released at once: nothing the request writes to the session before sign-in changes is lost.
		session_start(['read_and_close' => true]);

		$now            = time();
		$lastActivityAt = (int) ($_SESSION[self::LAST_ACTIVITY_KEY] ?? 0);
		if ($lastActivityAt > 0 && $now - $lastActivityAt > self::IDLE_TIMEOUT_MINUTES * 60) {
			self::signOut();
		}

		$needsActivityWrite = $now - $lastActivityAt >= self::ACTIVITY_WRITE_INTERVAL_SECONDS;
		if ($needsActivityWrite || empty($_SESSION[self::CSRF_TOKEN_KEY])) {
			self::reopen();
			$_SESSION[self::LAST_ACTIVITY_KEY] = $now;
			$_SESSION[self::CSRF_TOKEN_KEY] ??= bin2hex(random_bytes(32));
		}
		self::release();
	}

	public static function signedInUserId(): ?int {
		$userId = $_SESSION[self::SIGNED_IN_USER_KEY] ?? null;
		return $userId === null ? null : (int) $userId;
	}

	/** A new session id on sign-in, so an id planted before it is worthless. */
	public static function signIn(int $userId): void {
		self::reopen();
		session_regenerate_id(true);
		$_SESSION[self::SIGNED_IN_USER_KEY] = $userId;
		$_SESSION[self::CSRF_TOKEN_KEY]     = bin2hex(random_bytes(32));
		$_SESSION[self::LAST_ACTIVITY_KEY]  = time();
		self::release();
	}

	public static function signOut(): void {
		self::reopen();
		$_SESSION = [];
		session_regenerate_id(true);
		$_SESSION[self::CSRF_TOKEN_KEY]    = bin2hex(random_bytes(32));
		$_SESSION[self::LAST_ACTIVITY_KEY] = time();
		self::release();
	}

	public static function csrfToken(): string {
		if (empty($_SESSION[self::CSRF_TOKEN_KEY])) {
			self::reopen();
			$_SESSION[self::CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
			self::release();
		}
		return (string) $_SESSION[self::CSRF_TOKEN_KEY];
	}

	/** Open the session again, as stored now, to change it. Every change in this class comes after a reopen. */
	private static function reopen(): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			session_start();
		}
	}

	/** Write the session and drop its lock, so this browser's other requests need not wait for this one. */
	private static function release(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
	}

	private static function requestIsHttps(): bool {
		return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	}
}
