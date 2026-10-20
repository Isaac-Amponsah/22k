<?php

namespace App\Services;

use App\Helpers\Mailer;
use App\Models\PayrollRun;
use App\Models\PayrollRunPayslipEmail;

/**
 * PayslipEmailService
 * Emails every employee of a finalised or paid run their payslip as a PDF (PayslipPdfService), one email
 * each, and records each one sent. An employee is emailed once per run: sending again emails only those
 * not yet sent theirs (added an address since, or refused by the mail server last time). An employee with
 * no email address is skipped and named, never a reason to hold the run back.
 */
final class PayslipEmailService {

	/**
	 * Who in a run gets their payslip by email, who has had it, and who has no address.
	 *
	 * @return array{recipients: array, employees_missing_email: string[], unsent_count: int, is_mail_configured: bool}
	 */
	public function payslipEmailStatusForRun(int $payrollRunId, int $clientId): array {
		$recipients            = [];
		$employeesMissingEmail = [];
		$unsentCount           = 0;

		foreach (PayrollRun::getPayslipRecipientLines($payrollRunId, $clientId) as $recipientLine) {
			$recipients[] = [
				'payroll_run_line_id' => (int) $recipientLine['payroll_run_line_id'],
				'employee_name'       => $recipientLine['employee_name'],
				'email_address'       => $recipientLine['email_address'],
				'sent_to_email'       => $recipientLine['sent_to_email'],
				'payslip_sent_at'     => $recipientLine['payslip_sent_at'],
			];
			if ($recipientLine['sent_to_email'] !== null) {
				continue;
			}
			if ($this->addressOf($recipientLine) === null) {
				$employeesMissingEmail[] = $recipientLine['employee_name'];
			} else {
				$unsentCount++;
			}
		}

		return [
			'recipients'              => $recipients,
			'employees_missing_email' => $employeesMissingEmail,
			'unsent_count'            => $unsentCount,
			'is_mail_configured'      => Mailer::isConfigured(),
		];
	}

	/**
	 * Email the payslip of every employee in the run who has an address and has not been sent theirs yet.
	 *
	 * @param array $run A run PayrollService::findRun() returned.
	 * @param array{client_name: string, ssnit_employer_number: string} $employer The Client, as its payslips name it.
	 * @param array{user_id: int, name: string, email: string} $accountant Who sends them; replies go to them.
	 * @return array{sent_count: int, failed_employees: string[], employees_missing_email: string[]}
	 * @throws \InvalidArgumentException When the run is still a draft.
	 * @throws \RuntimeException         When mail is not set up.
	 */
	public function emailPayslipsForRun(array $run, int $clientId, array $employer, array $accountant): array {
		if ($run['status'] === PayrollService::STATUS_DRAFT) {
			throw new \InvalidArgumentException('Finalise this payroll run before emailing payslips.');
		}

		$payrollRunId = (int) $run['payroll_run_id'];
		// Two requests at once (a double click, two tabs) would each find the same payslips unsent and email them twice.
		if (!PayrollRunPayslipEmail::trySendingLock($payrollRunId)) {
			throw new \InvalidArgumentException('These payslips are already being emailed. Wait a moment, then reload.');
		}
		try {
			return $this->emailUnsentPayslips($run, $payrollRunId, $clientId, $employer, $accountant);
		} finally {
			PayrollRunPayslipEmail::releaseSendingLock($payrollRunId);
		}
	}

	/**
	 * @return array{sent_count: int, failed_employees: string[], employees_missing_email: string[]}
	 */
	private function emailUnsentPayslips(array $run, int $payrollRunId, int $clientId, array $employer, array $accountant): array {
		$clientName = (string) $employer['client_name'];
		$linesById  = [];
		foreach ($run['lines'] ?? [] as $line) {
			$linesById[(int) $line['payroll_run_line_id']] = $line;
		}

		$pdfService            = new PayslipPdfService();
		$payMonthName          = date('F Y', strtotime((string) $run['pay_period']));
		$messages              = [];
		$recipientByLine       = [];
		$employeeNameByLine    = [];
		$employeesMissingEmail = [];

		foreach (PayrollRun::getPayslipRecipientLines($payrollRunId, $clientId) as $recipientLine) {
			$payrollRunLineId = (int) $recipientLine['payroll_run_line_id'];
			if ($recipientLine['sent_to_email'] !== null || !isset($linesById[$payrollRunLineId])) {
				continue;
			}
			$recipientEmail = $this->addressOf($recipientLine);
			if ($recipientEmail === null) {
				$employeesMissingEmail[] = $recipientLine['employee_name'];
				continue;
			}

			$line                                  = $linesById[$payrollRunLineId];
			$recipientByLine[$payrollRunLineId]    = $recipientEmail;
			$employeeNameByLine[$payrollRunLineId] = $recipientLine['employee_name'];
			$messages[$payrollRunLineId]           = [
				'recipient_emails' => [$recipientEmail],
				'subject'          => "Your payslip for {$payMonthName}" . ($clientName === '' ? '' : " — {$clientName}"),
				'body'             => $this->emailBody((string) $recipientLine['first_name'], $payMonthName, $clientName, $accountant['name']),
				'attachments'      => [$pdfService->pdfFilename($run, $line) => $pdfService->pdfContents($run, $line, $employer)],
			];
		}

		if ($messages === []) {
			return ['sent_count' => 0, 'failed_employees' => [], 'employees_missing_email' => $employeesMissingEmail];
		}

		$refusals = Mailer::sendPlainTextEach($messages, ['address' => $accountant['email'], 'name' => $accountant['name']]);

		$failedEmployees = [];
		foreach (array_keys($messages) as $payrollRunLineId) {
			if (isset($refusals[$payrollRunLineId])) {
				// The mail server's own words stay in the log: they can name hosts and accounts.
				error_log("Payslip email for run line {$payrollRunLineId} failed: {$refusals[$payrollRunLineId]}");
				$failedEmployees[] = $employeeNameByLine[$payrollRunLineId];
				continue;
			}
			PayrollRunPayslipEmail::insertSentPayslip(
				$clientId,
				$payrollRunId,
				$payrollRunLineId,
				$recipientByLine[$payrollRunLineId],
				$accountant['user_id']
			);
		}

		return [
			'sent_count'              => count($messages) - count($failedEmployees),
			'failed_employees'        => $failedEmployees,
			'employees_missing_email' => $employeesMissingEmail,
		];
	}

	/**
	 * One sentence on how sending went, for the accountant.
	 *
	 * @param array{sent_count: int, failed_employees: string[], employees_missing_email: string[]} $sendResult
	 */
	public function summary(array $sendResult): string {
		$sentCount = $sendResult['sent_count'];
		$sentences = [match ($sentCount) {
			0       => 'No payslips emailed.',
			1       => 'Payslip emailed to 1 employee.',
			default => "Payslips emailed to {$sentCount} employees.",
		}];
		if ($sendResult['failed_employees'] !== []) {
			$sentences[] = 'Not sent, try again: ' . implode(', ', $sendResult['failed_employees']) . '.';
		}
		if ($sendResult['employees_missing_email'] !== []) {
			$sentences[] = 'No email address: ' . implode(', ', $sendResult['employees_missing_email']) . '.';
		}
		return implode(' ', $sentences);
	}

	private function addressOf(array $recipientLine): ?string {
		$emailAddress = trim((string) ($recipientLine['email_address'] ?? ''));
		return $emailAddress === '' ? null : $emailAddress;
	}

	private function emailBody(string $firstName, string $payMonthName, string $clientName, string $accountantName): string {
		$greeting = trim($firstName) === '' ? 'Hello,' : 'Dear ' . trim($firstName) . ',';
		$employer = $clientName === '' ? '' : " from {$clientName}";
		return "{$greeting}\n\nPlease find attached your payslip for {$payMonthName}{$employer}.\n\nRegards,\n{$accountantName}";
	}
}
