<?php

namespace App\Core;

/**
 * Router
 * Maps a method and path to a controller action behind a list of middleware.
 * Path parameters ({clientId}) are whole numbers and reach the action as ints, in path order.
 */
final class Router {

	/** @var array<int, array{method: string, pattern: string, action: array{0: class-string, 1: string}, middleware: class-string[]}> */
	private array $routes = [];

	/**
	 * @param array{0: class-string, 1: string} $action     [Controller class, method name]
	 * @param class-string[]                    $middleware Run in order before the action.
	 */
	public function add(string $method, string $path, array $action, array $middleware = []): void {
		$this->routes[] = [
			'method'     => $method,
			'pattern'    => '#^' . preg_replace('/\{([A-Za-z]+)\}/', '(?P<$1>\d{1,9})', $path) . '$#',
			'action'     => $action,
			'middleware' => $middleware,
		];
	}

	public function dispatch(Request $request): void {
		$pathMatched = false;

		foreach ($this->routes as $route) {
			if (!preg_match($route['pattern'], $request->path(), $pathMatches)) {
				continue;
			}
			$pathMatched = true;
			if ($route['method'] !== $request->method()) {
				continue;
			}

			$pathParameters = [];
			foreach ($pathMatches as $parameterName => $parameterValue) {
				if (is_string($parameterName)) {
					$pathParameters[$parameterName] = (int) $parameterValue;
				}
			}

			foreach ($route['middleware'] as $middlewareClass) {
				(new $middlewareClass())->handle($request, $pathParameters);
			}

			[$controllerClass, $actionMethod] = $route['action'];
			(new $controllerClass())->{$actionMethod}($request, ...array_values($pathParameters));
			return;
		}

		throw $pathMatched ? new HttpError(405, 'Method not allowed.') : new HttpError(404, 'Not found.');
	}
}
