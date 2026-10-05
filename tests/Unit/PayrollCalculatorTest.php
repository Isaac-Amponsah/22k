<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The payroll arithmetic, checked against a real September 2026 payroll sheet: every
 * distinct salary shape on it (flat allowance, and top-ups to 3,000 / 1,500 / 1,400 / 1,200 /
 * 1,000 / 800) must give the PAYE and take home the sheet shows. Also guards the rounding rule
 * (figures are whole pesewas, so a line always adds up) and the refusals a salary can hit.
 */
final class PayrollCalculatorTest extends TestCase {

	/** SSNIT 5.5% / 13% and the monthly PAYE bands in force from January 2024. */
	private const RATE_SET = [
		'employee_ssnit_percent' => 5.5,
		'employer_ssnit_percent' => 13.0,
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
	 * @return array<string, array{float, string, float, ?float, float, float, float}>
	 *         basic, mode, flat allowance, target, then the sheet's chargeable income, PAYE and take home.
	 */
	public static function sheetRows(): array {
		return [
			'flat allowance of 3,000' => [4000.00, 'flat', 3000.00, null, 6780.00, 1293.50, 5486.50],
			'top-up to 3,000'         => [2000.00, 'target', 0.0, 3000.00, 3000.00, 415.75, 2584.25],
			'top-up to 1,500'         => [995.00, 'target', 0.0, 1500.00, 1500.00, 153.25, 1346.75],
			'top-up to 1,400'         => [995.60, 'target', 0.0, 1400.00, 1400.00, 135.75, 1264.25],
			'top-up to 1,200'         => [995.00, 'target', 0.0, 1200.00, 1200.00, 100.75, 1099.25],
			'top-up to 1,000'         => [742.54, 'target', 0.0, 1000.00, 1000.00, 65.75, 934.25],
			'top-up to 800'           => [742.54, 'target', 0.0, 800.00, 800.00, 30.75, 769.25],
		];
	}

	#[Test]
	#[DataProvider('sheetRows')]
	public function it_reproduces_the_payroll_sheet(
		float $basicSalary,
		string $allowanceMode,
		float $flatAllowance,
		?float $targetChargeableIncome,
		float $sheetChargeableIncome,
		float $sheetPaye,
		float $sheetTakeHome
	): void {
		$pay = PayrollCalculator::compute($basicSalary, $allowanceMode, $flatAllowance, $targetChargeableIncome, self::RATE_SET);

		$this->assertSame($sheetChargeableIncome, $pay['chargeable_income']);
		$this->assertSame($sheetPaye, $pay['paye']);
		$this->assertSame($sheetTakeHome, $pay['net_pay']);
	}

	#[Test]
	public function it_rounds_ssnit_to_the_pesewa_and_keeps_the_line_adding_up(): void {
		// The sheet carries 40.8397 here; a payslip cannot.
		$pay = PayrollCalculator::compute(742.54, 'target', 0.0, 1000.00, self::RATE_SET);

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
	public function it_refuses_a_target_below_basic_salary_less_ssnit(): void {
		$this->expectException(\InvalidArgumentException::class);
		PayrollCalculator::compute(2000.00, 'target', 0.0, 1500.00, self::RATE_SET);
	}

	#[Test]
	public function it_accepts_a_target_equal_to_basic_salary_less_ssnit(): void {
		$pay = PayrollCalculator::compute(2000.00, 'target', 0.0, 1890.00, self::RATE_SET);
		$this->assertSame(0.0, $pay['allowance']);
	}

	#[Test]
	public function it_refuses_a_target_mode_without_a_target(): void {
		$this->expectException(\InvalidArgumentException::class);
		PayrollCalculator::compute(2000.00, 'target', 0.0, null, self::RATE_SET);
	}

	#[Test]
	public function it_refuses_negative_amounts_and_unknown_modes(): void {
		foreach ([[-1.0, 'flat', 0.0], [100.0, 'flat', -5.0], [100.0, 'bonus', 0.0]] as [$basicSalary, $allowanceMode, $flatAllowance]) {
			try {
				PayrollCalculator::compute($basicSalary, $allowanceMode, $flatAllowance, null, self::RATE_SET);
				$this->fail('Expected a refusal.');
			} catch (\InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
