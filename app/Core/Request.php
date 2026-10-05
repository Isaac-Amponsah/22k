<?php

namespace App\Core;

/**
 * Request
 * The incoming HTTP request: method, path and a JSON (or form) body.
 */
final class Request {

	/** Largest JSON body accepted; a salaries save for a big payroll stays far below this. */
	private const MAX_JSON_BODY_BYTES = 2 * 1024 * 1024;

	private ?array $parsedBody = null;

	public function method(): string {
		return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	}

	public function path(): string {
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		return $path === '/' ? '/' : rtrim($path, '/');
	}

	public function isReadOnly(): bool {
		return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
	}

	public function header(string $headerName): string {
		return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $headerName))] ?? '');
	}

	public function ipAddress(): string {
		return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
	}

	/**
	 * The request body as an array: decoded JSON, or the posted form fields for a file upload.
	 */
	public function body(): array {
		if ($this->parsedBody !== null) {
			return $this->parsedBody;
		}

		if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
			$rawBody = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BODY_BYTES + 1);
			if (strlen($rawBody) > self::MAX_JSON_BODY_BYTES) {
				throw new HttpError(413, 'The request is too large.');
			}
			$decodedBody = $rawBody === '' ? [] : json_decode($rawBody, true);
			if (!is_array($decodedBody)) {
				throw new HttpError(400, 'The request could not be read.');
			}
			return $this->parsedBody = $decodedBody;
		}

		return $this->parsedBody = $_POST;
	}

	public function input(string $fieldName, mixed $default = null): mixed {
		return $this->body()[$fieldName] ?? $default;
	}

	public function uploadedFile(string $fieldName): ?array {
		$upload = $_FILES[$fieldName] ?? null;
		return is_array($upload) ? $upload : null;
	}
}
