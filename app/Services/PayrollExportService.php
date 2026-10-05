<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * PayrollExportService
 * The monthly payroll schedule as an Excel file, in the layout of the accountant's own payroll sheet:
 *   No. | NAME | BASIC SALARY | SSNIT | BASIC SALARY LESS SSNIT | ALLOWANCE | CHARGEABLE INCOME |
 *   PAYE/TAX DEDUCTION | TAKE HOME AFTER PAYE | EMPLOYER SSNIT
 * with a TOTAL row. Figures are written as values, not formulas: the file shows what the run recorded.
 */
class PayrollExportService {

	private const MONEY_FORMAT = '#,##0.00';

	/** Line figure => column heading, in sheet order. */
	private const FIGURE_COLUMNS = [
		'basic_salary'      => 'BASIC SALARY',
		'employee_ssnit'    => 'SSNIT',
		'basic_less_ssnit'  => 'BASIC SALARY LESS SSNIT',
		'allowance'         => 'ALLOWANCE',
		'chargeable_income' => 'CHARGEABLE INCOME',
		'paye'              => 'PAYE/TAX DEDUCTION',
		'net_pay'           => 'TAKE HOME AFTER PAYE',
		'employer_ssnit'    => 'EMPLOYER SSNIT',
	];

	/**
	 * Stream the workbook directly to the browser.
	 *
	 * @param array $run Run row with its 'lines'.
	 */
	public function streamXlsx(array $run, string $clientName): void {
		$spreadsheet = $this->build($run, $clientName);
		$filename    = 'Payroll-' . date('Y-m', strtotime((string) $run['pay_period'])) . '.xlsx';

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		// Pay is confidential: no browser or proxy cache keeps the schedule.
		header('Cache-Control: no-store, private');

		(new Xlsx($spreadsheet))->save('php://output');
		$spreadsheet->disconnectWorksheets();
		exit;
	}

	private function build(array $run, string $clientName): Spreadsheet {
		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Payroll');

		// Title block
		$sheet->setCellValueExplicit('B1', $clientName !== '' ? strtoupper($clientName) : 'PAYROLL', DataType::TYPE_STRING);
		$sheet->setCellValue('B2', 'PAYROLL FOR THE MONTH OF ' . strtoupper(date('F, Y', strtotime((string) $run['pay_period']))));
		$sheet->getStyle('B1')->getFont()->setBold(true)->setSize(14);
		$sheet->getStyle('B2')->getFont()->setBold(true);

		$headerRow = 4;
		$headings  = array_merge(['No.', 'NAME'], array_values(self::FIGURE_COLUMNS));
		$headings[3] .= ' (' . $this->formatPercent($run['employee_ssnit_percent']) . '%)';
		$headings[9] .= ' (' . $this->formatPercent($run['employer_ssnit_percent']) . '%)';

		foreach ($headings as $headingIndex => $heading) {
			$sheet->setCellValue($this->columnLetter($headingIndex + 1) . $headerRow, $heading);
		}

		$lastColumn  = $this->columnLetter(count($headings));
		$headerRange = 'A' . $headerRow . ':' . $lastColumn . $headerRow;
		$sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
		$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F4E78');
		$sheet->getStyle($headerRange)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

		// Body
		$sheetRow = $headerRow + 1;
		foreach ($run['lines'] as $lineIndex => $line) {
			$sheet->setCellValue('A' . $sheetRow, $lineIndex + 1);
			// Explicit string: a name starting with "=" must never be read as a formula.
			$sheet->setCellValueExplicit('B' . $sheetRow, (string) $line['employee_name'], DataType::TYPE_STRING);
			$figureColumn = 3;
			foreach (array_keys(self::FIGURE_COLUMNS) as $figure) {
				$sheet->setCellValue($this->columnLetter($figureColumn) . $sheetRow, (float) $line[$figure]);
				$figureColumn++;
			}
			$sheetRow++;
		}

		// Total row: the run's stored totals, which are the sum of the lines.
		$totalRow = $sheetRow + 1;
		$sheet->setCellValue('B' . $totalRow, 'TOTAL');
		$figureColumn = 3;
		foreach (array_keys(self::FIGURE_COLUMNS) as $figure) {
			$sheet->setCellValue($this->columnLetter($figureColumn) . $totalRow, (float) $run['total_' . $figure]);
			$figureColumn++;
		}
		$sheet->getStyle('A' . $totalRow . ':' . $lastColumn . $totalRow)->getFont()->setBold(true);

		$figureRange = 'C' . ($headerRow + 1) . ':' . $lastColumn . $totalRow;
		$sheet->getStyle($figureRange)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
		$sheet->getStyle($figureRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

		$sheet->getColumnDimension('A')->setWidth(6);
		$sheet->getColumnDimension('B')->setAutoSize(true);
		foreach (range(3, count($headings)) as $columnNumber) {
			$sheet->getColumnDimension($this->columnLetter($columnNumber))->setWidth(16);
		}
		$sheet->getRowDimension($headerRow)->setRowHeight(32);

		// Freeze the header row so it stays visible while scrolling
		$sheet->freezePane('C' . ($headerRow + 1));

		return $spreadsheet;
	}

	/** 5.50 → "5.5", 13.00 → "13". */
	private function formatPercent(mixed $percent): string {
		return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
	}

	/** A, B, …, Z, AA, AB, … */
	private function columnLetter(int $columnNumber): string {
		$letters = '';
		while ($columnNumber > 0) {
			$columnNumber--;
			$letters      = chr(65 + ($columnNumber % 26)) . $letters;
			$columnNumber = intdiv($columnNumber, 26);
		}
		return $letters;
	}
}
