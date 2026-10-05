<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * Client Model
 * A business an accountant keeps payroll for. Every query names the accountant; row-level
 * security on the table enforces the same thing underneath.
 */
final class Client {

	/** Columns a Client can be created or edited with. */
	public const EDITABLE_COLUMNS = [
		'client_name',
		'tax_identification_number',
		'ssnit_employer_number',
		'contact_email',
		'contact_phone',
		'postal_address',
	];

	/**
	 * An accountant's Clients, active first, then by name.
	 */
	public static function listForAccountant(int $accountantUserId): array {
		return Database::fetchAll(
			'SELECT client_id, client_name, tax_identification_number, ssnit_employer_number,
			        contact_email, contact_phone, postal_address, is_archived, created_at
			 FROM clients
			 WHERE accountant_user_id = ?
			 ORDER BY is_archived ASC, LOWER(client_name) ASC',
			[$accountantUserId]
		);
	}

	public static function findForAccountant(int $clientId, int $accountantUserId): ?array {
		return Database::fetchOne(
			'SELECT client_id, client_name, tax_identification_number, ssnit_employer_number,
			        contact_email, contact_phone, postal_address, is_archived, created_at
			 FROM clients
			 WHERE client_id = ? AND accountant_user_id = ?',
			[$clientId, $accountantUserId]
		);
	}

	public static function nameTaken(int $accountantUserId, string $clientName, ?int $exceptClientId = null): bool {
		return (bool) Database::fetchOne(
			'SELECT 1 FROM clients
			 WHERE accountant_user_id = ? AND LOWER(client_name) = LOWER(?) AND client_id <> ?',
			[$accountantUserId, $clientName, $exceptClientId ?? 0]
		);
	}

	/**
	 * @param array $clientDetails Keys from EDITABLE_COLUMNS.
	 */
	public static function insertClient(int $accountantUserId, array $clientDetails): int {
		return Database::insert(
			'clients',
			['accountant_user_id' => $accountantUserId] + array_intersect_key($clientDetails, array_flip(self::EDITABLE_COLUMNS)),
			'client_id'
		);
	}

	public static function updateClient(int $clientId, int $accountantUserId, array $clientDetails): void {
		Database::update(
			'clients',
			array_intersect_key($clientDetails, array_flip(self::EDITABLE_COLUMNS)) + ['updated_at' => date('Y-m-d H:i:s')],
			['client_id' => $clientId, 'accountant_user_id' => $accountantUserId]
		);
	}

	public static function setArchived(int $clientId, int $accountantUserId, bool $isArchived): void {
		Database::update(
			'clients',
			['is_archived' => $isArchived, 'updated_at' => date('Y-m-d H:i:s')],
			['client_id' => $clientId, 'accountant_user_id' => $accountantUserId]
		);
	}

	/**
	 * Confine this database connection to one Client: the row-level security policies on every
	 * Client table then show and accept only that Client's rows.
	 */
	public static function scopeConnectionToClient(int $clientId): void {
		Database::setConnectionSetting('app.client_id', (string) $clientId);
	}
}
