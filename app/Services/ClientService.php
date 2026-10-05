<?php

namespace App\Services;

use App\Models\Client;

/**
 * ClientService
 * The businesses an accountant keeps payroll for, and the check that a Client is theirs before
 * its books are opened.
 */
final class ClientService {

	private const FIELD_MAX_LENGTHS = [
		'client_name'               => 150,
		'tax_identification_number' => 30,
		'ssnit_employer_number'     => 30,
		'contact_email'             => 190,
		'contact_phone'             => 30,
		'postal_address'            => 500,
	];

	public function listClients(int $accountantUserId): array {
		return Client::listForAccountant($accountantUserId);
	}

	/**
	 * The Client, or null when it does not exist or belongs to another accountant.
	 */
	public function findOwnedClient(int $clientId, int $accountantUserId): ?array {
		return $clientId > 0 ? Client::findForAccountant($clientId, $accountantUserId) : null;
	}

	/**
	 * From here on this request's database connection reads and writes only this Client's rows.
	 * Call only with a Client findOwnedClient() returned.
	 */
	public function confineConnectionToClient(int $clientId): void {
		Client::scopeConnectionToClient($clientId);
	}

	/**
	 * @return array{errors: array<string, string>, client: ?array}
	 */
	public function createClient(int $accountantUserId, array $input): array {
		$validated = $this->validateClientInput($accountantUserId, $input, null);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'client' => null];
		}

		$clientId = Client::insertClient($accountantUserId, $validated['client_details']);
		return ['errors' => [], 'client' => Client::findForAccountant($clientId, $accountantUserId)];
	}

	/**
	 * @return array{errors: array<string, string>, client: ?array, found: bool} found is false when the Client is not this accountant's.
	 */
	public function updateClient(int $clientId, int $accountantUserId, array $input): array {
		if (Client::findForAccountant($clientId, $accountantUserId) === null) {
			return ['errors' => [], 'client' => null, 'found' => false];
		}

		$validated = $this->validateClientInput($accountantUserId, $input, $clientId);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'client' => null, 'found' => true];
		}

		Client::updateClient($clientId, $accountantUserId, $validated['client_details']);
		return ['errors' => [], 'client' => Client::findForAccountant($clientId, $accountantUserId), 'found' => true];
	}

	/**
	 * Archive or restore a Client. An archived Client's payroll can be read but not changed.
	 *
	 * @return ?array The Client, or null when it is not this accountant's.
	 */
	public function setArchived(int $clientId, int $accountantUserId, bool $isArchived): ?array {
		if (Client::findForAccountant($clientId, $accountantUserId) === null) {
			return null;
		}
		Client::setArchived($clientId, $accountantUserId, $isArchived);
		return Client::findForAccountant($clientId, $accountantUserId);
	}

	/**
	 * @return array{errors: array<string, string>, client_details: array}
	 */
	private function validateClientInput(int $accountantUserId, array $input, ?int $editedClientId): array {
		$errors        = [];
		$clientDetails = [];

		foreach (self::FIELD_MAX_LENGTHS as $field => $maxLength) {
			$value = trim((string) ($input[$field] ?? ''));
			if (mb_strlen($value) > $maxLength) {
				$errors[$field] = "Keep this to {$maxLength} characters.";
			}
			$clientDetails[$field] = $value === '' ? null : $value;
		}

		if ($clientDetails['client_name'] === null) {
			$errors['client_name'] = 'Enter the business name.';
		} elseif (!isset($errors['client_name']) && Client::nameTaken($accountantUserId, $clientDetails['client_name'], $editedClientId)) {
			$errors['client_name'] = 'You already have a client with this name.';
		}
		if ($clientDetails['contact_email'] !== null && !filter_var($clientDetails['contact_email'], FILTER_VALIDATE_EMAIL)) {
			$errors['contact_email'] = 'Enter a valid email address.';
		}

		return ['errors' => $errors, 'client_details' => $clientDetails];
	}
}
