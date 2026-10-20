<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * EmployeeTaxRelief Model
 * The personal tax reliefs each employee claims, and how many times (children, dependants).
 * Client-scoped.
 */
final class EmployeeTaxRelief {

	public static function listForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT employee_id, relief_code, claim_count
			 FROM employee_tax_reliefs
			 WHERE client_id = ?
			 ORDER BY employee_id ASC, relief_code ASC',
			[$clientId]
		);
	}

	/**
	 * Replace every relief one employee claims.
	 *
	 * @param array<string, int> $claimCountByReliefCode Only counts of 1 or more.
	 */
	public static function replaceForEmployee(int $clientId, int $employeeId, array $claimCountByReliefCode): void {
		Database::query('DELETE FROM employee_tax_reliefs WHERE client_id = ? AND employee_id = ?', [$clientId, $employeeId]);
		foreach ($claimCountByReliefCode as $reliefCode => $claimCount) {
			Database::query(
				'INSERT INTO employee_tax_reliefs (client_id, employee_id, relief_code, claim_count) VALUES (?, ?, ?, ?)',
				[$clientId, $employeeId, (string) $reliefCode, $claimCount]
			);
		}
	}
}
