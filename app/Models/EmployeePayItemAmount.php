<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * EmployeePayItemAmount Model
 * What each employee gets on each of the Client's allowances, and has taken on each deduction, every
 * month. No row means nothing on that item. Client-scoped.
 */
final class EmployeePayItemAmount {

	/**
	 * Every amount the Client's employees hold, with the item it is on.
	 */
	public static function listForClient(int $clientId): array {
		return Database::fetchAll(
			'SELECT held.employee_id, held.pay_item_id, held.amount,
			        pay_item.item_kind, pay_item.item_name, pay_item.is_taxable, pay_item.display_order
			 FROM employee_pay_item_amounts held
			 JOIN client_pay_items pay_item ON pay_item.pay_item_id = held.pay_item_id AND pay_item.client_id = held.client_id
			 WHERE held.client_id = ?
			 ORDER BY held.employee_id ASC, pay_item.display_order ASC, pay_item.pay_item_id ASC',
			[$clientId]
		);
	}

	/**
	 * Replace every amount one employee holds. The composite foreign keys refuse an employee or an
	 * item that is not this Client's.
	 *
	 * @param array<int, float> $amountByPayItemId Only amounts above 0.
	 */
	public static function replaceForEmployee(int $clientId, int $employeeId, array $amountByPayItemId): void {
		Database::query('DELETE FROM employee_pay_item_amounts WHERE client_id = ? AND employee_id = ?', [$clientId, $employeeId]);
		foreach ($amountByPayItemId as $payItemId => $amount) {
			self::insertAmount($clientId, $employeeId, (int) $payItemId, $amount);
		}
	}

	/**
	 * Set one employee's amount on one item; 0 removes it.
	 */
	public static function setForEmployee(int $clientId, int $employeeId, int $payItemId, float $amount): void {
		Database::query(
			'DELETE FROM employee_pay_item_amounts WHERE client_id = ? AND employee_id = ? AND pay_item_id = ?',
			[$clientId, $employeeId, $payItemId]
		);
		if ($amount > 0) {
			self::insertAmount($clientId, $employeeId, $payItemId, $amount);
		}
	}

	private static function insertAmount(int $clientId, int $employeeId, int $payItemId, float $amount): void {
		Database::query(
			'INSERT INTO employee_pay_item_amounts (client_id, employee_id, pay_item_id, amount) VALUES (?, ?, ?, ?)',
			[$clientId, $employeeId, $payItemId, $amount]
		);
	}
}
