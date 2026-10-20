<?php

namespace App\Services;

/**
 * PayrollCalculator
 * The arithmetic of one employee's monthly pay. Pure: no database, no session.
 *
 *   SSNIT              = basic salary x employee SSNIT %
 *   basic less SSNIT   = basic salary - SSNIT
 *   allowance          = every allowance; the taxable ones are taxable allowance
 *   assessable income  = basic less SSNIT + taxable allowance
 *   Tier 3             = basic salary x the employee's voluntary Tier 3 %
 *   Tier 3 relief      = Tier 3, up to basic salary x the Tier 3 relief cap %
 *   personal relief    = each claimed relief's annual amount x claims / 12 (or a % of assessable income)
 *   chargeable income  = assessable income - Tier 3 relief - personal relief, never below 0
 *   PAYE               = chargeable income run through the monthly bands
 *   other deductions   = every after-tax deduction
 *   take home          = basic less SSNIT + allowance - Tier 3 - PAYE - other deductions
 *   employer SSNIT     = basic salary x employer SSNIT % (a cost to the employer, not a deduction)
 *   SSNIT Tier 1       = basic salary x Tier 1 %, paid to SSNIT
 *   SSNIT Tier 2       = SSNIT + employer SSNIT - Tier 1, paid to the Tier 2 trustee
 *
 * With one taxable allowance and nothing else, chargeable income is basic less SSNIT + allowance and
 * take home is chargeable income - PAYE, as on the accountant's sheet.
 *
 * Every figure is worked in whole pesewas and rounded once, so a line always adds up to the
 * pesewa and a run's totals are the sum of what the lines show.
 */
final class PayrollCalculator {

	/** Figures compute() answers with, in the order a line shows them. Each is a run total too. */
	public const FIGURES = [
		'basic_salary',
		'employee_ssnit',
		'basic_less_ssnit',
		'allowance',
		'taxable_allowance',
		'tier3_contribution',
		'tier3_relief',
		'personal_relief',
		'chargeable_income',
		'paye',
		'other_deductions',
		'net_pay',
		'employer_ssnit',
		'ssnit_tier1',
		'ssnit_tier2',
	];

	/**
	 * Work out one employee's pay.
	 *
	 * @param array $payInputs basic_salary; allowances (rows of item_name, amount, is_taxable); deductions (rows of
	 *                         item_name, amount); tier3_percent; relief_claims (relief_code => claim_count).
	 * @param array $rateSet   employee_ssnit_percent, employer_ssnit_percent, ssnit_tier1_percent, tier3_relief_cap_percent,
	 *                         bands (ordered rows of band_width — null for the last, open band — and rate_percent) and
	 *                         reliefs (relief_code => annual_amount or percent_of_assessable, and max_claims).
	 * @return array Every key in FIGURES as a float, and items: the line's allowances, deductions and reliefs as rows of
	 *               item_kind, item_name, is_taxable, amount.
	 * @throws \InvalidArgumentException When an amount is negative, a relief cannot be claimed, or deductions exceed pay.
	 */
	public static function compute(array $payInputs, array $rateSet): array {
		$basicSalary = (float) ($payInputs['basic_salary'] ?? 0);
		if ($basicSalary < 0) {
			throw new \InvalidArgumentException('Basic salary cannot be negative.');
		}
		$tier3Percent = (float) ($payInputs['tier3_percent'] ?? 0);
		if ($tier3Percent < 0 || $tier3Percent > 100) {
			throw new \InvalidArgumentException('Tier 3 must be a percentage from 0 to 100.');
		}

		$items = [];

		$basicPesewas          = self::toPesewas($basicSalary);
		$employeeSsnitPesewas  = self::percentOf($basicPesewas, (float) $rateSet['employee_ssnit_percent']);
		$employerSsnitPesewas  = self::percentOf($basicPesewas, (float) $rateSet['employer_ssnit_percent']);
		$tier1Pesewas          = self::percentOf($basicPesewas, (float) $rateSet['ssnit_tier1_percent']);
		$basicLessSsnitPesewas = $basicPesewas - $employeeSsnitPesewas;

		$allowancePesewas        = 0;
		$taxableAllowancePesewas = 0;
		foreach ($payInputs['allowances'] ?? [] as $allowance) {
			$amountPesewas = self::nonNegativePesewas($allowance['amount'], 'An allowance cannot be negative.');
			if ($amountPesewas === 0) {
				continue;
			}
			$isTaxable         = (bool) $allowance['is_taxable'];
			$allowancePesewas += $amountPesewas;
			if ($isTaxable) {
				$taxableAllowancePesewas += $amountPesewas;
			}
			$items[] = self::item('allowance', (string) $allowance['item_name'], $isTaxable, $amountPesewas);
		}

		$assessablePesewas  = $basicLessSsnitPesewas + $taxableAllowancePesewas;
		$tier3Pesewas       = self::percentOf($basicPesewas, $tier3Percent);
		$tier3ReliefPesewas = min($tier3Pesewas, self::percentOf($basicPesewas, (float) $rateSet['tier3_relief_cap_percent']));

		$personalReliefPesewas = 0;
		foreach (self::reliefItems($payInputs['relief_claims'] ?? [], $rateSet['reliefs'] ?? [], $assessablePesewas) as $reliefItem) {
			$personalReliefPesewas += $reliefItem['amount_pesewas'];
			$items[] = self::item('relief', $reliefItem['item_name'], null, $reliefItem['amount_pesewas']);
		}

		$chargeablePesewas = max(0, $assessablePesewas - $tier3ReliefPesewas - $personalReliefPesewas);
		$payePesewas       = self::payePesewas($chargeablePesewas, $rateSet['bands'] ?? []);

		$deductionPesewas = 0;
		foreach ($payInputs['deductions'] ?? [] as $deduction) {
			$amountPesewas = self::nonNegativePesewas($deduction['amount'], 'A deduction cannot be negative.');
			if ($amountPesewas === 0) {
				continue;
			}
			$deductionPesewas += $amountPesewas;
			$items[] = self::item('deduction', (string) $deduction['item_name'], null, $amountPesewas);
		}

		$netPayPesewas = $basicLessSsnitPesewas + $allowancePesewas - $tier3Pesewas - $payePesewas - $deductionPesewas;
		if ($netPayPesewas < 0) {
			throw new \InvalidArgumentException('Deductions come to more than this employee is paid.');
		}

		return [
			'basic_salary'       => self::toCedis($basicPesewas),
			'employee_ssnit'     => self::toCedis($employeeSsnitPesewas),
			'basic_less_ssnit'   => self::toCedis($basicLessSsnitPesewas),
			'allowance'          => self::toCedis($allowancePesewas),
			'taxable_allowance'  => self::toCedis($taxableAllowancePesewas),
			'tier3_contribution' => self::toCedis($tier3Pesewas),
			'tier3_relief'       => self::toCedis($tier3ReliefPesewas),
			'personal_relief'    => self::toCedis($personalReliefPesewas),
			'chargeable_income'  => self::toCedis($chargeablePesewas),
			'paye'               => self::toCedis($payePesewas),
			'other_deductions'   => self::toCedis($deductionPesewas),
			'net_pay'            => self::toCedis($netPayPesewas),
			'employer_ssnit'     => self::toCedis($employerSsnitPesewas),
			'ssnit_tier1'        => self::toCedis($tier1Pesewas),
			'ssnit_tier2'        => self::toCedis($employeeSsnitPesewas + $employerSsnitPesewas - $tier1Pesewas),
			'items'              => $items,
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

	/**
	 * The month's share of each claimed relief.
	 *
	 * @return array<int, array{item_name: string, amount_pesewas: int}>
	 * @throws \InvalidArgumentException When a relief is unknown, not in the rates, or claimed too often.
	 */
	private static function reliefItems(array $reliefClaims, array $reliefRates, int $assessablePesewas): array {
		$unknownCodes = array_diff(array_keys(array_filter($reliefClaims)), array_keys(TaxReliefTypes::LABELS));
		if ($unknownCodes !== []) {
			throw new \InvalidArgumentException('Unknown tax relief: ' . implode(', ', $unknownCodes) . '.');
		}

		$reliefItems = [];
		foreach (array_keys(TaxReliefTypes::LABELS) as $reliefCode) {
			$claimCount = (int) ($reliefClaims[$reliefCode] ?? 0);
			if ($claimCount <= 0) {
				continue;
			}
			$reliefLabel = TaxReliefTypes::label($reliefCode);
			$reliefRate  = $reliefRates[$reliefCode] ?? null;
			if ($reliefRate === null) {
				throw new \InvalidArgumentException("{$reliefLabel} relief is not in the payroll rates in force.");
			}
			if ($claimCount > (int) $reliefRate['max_claims']) {
				throw new \InvalidArgumentException("{$reliefLabel} relief can be claimed at most {$reliefRate['max_claims']} times.");
			}

			$amountPesewas = $reliefRate['percent_of_assessable'] !== null && $reliefRate['percent_of_assessable'] !== ''
				? self::percentOf(max(0, $assessablePesewas), (float) $reliefRate['percent_of_assessable'])
				: (int) round(self::toPesewas((float) $reliefRate['annual_amount']) * $claimCount / 12);

			if ($amountPesewas > 0) {
				$reliefItems[] = ['item_name' => $reliefLabel, 'amount_pesewas' => $amountPesewas];
			}
		}

		return $reliefItems;
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

	/** @return array{item_kind: string, item_name: string, is_taxable: ?bool, amount: float} */
	private static function item(string $itemKind, string $itemName, ?bool $isTaxable, int $amountPesewas): array {
		return ['item_kind' => $itemKind, 'item_name' => $itemName, 'is_taxable' => $isTaxable, 'amount' => self::toCedis($amountPesewas)];
	}

	private static function nonNegativePesewas(mixed $amount, string $refusal): int {
		$amountPesewas = self::toPesewas((float) $amount);
		if ($amountPesewas < 0) {
			throw new \InvalidArgumentException($refusal);
		}
		return $amountPesewas;
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
