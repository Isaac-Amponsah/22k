<?php

namespace App\Helpers;

/**
 * JsonResponse
 * Every API answer: { success, message, data } or { success: false, message, errors }.
 */
final class JsonResponse {

	public static function success(mixed $data = null, string $message = '', int $statusCode = 200): void {
		self::send($statusCode, ['success' => true, 'message' => $message, 'data' => $data]);
	}

	/**
	 * @param array<string, string> $fieldErrors Messages keyed by form field name.
	 */
	public static function failure(string $message, int $statusCode = 400, array $fieldErrors = []): void {
		self::send($statusCode, ['success' => false, 'message' => $message, 'errors' => (object) $fieldErrors]);
	}

	private static function send(int $statusCode, array $payload): void {
		http_response_code($statusCode);
		header('Content-Type: application/json; charset=utf-8');
		// Pay is confidential: no browser or proxy cache keeps an API answer.
		header('Cache-Control: no-store, private');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
