<?php

namespace App\Services;

use App\Models\PayrollRun;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * BankPaymentFileService
 * The Excel file a Client's bank pays salaries from, in the layout the bank is used to receiving:
 *   Name | AccountNumber | BankName | BankBranch | SortCode | Amount
 * One row per employee paid by the run, Amount being the take home. No title and no total row:
 * the bank reads the sheet as a list.
 */
final class BankPaymentFileService {

	private const COLUMN_HEADINGS = ['Name', 'AccountNumber', 'BankName', 'BankBranch', 'SortCode', 'Amount'];
	private const COLUMN_WIDTHS   = ['A' => 32, 'B' => 17, 'C' => 15, 'D' => 22, 'E' => 10, 'F' => 13];
	private const AMOUNT_FORMAT   = '#,##0.00';

	/** Without these the bank cannot pay an employee. */
	private const REQUIRED_ACCOUNT_DETAILS = ['bank_account_number', 'bank_name'];

	/**
	 * Names of the employees a run pays whose account number or bank is not recorded.
	 *
	 * @return string[]
	 */
	public function employeesMissingAccountDetails(int $payrollRunId, int $clientId): array {
		$employeeNames = [];
		foreach (PayrollRun::getBankPaymentLines($payrollRunId, $clientId) as $paymentLine) {
			foreach (self::REQUIRED_ACCOUNT_DETAILS as $requiredDetail) {
				if (trim((string) $paymentLine[$requiredDetail]) === '') {
					$employeeNames[] = (string) $paymentLine['employee_name'];
					break;
				}
			}
		}
		return $employeeNames;
	}

	/** "Adom Pharmacy Ltd Staff Salary October 2026.xlsx", with anything a file name cannot hold removed. */
	public function xlsxFilename(array $run, string $clientName): string {
		$safeClientName = trim((string) preg_replace('/[^\p{L}\p{N} &.\-]+/u', ' ', $clientName));
		$safeClientName = (string) preg_replace('/\s+/', ' ', $safeClientName);
		return trim(mb_substr($safeClientName, 0, 60) . ' Staff Salary ' . date('F Y', strtotime((string) $run['pay_period']))) . '.xlsx';
	}

	/** The workbook as file contents, for attaching to an email. */
	public function xlsxContents(int $payrollRunId, int $clientId): string {
		$spreadsheet = $this->build(PayrollRun::getBankPaymentLines($payrollRunId, $clientId));

		ob_start();
		try {
			(new Xlsx($spreadsheet))->save('php://output');
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$spreadsheet->disconnectWorksheets();
		}
	}

	/** Stream the workbook directly to the browser. */
	public function streamXlsx(array $run, int $clientId, string $clientName): void {
		$fileContents = $this->xlsxContents((int) $run['payroll_run_id'], $clientId);

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $this->xlsxFilename($run, $clientName) . '"');
		// Pay and account numbers are confidential: no browser or proxy cache keeps the file.
		header('Cache-Control: no-store, private');
		echo $fileContents;
		exit;
	}

	/**
	 * @param array $paymentLines Rows from PayrollRun::getBankPaymentLines().
	 */
	private function build(array $paymentLines): Spreadsheet {
		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Sheet1');

		foreach (self::COLUMN_HEADINGS as $headingIndex => $heading) {
			$sheet->setCellValue(chr(65 + $headingIndex) . '1', $heading);
		}

		$sheetRow = 2;
		foreach ($paymentLines as $paymentLine) {
			// Explicit strings: a name starting with "=" must never be read as a formula, and an account
			// number or sort code is an identifier — as a number it would lose a leading 0 or its last digits.
			$sheet->setCellValueExplicit('A' . $sheetRow, mb_strtoupper((string) $paymentLine['employee_name']), DataType::TYPE_STRING);
			$sheet->setCellValueExplicit('B' . $sheetRow, (string) $paymentLine['bank_account_number'], DataType::TYPE_STRING);
			$sheet->setCellValueExplicit('C' . $sheetRow, mb_strtoupper((string) $paymentLine['bank_name']), DataType::TYPE_STRING);
			$sheet->setCellValueExplicit('D' . $sheetRow, mb_strtoupper((string) $paymentLine['bank_branch']), DataType::TYPE_STRING);
			$sheet->setCellValueExplicit('E' . $sheetRow, (string) $paymentLine['bank_sort_code'], DataType::TYPE_STRING);
			$sheet->setCellValue('F' . $sheetRow, (float) $paymentLine['net_pay']);
			$sheetRow++;
		}

		$lastRow = max($sheetRow - 1, 1);
		$sheet->getStyle('F1:F' . $lastRow)->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
		if ($lastRow > 1) {
			$sheet->getStyle('F2:F' . $lastRow)->getFont()->setBold(true);
		}
		foreach (self::COLUMN_WIDTHS as $columnLetter => $columnWidth) {
			$sheet->getColumnDimension($columnLetter)->setWidth($columnWidth);
		}

		return $spreadsheet;
	}
}
