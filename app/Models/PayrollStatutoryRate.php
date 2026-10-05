<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollStatutoryRate Model
 * Effective-dated SSNIT percentages and the PAYE bands that go with them.
 * Not Client-scoped: statutory rates are the law, the same for every Client.
 */
final class PayrollStatutoryRate {

	/** SQLSTATE of a foreign-key violation: a payroll run still points at the rate set. */
	public const FOREIGN_KEY_VIOLATION = '23503';

	/**
	 * Every rate set, newest first, each with its bands.
	 */
	public static function allWithBands(): array {
		$rateSets = Database::fetchAll('SELECT * FROM payroll_statutory_rates ORDER BY effective_from DESC');

		foreach ($rateSets as $rateSetIndex => $rateSet) {
			$rateSets[$rateSetIndex]['bands'] = self::bandsFor((int) $rateSet['statutory_rate_id']);
		}
		return $rateSets;
	}

	/**
	 * The rate set in force on a date: the latest one that took effect on or before it.
	 *
	 * @param string $date Y-m-d
	 */
	public static function findInForceOn(string $date): ?array {
		$rateSet = Database::fetchOne(
			'SELECT * FROM payroll_statutory_rates
			 WHERE effective_from <= ?
			 ORDER BY effective_from DESC
			 LIMIT 1',
			[$date]
		);
		if ($rateSet === null) {
			return null;
		}
		$rateSet['bands'] = self::bandsFor((int) $rateSet['statutory_rate_id']);
		return $rateSet;
	}

	public static function exists(int $statutoryRateId): bool {
		return (bool) Database::fetchOne('SELECT 1 FROM payroll_statutory_rates WHERE statutory_rate_id = ?', [$statutoryRateId]);
	}

	public static function bandsFor(int $statutoryRateId): array {
		return Database::fetchAll(
			'SELECT band_order, band_width, rate_percent
			 FROM payroll_tax_bands
			 WHERE statutory_rate_id = ?
			 ORDER BY band_order',
			[$statutoryRateId]
		);
	}

	public static function effectiveDateTaken(string $effectiveFrom): bool {
		return (bool) Database::fetchOne('SELECT 1 FROM payroll_statutory_rates WHERE effective_from = ?', [$effectiveFrom]);
	}

	public static function countAll(): int {
		$row = Database::fetchOne('SELECT COUNT(*) AS rate_set_count FROM payroll_statutory_rates');
		return (int) ($row['rate_set_count'] ?? 0);
	}

	public static function insertRateSet(array $rateSetColumns): int {
		return Database::insert('payroll_statutory_rates', $rateSetColumns, 'statutory_rate_id');
	}

	public static function insertBand(array $bandColumns): int {
		return Database::insert('payroll_tax_bands', $bandColumns, 'tax_band_id');
	}

	/**
	 * Bands go with the set (ON DELETE CASCADE). A set any Client's run was computed from is refused
	 * by the database (ON DELETE RESTRICT), whichever Client is in scope.
	 *
	 * @throws \PDOException With code FOREIGN_KEY_VIOLATION when a run still uses the set.
	 */
	public static function deleteById(int $statutoryRateId): void {
		Database::query('DELETE FROM payroll_statutory_rates WHERE statutory_rate_id = ?', [$statutoryRateId]);
	}
}
