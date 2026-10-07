<?php

namespace App\Services;

use App\Helpers\Database;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * PayrollImportService
 * Reads salaries from a payroll workbook laid out like the accountant's own sheet and applies them.
 *
 * The sheet has a two-row heading (BASIC / SALARY, ALLOWANCE, CHARGEABLE / INCOME, …) and one row
 * per employee. Columns are found by their headings, not their letters. The allowance is taken as
 * the flat amount the cell shows, whether typed or worked out by a formula.
 *
 * Nothing is saved on upload: the parsed rows go back to the browser for review, each matched to an
 * employee by name, and are saved only on confirm.
 */
final class PayrollImportService {

	/** Rows read from a workbook; a payroll sheet beyond this is not a payroll sheet. */
	private const MAX_SHEET_ROWS = 2000;

	/** Columns read; the payroll sheet uses nine. */
	private const MAX_SHEET_COLUMNS = 30;

	/** Unpacked size and entry count a real payroll workbook stays far below; guards against a zip bomb. */
	private const MAX_UNPACKED_BYTES  = 20 * 1024 * 1024;
	private const MAX_ARCHIVE_ENTRIES = 200;

	/**
	 * A formula the importer will work out itself when the file carries no saved result: numbers,
	 * cell references and + - * / ( ) only. Anything that calls a function is never evaluated.
	 */
	private const PLAIN_ARITHMETIC_FORMULA = '/^=[\d\s.,+\-*\/()$A-Z]*$/i';
	private const FUNCTION_CALL            = '/[A-Z_.]{2,}\s*\(/i';

	/** Rows searched for the heading. */
	private const HEADING_SEARCH_ROWS = 20;

	/** Preview option: create the employee from the sheet's name instead of matching one. */
	public const CREATE_EMPLOYEE = 'new';

	private PayrollService $payrollService;

	public function __construct() {
		$this->payrollService = new PayrollService();
	}

	/**
	 * Parse the first sheet of a workbook into salary rows.
	 *
	 * @return array Rows of: sheet_row, employee_name, basic_salary, allowance, chargeable_income.
	 * @throws \InvalidArgumentException When the sheet is not in the payroll layout.
	 */
	public function parseWorkbook(string $workbookPath): array {
		$this->assertPlausibleWorkbook($workbookPath);

		$reader = new XlsxReader();
		$reader->setReadEmptyCells(false);
		// Values and formulas only: styles, images and charts are never needed and never loaded.
		$reader->setReadDataOnly(true);
		$reader->setIncludeCharts(false);
		$reader->setReadFilter($this->sheetBoundsFilter());
		$sheet = $reader->load($workbookPath)->getSheet(0);

		$columns = $this->locateColumns($sheet);
		$lastRow = min($sheet->getHighestDataRow(), self::MAX_SHEET_ROWS);
		$rows    = [];

		for ($sheetRow = $columns['first_data_row']; $sheetRow <= $lastRow; $sheetRow++) {
			$employeeName = trim((string) $this->cellValue($sheet, $columns['name'], $sheetRow));
			$basicCell    = $sheet->getCell([$columns['basic'], $sheetRow]);
			$basicSalary  = $this->cellValue($sheet, $columns['basic'], $sheetRow);

			// Skips blank rows and the TOTAL row (a label, or a basic salary that is a formula).
			if ($employeeName === '' || !is_numeric($basicSalary) || $basicCell->isFormula() || preg_match('/^totals?\b/i', $employeeName)) {
				continue;
			}

			$allowance        = round((float) $this->cellValue($sheet, $columns['allowance'], $sheetRow), 2);
			$chargeableIncome = $columns['chargeable'] === null
				? null
				: round((float) $this->cellValue($sheet, $columns['chargeable'], $sheetRow), 2);

			$rows[] = [
				'sheet_row'         => $sheetRow,
				'employee_name'     => preg_replace('/\s+/', ' ', $employeeName),
				'basic_salary'      => round((float) $basicSalary, 2),
				'allowance'         => $allowance,
				'chargeable_income' => $chargeableIncome,
			];
		}

		if ($rows === []) {
			throw new \InvalidArgumentException('No employee rows were found under the heading.');
		}
		return $rows;
	}

	/**
	 * Attach the best employee match to each parsed row.
	 *
	 * @return array{rows: array, employees: array} employees = the Client's active employees.
	 */
	public function matchEmployees(int $clientId, array $rows): array {
		$employees      = EmployeeSalary::listActiveForClient($clientId);
		$employeeByName = [];
		foreach ($employees as $employee) {
			$employeeId = (int) $employee['employee_id'];
			$employeeByName[$this->normaliseName($employee['first_name'] . ' ' . $employee['last_name'])] ??= $employeeId;
			$employeeByName[$this->normaliseName($employee['last_name'] . ' ' . $employee['first_name'])] ??= $employeeId;
		}

		$alreadyMatched = [];
		foreach ($rows as $rowIndex => $row) {
			$matchedEmployeeId = $employeeByName[$this->normaliseName($row['employee_name'])] ?? null;
			// Two sheet rows with one name: only the first claims the employee.
			if ($matchedEmployeeId !== null && isset($alreadyMatched[$matchedEmployeeId])) {
				$matchedEmployeeId = null;
			}
			if ($matchedEmployeeId !== null) {
				$alreadyMatched[$matchedEmployeeId] = true;
			}
			$rows[$rowIndex]['matched_employee_id'] = $matchedEmployeeId;
		}

		return ['rows' => $rows, 'employees' => $employees];
	}

	/**
	 * Save the rows chosen in the preview. Every figure is checked again here exactly as a salary
	 * typed on the Salaries page is; the employee each row names must be an active employee of this
	 * Client. All or nothing.
	 *
	 * @param array $chosenRows Rows of: sheet_row, employee_name, employee_id (an id, or 'new'),
	 *                          basic_salary, flat_allowance.
	 * @return array{saved: int, created: int, errors: string[]}
	 */
	public function applyRows(int $clientId, ?int $userId, array $chosenRows): array {
		$rateSet = $this->payrollService->rateSetInForce(date('Y-m-01'));
		if ($rateSet === null) {
			return ['saved' => 0, 'created' => 0, 'errors' => ['Payroll rates have not been set up yet. Add them under Payroll Rates.']];
		}
		if (count($chosenRows) > self::MAX_SHEET_ROWS) {
			return ['saved' => 0, 'created' => 0, 'errors' => ['Too many rows for one import.']];
		}

		$activeEmployeeIds = array_map(
			static fn(array $employee): int => (int) $employee['employee_id'],
			EmployeeSalary::listActiveForClient($clientId)
		);

		$errors          = [];
		$salaryByRow     = [];
		$employeeByRow   = [];
		$newNameByRow    = [];
		$chosenEmployees = [];

		foreach (array_values($chosenRows) as $rowIndex => $chosenRow) {
			if (!is_array($chosenRow)) {
				continue;
			}
			$employeeName = preg_replace('/\s+/', ' ', trim((string) ($chosenRow['employee_name'] ?? '')));
			$rowLabel     = 'Row ' . (int) ($chosenRow['sheet_row'] ?? 0) . " ({$employeeName})";

			$chosenEmployee = (string) ($chosenRow['employee_id'] ?? '');
			if ($chosenEmployee === self::CREATE_EMPLOYEE) {
				$newName = $this->splitName($employeeName);
				if ($newName === null) {
					$errors[] = "{$rowLabel}: a new employee needs a first and a last name.";
					continue;
				}
				$newNameByRow[$rowIndex] = $newName;
			} elseif (!in_array((int) $chosenEmployee, $activeEmployeeIds, true)) {
				$errors[] = "{$rowLabel}: choose the employee this row belongs to.";
				continue;
			} elseif (isset($chosenEmployees[(int) $chosenEmployee])) {
				$errors[] = "{$rowLabel}: this employee is already chosen for another row.";
				continue;
			} else {
				$chosenEmployees[(int) $chosenEmployee] = true;
			}

			$validated = $this->payrollService->validateSalaryInput($chosenRow, $rateSet);
			if ($validated['error'] !== null) {
				$errors[] = "{$rowLabel}: {$validated['error']}";
				continue;
			}

			$salaryByRow[$rowIndex]   = $validated['salary'];
			$employeeByRow[$rowIndex] = $chosenEmployee;
		}

		if ($errors !== []) {
			return ['saved' => 0, 'created' => 0, 'errors' => $errors];
		}
		if ($salaryByRow === []) {
			return ['saved' => 0, 'created' => 0, 'errors' => ['No rows were selected for import.']];
		}

		$createdCount = 0;
		Database::beginTransaction();
		try {
			foreach ($salaryByRow as $rowIndex => $salary) {
				$employeeId = $employeeByRow[$rowIndex];
				if ($employeeId === self::CREATE_EMPLOYEE) {
					[$firstName, $lastName] = $newNameByRow[$rowIndex];
					$employeeId = Employee::insertEmployee($clientId, [
						'first_name' => $firstName,
						'last_name'  => $lastName,
						'is_active'  => true,
					], $userId);
					$createdCount++;
				}
				EmployeeSalary::upsertForEmployee($clientId, (int) $employeeId, $salary, $userId);
			}
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}

		return ['saved' => count($salaryByRow), 'created' => $createdCount, 'errors' => []];
	}

	// -------------------------------------------------------------------------
	// Sheet reading
	// -------------------------------------------------------------------------

	/**
	 * Find the heading and the columns under it. A heading spans two rows ("BASIC" over "SALARY"),
	 * so each column's heading is the two cells read together.
	 *
	 * @return array{name: int, basic: int, allowance: int, chargeable: ?int, first_data_row: int}
	 * @throws \InvalidArgumentException
	 */
	private function locateColumns(Worksheet $sheet): array {
		$lastColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_SHEET_COLUMNS);

		for ($headingRow = 1; $headingRow <= self::HEADING_SEARCH_ROWS; $headingRow++) {
			$columns = ['basic' => null, 'allowance' => null, 'chargeable' => null];

			for ($column = 1; $column <= $lastColumn; $column++) {
				$heading = strtoupper(trim(
					$this->cellValue($sheet, $column, $headingRow) . ' ' . $this->cellValue($sheet, $column, $headingRow + 1)
				));
				if ($columns['basic'] === null && str_contains($heading, 'BASIC') && !str_contains($heading, 'LESS')) {
					$columns['basic'] = $column;
				} elseif ($columns['allowance'] === null && str_contains($heading, 'ALLOWANCE')) {
					$columns['allowance'] = $column;
				} elseif ($columns['chargeable'] === null && str_contains($heading, 'CHARGEABLE')) {
					$columns['chargeable'] = $column;
				}
			}

			// Names sit in the column just left of the basic salary.
			if ($columns['basic'] !== null && $columns['basic'] > 1 && $columns['allowance'] !== null) {
				return $columns + ['name' => $columns['basic'] - 1, 'first_data_row' => $headingRow + 2];
			}
		}

		throw new \InvalidArgumentException(
			'This does not look like a payroll sheet: no BASIC SALARY and ALLOWANCE headings were found.'
		);
	}

	/**
	 * A cell's value as shown in Excel. For a formula that is the result Excel saved with the file;
	 * an uploaded formula is only ever worked out here when it is plain arithmetic, so a workbook
	 * cannot make the server run spreadsheet functions.
	 */
	private function cellValue(Worksheet $sheet, int $column, int $sheetRow): mixed {
		$cell = $sheet->getCell([$column, $sheetRow]);
		if (!$cell->isFormula()) {
			return $cell->getValue();
		}

		$savedResult = $cell->getOldCalculatedValue();
		if ($savedResult !== null && $savedResult !== '') {
			return $savedResult;
		}

		$formula = (string) $cell->getValue();
		if (!preg_match(self::PLAIN_ARITHMETIC_FORMULA, $formula) || preg_match(self::FUNCTION_CALL, $formula)) {
			return null;
		}
		try {
			return $cell->getCalculatedValue();
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Refuse a file that is not an Excel workbook, or that unpacks to far more than a payroll sheet
	 * could (a zip bomb), before the spreadsheet library opens it.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertPlausibleWorkbook(string $workbookPath): void {
		$archive = new \ZipArchive();
		if ($archive->open($workbookPath, \ZipArchive::RDONLY) !== true) {
			throw new \InvalidArgumentException('The file is not an Excel workbook.');
		}

		try {
			if ($archive->locateName('[Content_Types].xml') === false || $archive->locateName('xl/workbook.xml') === false) {
				throw new \InvalidArgumentException('The file is not an Excel workbook.');
			}
			if ($archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
				throw new \InvalidArgumentException('The workbook is too large to be a payroll sheet.');
			}

			$unpackedBytes = 0;
			for ($entryIndex = 0; $entryIndex < $archive->numFiles; $entryIndex++) {
				$entry          = $archive->statIndex($entryIndex);
				$unpackedBytes += $entry === false ? 0 : (int) $entry['size'];
				if ($unpackedBytes > self::MAX_UNPACKED_BYTES) {
					throw new \InvalidArgumentException('The workbook is too large to be a payroll sheet.');
				}
			}
		} finally {
			$archive->close();
		}
	}

	/** Reads only the top-left block a payroll sheet can occupy, however large the sheet claims to be. */
	private function sheetBoundsFilter(): IReadFilter {
		return new class (self::MAX_SHEET_ROWS, self::MAX_SHEET_COLUMNS) implements IReadFilter {
			public function __construct(private int $maxRows, private int $maxColumns) {
			}

			public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
				return $row <= $this->maxRows && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
			}
		};
	}

	private function normaliseName(string $name): string {
		return strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
	}

	/**
	 * "THERESE ADWOA BABALOLA" → ['Therese Adwoa', 'Babalola']. Null for a single word.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function splitName(string $fullName): ?array {
		$nameParts = preg_split('/\s+/', trim($fullName)) ?: [];
		if (count($nameParts) < 2) {
			return null;
		}
		$lastName  = array_pop($nameParts);
		$titleCase = static fn(string $namePart): string => mb_convert_case($namePart, MB_CASE_TITLE, 'UTF-8');

		return [mb_substr($titleCase(implode(' ', $nameParts)), 0, 100), mb_substr($titleCase($lastName), 0, 100)];
	}
}
