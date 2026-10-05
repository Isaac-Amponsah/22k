<?php

namespace App\Helpers;

/**
 * Env
 * Settings come from two files, the second laid over the first:
 *   .env            — what is the same everywhere, and APP_ENV, which names the environment.
 *   .env.{APP_ENV}  — that environment's own settings (.env.local, .env.staging, .env.production):
 *                     its database, its credentials.
 * A real environment variable wins over both files.
 */
final class Env {

	public const ENVIRONMENT_LOCAL      = 'local';
	public const ENVIRONMENT_STAGING    = 'staging';
	public const ENVIRONMENT_PRODUCTION = 'production';

	public const ENVIRONMENTS = [self::ENVIRONMENT_LOCAL, self::ENVIRONMENT_STAGING, self::ENVIRONMENT_PRODUCTION];

	/** @var array<string, string> */
	private static array $fileValues = [];

	/**
	 * Load .env and then the file of the environment it names.
	 *
	 * @param string $projectRoot Directory holding the .env files.
	 * @throws \RuntimeException When APP_ENV is missing or unknown, or its file does not exist: running
	 *                           with another environment's database is worse than not running.
	 */
	public static function loadForEnvironment(string $projectRoot): void {
		self::$fileValues = [];
		self::loadFile($projectRoot . '/.env');

		$environment = self::environment();
		if (!in_array($environment, self::ENVIRONMENTS, true)) {
			throw new \RuntimeException('APP_ENV in .env must be one of: ' . implode(', ', self::ENVIRONMENTS) . '.');
		}

		$environmentFile = $projectRoot . '/.env.' . $environment;
		if (!is_file($environmentFile)) {
			throw new \RuntimeException("APP_ENV is {$environment} but .env.{$environment} does not exist.");
		}
		self::loadFile($environmentFile);
	}

	/** The environment this process runs in: one of ENVIRONMENTS once loadForEnvironment() has passed. */
	public static function environment(): string {
		return strtolower(trim((string) self::get('APP_ENV', '')));
	}

	public static function isLocal(): bool {
		return self::environment() === self::ENVIRONMENT_LOCAL;
	}

	public static function get(string $envKey, ?string $default = null): ?string {
		$processValue = getenv($envKey);
		if ($processValue !== false) {
			return $processValue;
		}
		return self::$fileValues[$envKey] ?? $default;
	}

	/** Later files overwrite the keys of earlier ones. */
	private static function loadFile(string $envFilePath): void {
		if (!is_file($envFilePath)) {
			return;
		}
		foreach (file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $envLine) {
			$envLine = trim($envLine);
			if ($envLine === '' || $envLine[0] === '#' || !str_contains($envLine, '=')) {
				continue;
			}
			[$envKey, $envValue] = explode('=', $envLine, 2);
			$envValue = trim($envValue);
			// "quoted value" or 'quoted value'
			if (strlen($envValue) >= 2 && ($envValue[0] === '"' || $envValue[0] === "'") && $envValue[-1] === $envValue[0]) {
				$envValue = substr($envValue, 1, -1);
			}
			self::$fileValues[trim($envKey)] = $envValue;
		}
	}
}
