<?php

namespace App\Services;

use App\Models\ClientPayItem;

/**
 * PayItemService
 * A Client's allowance types (each taxable or not) and after-tax deduction types. Employees hold an
 * amount on any of them on the Salaries page; a payroll run records each by the name it had that month.
 */
final class PayItemService {

	private const ITEM_KINDS     = [ClientPayItem::KIND_ALLOWANCE, ClientPayItem::KIND_DEDUCTION];
	private const MAX_NAME_CHARS = 100;

	/** More pay items than any payroll sheet has columns for; stops a runaway form post. */
	private const MAX_ITEMS_PER_CLIENT = 40;

	public function listPayItems(int $clientId): array {
		return ClientPayItem::listForClient($clientId);
	}

	/**
	 * @param array $input item_kind, item_name, is_taxable (allowances only)
	 * @return array{errors: array<string, string>, pay_item: ?array}
	 */
	public function createPayItem(int $clientId, array $input): array {
		$itemKind = (string) ($input['item_kind'] ?? '');
		if (!in_array($itemKind, self::ITEM_KINDS, true)) {
			return ['errors' => ['item_kind' => 'Choose allowance or deduction.'], 'pay_item' => null];
		}
		if (count(ClientPayItem::listForClient($clientId)) >= self::MAX_ITEMS_PER_CLIENT) {
			return ['errors' => ['item_name' => 'This client already has as many pay items as it can hold.'], 'pay_item' => null];
		}

		$validated = $this->validateNameAndTax($clientId, $itemKind, $input, null);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'pay_item' => null];
		}

		$payItemId = ClientPayItem::insertPayItem($clientId, $validated['columns'] + [
			'item_kind'     => $itemKind,
			'display_order' => ClientPayItem::nextDisplayOrder($clientId, $itemKind),
		]);
		return ['errors' => [], 'pay_item' => ClientPayItem::findForClient($payItemId, $clientId)];
	}

	/**
	 * Rename an item or change whether an allowance is taxed. Its kind never changes. Draft runs pick the
	 * change up on recalculation; finalised runs keep the name and tax they were worked out with.
	 *
	 * @return array{errors: array<string, string>, pay_item: ?array, found: bool}
	 */
	public function updatePayItem(int $payItemId, int $clientId, array $input): array {
		$payItem = ClientPayItem::findForClient($payItemId, $clientId);
		if ($payItem === null) {
			return ['errors' => [], 'pay_item' => null, 'found' => false];
		}

		$validated = $this->validateNameAndTax($clientId, (string) $payItem['item_kind'], $input, $payItemId);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'pay_item' => null, 'found' => true];
		}

		ClientPayItem::updatePayItem($payItemId, $clientId, $validated['columns']);
		return ['errors' => [], 'pay_item' => ClientPayItem::findForClient($payItemId, $clientId), 'found' => true];
	}

	/**
	 * @throws \InvalidArgumentException When the item is missing or an employee still holds an amount on it.
	 */
	public function deletePayItem(int $payItemId, int $clientId): void {
		$payItem = ClientPayItem::findForClient($payItemId, $clientId);
		if ($payItem === null) {
			throw new \InvalidArgumentException('Pay item not found.');
		}

		try {
			ClientPayItem::deletePayItem($payItemId, $clientId);
		} catch (\PDOException $e) {
			if ($e->getCode() === ClientPayItem::RESTRICT_VIOLATION) {
				throw new \InvalidArgumentException(
					"Employees still have an amount on {$payItem['item_name']}. Clear it from their salaries first."
				);
			}
			throw $e;
		}
	}

	/**
	 * @return array{errors: array<string, string>, columns: array}
	 */
	private function validateNameAndTax(int $clientId, string $itemKind, array $input, ?int $editedPayItemId): array {
		$itemName = preg_replace('/\s+/', ' ', trim((string) ($input['item_name'] ?? '')));
		$errors   = [];

		if ($itemName === '') {
			$errors['item_name'] = 'Enter a name.';
		} elseif (mb_strlen($itemName) > self::MAX_NAME_CHARS) {
			$errors['item_name'] = 'Keep the name to ' . self::MAX_NAME_CHARS . ' characters.';
		} elseif (ClientPayItem::nameTaken($clientId, $itemKind, $itemName, $editedPayItemId)) {
			$errors['item_name'] = "This client already has {$this->kindLabel($itemKind)} with this name.";
		}

		// A deduction is taken after PAYE, so whether it is taxed means nothing; it is stored as not.
		$isTaxable = $itemKind === ClientPayItem::KIND_ALLOWANCE && filter_var($input['is_taxable'] ?? true, FILTER_VALIDATE_BOOLEAN);

		return ['errors' => $errors, 'columns' => ['item_name' => $itemName, 'is_taxable' => $isTaxable]];
	}

	private function kindLabel(string $itemKind): string {
		return $itemKind === ClientPayItem::KIND_ALLOWANCE ? 'an allowance' : 'a deduction';
	}
}
