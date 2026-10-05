<?php

namespace App\Middleware;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\Session;
use App\Services\AuthService;

/**
 * AuthMiddleware
 * Refuses a request with no signed-in, active user, then confines the database connection to
 * that user: from here on the connection sees only their Clients.
 */
final class AuthMiddleware {

	public function handle(Request $request, array $pathParameters): void {
		$authService    = new AuthService();
		$signedInUserId = Session::signedInUserId();
		$signedInUser   = $signedInUserId === null ? null : $authService->activeUser($signedInUserId);

		if ($signedInUser === null) {
			Session::signOut();
			throw new HttpError(401, 'Sign in to continue.');
		}

		$authService->confineConnectionToUser((int) $signedInUser['user_id']);
		RequestContext::setSignedInUser($signedInUser);
	}
}
