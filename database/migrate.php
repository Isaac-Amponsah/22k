<?php

/**
 * Migration runner: php database/migrate.php
 *
 * Connects as the database owner (DB_ADMIN_USERNAME), never as the application role. The roles and
 * the database come from the environment's seed (database/seeds/roles.<environment>.sql, kept out
 * of git). Applies each file in database/migrations/ once, in name order, then checks the isolation guarantees and fails loudly if any is missing:
 *   - the application role is not a superuser and cannot bypass row-level security;
 *   - every table with a client_id column has row-level security enabled, forced, and a policy.
 * A migration that adds a Client's table without its policy therefore does not pass.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Env;

Env::loadForEnvironment(dirname(__DIR__));
$environment = Env::environment();
echo 'Migrating ' . Env::get('DB_DATABASE', 'payroll') . ' on ' . Env::get('DB_HOST', '127.0.0.1') . " [{$environment}]\n";

$applicationRole = (string) Env::get('DB_USERNAME', '');
$ownerRole       = (string) Env::get('DB_ADMIN_USERNAME', '');

// Letters, digits and underscores only. A role name may start with a digit (22k_app), which SQL
// accepts only inside double quotes, so the name is always written quoted.
if (!preg_match('/^[A-Za-z0-9_]+$/', $applicationRole)) {
	fwrite(STDERR, "DB_USERNAME must be set and may use letters, digits and underscores only.\n");
	exit(1);
}
if ($ownerRole === '' || $ownerRole === $applicationRole) {
	fwrite(STDERR, "DB_ADMIN_USERNAME must be set and must differ from DB_USERNAME: the application never runs as the owner.\n");
	exit(1);
}
$quotedApplicationRole = '"' . $applicationRole . '"';

$ownerConnection = new PDO(
	sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '5432'), Env::get('DB_DATABASE', 'payroll')),
	$ownerRole,
	(string) Env::get('DB_ADMIN_PASSWORD', ''),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Roles are created by the environment's seed (a superuser's job), never here.
$roleExists = $ownerConnection->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
$roleExists->execute([$applicationRole]);
if (!$roleExists->fetchColumn()) {
	fwrite(STDERR, "Role {$applicationRole} does not exist. Seed this environment's roles first, as a Postgres superuser:\n"
		. "  run database/seeds/roles.{$environment}.sql in DBeaver (the file's header says how)\n");
	exit(1);
}

// The owner role creates tables only in a database it owns. A database made by hand (or by
// postgres) before the seed ran belongs to someone else; say so instead of failing on the first CREATE.
$canCreateTables = $ownerConnection->query("SELECT has_schema_privilege(current_user, 'public', 'CREATE')")->fetchColumn();
if (!$canCreateTables) {
	$databaseName = (string) Env::get('DB_DATABASE', 'payroll');
	fwrite(STDERR, "{$ownerRole} may not create tables in {$databaseName}: the database belongs to another role.\n"
		. "As a Postgres superuser (DBeaver, connected as postgres), run step 3 of database/seeds/roles.{$environment}.sql, or just:\n"
		. "  ALTER DATABASE \"{$databaseName}\" OWNER TO \"{$ownerRole}\";\n");
	exit(1);
}

$ownerConnection->exec(
	'CREATE TABLE IF NOT EXISTS schema_migrations (
		migration_name VARCHAR(190) PRIMARY KEY,
		applied_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	)'
);
$appliedMigrations = $ownerConnection->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationFiles);

foreach ($migrationFiles as $migrationFile) {
	$migrationName = basename($migrationFile);
	if (in_array($migrationName, $appliedMigrations, true)) {
		continue;
	}

	$migrationSql = str_replace('{{app_role}}', $quotedApplicationRole, (string) file_get_contents($migrationFile));
	$ownerConnection->beginTransaction();
	try {
		$ownerConnection->exec($migrationSql);
		$ownerConnection->prepare('INSERT INTO schema_migrations (migration_name) VALUES (?)')->execute([$migrationName]);
		$ownerConnection->commit();
	} catch (Throwable $migrationError) {
		$ownerConnection->rollBack();
		fwrite(STDERR, "FAILED {$migrationName}: {$migrationError->getMessage()}\n");
		exit(1);
	}
	echo "Applied {$migrationName}\n";
}

// -----------------------------------------------------------------------------
// Isolation checks
// -----------------------------------------------------------------------------
$isolationFailures = [];

$roleAttributes = $ownerConnection->prepare('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?');
$roleAttributes->execute([$applicationRole]);
$applicationRoleAttributes = $roleAttributes->fetch();
if ($applicationRoleAttributes['rolsuper'] || $applicationRoleAttributes['rolbypassrls']) {
	$isolationFailures[] = "Role {$applicationRole} is a superuser or has BYPASSRLS: row-level security would not apply to it.";
}

$ownedTables = $ownerConnection->prepare(
	"SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = ?"
);
$ownedTables->execute([$applicationRole]);
if ((int) $ownedTables->fetchColumn() > 0) {
	$isolationFailures[] = "Role {$applicationRole} owns tables: an owner can switch row-level security off.";
}

$clientTables = $ownerConnection->query(
	"SELECT table_class.relname AS table_name,
	        table_class.relrowsecurity AS security_enabled,
	        table_class.relforcerowsecurity AS security_forced,
	        (SELECT COUNT(*) FROM pg_policy WHERE pg_policy.polrelid = table_class.oid) AS policy_count
	 FROM pg_class table_class
	 JOIN pg_namespace ON pg_namespace.oid = table_class.relnamespace AND pg_namespace.nspname = 'public'
	 WHERE table_class.relkind = 'r'
	   AND (table_class.relname = 'clients' OR EXISTS (
	         SELECT 1 FROM pg_attribute
	         WHERE pg_attribute.attrelid = table_class.oid AND pg_attribute.attname = 'client_id' AND NOT pg_attribute.attisdropped
	       ))"
)->fetchAll();

foreach ($clientTables as $clientTable) {
	if (!$clientTable['security_enabled'] || !$clientTable['security_forced'] || (int) $clientTable['policy_count'] === 0) {
		$isolationFailures[] = "Table {$clientTable['table_name']} holds Client data but is not confined by a forced row-level security policy.";
	}
}

if ($isolationFailures !== []) {
	fwrite(STDERR, "ISOLATION CHECK FAILED\n - " . implode("\n - ", $isolationFailures) . "\n");
	exit(1);
}

echo 'Up to date. Isolation checks passed for ' . count($clientTables) . " tables.\n";
