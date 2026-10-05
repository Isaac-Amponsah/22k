<?php

namespace App\Helpers;

use PDO;

/**
 * Database
 * PDO wrapper. Connects as the application role (DB_USERNAME), which row-level security confines
 * to the signed-in accountant's Clients and the Client in scope. Only models call this class.
 */
final class Database {

	private static ?PDO $connection = null;

	public static function getConnection(): PDO {
		if (self::$connection === null) {
			self::$connection = new PDO(
				sprintf(
					'pgsql:host=%s;port=%s;dbname=%s',
					Env::get('DB_HOST', '127.0.0.1'),
					Env::get('DB_PORT', '5432'),
					Env::get('DB_DATABASE', 'payroll')
				),
				(string) Env::get('DB_USERNAME', ''),
				(string) Env::get('DB_PASSWORD', ''),
				[
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					// Never pooled across requests: the user and Client scope set on a connection die with it.
					PDO::ATTR_PERSISTENT         => false,
				]
			);
		}
		return self::$connection;
	}

	/** Drop the connection, and with it the user and Client scope set on it. */
	public static function disconnect(): void {
		self::$connection = null;
	}

	public static function query(string $sql, array $parameters = []): \PDOStatement {
		// PDO sends a PHP false as '', which Postgres refuses as a boolean.
		foreach ($parameters as $parameterKey => $parameterValue) {
			if (is_bool($parameterValue)) {
				$parameters[$parameterKey] = $parameterValue ? 'true' : 'false';
			}
		}

		$statement = self::getConnection()->prepare($sql);
		$statement->execute($parameters);
		return $statement;
	}

	public static function fetchAll(string $sql, array $parameters = []): array {
		return self::query($sql, $parameters)->fetchAll();
	}

	public static function fetchOne(string $sql, array $parameters = []): ?array {
		return self::query($sql, $parameters)->fetch() ?: null;
	}

	/**
	 * Insert one row and return its generated key.
	 *
	 * @param string $primaryKeyColumn Column whose generated value is returned.
	 */
	public static function insert(string $table, array $columnValues, string $primaryKeyColumn): int {
		$columns = array_map([self::class, 'guardIdentifier'], array_keys($columnValues));

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
			self::guardIdentifier($table),
			implode(', ', $columns),
			implode(', ', array_fill(0, count($columnValues), '?')),
			self::guardIdentifier($primaryKeyColumn)
		);

		return (int) self::query($sql, array_values($columnValues))->fetchColumn();
	}

	/**
	 * Update the rows matching every column in $whereColumnValues.
	 *
	 * @return int Rows changed.
	 */
	public static function update(string $table, array $columnValues, array $whereColumnValues): int {
		$assignments = [];
		foreach (array_keys($columnValues) as $column) {
			$assignments[] = self::guardIdentifier($column) . ' = ?';
		}
		$conditions = [];
		foreach (array_keys($whereColumnValues) as $column) {
			$conditions[] = self::guardIdentifier($column) . ' = ?';
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			self::guardIdentifier($table),
			implode(', ', $assignments),
			implode(' AND ', $conditions)
		);

		return self::query($sql, array_merge(array_values($columnValues), array_values($whereColumnValues)))->rowCount();
	}

	/**
	 * Set a connection-level setting the row-level security policies read (app.user_id, app.client_id).
	 */
	public static function setConnectionSetting(string $settingName, string $settingValue): void {
		self::query('SELECT set_config(?, ?, FALSE)', [$settingName, $settingValue]);
	}

	/**
	 * Accept only well-formed SQL identifiers as table or column names, so an array key can never
	 * smuggle raw SQL past the prepared statement.
	 */
	public static function guardIdentifier(string $identifier): string {
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
			throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
		}
		return $identifier;
	}

	public static function beginTransaction(): void {
		self::getConnection()->beginTransaction();
	}

	public static function commit(): void {
		self::getConnection()->commit();
	}

	public static function rollback(): void {
		if (self::getConnection()->inTransaction()) {
			self::getConnection()->rollBack();
		}
	}
}
