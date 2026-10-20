<?php

namespace App\Models;

use App\Helpers\Database;

/**
 * PayrollStatutoryRate Model
 * Effective-dated SSNIT percentages (with the Tier 1 share and the Tier 3 relief cap), the PAYE bands and
 * the personal relief amounts that go with them.
 * Not Client-scoped: statutory rates are the law, the same for every Client.
 */
final class PayrollStatutoryRate {

	/** SQLSTATE of the ON DELETE RESTRICT refusal: a payroll run still points at the rate set. */
	public const RESTRICT_VIOLATION = '23001';

	/**
	 * Every rate set, newest first, each with its bands.
	 */
	public static function allWithBands(): array {
		$rateSets = Database::fetchAll('SELECT * FROM payroll_statutory_rates ORDER BY effective_from DESC');

		foreach ($rateSets as $rateSetIndex => $rateSet) {
			$rateSets[$rateSetIndex]['bands']   = self::bandsFor((int) $rateSet['statutory_rate_id']);
			$rateSets[$rateSetIndex]['reliefs'] = self::reliefsFor((int) $rateSet['statutory_rate_id']);
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
		$rateSet['bands']   = self::bandsFor((int) $rateSet['statutory_rate_id']);
		$rateSet['reliefs'] = self::reliefsFor((int) $rateSet['statutory_rate_id']);
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

	/**
	 * The reliefs a rate set allows, keyed by relief code.
	 *
	 * @return array<string, array{annual_amount: ?string, percent_of_assessable: ?string, max_claims: int}>
	 */
	public static function reliefsFor(int $statutoryRateId): array {
		$reliefRows = Database::fetchAll(
			'SELECT relief_code, annual_amount, percent_of_assessable, max_claims
			 FROM payroll_relief_rates
			 WHERE statutory_rate_id = ?',
			[$statutoryRateId]
		);

		$reliefsByCode = [];
		foreach ($reliefRows as $reliefRow) {
			$reliefsByCode[$reliefRow['relief_code']] = [
				'annual_amount'         => $reliefRow['annual_amount'],
				'percent_of_assessable' => $reliefRow['percent_of_assessable'],
				'max_claims'            => (int) $reliefRow['max_claims'],
			];
		}
		return $reliefsByCode;
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
	 * @param array $reliefColumns statutory_rate_id, relief_code, annual_amount or percent_of_assessable, max_claims
	 */
	public static function insertRelief(array $reliefColumns): void {
		Database::query(
			'INSERT INTO payroll_relief_rates (statutory_rate_id, relief_code, annual_amount, percent_of_assessable, max_claims)
			 VALUES (?, ?, ?, ?, ?)',
			[
				$reliefColumns['statutory_rate_id'],
				$reliefColumns['relief_code'],
				$reliefColumns['annual_amount'],
				$reliefColumns['percent_of_assessable'],
				$reliefColumns['max_claims'],
			]
		);
	}

	/**
	 * Bands go with the set (ON DELETE CASCADE). A set any Client's run was computed from is refused
	 * by the database (ON DELETE RESTRICT), whichever Client is in scope.
	 *
	 * @throws \PDOException With code RESTRICT_VIOLATION when a run still uses the set.
	 */
	public static function deleteById(int $statutoryRateId): void {
		Database::query('DELETE FROM payroll_statutory_rates WHERE statutory_rate_id = ?', [$statutoryRateId]);
	}
}
