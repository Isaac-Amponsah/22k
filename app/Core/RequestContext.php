<?php

namespace App\Core;

/**
 * RequestContext
 * Who is signed in and which Client's books this request works on. Written once by the
 * middleware (AuthMiddleware, ClientScopeMiddleware); controllers only read it.
 *
 * The Client comes from the request's address (/api/clients/{clientId}/…), never from the
 * session: two browser tabs open on two Clients each keep working on their own.
 */
final class RequestContext {

	private static ?array $signedInUser = null;
	private static ?array $clientInScope = null;

	public static function setSignedInUser(array $user): void {
		self::$signedInUser = $user;
	}

	public static function setClientInScope(array $client): void {
		self::$clientInScope = $client;
	}

	public static function signedInUserId(): int {
		if (self::$signedInUser === null) {
			throw new HttpError(401, 'Sign in to continue.');
		}
		return (int) self::$signedInUser['user_id'];
	}

	/** The signed-in accountant's "First Last". */
	public static function signedInUserName(): string {
		self::signedInUserId();
		return trim(self::$signedInUser['first_name'] . ' ' . self::$signedInUser['last_name']);
	}

	public static function signedInUserEmail(): string {
		self::signedInUserId();
		return (string) self::$signedInUser['email'];
	}

	/**
	 * The Client this request is confined to. Refuses when the route was not Client-scoped, so a
	 * controller can never run a Client query without one.
	 */
	public static function clientIdInScope(): int {
		if (self::$clientInScope === null) {
			throw new HttpError(404, 'Client not found.');
		}
		return (int) self::$clientInScope['client_id'];
	}

	public static function clientNameInScope(): string {
		return (string) (self::$clientInScope['client_name'] ?? '');
	}
}
