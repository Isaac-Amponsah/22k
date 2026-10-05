<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BankEmailTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BankEmailTemplateTest extends TestCase {

	private const RUN = ['pay_period' => '2026-10-01', 'employee_count' => 12, 'total_net_pay' => '48250.50'];

	#[Test]
	public function every_offered_placeholder_is_filled_in_the_default_email(): void {
		$emailWords = BankEmailTemplate::render(
			BankEmailTemplate::DEFAULT_SUBJECT . "\n" . BankEmailTemplate::DEFAULT_BODY . "\n{bank_name}",
			self::RUN,
			'Adom Pharmacy Ltd',
			'Unity Bank',
			'Ama Mensah'
		);

		foreach (array_keys(BankEmailTemplate::PLACEHOLDERS) as $placeholder) {
			$this->assertStringNotContainsString($placeholder, $emailWords);
		}
		$this->assertStringContainsString('Payroll for October 2026 - Adom Pharmacy Ltd', $emailWords);
		$this->assertStringContainsString('Employees to pay: 12', $emailWords);
		$this->assertStringContainsString('Total take home: GHS 48,250.50', $emailWords);
		$this->assertStringContainsString("Regards,\nAma Mensah", $emailWords);
		$this->assertStringEndsWith('Unity Bank', $emailWords);
	}

	#[Test]
	public function a_value_that_looks_like_a_placeholder_is_not_filled_a_second_time(): void {
		$emailWords = BankEmailTemplate::render('{client_name} / {accountant_name}', self::RUN, '{accountant_name} Ltd', null, 'Ama');

		$this->assertSame('{accountant_name} Ltd / Ama', $emailWords);
	}

	#[Test]
	public function braces_that_are_not_a_placeholder_stay_as_typed(): void {
		$this->assertSame('Ref {branch} for ', BankEmailTemplate::render('Ref {branch} for {bank_name}', self::RUN, 'Client', null, 'Ama'));
	}

	#[Test]
	public function a_subject_is_brought_onto_one_line(): void {
		$this->assertSame('Payroll Bcc: someone@else.test', BankEmailTemplate::singleLine("  Payroll\r\nBcc: someone@else.test "));
	}
}
