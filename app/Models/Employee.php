<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * Employee Model
 * A Client's employees. Pay is not here: salaries sit in employee_salaries.
 * Employees are deactivated, never deleted, because past payroll lines point at them.
 */
final class Employee {

	/** Columns an employee can be created or edited with. */
	public const EDITABLE_COLUMNS = [
		'employee_code',
		'first_name',
		'last_name',
		'job_title_name',
		'department_name',
		'hire_date',
		'ssnit_number',
		'tax_identification_number',
		'bank_account_number',
		'bank_name',
		'bank_branch',
		'bank_sort_code',
		'is_active',
	];

	public static function listForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT employee_id, employee_code, first_name, last_name, job_title_name, department_name,
			        hire_date, ssnit_number, tax_identification_number,
			        bank_account_number, bank_name, bank_branch, bank_sort_code, is_active
			 FROM employees
			 WHERE client_id = ?
			 ORDER BY is_active DESC, first_name ASC, last_name ASC, employee_id ASC',
			[$clientId]
		);
	}

	public static function countActiveForClient(int $clientId): int {
		$counted = Database::fetchOne(
			'SELECT COUNT(*) AS active_employee_count FROM employees WHERE client_id = ? AND is_active',
			[$clientId]
		);
		return (int) ($counted['active_employee_count'] ?? 0);
	}

	public static function findForClient(int $employeeId, int $clientId): ?array {
		return Database::fetchOne(
			'SELECT employee_id, employee_code, first_name, last_name, job_title_name, department_name,
			        hire_date, ssnit_number, tax_identification_number,
			        bank_account_number, bank_name, bank_branch, bank_sort_code, is_active
			 FROM employees
			 WHERE employee_id = ? AND client_id = ?',
			[$employeeId, $clientId]
		);
	}

	public static function codeTaken(int $clientId, string $employeeCode, ?int $exceptEmployeeId = null): bool {
		return (bool) Database::fetchOne(
			'SELECT 1 FROM employees WHERE client_id = ? AND LOWER(employee_code) = LOWER(?) AND employee_id <> ?',
			[$clientId, $employeeCode, $exceptEmployeeId ?? 0]
		);
	}

	/**
	 * @param array $employeeDetails Keys from EDITABLE_COLUMNS.
	 */
	public static function insertEmployee(int $clientId, array $employeeDetails, ?int $createdByUserId): int {
		return Database::insert(
			'employees',
			['client_id' => $clientId, 'created_by' => $createdByUserId]
				+ array_intersect_key($employeeDetails, array_flip(self::EDITABLE_COLUMNS)),
			'employee_id'
		);
	}

	public static function updateEmployee(int $employeeId, int $clientId, array $employeeDetails): void {
		Database::update(
			'employees',
			array_intersect_key($employeeDetails, array_flip(self::EDITABLE_COLUMNS)) + ['updated_at' => date('Y-m-d H:i:s')],
			['employee_id' => $employeeId, 'client_id' => $clientId]
		);
	}
}
