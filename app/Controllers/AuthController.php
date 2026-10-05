<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Helpers\Env;
use App\Helpers\JsonResponse;
use App\Helpers\Session;
use App\Services\AuthService;

/**
 * Auth Controller
 * Sign in, sign out, and the session the app starts from.
 */
final class AuthController {

	private AuthService $authService;

	public function __construct() {
		$this->authService = new AuthService();
	}

	/** GET /api/auth/session — who is signed in (or nobody), the CSRF token, and APP_NAME for the UI. */
	public function session(Request $request): void {
		$signedInUserId = Session::signedInUserId();

		JsonResponse::success([
			'user'       => $signedInUserId === null ? null : $this->authService->activeUser($signedInUserId),
			'csrf_token' => Session::csrfToken(),
			'app_name'   => (string) Env::get('APP_NAME', ''),
		]);
	}

	/** POST /api/auth/login */
	public function login(Request $request): void {
		try {
			$user = $this->authService->attemptSignIn(
				(string) $request->input('email', ''),
				(string) $request->input('password', ''),
				$request->ipAddress()
			);
		} catch (\InvalidArgumentException $e) {
			throw new HttpError(422, $e->getMessage());
		}

		Session::signIn((int) $user['user_id']);
		JsonResponse::success(['user' => $user, 'csrf_token' => Session::csrfToken()], 'Signed in.');
	}

	/** POST /api/auth/logout */
	public function logout(Request $request): void {
		Session::signOut();
		JsonResponse::success(['csrf_token' => Session::csrfToken()], 'Signed out.');
	}
}
