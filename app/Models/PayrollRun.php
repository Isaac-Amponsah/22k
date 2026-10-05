<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollRun Model
 * A Client's payroll for one month and its lines (one per employee paid), with soft deletes.
 * Lines are a snapshot of what each employee was paid; they are rebuilt only while the run is a draft.
 */
final class PayrollRun {

	// -------------------------------------------------------------------------
	// Runs
	// -------------------------------------------------------------------------

	/**
	 * A Client's runs, latest month first.
	 */
	public static function listForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT * FROM payroll_runs
			 WHERE client_id = ? AND deleted_at IS NULL
			 ORDER BY pay_period DESC',
			[$clientId]
		);
	}

	/**
	 * One run of a Client, with the names of who created, finalised and paid it.
	 */
	public static function findForClient(int $payrollRunId, int $clientId): ?array {
		return Database::fetchOne(
			"SELECT run.*,
			        TRIM(CONCAT(creator.first_name, ' ', creator.last_name))         AS created_by_name,
			        TRIM(CONCAT(finaliser.first_name, ' ', finaliser.last_name))     AS finalised_by_name,
			        TRIM(CONCAT(paying_user.first_name, ' ', paying_user.last_name)) AS paid_by_name
			 FROM payroll_runs run
			 LEFT JOIN users creator     ON creator.user_id     = run.created_by
			 LEFT JOIN users finaliser   ON finaliser.user_id   = run.finalised_by
			 LEFT JOIN users paying_user ON paying_user.user_id = run.paid_by
			 WHERE run.payroll_run_id = ? AND run.client_id = ? AND run.deleted_at IS NULL",
			[$payrollRunId, $clientId]
		);
	}

	/**
	 * Lock a Client's run for the rest of the transaction so two requests cannot both change it.
	 */
	public static function lockForUpdate(int $payrollRunId, int $clientId): ?array {
		return Database::fetchOne(
			'SELECT * FROM payroll_runs
			 WHERE payroll_run_id = ? AND client_id = ? AND deleted_at IS NULL
			 FOR UPDATE',
			[$payrollRunId, $clientId]
		);
	}

	/**
	 * Is there already a run for this Client and month?
	 *
	 * @param string $payPeriod Y-m-d, first day of the month.
	 */
	public static function periodTaken(int $clientId, string $payPeriod): bool {
		return (bool) Database::fetchOne(
			'SELECT 1 FROM payroll_runs WHERE client_id = ? AND pay_period = ? AND deleted_at IS NULL',
			[$clientId, $payPeriod]
		);
	}

	public static function insertRun(array $runColumns): int {
		return Database::insert('payroll_runs', $runColumns, 'payroll_run_id');
	}

	public static function updateRun(int $payrollRunId, int $clientId, array $runColumns): void {
		$runColumns['updated_at'] = date('Y-m-d H:i:s');
		Database::update('payroll_runs', $runColumns, ['payroll_run_id' => $payrollRunId, 'client_id' => $clientId]);
	}

	public static function softDelete(int $payrollRunId, int $clientId): void {
		Database::update(
			'payroll_runs',
			['deleted_at' => date('Y-m-d H:i:s')],
			['payroll_run_id' => $payrollRunId, 'client_id' => $clientId]
		);
	}

	// -------------------------------------------------------------------------
	// Lines
	// -------------------------------------------------------------------------

	public static function getLines(int $payrollRunId, int $clientId): array {
		return Database::fetchAll(
			'SELECT * FROM payroll_run_lines
			 WHERE payroll_run_id = ? AND client_id = ?
			 ORDER BY line_order ASC, payroll_run_line_id ASC',
			[$payrollRunId, $clientId]
		);
	}

	public static function insertLine(array $lineColumns): int {
		return Database::insert('payroll_run_lines', $lineColumns, 'payroll_run_line_id');
	}

	public static function deleteLines(int $payrollRunId, int $clientId): void {
		Database::query(
			'DELETE FROM payroll_run_lines WHERE payroll_run_id = ? AND client_id = ?',
			[$payrollRunId, $clientId]
		);
	}
}
