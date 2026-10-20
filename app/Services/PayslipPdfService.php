<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PayslipPdfService
 * One employee's payslip for one finalised or paid run, as an A4 PDF in the ledger layout: earnings and
 * deductions side by side, the net pay bar, then strips showing how the PAYE was reached and how the SSNIT
 * contributions split into Tier 1 and Tier 2. The same layout as the printable payslip page
 * (frontend/src/payroll/PayslipsPage.tsx).
 */
final class PayslipPdfService {

	/**
	 * @param array $run      A run PayrollService::findRun() returned (its rates and pay month are used).
	 * @param array $line     One of its lines, with its items.
	 * @param array{client_name: string, ssnit_employer_number: string} $employer
	 */
	public function pdfContents(array $run, array $line, array $employer): string {
		$options = new Options();
		// Nothing outside the document: no remote images or fonts, no PHP in the template.
		$options->setIsRemoteEnabled(false);
		$options->setIsPhpEnabled(false);
		$options->setDefaultFont('DejaVu Sans');

		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($this->payslipHtml($run, $line, $employer), 'UTF-8');
		$dompdf->setPaper('A4');
		$dompdf->render();
		return (string) $dompdf->output();
	}

	/**
	 * Send one payslip to the browser as a download, and end the request.
	 *
	 * @param array{client_name: string, ssnit_employer_number: string} $employer
	 */
	public function streamPdf(array $run, array $line, array $employer): void {
		$pdfContents = $this->pdfContents($run, $line, $employer);
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $this->pdfFilename($run, $line) . '"');
		// Pay is confidential: no browser or proxy cache keeps the file.
		header('Cache-Control: no-store, private');
		echo $pdfContents;
		exit;
	}

	public function pdfFilename(array $run, array $line): string {
		$employeeName = trim((string) preg_replace('/[^A-Za-z0-9 .\-]+/', '', (string) $line['employee_name']));
		return 'Payslip ' . date('Y-m', strtotime((string) $run['pay_period'])) . ($employeeName === '' ? '' : " {$employeeName}") . '.pdf';
	}

	private function payslipHtml(array $run, array $line, array $employer): string {
		$items   = $line['items'] ?? [];
		$itemsOf = static fn(string $itemKind): array => array_values(
			array_filter($items, static fn(array $lineItem): bool => $lineItem['item_kind'] === $itemKind)
		);
		$hasTier3 = (float) $line['tier3_contribution'] > 0;

		$earnings = [['label' => 'Basic salary', 'amount' => $line['basic_salary'], 'tag' => '']];
		foreach ($itemsOf('allowance') as $allowance) {
			$earnings[] = ['label' => $allowance['item_name'], 'amount' => $allowance['amount'], 'tag' => $allowance['is_taxable'] === false ? 'non-taxable' : ''];
		}

		$deductions = [['label' => 'SSNIT (' . $this->percent($run['employee_ssnit_percent']) . '%)', 'amount' => $line['employee_ssnit'], 'tag' => '']];
		if ($hasTier3) {
			$deductions[] = ['label' => 'Tier 3 pension', 'amount' => $line['tier3_contribution'], 'tag' => 'voluntary'];
		}
		$deductions[] = ['label' => 'PAYE', 'amount' => $line['paye'], 'tag' => ''];
		foreach ($itemsOf('deduction') as $deduction) {
			$deductions[] = ['label' => $deduction['item_name'], 'amount' => $deduction['amount'], 'tag' => ''];
		}

		$ledgerRows = '';
		for ($rowIndex = 0; $rowIndex < max(count($earnings), count($deductions)); $rowIndex++) {
			$ledgerRows .= '<tr>' . $this->ledgerCells($earnings[$rowIndex] ?? null, '') . $this->ledgerCells($deductions[$rowIndex] ?? null, 'split') . '</tr>';
		}

		$grossPay        = (float) $line['basic_salary'] + (float) $line['allowance'];
		$totalDeductions = (float) $line['employee_ssnit'] + (float) $line['tier3_contribution'] + (float) $line['paye'] + (float) $line['other_deductions'];

		$reliefs     = $itemsOf('relief');
		$reliefNames = implode(', ', array_map(static fn(array $relief): string => $relief['item_name'], $reliefs));
		$taxCells    = [['Assessable income', (float) $line['basic_less_ssnit'] + (float) $line['taxable_allowance'], '', false]];
		if ((float) $line['tier3_relief'] > 0) {
			$taxCells[] = ['Tier 3 relief', $line['tier3_relief'], '', false];
		}
		if ($reliefs !== []) {
			$taxCells[] = ['Personal relief', $line['personal_relief'], $reliefNames, false];
		}
		$taxCells[] = ['Chargeable income', $line['chargeable_income'], '', true];
		$taxCells[] = ['PAYE', $line['paye'], '', true];

		$tier2Percent = (float) $run['employee_ssnit_percent'] + (float) $run['employer_ssnit_percent'] - (float) $run['ssnit_tier1_percent'];
		$ssnitCells   = [
			['Employee ' . $this->percent($run['employee_ssnit_percent']) . '%', $line['employee_ssnit'], '', false],
			['Employer ' . $this->percent($run['employer_ssnit_percent']) . '%', $line['employer_ssnit'], '', false],
			['Tier 1 ' . $this->percent($run['ssnit_tier1_percent']) . '%', $line['ssnit_tier1'], 'to SSNIT', true],
			['Tier 2 ' . $this->percent($tier2Percent) . '%', $line['ssnit_tier2'], 'to trustee', true],
		];
		if ($hasTier3) {
			$ssnitCells[] = ['Tier 3', $line['tier3_contribution'], 'voluntary', true];
		}

		$employeeFields = '<td class="field"><div class="caption">Employee</div><div class="employee-name">' . $this->text($line['employee_name']) . '</div></td>';
		foreach ([
			'Employee no.' => $line['employee_code'] ?? null,
			'Position'     => trim(implode(', ', array_filter([$line['job_title_name'] ?? null, $line['department_name'] ?? null]))),
			'SSNIT no.'    => $line['ssnit_number'] ?? null,
			'TIN'          => $line['tax_identification_number'] ?? null,
		] as $fieldLabel => $fieldValue) {
			if ($fieldValue !== null && $fieldValue !== '') {
				$employeeFields .= '<td class="field"><div class="caption">' . $this->text($fieldLabel) . '</div><div>' . $this->text($fieldValue) . '</div></td>';
			}
		}

		$employerName   = trim((string) $employer['client_name']);
		$employerNumber = trim((string) $employer['ssnit_employer_number']);
		$paidOn         = $run['paid_at'] ? '<div class="caption">Paid on ' . $this->text(date('j M Y', strtotime((string) $run['paid_at']))) . '</div>' : '';

		// Zero @page margin: the real margin is the body's padding.
		return '<!doctype html><html><head><meta charset="utf-8"><style>
			@page { margin: 0; }
			body { margin: 0; padding: 14mm 16mm; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #16202e; }
			table { border-collapse: collapse; width: 100%; }
			td, th { vertical-align: top; }
			.caption { font-size: 6.5pt; font-weight: bold; color: #55606f; text-transform: uppercase; letter-spacing: 0.6px; }
			.masthead td { padding: 0 0 8px; border-bottom: 2px solid #16202e; }
			.employer-name { font-size: 12pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
			.document-name { font-size: 16pt; font-weight: bold; letter-spacing: 4px; }
			.pay-month { font-size: 10pt; font-weight: bold; margin-top: 2px; }
			.employee { margin-top: 10px; background: #f3f5f8; }
			.employee .field { padding: 7px 10px; }
			.employee-name { font-size: 10.5pt; font-weight: bold; }
			.ledger { margin-top: 10px; border: 1px solid #16202e; table-layout: fixed; }
			.ledger th { background: #f3f5f8; border-bottom: 1px solid #16202e; padding: 6px 10px; text-align: left; font-size: 6.5pt; color: #55606f; letter-spacing: 0.6px; }
			.ledger td { padding: 4px 10px; }
			.ledger .amount { text-align: right; white-space: nowrap; }
			.ledger .split { border-left: 1px solid #16202e; }
			.ledger .tag { font-size: 6.5pt; color: #55606f; }
			.ledger .total td { border-top: 1px solid #16202e; font-weight: bold; padding-top: 6px; padding-bottom: 6px; }
			.net-pay td { background: #16202e; color: #ffffff; padding: 9px 10px; font-weight: bold; vertical-align: middle; }
			.net-pay .net-label { letter-spacing: 2px; font-size: 9pt; }
			.net-pay .net-amount { text-align: right; font-size: 14pt; }
			.strip { margin-top: 10px; border: 1px solid #c9cfd8; table-layout: fixed; }
			.strip td { padding: 6px 8px; border-left: 1px solid #c9cfd8; }
			.strip td.strip-heading { border-left: 0; background: #f3f5f8; vertical-align: middle; }
			.strip .figure { font-size: 9.5pt; margin-top: 2px; }
			.strip .result { font-weight: bold; }
			.strip .note { font-size: 6.5pt; color: #55606f; margin-top: 1px; }
			.signatures td { padding-top: 34px; width: 50%; }
			.signatures div { border-top: 1px solid #16202e; width: 58mm; padding-top: 3px; color: #55606f; font-size: 7.5pt; }
		</style></head><body>
			<table class="masthead"><tr>
				<td style="vertical-align:bottom">' . ($employerName !== '' ? '<div class="employer-name">' . $this->text($employerName) . '</div>' : '')
				. ($employerNumber !== '' ? '<div class="caption" style="margin-top:3px">Employer SSNIT no. ' . $this->text($employerNumber) . '</div>' : '') . '</td>
				<td style="text-align:right; vertical-align:bottom"><div class="document-name">PAYSLIP</div>
					<div class="pay-month">' . $this->text(date('F Y', strtotime((string) $run['pay_period']))) . '</div>' . $paidOn . '</td>
			</tr></table>

			<table class="employee"><tr>' . $employeeFields . '</tr></table>

			<table class="ledger">
				<colgroup><col style="width:32%"><col style="width:18%"><col style="width:32%"><col style="width:18%"></colgroup>
				<tr><th>EARNINGS</th><th class="amount">GHS</th><th class="split">DEDUCTIONS</th><th class="amount">GHS</th></tr>
				' . $ledgerRows . '
				<tr class="total"><td>Gross pay</td><td class="amount">' . $this->money($grossPay) . '</td>
					<td class="split">Total deductions</td><td class="amount">' . $this->money($totalDeductions) . '</td></tr>
			</table>

			<table class="net-pay"><tr><td class="net-label">NET PAY</td><td class="net-amount">GHS ' . $this->money($line['net_pay']) . '</td></tr></table>

			' . $this->strip('Tax analysis', $taxCells) . '
			' . $this->strip('SSNIT', $ssnitCells) . '

			<table class="signatures"><tr><td><div>Authorised signatory</div></td><td><div style="margin-left:auto">Employee</div></td></tr></table>
		</body></html>';
	}

	/** @param array{label: string, amount: mixed, tag: string}|null $ledgerEntry Null leaves the side blank. */
	private function ledgerCells(?array $ledgerEntry, string $sideClass): string {
		if ($ledgerEntry === null) {
			return '<td class="' . $sideClass . '">&nbsp;</td><td></td>';
		}
		$tag = $ledgerEntry['tag'] === '' ? '' : ' <span class="tag">' . $this->text($ledgerEntry['tag']) . '</span>';
		return '<td class="' . $sideClass . '">' . $this->text($ledgerEntry['label']) . $tag . '</td><td class="amount">' . $this->money($ledgerEntry['amount']) . '</td>';
	}

	/** @param array<int, array{0: string, 1: mixed, 2: string, 3: bool}> $cells label, amount, note, is a result */
	private function strip(string $heading, array $cells): string {
		$cellsHtml = '';
		foreach ($cells as [$label, $amount, $note, $isResult]) {
			$cellsHtml .= '<td><div class="caption">' . $this->text($label) . '</div>'
				. '<div class="figure' . ($isResult ? ' result' : '') . '">' . $this->money($amount) . '</div>'
				. ($note === '' ? '' : '<div class="note">' . $this->text($note) . '</div>') . '</td>';
		}
		return '<table class="strip"><tr><td class="strip-heading caption">' . $this->text($heading) . '</td>' . $cellsHtml . '</tr></table>';
	}

	private function money(mixed $amount): string {
		return number_format((float) $amount, 2);
	}

	/** 5.50 → 5.5, 13.00 → 13 */
	private function percent(mixed $percent): string {
		return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
	}

	private function text(mixed $plainText): string {
		return htmlspecialchars((string) $plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
