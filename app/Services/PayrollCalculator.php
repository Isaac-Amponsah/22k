<?php

namespace App\Services;

/**
 * PayrollCalculator
 * The arithmetic of one employee's monthly pay. Pure: no database, no session.
 *
 *   SSNIT             = basic salary x employee SSNIT %
 *   basic less SSNIT  = basic salary - SSNIT
 *   allowance         = the flat amount, or (target chargeable income - basic less SSNIT)
 *   chargeable income = basic less SSNIT + allowance
 *   PAYE              = chargeable income run through the monthly bands
 *   take home         = chargeable income - PAYE
 *   employer SSNIT    = basic salary x employer SSNIT % (a cost to the employer, not a deduction)
 *
 * Every figure is worked in whole pesewas and rounded once, so a line always adds up to the
 * pesewa and a run's totals are the sum of what the lines show.
 */
final class PayrollCalculator {

	public const MODE_FLAT   = 'flat';
	public const MODE_TARGET = 'target';

	public const ALLOWANCE_MODES = [self::MODE_FLAT, self::MODE_TARGET];

	/**
	 * Work out one employee's pay.
	 *
	 * @param array $rateSet Keys: employee_ssnit_percent, employer_ssnit_percent, bands
	 *                       (ordered rows of band_width — null for the last, open band — and rate_percent).
	 * @return array{basic_salary: float, employee_ssnit: float, basic_less_ssnit: float, allowance: float,
	 *               chargeable_income: float, paye: float, net_pay: float, employer_ssnit: float}
	 * @throws \InvalidArgumentException When the mode is unknown, an amount is negative, or a target
	 *                                   is below basic less SSNIT (the allowance would be negative).
	 */
	public static function compute(
		float $basicSalary,
		string $allowanceMode,
		float $flatAllowance,
		?float $targetChargeableIncome,
		array $rateSet
	): array {
		if (!in_array($allowanceMode, self::ALLOWANCE_MODES, true)) {
			throw new \InvalidArgumentException('Unknown allowance type.');
		}
		if ($basicSalary < 0) {
			throw new \InvalidArgumentException('Basic salary cannot be negative.');
		}

		$basicPesewas          = self::toPesewas($basicSalary);
		$employeeSsnitPesewas  = self::percentOf($basicPesewas, (float) $rateSet['employee_ssnit_percent']);
		$employerSsnitPesewas  = self::percentOf($basicPesewas, (float) $rateSet['employer_ssnit_percent']);
		$basicLessSsnitPesewas = $basicPesewas - $employeeSsnitPesewas;

		if ($allowanceMode === self::MODE_TARGET) {
			if ($targetChargeableIncome === null) {
				throw new \InvalidArgumentException('Enter the target chargeable income.');
			}
			$allowancePesewas = self::toPesewas($targetChargeableIncome) - $basicLessSsnitPesewas;
			if ($allowancePesewas < 0) {
				throw new \InvalidArgumentException(
					'Target chargeable income cannot be below basic salary less SSNIT ('
					. number_format($basicLessSsnitPesewas / 100, 2) . ').'
				);
			}
		} else {
			if ($flatAllowance < 0) {
				throw new \InvalidArgumentException('Allowance cannot be negative.');
			}
			$allowancePesewas = self::toPesewas($flatAllowance);
		}

		$chargeablePesewas = $basicLessSsnitPesewas + $allowancePesewas;
		$payePesewas       = self::payePesewas($chargeablePesewas, $rateSet['bands'] ?? []);

		return [
			'basic_salary'      => self::toCedis($basicPesewas),
			'employee_ssnit'    => self::toCedis($employeeSsnitPesewas),
			'basic_less_ssnit'  => self::toCedis($basicLessSsnitPesewas),
			'allowance'         => self::toCedis($allowancePesewas),
			'chargeable_income' => self::toCedis($chargeablePesewas),
			'paye'              => self::toCedis($payePesewas),
			'net_pay'           => self::toCedis($chargeablePesewas - $payePesewas),
			'employer_ssnit'    => self::toCedis($employerSsnitPesewas),
		];
	}

	/**
	 * PAYE on a month's chargeable income.
	 *
	 * @param array $bands Ordered rows of band_width (null = open band) and rate_percent.
	 */
	public static function paye(float $chargeableIncome, array $bands): float {
		return self::toCedis(self::payePesewas(self::toPesewas($chargeableIncome), $bands));
	}

	private static function payePesewas(int $chargeablePesewas, array $bands): int {
		$remainingPesewas = max(0, $chargeablePesewas);
		$taxPesewas       = 0.0;

		foreach ($bands as $band) {
			if ($remainingPesewas <= 0) {
				break;
			}
			$isOpenBand         = $band['band_width'] === null || $band['band_width'] === '';
			$bandWidthPesewas   = $isOpenBand ? $remainingPesewas : self::toPesewas((float) $band['band_width']);
			$taxedInBandPesewas = min($remainingPesewas, $bandWidthPesewas);

			$taxPesewas       += $taxedInBandPesewas * (float) $band['rate_percent'] / 100;
			$remainingPesewas -= $taxedInBandPesewas;
		}

		return (int) round($taxPesewas);
	}

	private static function toPesewas(float $amount): int {
		return (int) round($amount * 100);
	}

	/** Always a float: 100000 / 100 would otherwise come back as the integer 1000. */
	private static function toCedis(int $pesewas): float {
		return (float) ($pesewas / 100);
	}

	private static function percentOf(int $pesewas, float $percent): int {
		return (int) round($pesewas * $percent / 100);
	}
}
