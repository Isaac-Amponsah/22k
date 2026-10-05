<?php

/**
 * Create an accountant's account: php bin/create-user.php <email> <first name> <last name>
 * The password is read from the terminal (or from the PAYROLL_NEW_USER_PASSWORD environment
 * variable for scripted setups), never from the command line, where it would land in shell history.
 * There is no public sign-up: this is the only way an account comes to exist.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Env;
use App\Services\AuthService;

Env::loadForEnvironment(dirname(__DIR__));

if ($argc !== 4) {
	fwrite(STDERR, "Usage: php bin/create-user.php <email> <first name> <last name>\n");
	exit(1);
}

$password = getenv('PAYROLL_NEW_USER_PASSWORD');
if ($password === false || $password === '') {
	fwrite(STDOUT, 'Password (at least 12 characters): ');
	$password = trim((string) fgets(STDIN));
}

try {
	$userId = (new AuthService())->createUser($argv[1], $password, $argv[2], $argv[3]);
} catch (InvalidArgumentException $refusal) {
	fwrite(STDERR, $refusal->getMessage() . "\n");
	exit(1);
}

echo "Created user {$userId} ({$argv[1]}).\n";
