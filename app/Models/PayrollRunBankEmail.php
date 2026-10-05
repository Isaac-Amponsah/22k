<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollRunBankEmail Model
 * The record of each payroll email sent to a Client's bank: added and read, never changed.
 */
final class PayrollRunBankEmail {

	/**
	 * @param string[] $recipientEmails
	 */
	public static function insertSentEmail(
		int $clientId,
		int $payrollRunId,
		array $recipientEmails,
		string $emailSubject,
		string $emailBody,
		string $attachmentFilename,
		?int $sentByUserId
	): void {
		Database::query(
			'INSERT INTO payroll_run_bank_emails
			        (client_id, payroll_run_id, recipient_emails, email_subject, email_body, attachment_filename, sent_by)
			 VALUES (?, ?, ?::jsonb, ?, ?, ?, ?)',
			[$clientId, $payrollRunId, json_encode(array_values($recipientEmails)), $emailSubject, $emailBody, $attachmentFilename, $sentByUserId]
		);
	}

	/**
	 * The emails sent for one run of a Client, latest first, with who sent each.
	 */
	public static function listForRun(int $payrollRunId, int $clientId): array {
		$sentEmails = Database::fetchAll(
			"SELECT sent_email.payroll_run_bank_email_id, sent_email.recipient_emails, sent_email.email_subject, sent_email.sent_at,
			        TRIM(CONCAT(sender.first_name, ' ', sender.last_name)) AS sent_by_name
			 FROM payroll_run_bank_emails sent_email
			 LEFT JOIN users sender ON sender.user_id = sent_email.sent_by
			 WHERE sent_email.payroll_run_id = ? AND sent_email.client_id = ?
			 ORDER BY sent_email.sent_at DESC, sent_email.payroll_run_bank_email_id DESC",
			[$payrollRunId, $clientId]
		);
		foreach ($sentEmails as $sentEmailIndex => $sentEmail) {
			$sentEmails[$sentEmailIndex]['recipient_emails'] = json_decode((string) $sentEmail['recipient_emails'], true) ?: [];
		}
		return $sentEmails;
	}
}
