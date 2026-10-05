<?php

namespace App\Services;

/**
 * BankEmailTemplate
 * The words of the payroll email to a Client's bank: the template a Client starts with, the
 * {placeholders} a template may use, and how a template becomes the email for one run.
 */
final class BankEmailTemplate {

	public const DEFAULT_SUBJECT = 'Payroll for {pay_month} - {client_name}';

	public const DEFAULT_BODY = "Dear Team,\n\n"
		. "Please find attached the payroll of {client_name} for {pay_month}.\n\n"
		. "Employees to pay: {employee_count}\n"
		. "Total take home: GHS {total_take_home}\n\n"
		. "Kindly process the salaries and confirm once they are paid.\n\n"
		. "Regards,\n"
		. '{accountant_name}';

	/** Placeholder => what it stands for, in the order they are offered. */
	public const PLACEHOLDERS = [
		'{client_name}'     => 'Client name',
		'{bank_name}'       => 'Bank name',
		'{pay_month}'       => 'Month paid',
		'{employee_count}'  => 'Employees paid',
		'{total_take_home}' => 'Total take home',
		'{accountant_name}' => 'Your name',
	];

	/**
	 * Fill a template's placeholders for one run. Anything in braces that is not a placeholder is left as typed.
	 *
	 * @param array $run Run row: pay_period, employee_count, total_net_pay.
	 */
	public static function render(string $template, array $run, string $clientName, ?string $bankName, string $accountantName): string {
		return strtr($template, [
			'{client_name}'     => $clientName,
			'{bank_name}'       => (string) $bankName,
			'{pay_month}'       => date('F Y', strtotime((string) $run['pay_period'])),
			'{employee_count}'  => (string) (int) $run['employee_count'],
			'{total_take_home}' => number_format((float) $run['total_net_pay'], 2),
			'{accountant_name}' => $accountantName,
		]);
	}

	/** A subject is one line: line breaks typed or filled in become spaces. */
	public static function singleLine(string $text): string {
		return trim((string) preg_replace('/\s+/', ' ', $text));
	}
}
