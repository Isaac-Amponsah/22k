<?php

namespace App\Services;

use App\Helpers\Mailer;
use App\Models\ClientBankEmailSetting;
use App\Models\PayrollRunBankEmail;

/**
 * BankPayrollEmailService
 * Sends a Client's payroll run to its bank by email: who at the bank receives it and the words of
 * the email are the Client's own settings; the bank payment file (BankPaymentFileService) is attached; every email sent is recorded.
 * Only a finalised or paid run is sent: a draft's figures can still change.
 */
final class BankPayrollEmailService {

	private const MOST_RECIPIENTS    = 10;
	private const BANK_NAME_MAX      = 150;
	private const EMAIL_ADDRESS_MAX  = 190;
	private const SUBJECT_MAX_LENGTH = 200;
	private const BODY_MAX_LENGTH    = 5000;

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * A Client's bank email settings; one that has saved none starts from the default template.
	 *
	 * @return array{bank_name: ?string, recipient_emails: string[], email_subject_template: string, email_body_template: string}
	 */
	public function settingsForClient(int $clientId): array {
		$savedSetting = ClientBankEmailSetting::findForClient($clientId);

		return [
			'bank_name'              => $savedSetting['bank_name'] ?? null,
			'recipient_emails'       => $savedSetting['recipient_emails'] ?? [],
			'email_subject_template' => $savedSetting['email_subject_template'] ?? BankEmailTemplate::DEFAULT_SUBJECT,
			'email_body_template'    => $savedSetting['email_body_template'] ?? BankEmailTemplate::DEFAULT_BODY,
		];
	}

	/**
	 * @return array<string, string> Errors keyed by form field name; empty when the settings were saved.
	 */
	public function saveSettings(int $clientId, ?int $userId, array $input): array {
		$errors = [];

		$bankName = trim((string) ($input['bank_name'] ?? ''));
		if (mb_strlen($bankName) > self::BANK_NAME_MAX) {
			$errors['bank_name'] = 'Keep this to ' . self::BANK_NAME_MAX . ' characters.';
		}

		$recipients = $this->validateRecipientEmails($input['recipient_emails'] ?? []);
		if ($recipients['error'] !== null) {
			$errors['recipient_emails'] = $recipients['error'];
		}

		$words = $this->validateSubjectAndBody($input['email_subject_template'] ?? '', $input['email_body_template'] ?? '');
		foreach ($words['errors'] as $field => $error) {
			$errors["email_{$field}_template"] = $error;
		}

		if ($errors !== []) {
			return $errors;
		}

		ClientBankEmailSetting::upsertForClient($clientId, [
			'bank_name'              => $bankName === '' ? null : $bankName,
			'recipient_emails'       => $recipients['emails'],
			'email_subject_template' => $words['subject'],
			'email_body_template'    => $words['body'],
		], $userId);

		return [];
	}

	// -------------------------------------------------------------------------
	// Sending a run
	// -------------------------------------------------------------------------

	/**
	 * The email as it would go out for a run, ready to be read over and adjusted, and what has been sent already.
	 *
	 * @param array $run A run PayrollService::findRun() returned.
	 * @return array{recipient_emails: string[], email_subject: string, email_body: string, attachment_filename: string,
	 *               employees_missing_account_details: string[], is_mail_configured: bool, sent_emails: array}
	 */
	public function emailDraftForRun(array $run, int $clientId, string $clientName, string $accountantName): array {
		$setting = $this->settingsForClient($clientId);
		$render  = static fn(string $template): string => BankEmailTemplate::render(
			$template,
			$run,
			$clientName,
			$setting['bank_name'],
			$accountantName
		);

		$bankPaymentFileService = new BankPaymentFileService();
		$payrollRunId           = (int) $run['payroll_run_id'];

		return [
			'recipient_emails'                  => $setting['recipient_emails'],
			'email_subject'                     => BankEmailTemplate::singleLine($render($setting['email_subject_template'])),
			'email_body'                        => $render($setting['email_body_template']),
			'attachment_filename'               => $bankPaymentFileService->xlsxFilename($run, $clientName),
			'employees_missing_account_details' => $bankPaymentFileService->employeesMissingAccountDetails($payrollRunId, $clientId),
			'is_mail_configured'                => Mailer::isConfigured(),
			'sent_emails'                       => PayrollRunBankEmail::listForRun($payrollRunId, $clientId),
		];
	}

	/**
	 * Email a run to the Client's bank with the bank payment file attached, and record it.
	 *
	 * @param array                                $run        A run PayrollService::findRun() returned.
	 * @param array{user_id: int, name: string, email: string} $accountant Who sends it; the bank's replies go to them.
	 * @throws \InvalidArgumentException For anything the accountant can fix (draft run, no recipients, a missing account, empty words).
	 * @throws \RuntimeException         When mail is not set up or the mail server refuses the message.
	 */
	public function sendRunToBank(array $run, int $clientId, string $clientName, array $accountant, string $emailSubject, string $emailBody): void {
		if ($run['status'] === PayrollService::STATUS_DRAFT) {
			throw new \InvalidArgumentException('Finalise this payroll run before sending it to the bank.');
		}

		$recipientEmails = $this->settingsForClient($clientId)['recipient_emails'];
		if ($recipientEmails === []) {
			throw new \InvalidArgumentException('Add the bank\'s email address under Bank before sending.');
		}

		$words = $this->validateSubjectAndBody($emailSubject, $emailBody);
		if ($words['errors'] !== []) {
			throw new \InvalidArgumentException(reset($words['errors']));
		}

		$bankPaymentFileService = new BankPaymentFileService();
		$payrollRunId           = (int) $run['payroll_run_id'];

		$employeesMissingAccountDetails = $bankPaymentFileService->employeesMissingAccountDetails($payrollRunId, $clientId);
		if ($employeesMissingAccountDetails !== []) {
			throw new \InvalidArgumentException(
				'Add the bank account of ' . implode(', ', $employeesMissingAccountDetails) . ' under Employees before sending.'
			);
		}

		$attachmentFilename = $bankPaymentFileService->xlsxFilename($run, $clientName);

		Mailer::sendPlainText(
			$recipientEmails,
			$words['subject'],
			$words['body'],
			['address' => $accountant['email'], 'name' => $accountant['name']],
			[$attachmentFilename => $bankPaymentFileService->xlsxContents($payrollRunId, $clientId)]
		);

		PayrollRunBankEmail::insertSentEmail(
			$clientId,
			$payrollRunId,
			$recipientEmails,
			$words['subject'],
			$words['body'],
			$attachmentFilename,
			$accountant['user_id']
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Trim, drop blanks and repeats, and check each address.
	 *
	 * @return array{emails: string[], error: ?string}
	 */
	private function validateRecipientEmails(mixed $postedEmails): array {
		if (!is_array($postedEmails)) {
			return ['emails' => [], 'error' => 'Enter the bank\'s email addresses.'];
		}

		$emails = [];
		foreach ($postedEmails as $postedEmail) {
			$email = trim((string) (is_scalar($postedEmail) ? $postedEmail : ''));
			if ($email === '') {
				continue;
			}
			if (mb_strlen($email) > self::EMAIL_ADDRESS_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				return ['emails' => [], 'error' => "{$email} is not a valid email address."];
			}
			$emails[strtolower($email)] ??= $email;
		}

		if (count($emails) > self::MOST_RECIPIENTS) {
			return ['emails' => [], 'error' => 'Keep this to ' . self::MOST_RECIPIENTS . ' email addresses.'];
		}
		return ['emails' => array_values($emails), 'error' => null];
	}

	/**
	 * @return array{subject: string, body: string, errors: array<string, string>} errors keyed 'subject' and 'body'.
	 */
	private function validateSubjectAndBody(mixed $subjectInput, mixed $bodyInput): array {
		$subject = BankEmailTemplate::singleLine(is_string($subjectInput) ? $subjectInput : '');
		$body    = trim(str_replace("\r\n", "\n", is_string($bodyInput) ? $bodyInput : ''));
		$errors  = [];

		if ($subject === '') {
			$errors['subject'] = 'Enter the email subject.';
		} elseif (mb_strlen($subject) > self::SUBJECT_MAX_LENGTH) {
			$errors['subject'] = 'Keep the subject to ' . self::SUBJECT_MAX_LENGTH . ' characters.';
		}
		if ($body === '') {
			$errors['body'] = 'Enter the email message.';
		} elseif (mb_strlen($body) > self::BODY_MAX_LENGTH) {
			$errors['body'] = 'Keep the message to ' . self::BODY_MAX_LENGTH . ' characters.';
		}

		return ['subject' => $subject, 'body' => $body, 'errors' => $errors];
	}
}
