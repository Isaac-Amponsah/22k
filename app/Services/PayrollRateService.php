<?php

namespace App\Services;

use App\Helpers\Database;
use App\Models\PayrollStatutoryRate;

/**
 * PayrollRateService
 * Statutory payroll rates: SSNIT percentages and the monthly PAYE bands, effective-dated.
 * A rate set is never edited — a change in the law is a new set with its own start date, so the
 * months already paid keep the rates they were worked with.
 */
final class PayrollRateService {

	/** More bands than any PAYE table has had; stops a runaway form post. */
	private const MAX_BANDS = 20;

	public function listRateSets(): array {
		return PayrollStatutoryRate::allWithBands();
	}

	/**
	 * Add a rate set.
	 *
	 * @param array $input effective_from, employee_ssnit_percent, employer_ssnit_percent, note,
	 *                     band_width[] and rate_percent[] (the last band's width is left blank).
	 * @return array{errors: array<string, string>, statutory_rate_id: ?int}
	 */
	public function createRateSet(array $input, ?int $userId): array {
		$errors = [];

		$effectiveFrom = trim((string) ($input['effective_from'] ?? ''));
		$effectiveDate = \DateTime::createFromFormat('!Y-m-d', $effectiveFrom);
		if (!$effectiveDate || $effectiveDate->format('Y-m-d') !== $effectiveFrom) {
			$errors['effective_from'] = 'Enter the date the rates take effect.';
		} elseif (PayrollStatutoryRate::effectiveDateTaken($effectiveFrom)) {
			$errors['effective_from'] = 'There is already a rate set starting on this date.';
		}

		$ssnitPercents = [];
		foreach (['employee_ssnit_percent' => 'employee SSNIT', 'employer_ssnit_percent' => 'employer SSNIT'] as $field => $label) {
			$percent = $this->parsePercent($input[$field] ?? null);
			if ($percent === null) {
				$errors[$field] = "Enter the {$label} rate as a percentage from 0 to 100.";
			}
			$ssnitPercents[$field] = $percent;
		}

		$bands = $this->parseBands((array) ($input['band_width'] ?? []), (array) ($input['rate_percent'] ?? []));
		if ($bands['error'] !== null) {
			$errors['bands'] = $bands['error'];
		}

		if ($errors !== []) {
			return ['errors' => $errors, 'statutory_rate_id' => null];
		}

		Database::beginTransaction();
		try {
			$statutoryRateId = PayrollStatutoryRate::insertRateSet([
				'effective_from'         => $effectiveFrom,
				'employee_ssnit_percent' => $ssnitPercents['employee_ssnit_percent'],
				'employer_ssnit_percent' => $ssnitPercents['employer_ssnit_percent'],
				'note'                   => trim((string) ($input['note'] ?? '')) ?: null,
				'created_by'             => $userId,
			]);
			foreach ($bands['bands'] as $bandIndex => $band) {
				PayrollStatutoryRate::insertBand([
					'statutory_rate_id' => $statutoryRateId,
					'band_order'        => $bandIndex + 1,
					'band_width'        => $band['band_width'],
					'rate_percent'      => $band['rate_percent'],
				]);
			}
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}

		return ['errors' => [], 'statutory_rate_id' => $statutoryRateId];
	}

	/**
	 * Remove a rate set nothing was computed from.
	 *
	 * @throws \InvalidArgumentException When it is missing, in use, or the only set left.
	 */
	public function deleteRateSet(int $statutoryRateId): void {
		if (!PayrollStatutoryRate::exists($statutoryRateId)) {
			throw new \InvalidArgumentException('Rate set not found.');
		}
		if (PayrollStatutoryRate::countAll() <= 1) {
			throw new \InvalidArgumentException('This is the only rate set. Add its replacement before deleting it.');
		}

		// Whether a run uses the set is asked of the database, not counted here: this connection sees
		// one Client's runs at most, and the foreign key sees every Client's.
		try {
			PayrollStatutoryRate::deleteById($statutoryRateId);
		} catch (\PDOException $e) {
			if ($e->getCode() === PayrollStatutoryRate::FOREIGN_KEY_VIOLATION) {
				throw new \InvalidArgumentException('Payroll runs were computed from this rate set, so it cannot be deleted.');
			}
			throw $e;
		}
	}

	/**
	 * Pair the posted widths and rates into ordered bands. Every band but the last needs a width;
	 * the last is the open band (blank width) that taxes whatever is left.
	 *
	 * @return array{bands: array, error: ?string}
	 */
	private function parseBands(array $postedWidths, array $postedRates): array {
		$postedWidths = array_values($postedWidths);
		$postedRates  = array_values($postedRates);
		$bandCount    = count($postedRates);

		if ($bandCount === 0) {
			return ['bands' => [], 'error' => 'Add at least one PAYE band.'];
		}
		if ($bandCount > self::MAX_BANDS) {
			return ['bands' => [], 'error' => 'Too many PAYE bands.'];
		}

		$bands = [];
		foreach ($postedRates as $bandIndex => $postedRate) {
			$bandNumber  = $bandIndex + 1;
			$isLastBand  = $bandIndex === $bandCount - 1;
			$ratePercent = $this->parsePercent($postedRate);
			if ($ratePercent === null) {
				return ['bands' => [], 'error' => "Band {$bandNumber}: enter the rate as a percentage from 0 to 100."];
			}

			$widthInput = str_replace([',', ' '], '', trim((string) ($postedWidths[$bandIndex] ?? '')));
			if ($isLastBand) {
				if ($widthInput !== '') {
					return ['bands' => [], 'error' => 'Leave the last band\'s width blank: it taxes everything above the bands before it.'];
				}
				$bandWidth = null;
			} else {
				if (!is_numeric($widthInput) || (float) $widthInput <= 0) {
					return ['bands' => [], 'error' => "Band {$bandNumber}: enter a width above 0."];
				}
				$bandWidth = round((float) $widthInput, 2);
			}

			$bands[] = ['band_width' => $bandWidth, 'rate_percent' => $ratePercent];
		}

		return ['bands' => $bands, 'error' => null];
	}

	private function parsePercent(mixed $rawPercent): ?float {
		$cleaned = trim((string) $rawPercent);
		if ($cleaned === '' || !is_numeric($cleaned)) {
			return null;
		}
		$percent = round((float) $cleaned, 2);
		return ($percent < 0 || $percent > 100) ? null : $percent;
	}
}
