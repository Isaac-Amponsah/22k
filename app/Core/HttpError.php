<?php

namespace App\Core;

/**
 * HttpError
 * A refusal the front controller answers as JSON with the given status. Its message is shown to the user.
 */
final class HttpError extends \RuntimeException {

	/**
	 * @param array<string, string> $fieldErrors Messages keyed by form field name.
	 */
	public function __construct(private int $statusCode, string $message, private array $fieldErrors = []) {
		parent::__construct($message);
	}

	public function statusCode(): int {
		return $this->statusCode;
	}

	/** @return array<string, string> */
	public function fieldErrors(): array {
		return $this->fieldErrors;
	}
}
