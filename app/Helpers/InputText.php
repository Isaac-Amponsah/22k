<?php

namespace App\Helpers;

/**
 * InputText
 * A value from a request body as text. A JSON body can carry an array or object where a form sends text;
 * cast with (string), that becomes the word "Array" and would be stored. Such a value is read as blank instead,
 * so the field's own validation refuses it.
 */
final class InputText {

	public static function of(mixed $requestValue): string {
		return is_scalar($requestValue) ? (string) $requestValue : '';
	}
}
