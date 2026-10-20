<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The payroll arithmetic, checked against a real September 2026 payroll sheet: every
 * distinct salary shape on it must give the chargeable income, PAYE and take home the sheet shows.
 * Also guards the rounding rule (figures are whole pesewas, so a line always adds up) and the
 * refusals a salary can hit.
 */
final class PayrollCalculatorTest extends TestCase {

	/** SSNIT 5.5% / 13% (Tier 1 13.5%), the monthly PAYE bands in force from January 2024 and the GRA reliefs. */
	private const RATE_SET = [
		'employee_ssnit_percent'   => 5.5,
		'employer_ssnit_percent'   => 13.0,
		'ssnit_tier1_percent'      => 13.5,
		'tier3_relief_cap_percent' => 16.5,
		'reliefs' => [
			'marriage_responsibility' => ['annual_amount' => 1200.00, 'percent_of_assessable' => null, 'max_claims' => 1],
			'child_education'         => ['annual_amount' => 600.00, 'percent_of_assessable' => null, 'max_claims' => 3],
			'disability'              => ['annual_amount' => null, 'percent_of_assessable' => 25.00, 'max_claims' => 1],
		],
		'bands' => [
			['band_width' => 490.00,   'rate_percent' => 0.0],
			['band_width' => 110.00,   'rate_percent' => 5.0],
			['band_width' => 130.00,   'rate_percent' => 10.0],
			['band_width' => 3166.67,  'rate_percent' => 17.5],
			['band_width' => 16000.00, 'rate_percent' => 25.0],
			['band_width' => 30520.00, 'rate_percent' => 30.0],
			['band_width' => null,     'rate_percent' => 35.0],
		],
	];

	/**
	 * @return array<string, array{float, float, float, float, float}>
	 *         basic, allowance, then the sheet's chargeable income, PAYE and take home.
	 */
	public static function sheetRows(): array {
		return [
			'4,000 basic, 3,000 allowance' => [4000.00, 3000.00, 6780.00, 1293.50, 5486.50],
			'2,000 basic, 1,110 allowance' => [2000.00, 1110.00, 3000.00, 415.75, 2584.25],
			'995 basic, 559.73 allowance'  => [995.00, 559.73, 1500.00, 153.25, 1346.75],
			'995.60 basic, 459.16'         => [995.60, 459.16, 1400.00, 135.75, 1264.25],
			'995 basic, 259.73 allowance'  => [995.00, 259.73, 1200.00, 100.75, 1099.25],
			'742.54 basic, 298.30'         => [742.54, 298.30, 1000.00, 65.75, 934.25],
			'742.54 basic, 98.30'          => [742.54, 98.30, 800.00, 30.75, 769.25],
		];
	}

	#[Test]
	#[DataProvider('sheetRows')]
	public function it_reproduces_the_payroll_sheet(
		float $basicSalary,
		float $flatAllowance,
		float $sheetChargeableIncome,
		float $sheetPaye,
		float $sheetTakeHome
	): void {
		$pay = PayrollCalculator::compute(self::basicAndAllowance($basicSalary, $flatAllowance), self::RATE_SET);

		$this->assertSame($sheetChargeableIncome, $pay['chargeable_income']);
		$this->assertSame($sheetPaye, $pay['paye']);
		$this->assertSame($sheetTakeHome, $pay['net_pay']);
	}

	#[Test]
	public function it_rounds_ssnit_to_the_pesewa_and_keeps_the_line_adding_up(): void {
		// The sheet carries 40.8397 here; a payslip cannot.
		$pay = PayrollCalculator::compute(self::basicAndAllowance(742.54, 298.30), self::RATE_SET);

		$this->assertSame(40.84, $pay['employee_ssnit']);
		$this->assertSame(701.70, $pay['basic_less_ssnit']);
		$this->assertSame(298.30, $pay['allowance']);
		$this->assertSame(96.53, $pay['employer_ssnit']);
		$this->assertSame($pay['chargeable_income'], round($pay['basic_less_ssnit'] + $pay['allowance'], 2));
		$this->assertSame($pay['net_pay'], round($pay['chargeable_income'] - $pay['paye'], 2));
	}

	#[Test]
	public function it_charges_no_paye_inside_the_tax_free_band(): void {
		$this->assertSame(0.0, PayrollCalculator::paye(0.0, self::RATE_SET['bands']));
		$this->assertSame(0.0, PayrollCalculator::paye(490.00, self::RATE_SET['bands']));
		$this->assertSame(0.05, PayrollCalculator::paye(491.00, self::RATE_SET['bands']));
	}

	#[Test]
	public function it_taxes_income_above_the_last_fixed_band_at_the_open_band_rate(): void {
		// The fixed bands end at 50,416.67 with 13,728.67 of tax; 1,000 more is taxed at 35%.
		$this->assertSame(13728.67, PayrollCalculator::paye(50416.67, self::RATE_SET['bands']));
		$this->assertSame(14078.67, PayrollCalculator::paye(51416.67, self::RATE_SET['bands']));
	}

	#[Test]
	public function it_refuses_negative_amounts(): void {
		foreach ([[-1.0, 0.0], [100.0, -5.0]] as [$basicSalary, $flatAllowance]) {
			try {
				PayrollCalculator::compute(self::basicAndAllowance($basicSalary, $flatAllowance), self::RATE_SET);
				$this->fail('Expected a refusal.');
			} catch (\InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	#[Test]
	public function it_splits_the_ssnit_contributions_into_tier_1_and_tier_2_that_add_up_to_what_was_taken(): void {
		$pay = PayrollCalculator::compute(self::basicAndAllowance(742.54, 0), self::RATE_SET);

		// 18.5% of 742.54 is 40.84 + 96.53 = 137.37; Tier 1 is 13.5% = 100.24, Tier 2 the remaining 37.13.
		$this->assertSame(100.24, $pay['ssnit_tier1']);
		$this->assertSame(37.13, $pay['ssnit_tier2']);
		$this->assertSame(round($pay['employee_ssnit'] + $pay['employer_ssnit'], 2), round($pay['ssnit_tier1'] + $pay['ssnit_tier2'], 2));
	}

	#[Test]
	public function a_non_taxable_allowance_is_paid_without_being_taxed(): void {
		$payInputs                 = self::basicAndAllowance(2000.00, 1110.00);
		$payInputs['allowances'][] = ['item_name' => 'Education', 'amount' => 500.00, 'is_taxable' => false];

		$pay = PayrollCalculator::compute($payInputs, self::RATE_SET);

		// Same tax as the sheet's 2,000 + 1,110 row; the 500 goes straight to take home.
		$this->assertSame(3000.00, $pay['chargeable_income']);
		$this->assertSame(415.75, $pay['paye']);
		$this->assertSame(1610.00, $pay['allowance']);
		$this->assertSame(1110.00, $pay['taxable_allowance']);
		$this->assertSame(3084.25, $pay['net_pay']);
	}

	#[Test]
	public function tier_3_is_taken_from_pay_and_relieved_only_up_to_the_cap(): void {
		$payInputs = self::basicAndAllowance(4000.00, 0) + ['tier3_percent' => 20.0];

		$pay = PayrollCalculator::compute($payInputs, self::RATE_SET);

		// 20% of 4,000 is 800 taken; only 16.5% (660) comes off chargeable income.
		$this->assertSame(800.00, $pay['tier3_contribution']);
		$this->assertSame(660.00, $pay['tier3_relief']);
		$this->assertSame(3120.00, $pay['chargeable_income']);
		$this->assertSame(round(3780.00 - 800.00 - $pay['paye'], 2), $pay['net_pay']);
	}

	#[Test]
	public function personal_reliefs_come_off_chargeable_income_a_twelfth_at_a_time(): void {
		$payInputs = self::basicAndAllowance(2000.00, 1110.00)
			+ ['relief_claims' => ['marriage_responsibility' => 1, 'child_education' => 2]];

		$pay = PayrollCalculator::compute($payInputs, self::RATE_SET);

		// 1,200 / 12 = 100 and 600 x 2 / 12 = 100.
		$this->assertSame(200.00, $pay['personal_relief']);
		$this->assertSame(2800.00, $pay['chargeable_income']);
		$this->assertSame(
			[['item_kind' => 'relief', 'item_name' => 'Marriage / responsibility', 'is_taxable' => null, 'amount' => 100.0],
			 ['item_kind' => 'relief', 'item_name' => 'Child education', 'is_taxable' => null, 'amount' => 100.0]],
			array_values(array_filter($pay['items'], static fn(array $item): bool => $item['item_kind'] === 'relief'))
		);
	}

	#[Test]
	public function disability_relief_is_a_share_of_assessable_income(): void {
		$pay = PayrollCalculator::compute(self::basicAndAllowance(2000.00, 1110.00) + ['relief_claims' => ['disability' => 1]], self::RATE_SET);

		$this->assertSame(750.00, $pay['personal_relief']);
		$this->assertSame(2250.00, $pay['chargeable_income']);
	}

	#[Test]
	public function deductions_come_out_of_take_home_after_paye(): void {
		$payInputs = self::basicAndAllowance(4000.00, 3000.00)
			+ ['deductions' => [['item_name' => 'Staff loan', 'amount' => 486.50], ['item_name' => 'Welfare', 'amount' => 0]]];

		$pay = PayrollCalculator::compute($payInputs, self::RATE_SET);

		$this->assertSame(1293.50, $pay['paye']);
		$this->assertSame(486.50, $pay['other_deductions']);
		$this->assertSame(5000.00, $pay['net_pay']);
		$this->assertCount(2, $pay['items'], 'The zero welfare deduction is left off the line.');
	}

	#[Test]
	public function it_refuses_deductions_that_exceed_pay_and_reliefs_claimed_too_often(): void {
		$refusedInputs = [
			self::basicAndAllowance(1000.00, 0) + ['deductions' => [['item_name' => 'Loan', 'amount' => 5000.00]]],
			self::basicAndAllowance(1000.00, 0) + ['relief_claims' => ['child_education' => 4]],
			self::basicAndAllowance(1000.00, 0) + ['relief_claims' => ['aged_dependant' => 1]],
			self::basicAndAllowance(1000.00, 0) + ['tier3_percent' => 101],
		];
		foreach ($refusedInputs as $payInputs) {
			try {
				PayrollCalculator::compute($payInputs, self::RATE_SET);
				$this->fail('Expected a refusal.');
			} catch (\InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/** One taxable allowance, as on the accountant's sheet. */
	private static function basicAndAllowance(float $basicSalary, float $allowance): array {
		return [
			'basic_salary' => $basicSalary,
			'allowances'   => [['item_name' => 'Allowance', 'amount' => $allowance, 'is_taxable' => true]],
		];
	}
}
