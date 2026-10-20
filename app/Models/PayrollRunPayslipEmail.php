<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollRunPayslipEmail Model
 * The record of each payslip emailed to an employee, one per run line: added and read, never changed.
 * Client-scoped.
 */
final class PayrollRunPayslipEmail {

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
