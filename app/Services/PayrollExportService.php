<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * PayrollExportService
 * A payroll run as Excel, in three layouts:
 *   - the monthly schedule, in the layout of the accountant's own payroll sheet:
 *       No. | NAME | BASIC SALARY | SSNIT | BASIC SALARY LESS SSNIT | <each allowance> | [TIER 3] | [TAX RELIEF] |
 *       CHARGEABLE INCOME | PAYE/TAX DEDUCTION | <each deduction> | TAKE HOME | EMPLOYER SSNIT
 *   - the SSNIT contribution statement: each employee's contributions and their Tier 1 / Tier 2 / Tier 3 split;
 *   - the tax analysis: how each employee's PAYE was reached, from assessable income through the reliefs.
 * Each has a TOTAL row. Figures are written as values, not formulas: the file shows what the run recorded.
 */
class PayrollExportService {

	private const MONEY_FORMAT = '#,##0.00';
	private const HEADER_ROW   = 5;

	/**
	 * Stream the monthly schedule directly to the browser.
	 *
	 * @param array $run Run row with its 'lines', each with its 'items' (PayrollService::findRun()).
	 */
	public function streamXlsx(array $run, string $clientName): void {
		$this->stream(
			$this->build($run, $clientName, 'PAYROLL FOR THE MONTH OF', [], $this->scheduleColumns($run)),
			$this->xlsxFilename($run)
		);
	}

	/** "Payroll-2026-10.xlsx" for the run's month. */
	public function xlsxFilename(array $run): string {
		return 'Payroll-' . $this->periodSlug($run) . '.xlsx';
	}

	/**
	 * Stream the SSNIT contribution statement directly to the browser.
	 */
	public function streamContributionStatementXlsx(array $run, string $clientName, string $ssnitEmployerNumber): void {
		$this->stream(
			$this->build(
				$run,
				$clientName,
				'SSNIT CONTRIBUTION STATEMENT FOR THE MONTH OF',
				$ssnitEmployerNumber === '' ? [] : ['SSNIT EMPLOYER NO. ' . $ssnitEmployerNumber],
				$this->contributionColumns($run)
			),
			'SSNIT-Contributions-' . $this->periodSlug($run) . '.xlsx'
		);
	}

	/**
	 * Stream the tax analysis directly to the browser.
	 */
	public function streamTaxAnalysisXlsx(array $run, string $clientName, string $taxIdentificationNumber): void {
		$this->stream(
			$this->build(
				$run,
				$clientName,
				'PAYE TAX ANALYSIS FOR THE MONTH OF',
				$taxIdentificationNumber === '' ? [] : ['TIN ' . $taxIdentificationNumber],
				$this->taxAnalysisColumns($run)
			),
			'Tax-Analysis-' . $this->periodSlug($run) . '.xlsx'
		);
	}

	// -------------------------------------------------------------------------
	// Layouts
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, array{heading: string, type: string, value: callable, total: ?float}>
	 */
	private function scheduleColumns(array $run): array {
		$lines   = $run['lines'];
		$columns = [
			$this->indexColumn(),
			$this->textColumn('NAME', 'employee_name'),
			$this->figureColumn($run, 'BASIC SALARY', 'basic_salary'),
			$this->figureColumn($run, 'SSNIT (' . $this->formatPercent($run['employee_ssnit_percent']) . '%)', 'employee_ssnit'),
			$this->figureColumn($run, 'BASIC SALARY LESS SSNIT', 'basic_less_ssnit'),
		];

		$allowanceColumns = $this->itemColumns($lines, 'allowance');
		// A run that pays no allowance still shows the column, so the sheet keeps the layout it is imported in.
		array_push($columns, ...($allowanceColumns === [] ? [$this->figureColumn($run, 'ALLOWANCE', 'allowance')] : $allowanceColumns));

		if ((float) $run['total_tier3_contribution'] > 0) {
			$columns[] = $this->figureColumn($run, 'TIER 3', 'tier3_contribution');
		}
		if ((float) $run['total_tier3_relief'] + (float) $run['total_personal_relief'] > 0) {
			$columns[] = $this->sumColumn($lines, 'TAX RELIEF', ['tier3_relief', 'personal_relief']);
		}

		$columns[] = $this->figureColumn($run, 'CHARGEABLE INCOME', 'chargeable_income');
		$columns[] = $this->figureColumn($run, 'PAYE/TAX DEDUCTION', 'paye');
		array_push($columns, ...$this->itemColumns($lines, 'deduction'));
		$columns[] = $this->figureColumn($run, 'TAKE HOME', 'net_pay');
		$columns[] = $this->figureColumn($run, 'EMPLOYER SSNIT (' . $this->formatPercent($run['employer_ssnit_percent']) . '%)', 'employer_ssnit');

		return $columns;
	}

	private function contributionColumns(array $run): array {
		$totalSsnitPercent = (float) $run['employee_ssnit_percent'] + (float) $run['employer_ssnit_percent'];
		$tier2Percent      = $totalSsnitPercent - (float) $run['ssnit_tier1_percent'];

		$columns = [
			$this->indexColumn(),
			$this->textColumn('SSNIT NUMBER', 'ssnit_number'),
			$this->textColumn('NAME', 'employee_name'),
			$this->figureColumn($run, 'BASIC SALARY', 'basic_salary'),
			$this->figureColumn($run, 'EMPLOYEE (' . $this->formatPercent($run['employee_ssnit_percent']) . '%)', 'employee_ssnit'),
			$this->figureColumn($run, 'EMPLOYER (' . $this->formatPercent($run['employer_ssnit_percent']) . '%)', 'employer_ssnit'),
			$this->sumColumn($run['lines'], 'TOTAL (' . $this->formatPercent($totalSsnitPercent) . '%)', ['employee_ssnit', 'employer_ssnit']),
			$this->figureColumn($run, 'TIER 1 (' . $this->formatPercent($run['ssnit_tier1_percent']) . '%)', 'ssnit_tier1'),
			$this->figureColumn($run, 'TIER 2 (' . $this->formatPercent($tier2Percent) . '%)', 'ssnit_tier2'),
		];
		if ((float) $run['total_tier3_contribution'] > 0) {
			$columns[] = $this->figureColumn($run, 'TIER 3 (VOLUNTARY)', 'tier3_contribution');
		}
		return $columns;
	}

	private function taxAnalysisColumns(array $run): array {
		$lines = $run['lines'];
		return [
			$this->indexColumn(),
			$this->textColumn('TIN', 'tax_identification_number'),
			$this->textColumn('NAME', 'employee_name'),
			$this->figureColumn($run, 'BASIC SALARY', 'basic_salary'),
			$this->figureColumn($run, 'SSNIT (' . $this->formatPercent($run['employee_ssnit_percent']) . '%)', 'employee_ssnit'),
			$this->figureColumn($run, 'TAXABLE ALLOWANCES', 'taxable_allowance'),
			$this->differenceColumn($lines, 'NON-TAXABLE ALLOWANCES', 'allowance', 'taxable_allowance'),
			$this->sumColumn($lines, 'ASSESSABLE INCOME', ['basic_less_ssnit', 'taxable_allowance']),
			$this->figureColumn($run, 'TIER 3 RELIEF', 'tier3_relief'),
			$this->figureColumn($run, 'PERSONAL RELIEF', 'personal_relief'),
			$this->figureColumn($run, 'CHARGEABLE INCOME', 'chargeable_income'),
			$this->figureColumn($run, 'PAYE', 'paye'),
		];
	}

	// -------------------------------------------------------------------------
	// Columns
	// -------------------------------------------------------------------------

	private function indexColumn(): array {
		return ['heading' => 'No.', 'type' => 'index', 'value' => static fn(array $line, int $lineIndex): int => $lineIndex + 1, 'total' => null];
	}

	private function textColumn(string $heading, string $lineField): array {
		return ['heading' => $heading, 'type' => 'text', 'value' => static fn(array $line): string => (string) ($line[$lineField] ?? ''), 'total' => null];
	}

	/** A line figure, totalled by the run's own stored total of it. */
	private function figureColumn(array $run, string $heading, string $figure): array {
		return ['heading' => $heading, 'type' => 'money', 'value' => static fn(array $line): float => (float) $line[$figure], 'total' => (float) $run['total_' . $figure]];
	}

	/** Several line figures added together, worked in pesewas so the total is the sum of the rows. */
	private function sumColumn(array $lines, string $heading, array $figures): array {
		$valueOf = static fn(array $line): float => array_sum(array_map(static fn(string $figure): int => (int) round((float) $line[$figure] * 100), $figures)) / 100;
		return ['heading' => $heading, 'type' => 'money', 'value' => $valueOf, 'total' => $this->totalOf($lines, $valueOf)];
	}

	private function differenceColumn(array $lines, string $heading, string $figure, string $lessFigure): array {
		$valueOf = static fn(array $line): float => ((int) round((float) $line[$figure] * 100) - (int) round((float) $line[$lessFigure] * 100)) / 100;
		return ['heading' => $heading, 'type' => 'money', 'value' => $valueOf, 'total' => $this->totalOf($lines, $valueOf)];
	}

	/**
	 * One column per allowance or deduction named on any line, in the order they first appear.
	 */
	private function itemColumns(array $lines, string $itemKind): array {
		$itemHeadings = [];
		foreach ($lines as $line) {
			foreach ($line['items'] ?? [] as $lineItem) {
				if ($lineItem['item_kind'] !== $itemKind || isset($itemHeadings[$lineItem['item_name']])) {
					continue;
				}
				$isNonTaxableAllowance = $itemKind === 'allowance' && !$lineItem['is_taxable'];
				$itemHeadings[$lineItem['item_name']] = mb_strtoupper($lineItem['item_name']) . ($isNonTaxableAllowance ? ' (NON-TAXABLE)' : '');
			}
		}

		$columns = [];
		foreach ($itemHeadings as $itemName => $heading) {
			$valueOf = static function (array $line) use ($itemKind, $itemName): float {
				$amountPesewas = 0;
				foreach ($line['items'] ?? [] as $lineItem) {
					if ($lineItem['item_kind'] === $itemKind && $lineItem['item_name'] === $itemName) {
						$amountPesewas += (int) round((float) $lineItem['amount'] * 100);
					}
				}
				return $amountPesewas / 100;
			};
			$columns[] = ['heading' => $heading, 'type' => 'money', 'value' => $valueOf, 'total' => $this->totalOf($lines, $valueOf)];
		}
		return $columns;
	}

	private function totalOf(array $lines, callable $valueOf): float {
		$totalPesewas = 0;
		foreach ($lines as $lineIndex => $line) {
			$totalPesewas += (int) round($valueOf($line, $lineIndex) * 100);
		}
		return $totalPesewas / 100;
	}

	// -------------------------------------------------------------------------
	// Workbook
	// -------------------------------------------------------------------------

	/**
	 * @param string[] $subtitleLines Shown under the title (the employer's SSNIT number or TIN).
	 */
	private function build(array $run, string $clientName, string $titlePrefix, array $subtitleLines, array $columns): Spreadsheet {
		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle('Payroll');

		// Title block
		$sheet->setCellValueExplicit('B1', $clientName !== '' ? strtoupper($clientName) : 'PAYROLL', DataType::TYPE_STRING);
		$sheet->setCellValue('B2', $titlePrefix . ' ' . strtoupper(date('F, Y', strtotime((string) $run['pay_period']))));
		$sheet->getStyle('B1')->getFont()->setBold(true)->setSize(14);
		$sheet->getStyle('B2')->getFont()->setBold(true);
		foreach ($subtitleLines as $subtitleIndex => $subtitleLine) {
			$sheet->setCellValueExplicit('B' . (3 + $subtitleIndex), $subtitleLine, DataType::TYPE_STRING);
		}

		// Explicit strings: a heading carries a pay item's name, which must never be read as a formula.
		foreach ($columns as $columnIndex => $column) {
			$sheet->setCellValueExplicit($this->columnLetter($columnIndex + 1) . self::HEADER_ROW, (string) $column['heading'], DataType::TYPE_STRING);
		}

		$lastColumn  = $this->columnLetter(count($columns));
		$headerRange = 'A' . self::HEADER_ROW . ':' . $lastColumn . self::HEADER_ROW;
		$sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
		$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F4E78');
		$sheet->getStyle($headerRange)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

		// Body
		$sheetRow = self::HEADER_ROW + 1;
		foreach ($run['lines'] as $lineIndex => $line) {
			foreach ($columns as $columnIndex => $column) {
				$cellAddress = $this->columnLetter($columnIndex + 1) . $sheetRow;
				$cellValue   = ($column['value'])($line, $lineIndex);
				if ($column['type'] === 'text') {
					// Explicit string: a name starting with "=" must never be read as a formula, and an SSNIT
					// number or TIN is an identifier that must keep any leading 0.
					$sheet->setCellValueExplicit($cellAddress, (string) $cellValue, DataType::TYPE_STRING);
				} else {
					$sheet->setCellValue($cellAddress, $cellValue);
				}
			}
			$sheetRow++;
		}

		// Total row: the run's stored totals where a column is one figure, which are the sum of the lines.
		$totalRow         = $sheetRow + 1;
		$labelColumnIndex = $this->lastTextColumnIndex($columns);
		$sheet->setCellValue($this->columnLetter($labelColumnIndex + 1) . $totalRow, 'TOTAL');
		foreach ($columns as $columnIndex => $column) {
			if ($column['total'] !== null) {
				$sheet->setCellValue($this->columnLetter($columnIndex + 1) . $totalRow, $column['total']);
			}
		}
		$sheet->getStyle('A' . $totalRow . ':' . $lastColumn . $totalRow)->getFont()->setBold(true);

		$firstMoneyColumn = $this->columnLetter($labelColumnIndex + 2);
		$figureRange      = $firstMoneyColumn . (self::HEADER_ROW + 1) . ':' . $lastColumn . $totalRow;
		$sheet->getStyle($figureRange)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
		$sheet->getStyle($figureRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

		foreach ($columns as $columnIndex => $column) {
			$columnDimension = $sheet->getColumnDimension($this->columnLetter($columnIndex + 1));
			match ($column['type']) {
				'index' => $columnDimension->setWidth(6),
				'text'  => $columnDimension->setAutoSize(true),
				default => $columnDimension->setWidth(16),
			};
		}
		$sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(32);

		// Freeze the header row and the names so they stay visible while scrolling
		$sheet->freezePane($firstMoneyColumn . (self::HEADER_ROW + 1));

		return $spreadsheet;
	}

	/** The last text column before the figures begin: where the TOTAL label goes. */
	private function lastTextColumnIndex(array $columns): int {
		$lastTextColumnIndex = 0;
		foreach ($columns as $columnIndex => $column) {
			if ($column['type'] === 'money') {
				break;
			}
			$lastTextColumnIndex = $columnIndex;
		}
		return $lastTextColumnIndex;
	}

	private function stream(Spreadsheet $spreadsheet, string $filename): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		// Pay is confidential: no browser or proxy cache keeps the file.
		header('Cache-Control: no-store, private');

		(new Xlsx($spreadsheet))->save('php://output');
		$spreadsheet->disconnectWorksheets();
		exit;
	}

	/** "2026-10" for the run's month. */
	private function periodSlug(array $run): string {
		return date('Y-m', strtotime((string) $run['pay_period']));
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
