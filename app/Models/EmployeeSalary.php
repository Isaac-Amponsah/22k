<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * EmployeeSalary Model
 * One row per employee: basic salary and voluntary Tier 3 percentage. Kept apart from employees so the
 * employee list never carries pay. Allowances and deductions are in EmployeePayItemAmount, reliefs in
 * EmployeeTaxRelief. Client-scoped.
 */
final class EmployeeSalary {

	/**
	 * Active employees of a Client, each with its salary row if one is set.
	 */
	public static function listActiveForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT e.employee_id, e.employee_code, e.first_name, e.last_name, e.hire_date,
			        e.department_name, e.job_title_name,
			        s.employee_salary_id, s.basic_salary, s.tier3_percent, s.updated_at AS salary_updated_at
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
			        e.department_name, e.job_title_name, e.ssnit_number, e.tax_identification_number,
			        s.basic_salary, s.tier3_percent
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
	 *
	 * @param array $salary basic_salary, tier3_percent
	 */
	public static function upsertForEmployee(int $clientId, int $employeeId, array $salary, ?int $updatedByUserId): void {
		Database::query(
			'INSERT INTO employee_salaries
			    (client_id, employee_id, basic_salary, tier3_percent, updated_by)
			 VALUES (?, ?, ?, ?, ?)
			 ON CONFLICT (employee_id) DO UPDATE
			    SET basic_salary  = EXCLUDED.basic_salary,
			        tier3_percent = EXCLUDED.tier3_percent,
			        updated_by    = EXCLUDED.updated_by,
			        updated_at    = CURRENT_TIMESTAMP
			  WHERE employee_salaries.client_id = EXCLUDED.client_id',
			[
				$clientId,
				$employeeId,
				$salary['basic_salary'],
				$salary['tier3_percent'],
				$updatedByUserId,
			]
		);
	}

	/**
	 * Set only an employee's basic salary (an import), keeping their Tier 3 percentage; a new salary row
	 * starts with none.
	 */
	public static function upsertBasicSalaryForEmployee(int $clientId, int $employeeId, float $basicSalary, ?int $updatedByUserId): void {
		Database::query(
			'INSERT INTO employee_salaries
			    (client_id, employee_id, basic_salary, updated_by)
			 VALUES (?, ?, ?, ?)
			 ON CONFLICT (employee_id) DO UPDATE
			    SET basic_salary = EXCLUDED.basic_salary,
			        updated_by   = EXCLUDED.updated_by,
			        updated_at   = CURRENT_TIMESTAMP
			  WHERE employee_salaries.client_id = EXCLUDED.client_id',
			[$clientId, $employeeId, $basicSalary, $updatedByUserId]
		);
	}
}
