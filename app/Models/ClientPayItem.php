<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * ClientPayItem Model
 * A Client's allowance and deduction types (motivational allowance, staff loan, …). Client-scoped.
 */
final class ClientPayItem {

	public const KIND_ALLOWANCE = 'allowance';
	public const KIND_DEDUCTION = 'deduction';

	/** SQLSTATE of the ON DELETE RESTRICT refusal: an employee still holds an amount on the item. */
	public const RESTRICT_VIOLATION = '23001';

	/**
	 * A Client's pay items, allowances first, each kind in its display order.
	 */
	public static function listForClient(int $clientId): array {
		return Database::fetchAll(
			"SELECT pay_item.pay_item_id, pay_item.item_kind, pay_item.item_name, pay_item.is_taxable, pay_item.display_order,
			        (SELECT COUNT(*) FROM employee_pay_item_amounts held
			         WHERE held.pay_item_id = pay_item.pay_item_id AND held.client_id = pay_item.client_id) AS employee_count
			 FROM client_pay_items pay_item
			 WHERE pay_item.client_id = ?
			 ORDER BY CASE pay_item.item_kind WHEN 'allowance' THEN 0 ELSE 1 END,
			          pay_item.display_order ASC, LOWER(pay_item.item_name) ASC, pay_item.pay_item_id ASC",
			[$clientId]
		);
	}

	public static function findForClient(int $payItemId, int $clientId): ?array {
		return Database::fetchOne(
			'SELECT pay_item_id, item_kind, item_name, is_taxable, display_order
			 FROM client_pay_items
			 WHERE pay_item_id = ? AND client_id = ?',
			[$payItemId, $clientId]
		);
	}

	/**
	 * The Client's first taxable allowance, where an imported sheet's single ALLOWANCE column goes.
	 */
	public static function firstTaxableAllowanceForClient(int $clientId): ?array {
		return Database::fetchOne(
			"SELECT pay_item_id, item_kind, item_name, is_taxable, display_order
			 FROM client_pay_items
			 WHERE client_id = ? AND item_kind = 'allowance' AND is_taxable
			 ORDER BY display_order ASC, pay_item_id ASC
			 LIMIT 1",
			[$clientId]
		);
	}

	public static function nameTaken(int $clientId, string $itemKind, string $itemName, ?int $exceptPayItemId = null): bool {
		return (bool) Database::fetchOne(
			'SELECT 1 FROM client_pay_items
			 WHERE client_id = ? AND item_kind = ? AND LOWER(item_name) = LOWER(?) AND pay_item_id <> ?',
			[$clientId, $itemKind, $itemName, $exceptPayItemId ?? 0]
		);
	}

	/** The display order a new item of this kind takes: after every other. */
	public static function nextDisplayOrder(int $clientId, string $itemKind): int {
		$row = Database::fetchOne(
			'SELECT COALESCE(MAX(display_order), 0) + 1 AS next_display_order
			 FROM client_pay_items WHERE client_id = ? AND item_kind = ?',
			[$clientId, $itemKind]
		);
		return (int) ($row['next_display_order'] ?? 1);
	}

	/**
	 * @param array $payItemColumns item_kind, item_name, is_taxable, display_order
	 */
	public static function insertPayItem(int $clientId, array $payItemColumns): int {
		return Database::insert('client_pay_items', ['client_id' => $clientId] + $payItemColumns, 'pay_item_id');
	}

	/**
	 * @param array $payItemColumns item_name, is_taxable
	 */
	public static function updatePayItem(int $payItemId, int $clientId, array $payItemColumns): void {
		Database::update(
			'client_pay_items',
			$payItemColumns + ['updated_at' => date('Y-m-d H:i:s')],
			['pay_item_id' => $payItemId, 'client_id' => $clientId]
		);
	}

	/**
	 * @throws \PDOException With code RESTRICT_VIOLATION when an employee still holds an amount on it.
	 */
	public static function deletePayItem(int $payItemId, int $clientId): void {
		Database::query('DELETE FROM client_pay_items WHERE pay_item_id = ? AND client_id = ?', [$payItemId, $clientId]);
	}
}
