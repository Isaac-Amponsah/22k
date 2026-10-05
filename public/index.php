<?php

/**
 * Front controller.
 *   /api/*          — the JSON API (config/routes.php).
 *   everything else — the built single-page app (public/spa/index.html), which routes in the browser.
 */

declare(strict_types=1);

use App\Core\HttpError;
use App\Core\Request;
use App\Core\Router;
use App\Helpers\Env;
use App\Helpers\JsonResponse;
use App\Helpers\Session;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP's built-in server (local dev): let it serve real files under public/ itself.
if (PHP_SAPI === 'cli-server' && $requestPath !== '/' && is_file(__DIR__ . $requestPath)) {
	return false;
}

if (!str_starts_with($requestPath, '/api/')) {
	$singlePageApp = __DIR__ . '/spa/index.html';
	if (!is_file($singlePageApp)) {
		http_response_code(503);
		header('Content-Type: text/plain; charset=utf-8');
		echo "The app has not been built yet. Run: cd frontend && npm run build\n";
		return;
	}
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-cache');
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: same-origin');
	readfile($singlePageApp);
	return;
}

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');

try {
	Env::loadForEnvironment(dirname(__DIR__));
	date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Africa/Accra'));
	Session::start();

	$router = new Router();
	(require dirname(__DIR__) . '/config/routes.php')($router);
	$router->dispatch(new Request());
} catch (HttpError $httpError) {
	JsonResponse::failure($httpError->getMessage(), $httpError->statusCode(), $httpError->fieldErrors());
} catch (Throwable $unexpectedError) {
	// Never shown: a database message can name tables, columns and values.
	error_log('[payroll] ' . get_class($unexpectedError) . ': ' . $unexpectedError->getMessage()
		. ' at ' . $unexpectedError->getFile() . ':' . $unexpectedError->getLine());
	JsonResponse::failure('Something went wrong. Please try again.', 500);
}
