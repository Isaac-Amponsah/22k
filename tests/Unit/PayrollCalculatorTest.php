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
		$pay = PayrollCalculator::compute($basicSalary, $flatAllowance, self::RATE_SET);

		$this->assertSame($sheetChargeableIncome, $pay['chargeable_income']);
		$this->assertSame($sheetPaye, $pay['paye']);
		$this->assertSame($sheetTakeHome, $pay['net_pay']);
	}

	#[Test]
	public function it_rounds_ssnit_to_the_pesewa_and_keeps_the_line_adding_up(): void {
		// The sheet carries 40.8397 here; a payslip cannot.
		$pay = PayrollCalculator::compute(742.54, 298.30, self::RATE_SET);

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
				PayrollCalculator::compute($basicSalary, $flatAllowance, self::RATE_SET);
				$this->fail('Expected a refusal.');
			} catch (\InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
