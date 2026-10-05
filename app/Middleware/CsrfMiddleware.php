<?php

namespace App\Middleware;

use App\Core\HttpError;
use App\Core\Request;
use App\Helpers\Session;

/**
 * CsrfMiddleware
 * Every request that changes something must carry the session's token in X-CSRF-Token.
 */
final class CsrfMiddleware {

	public function handle(Request $request, array $pathParameters): void {
		if ($request->isReadOnly()) {
			return;
		}
		if (!hash_equals(Session::csrfToken(), $request->header('X-CSRF-Token'))) {
			throw new HttpError(419, 'Your session has expired. Reload the page and try again.');
		}
	}
}
