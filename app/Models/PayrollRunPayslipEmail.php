<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollRunPayslipEmail Model
 * The record of each payslip emailed to an employee, one per run line: added and read, never changed.
 * Client-scoped.
 */
final class PayrollRunPayslipEmail {

	/** First key of the advisory lock taken while a run's payslips are emailed; the run id is the second. */
	private const SENDING_LOCK_NAMESPACE = 7301;

	/**
	 * Claim the sending of one run's payslips for this connection, or false when another request has it.
	 * Held until releaseSendingLock() or the end of the connection.
	 */
	public static function trySendingLock(int $payrollRunId): bool {
		$lockRow = Database::fetchOne('SELECT pg_try_advisory_lock(?, ?) AS is_locked', [self::SENDING_LOCK_NAMESPACE, $payrollRunId]);
		return (bool) ($lockRow['is_locked'] ?? false);
	}

	public static function releaseSendingLock(int $payrollRunId): void {
		Database::query('SELECT pg_advisory_unlock(?, ?)', [self::SENDING_LOCK_NAMESPACE, $payrollRunId]);
	}

	/**
	 * Record a payslip as emailed. A line already recorded is left as it is (a second request emailed it too).
	 */
	public static function insertSentPayslip(int $clientId, int $payrollRunId, int $payrollRunLineId, string $recipientEmail, ?int $sentByUserId): void {
		Database::query(
			'INSERT INTO payroll_run_payslip_emails (client_id, payroll_run_id, payroll_run_line_id, recipient_email, sent_by)
			 VALUES (?, ?, ?, ?, ?)
			 ON CONFLICT (payroll_run_line_id) DO NOTHING',
			[$clientId, $payrollRunId, $payrollRunLineId, $recipientEmail, $sentByUserId]
		);
	}
}
