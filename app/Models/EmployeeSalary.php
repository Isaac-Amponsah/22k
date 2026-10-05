<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * EmployeeSalary Model
 * One row per employee: basic salary and allowance. Kept apart from employees so the employee
 * list never carries pay. Client-scoped.
 */
final class EmployeeSalary {

	/**
	 * Active employees of a Client, each with its salary row if one is set.
	 */
	public static function listActiveForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.hire_date,
			        e.department_name, e.job_title_name,
			        s.employee_salary_id, s.basic_salary, s.allowance_mode, s.flat_allowance,
			        s.target_chargeable_income, s.updated_at AS salary_updated_at
			 FROM employees e
			 LEFT JOIN employee_salaries s ON s.employee_id = e.employee_id AND s.client_id = e.client_id
			 WHERE e.client_id = ?
			   AND e.is_active = TRUE
			 ORDER BY e.first_name ASC, e.last_name ASC, e.employee_id ASC',
			[$clientId]
		);
	}

	/**
	 * The employees a payroll run for the given month pays: active, with a salary set, and hired
	 * on or before the last day of that month.
	 *
	 * @param string $periodEnd Y-m-d, last day of the month being paid.
	 */
	public static function payableForClient(int $clientId, string $periodEnd): array {
		return Database::fetchAll(
			'SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
			        e.department_name, e.job_title_name,
			        s.basic_salary, s.allowance_mode, s.flat_allowance, s.target_chargeable_income
			 FROM employees e
			 JOIN employee_salaries s ON s.employee_id = e.employee_id AND s.client_id = e.client_id
			 WHERE e.client_id = ?
			   AND e.is_active = TRUE
			   AND (e.hire_date IS NULL OR e.hire_date <= ?)
			 ORDER BY e.first_name ASC, e.last_name ASC, e.employee_id ASC',
			[$clientId, $periodEnd]
		);
	}

	/**
	 * Set an employee's salary, replacing the one already there. The composite foreign key
	 * (client_id, employee_id) refuses an employee that is not this Client's.
	 */
	public static function upsertForEmployee(int $clientId, int $employeeId, array $salary, ?int $updatedByUserId): void {
		Database::query(
			'INSERT INTO employee_salaries
			    (client_id, employee_id, basic_salary, allowance_mode, flat_allowance, target_chargeable_income, updated_by)
			 VALUES (?, ?, ?, ?, ?, ?, ?)
			 ON CONFLICT (employee_id) DO UPDATE
			    SET basic_salary             = EXCLUDED.basic_salary,
			        allowance_mode           = EXCLUDED.allowance_mode,
			        flat_allowance           = EXCLUDED.flat_allowance,
			        target_chargeable_income = EXCLUDED.target_chargeable_income,
			        updated_by               = EXCLUDED.updated_by,
			        updated_at               = CURRENT_TIMESTAMP
			  WHERE employee_salaries.client_id = EXCLUDED.client_id',
			[
				$clientId,
				$employeeId,
				$salary['basic_salary'],
				$salary['allowance_mode'],
				$salary['flat_allowance'],
				$salary['target_chargeable_income'],
				$updatedByUserId,
			]
		);
	}
}
