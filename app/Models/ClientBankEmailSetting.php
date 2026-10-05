<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * ClientBankEmailSetting Model
 * One row per Client: its bank, who at the bank receives the payroll, and the email template.
 * recipient_emails is stored as a JSON array and handed back as a PHP list.
 */
final class ClientBankEmailSetting {

	public static function findForClient(int $clientId): ?array {
		$setting = Database::fetchOne(
			'SELECT client_id, bank_name, recipient_emails, email_subject_template, email_body_template, updated_at
			 FROM client_bank_email_settings
			 WHERE client_id = ?',
			[$clientId]
		);
		if ($setting === null) {
			return null;
		}
		$setting['recipient_emails'] = json_decode((string) $setting['recipient_emails'], true) ?: [];
		return $setting;
	}

	/**
	 * @param array{bank_name: ?string, recipient_emails: string[], email_subject_template: string, email_body_template: string} $setting
	 */
	public static function upsertForClient(int $clientId, array $setting, ?int $updatedByUserId): void {
		Database::query(
			'INSERT INTO client_bank_email_settings
			        (client_id, bank_name, recipient_emails, email_subject_template, email_body_template, updated_by)
			 VALUES (?, ?, ?::jsonb, ?, ?, ?)
			 ON CONFLICT (client_id) DO UPDATE SET
			        bank_name              = EXCLUDED.bank_name,
			        recipient_emails       = EXCLUDED.recipient_emails,
			        email_subject_template = EXCLUDED.email_subject_template,
			        email_body_template    = EXCLUDED.email_body_template,
			        updated_by             = EXCLUDED.updated_by,
			        updated_at             = CURRENT_TIMESTAMP',
			[
				$clientId,
				$setting['bank_name'],
				json_encode(array_values($setting['recipient_emails'])),
				$setting['email_subject_template'],
				$setting['email_body_template'],
				$updatedByUserId,
			]
		);
	}
}
