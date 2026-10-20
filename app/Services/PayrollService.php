<?php

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\InputText;
use App\Models\ClientPayItem;
use App\Models\Employee;
use App\Models\EmployeePayItemAmount;
use App\Models\EmployeeSalary;
use App\Models\EmployeeTaxRelief;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryRate;

/**
 * PayrollService
 * A Client's salaries and monthly payroll runs: draft → finalised (figures frozen) → paid.
 * A salary is a basic salary, an amount on any of the Client's allowances and deductions, a voluntary
 * Tier 3 percentage and the personal reliefs claimed.
 * Queries live in the models; the arithmetic lives in PayrollCalculator.
 */
final class PayrollService {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_FINALISED = 'finalised';
	public const STATUS_PAID      = 'paid';

	/** Largest amount a numeric(12,2) salary column holds. */
	private const MAX_AMOUNT = 9999999999.99;

	/** Most claims any relief allows; stops a runaway form post before the rate set is asked. */
	private const MAX_RELIEF_CLAIMS = 99;

	// -------------------------------------------------------------------------
	// Salaries
	// -------------------------------------------------------------------------

	/**
	 * Active employees with their salary and, where one is set, the pay it works out to at the rates
	 * in force this month; the Client's pay items; and the reliefs that can be claimed.
	 *
	 * @return array{employees: array, rate_set: ?array, pay_items: array, relief_types: array}
	 */
	public function salaryList(int $clientId): array {
		$rateSet      = $this->rateSetInForce(date('Y-m-01'));
		$payItems     = ClientPayItem::listForClient($clientId);
		$storedInputs = $this->storedPayInputsByEmployee($clientId);
		$employees    = EmployeeSalary::listActiveForClient($clientId);

		foreach ($employees as $employeeIndex => $employee) {
			$employeeId = (int) $employee['employee_id'];
			$employees[$employeeIndex]['pay_item_amounts'] = (object) ($storedInputs['amounts'][$employeeId] ?? []);
			$employees[$employeeIndex]['relief_claims']    = (object) ($storedInputs['reliefs'][$employeeId] ?? []);
			$employees[$employeeIndex]['pay']              = null;
			if ($rateSet === null || $employee['employee_salary_id'] === null) {
				continue;
			}
			try {
				$employees[$employeeIndex]['pay'] = PayrollCalculator::compute(
					$this->payInputs($employee, $storedInputs['amounts'][$employeeId] ?? [], $storedInputs['reliefs'][$employeeId] ?? [], $payItems),
					$rateSet
				);
			} catch (\InvalidArgumentException $e) {
				// A stored figure the calculator refuses: shown as "needs attention".
				$employees[$employeeIndex]['pay_error'] = $e->getMessage();
			}
		}

		return [
			'employees'    => $employees,
			'rate_set'     => $rateSet,
			'pay_items'    => $payItems,
			'relief_types' => array_map(
				static fn(string $reliefCode, string $label): array => ['relief_code' => $reliefCode, 'label' => $label],
				array_keys(TaxReliefTypes::LABELS),
				TaxReliefTypes::LABELS
			),
		];
	}

	/**
	 * Save the salaries posted from the Salaries page. All or nothing: one bad row saves none.
	 * A row whose basic salary is left blank is skipped (that employee has no salary yet).
	 *
	 * @param array $postedSalaries [employee_id => [basic_salary, tier3_percent, pay_items: [pay_item_id => amount],
	 *                              reliefs: [relief_code => claim_count]]]
	 * @return array{saved: int, errors: array<string, string>} errors keyed by the form field name.
	 */
	public function saveSalaries(int $clientId, ?int $userId, array $postedSalaries): array {
		$rateSet = $this->rateSetInForce(date('Y-m-01'));
		if ($rateSet === null) {
			return ['saved' => 0, 'errors' => ['salaries' => 'Payroll rates have not been set up yet. Add them under Payroll Rates.']];
		}

		$activeEmployeeIds = array_map(
			static fn(array $employee): int => (int) $employee['employee_id'],
			EmployeeSalary::listActiveForClient($clientId)
		);
		$payItems = ClientPayItem::listForClient($clientId);

		$validSalaries = [];
		$errors        = [];
		foreach ($postedSalaries as $employeeId => $postedSalary) {
			$employeeId = (int) $employeeId;
			if (!is_array($postedSalary) || trim(InputText::of($postedSalary['basic_salary'] ?? '')) === '') {
				continue;
			}
			if (!in_array($employeeId, $activeEmployeeIds, true)) {
				$errors["salaries[{$employeeId}][basic_salary]"] = 'This employee is not an active employee of this client.';
				continue;
			}

			$validated = $this->validateSalaryInput($postedSalary, $payItems, $rateSet);
			if ($validated['error'] !== null) {
				$errors["salaries[{$employeeId}][{$validated['field']}]"] = $validated['error'];
				continue;
			}
			$validSalaries[$employeeId] = $validated;
		}

		if ($errors !== []) {
			return ['saved' => 0, 'errors' => $errors];
		}

		Database::beginTransaction();
		try {
			foreach ($validSalaries as $employeeId => $validated) {
				EmployeeSalary::upsertForEmployee($clientId, $employeeId, $validated['salary'], $userId);
				EmployeePayItemAmount::replaceForEmployee($clientId, $employeeId, $validated['pay_item_amounts']);
				EmployeeTaxRelief::replaceForEmployee($clientId, $employeeId, $validated['relief_claims']);
			}
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}

		return ['saved' => count($validSalaries), 'errors' => []];
	}

	/**
	 * Check one salary as typed, and shape it for storage.
	 *
	 * @param array $input    basic_salary, tier3_percent, pay_items [pay_item_id => amount], reliefs [relief_code => claim_count]
	 * @param array $payItems The Client's pay items (ClientPayItem::listForClient()).
	 * @param array $rateSet  Rates the salary is worked out against.
	 * @return array{salary: ?array, pay_item_amounts: array<int, float>, relief_claims: array<string, int>, field: ?string, error: ?string}
	 */
	public function validateSalaryInput(array $input, array $payItems, array $rateSet): array {
		$fail = static fn(string $field, string $error): array => [
			'salary' => null, 'pay_item_amounts' => [], 'relief_claims' => [], 'field' => $field, 'error' => $error,
		];

		$basicSalary = $this->parseAmount($input['basic_salary'] ?? null);
		if ($basicSalary === null) {
			return $fail('basic_salary', 'Enter a basic salary of 0 or more.');
		}

		$tier3Input   = trim(InputText::of($input['tier3_percent'] ?? ''));
		$tier3Percent = $tier3Input === '' ? 0.0 : $this->parsePercent($tier3Input);
		if ($tier3Percent === null) {
			return $fail('tier3_percent', 'Enter Tier 3 as a percentage from 0 to 100.');
		}

		$payItemIds     = array_map(static fn(array $payItem): int => (int) $payItem['pay_item_id'], $payItems);
		$payItemAmounts = [];
		foreach ((array) ($input['pay_items'] ?? []) as $payItemId => $amountInput) {
			$payItemId = (int) $payItemId;
			if (!in_array($payItemId, $payItemIds, true)) {
				return $fail("pay_items][{$payItemId}", 'This pay item is not one of this client\'s.');
			}
			$amount = trim((string) $amountInput) === '' ? 0.0 : $this->parseAmount($amountInput);
			if ($amount === null) {
				return $fail("pay_items][{$payItemId}", 'Enter an amount of 0 or more.');
			}
			if ($amount > 0) {
				$payItemAmounts[$payItemId] = $amount;
			}
		}

		$reliefClaims = [];
		foreach ((array) ($input['reliefs'] ?? []) as $reliefCode => $claimCountInput) {
			$reliefCode = (string) $reliefCode;
			$claimCount = filter_var($claimCountInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_RELIEF_CLAIMS]]);
			if (!TaxReliefTypes::isKnown($reliefCode) || $claimCount === false) {
				return $fail('reliefs', 'Choose the reliefs this employee claims.');
			}
			if ($claimCount > 0) {
				$reliefClaims[$reliefCode] = $claimCount;
			}
		}

		$salary = ['basic_salary' => $basicSalary, 'tier3_percent' => $tier3Percent];
		try {
			PayrollCalculator::compute($this->payInputs($salary, $payItemAmounts, $reliefClaims, $payItems), $rateSet);
		} catch (\InvalidArgumentException $e) {
			return $fail('pay', $e->getMessage());
		}

		return ['salary' => $salary, 'pay_item_amounts' => $payItemAmounts, 'relief_claims' => $reliefClaims, 'field' => null, 'error' => null];
	}

	/**
	 * Every employee's stored pay item amounts and relief claims.
	 *
	 * @return array{amounts: array<int, array<int, float>>, reliefs: array<int, array<string, int>>} keyed by employee_id.
	 */
	public function storedPayInputsByEmployee(int $clientId): array {
		$amountsByEmployee = [];
		foreach (EmployeePayItemAmount::listForClient($clientId) as $heldAmount) {
			$amountsByEmployee[(int) $heldAmount['employee_id']][(int) $heldAmount['pay_item_id']] = (float) $heldAmount['amount'];
		}
		$reliefsByEmployee = [];
		foreach (EmployeeTaxRelief::listForClient($clientId) as $reliefClaim) {
			$reliefsByEmployee[(int) $reliefClaim['employee_id']][$reliefClaim['relief_code']] = (int) $reliefClaim['claim_count'];
		}
		return ['amounts' => $amountsByEmployee, 'reliefs' => $reliefsByEmployee];
	}

	/**
	 * What PayrollCalculator::compute() takes for one salary.
	 *
	 * @param array               $salary         basic_salary, tier3_percent
	 * @param array<int, float>   $payItemAmounts Keyed by pay_item_id.
	 * @param array<string, int>  $reliefClaims   Keyed by relief_code.
	 * @param array               $payItems       The Client's pay items, in display order.
	 */
	public function payInputs(array $salary, array $payItemAmounts, array $reliefClaims, array $payItems): array {
		$allowances = [];
		$deductions = [];
		foreach ($payItems as $payItem) {
			$amount = $payItemAmounts[(int) $payItem['pay_item_id']] ?? 0.0;
			if ($amount <= 0) {
				continue;
			}
			if ($payItem['item_kind'] === ClientPayItem::KIND_ALLOWANCE) {
				$allowances[] = ['item_name' => $payItem['item_name'], 'amount' => $amount, 'is_taxable' => (bool) $payItem['is_taxable']];
			} else {
				$deductions[] = ['item_name' => $payItem['item_name'], 'amount' => $amount];
			}
		}

		return [
			'basic_salary'  => (float) $salary['basic_salary'],
			'tier3_percent' => (float) ($salary['tier3_percent'] ?? 0),
			'allowances'    => $allowances,
			'deductions'    => $deductions,
			'relief_claims' => $reliefClaims,
		];
	}

	/**
	 * A typed amount as a float, or null when it is not a number from 0 up to what the column holds.
	 * Thousands separators are accepted ("4,000.00").
	 */
	public function parseAmount(mixed $rawAmount): ?float {
		$cleaned = str_replace([',', ' '], '', trim(InputText::of($rawAmount)));
		if ($cleaned === '' || !is_numeric($cleaned)) {
			return null;
		}
		$amount = round((float) $cleaned, 2);
		return ($amount < 0 || $amount > self::MAX_AMOUNT) ? null : $amount;
	}

	// -------------------------------------------------------------------------
	// Runs
	// -------------------------------------------------------------------------

	public function listRuns(int $clientId): array {
		return PayrollRun::listForClient($clientId);
	}

	/**
	 * A Client's run with its lines, each with its allowances, deductions and reliefs, or null when it is
	 * not theirs.
	 */
	public function findRun(int $payrollRunId, int $clientId): ?array {
		$run = PayrollRun::findForClient($payrollRunId, $clientId);
		if ($run === null) {
			return null;
		}

		$itemsByLine = [];
		foreach (PayrollRun::getLineItems($payrollRunId, $clientId) as $lineItem) {
			$itemsByLine[(int) $lineItem['payroll_run_line_id']][] = $lineItem;
		}

		$run['lines'] = PayrollRun::getLines($payrollRunId, $clientId);
		foreach ($run['lines'] as $lineIndex => $line) {
			$run['lines'][$lineIndex]['items'] = $itemsByLine[(int) $line['payroll_run_line_id']] ?? [];
		}
		return $run;
	}

	/**
	 * One employee's payslips: every finalised or paid run they were paid in, latest month first, each line
	 * with its items and the rates its run was worked out at. Null when the employee is not this Client's.
	 */
	public function payslipsForEmployee(int $employeeId, int $clientId): ?array {
		$employee = Employee::findForClient($employeeId, $clientId);
		if ($employee === null) {
			return null;
		}

		$itemsByLine = [];
		foreach (PayrollRun::getIssuedLineItemsForEmployee($employeeId, $clientId) as $lineItem) {
			$itemsByLine[(int) $lineItem['payroll_run_line_id']][] = $lineItem;
		}

		$payslips = PayrollRun::getIssuedLinesForEmployee($employeeId, $clientId);
		foreach ($payslips as $payslipIndex => $payslip) {
			$payslips[$payslipIndex]['items'] = $itemsByLine[(int) $payslip['payroll_run_line_id']] ?? [];
		}
		return ['employee' => $employee, 'payslips' => $payslips];
	}

	/**
	 * Create the draft run for a month and work out every payable employee's line.
	 *
	 * @param string $payMonth Y-m, the month being paid.
	 * @return int New payroll_run_id
	 * @throws \InvalidArgumentException For anything the user can fix (bad month, month taken, no salaries).
	 */
	public function createRun(int $clientId, ?int $userId, string $payMonth, string $notes = ''): int {
		$payPeriod = $this->payPeriodFromMonth($payMonth);

		if (PayrollRun::periodTaken($clientId, $payPeriod)) {
			throw new \InvalidArgumentException('There is already a payroll run for ' . date('F Y', strtotime($payPeriod)) . '.');
		}

		$rateSet = $this->requireRateSet($payPeriod);

		Database::beginTransaction();
		try {
			$worked = $this->workOutLines($clientId, $payPeriod, $rateSet);
			if ($worked['lines'] === []) {
				throw new \InvalidArgumentException('No active employee has a salary set. Set salaries first.');
			}

			$payrollRunId = PayrollRun::insertRun([
				'client_id'  => $clientId,
				'pay_period' => $payPeriod,
				'status'     => self::STATUS_DRAFT,
				'notes'      => trim($notes) === '' ? null : trim($notes),
				'created_by' => $userId,
			] + $this->runFigures($rateSet, $worked));

			$this->insertLines($payrollRunId, $clientId, $worked['lines']);
			Database::commit();
		} catch (\PDOException $e) {
			Database::rollback();
			// uq_payroll_runs_client_period: a second request created the same month first.
			if ($e->getCode() === '23505') {
				throw new \InvalidArgumentException('There is already a payroll run for ' . date('F Y', strtotime($payPeriod)) . '.');
			}
			throw $e;
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}

		return $payrollRunId;
	}

	/**
	 * Rebuild a draft run from the salaries and rates as they are now.
	 *
	 * @throws \InvalidArgumentException When the run is no longer a draft, or a salary cannot be worked out.
	 */
	public function recalculate(int $payrollRunId, int $clientId): void {
		Database::beginTransaction();
		try {
			$run = $this->lockRunInStatus($payrollRunId, $clientId, self::STATUS_DRAFT, 'Only a draft payroll run can be recalculated.');

			$rateSet = $this->requireRateSet((string) $run['pay_period']);
			$worked  = $this->workOutLines($clientId, (string) $run['pay_period'], $rateSet);

			PayrollRun::deleteLines($payrollRunId, $clientId);
			$this->insertLines($payrollRunId, $clientId, $worked['lines']);
			PayrollRun::updateRun($payrollRunId, $clientId, $this->runFigures($rateSet, $worked));

			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}
	}

	/**
	 * Freeze a draft run: its lines are no longer rebuilt and payslips can be issued.
	 *
	 * @throws \InvalidArgumentException When the run is not a draft or pays nobody.
	 */
	public function finalise(int $payrollRunId, int $clientId, ?int $userId): void {
		Database::beginTransaction();
		try {
			$run = $this->lockRunInStatus($payrollRunId, $clientId, self::STATUS_DRAFT, 'Only a draft payroll run can be finalised.');
			if ((int) $run['employee_count'] === 0) {
				throw new \InvalidArgumentException('This payroll run pays nobody. Set salaries, then recalculate.');
			}
			// Finalising freezes what is on screen; it must not freeze figures the salaries have moved on from.
			if ($this->draftIsStale($run, $clientId)) {
				throw new \InvalidArgumentException(
					'Salaries, employees or rates changed after this draft was calculated. Recalculate, review the figures, then finalise.'
				);
			}

			PayrollRun::updateRun($payrollRunId, $clientId, [
				'status'       => self::STATUS_FINALISED,
				'finalised_by' => $userId,
				'finalised_at' => date('Y-m-d H:i:s'),
			]);
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}
	}

	/**
	 * Record that a finalised run's salaries were paid.
	 *
	 * @throws \InvalidArgumentException When the run is not finalised.
	 */
	public function markPaid(int $payrollRunId, int $clientId, ?int $userId): void {
		Database::beginTransaction();
		try {
			$this->lockRunInStatus($payrollRunId, $clientId, self::STATUS_FINALISED, 'Only a finalised payroll run can be marked paid.');

			PayrollRun::updateRun($payrollRunId, $clientId, [
				'status'  => self::STATUS_PAID,
				'paid_by' => $userId,
				'paid_at' => date('Y-m-d H:i:s'),
			]);
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}
	}

	/**
	 * Delete a draft run. A finalised or paid run is a record and stays.
	 *
	 * @throws \InvalidArgumentException When the run is not a draft.
	 */
	public function deleteDraft(int $payrollRunId, int $clientId): void {
		Database::beginTransaction();
		try {
			$this->lockRunInStatus($payrollRunId, $clientId, self::STATUS_DRAFT, 'Only a draft payroll run can be deleted.');
			PayrollRun::softDelete($payrollRunId, $clientId);
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}
	}

	/**
	 * The rate set in force for the month starting on the given date: the latest set that took
	 * effect on or before the month's last day.
	 *
	 * @param string $payPeriod Y-m-d, first day of the month.
	 */
	public function rateSetInForce(string $payPeriod): ?array {
		return PayrollStatutoryRate::findInForceOn(date('Y-m-t', strtotime($payPeriod)));
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	private function requireRateSet(string $payPeriod): array {
		$rateSet = $this->rateSetInForce($payPeriod);
		if ($rateSet === null || empty($rateSet['bands'])) {
			throw new \InvalidArgumentException(
				'No payroll rates are in force for ' . date('F Y', strtotime($payPeriod)) . '. Add them under Payroll Rates.'
			);
		}
		return $rateSet;
	}

	/**
	 * @param string $payMonth Y-m
	 * @return string Y-m-d, first day of that month.
	 * @throws \InvalidArgumentException
	 */
	private function payPeriodFromMonth(string $payMonth): string {
		$payMonth = trim($payMonth);
		if (!preg_match('/^(\d{4})-(\d{2})$/', $payMonth, $monthParts) || !checkdate((int) $monthParts[2], 1, (int) $monthParts[1])) {
			throw new \InvalidArgumentException('Choose the month to pay.');
		}
		$payPeriod = $payMonth . '-01';
		// Next month's payroll may be prepared ahead; nothing further out.
		if ($payPeriod > date('Y-m-01', strtotime('first day of next month'))) {
			throw new \InvalidArgumentException('A payroll run cannot be more than one month ahead.');
		}
		return $payPeriod;
	}

	private function lockRunInStatus(int $payrollRunId, int $clientId, string $requiredStatus, string $refusal): array {
		$run = PayrollRun::lockForUpdate($payrollRunId, $clientId);
		if ($run === null) {
			throw new \InvalidArgumentException('Payroll run not found.');
		}
		if ($run['status'] !== $requiredStatus) {
			throw new \InvalidArgumentException($refusal);
		}
		return $run;
	}

	/**
	 * One line per payable employee for the month, and the totals of each figure.
	 *
	 * @return array{lines: array, totals: array<string, float>}
	 * @throws \InvalidArgumentException Naming the employee whose salary cannot be worked out.
	 */
	private function workOutLines(int $clientId, string $payPeriod, array $rateSet): array {
		$periodEnd    = date('Y-m-t', strtotime($payPeriod));
		$payItems     = ClientPayItem::listForClient($clientId);
		$storedInputs = $this->storedPayInputsByEmployee($clientId);
		$lines        = [];
		// Totals are summed in pesewas so they equal the sum of the lines exactly.
		$totalPesewas = array_fill_keys(PayrollCalculator::FIGURES, 0);

		foreach (EmployeeSalary::payableForClient($clientId, $periodEnd) as $lineIndex => $employee) {
			$employeeId   = (int) $employee['employee_id'];
			$employeeName = trim($employee['first_name'] . ' ' . $employee['last_name']);
			try {
				$pay = PayrollCalculator::compute(
					$this->payInputs($employee, $storedInputs['amounts'][$employeeId] ?? [], $storedInputs['reliefs'][$employeeId] ?? [], $payItems),
					$rateSet
				);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException("Salary for {$employeeName}: " . $e->getMessage());
			}

			foreach ($totalPesewas as $figure => $runningPesewas) {
				$totalPesewas[$figure] = $runningPesewas + (int) round($pay[$figure] * 100);
			}

			$lines[] = [
				'employee_id'               => $employeeId,
				'line_order'                => $lineIndex + 1,
				'employee_name'             => $employeeName,
				'employee_code'             => $employee['employee_code'],
				'job_title_name'            => $employee['job_title_name'],
				'department_name'           => $employee['department_name'],
				'ssnit_number'              => $employee['ssnit_number'],
				'tax_identification_number' => $employee['tax_identification_number'],
			] + $pay;
		}

		return [
			'lines'  => $lines,
			'totals' => array_map(static fn(int $pesewas): float => (float) ($pesewas / 100), $totalPesewas),
		];
	}

	/**
	 * Would Recalculate change this draft? True when the employees paid, any of their figures, or the
	 * allowances, deductions and reliefs that make them up differ from what the current salaries and
	 * rates give.
	 */
	private function draftIsStale(array $run, int $clientId): bool {
		$rateSet = $this->requireRateSet((string) $run['pay_period']);
		$current = $this->workOutLines($clientId, (string) $run['pay_period'], $rateSet)['lines'];
		$stored  = ($this->findRun((int) $run['payroll_run_id'], $clientId) ?? [])['lines'] ?? [];

		if (count($current) !== count($stored)) {
			return true;
		}

		$storedByEmployee = [];
		foreach ($stored as $storedLine) {
			$storedByEmployee[(int) $storedLine['employee_id']] = $storedLine;
		}
		foreach ($current as $currentLine) {
			$storedLine = $storedByEmployee[$currentLine['employee_id']] ?? null;
			if ($storedLine === null) {
				return true;
			}
			foreach (PayrollCalculator::FIGURES as $figure) {
				if ((int) round($currentLine[$figure] * 100) !== (int) round((float) $storedLine[$figure] * 100)) {
					return true;
				}
			}
			if ($this->itemsFingerprint($currentLine['items']) !== $this->itemsFingerprint($storedLine['items'])) {
				return true;
			}
		}
		return false;
	}

	/** A line's items reduced to what matters for comparing two of them: kind, name, tax and amount. */
	private function itemsFingerprint(array $lineItems): string {
		return implode('|', array_map(
			static fn(array $lineItem): string => implode(':', [
				$lineItem['item_kind'],
				$lineItem['item_name'],
				$lineItem['is_taxable'] === null ? '' : ((bool) $lineItem['is_taxable'] ? '1' : '0'),
				(int) round((float) $lineItem['amount'] * 100),
			]),
			$lineItems
		));
	}

	/** Run columns that follow from the rate set and the worked-out lines. */
	private function runFigures(array $rateSet, array $worked): array {
		$figures = [
			'statutory_rate_id'      => (int) $rateSet['statutory_rate_id'],
			'employee_ssnit_percent' => $rateSet['employee_ssnit_percent'],
			'employer_ssnit_percent' => $rateSet['employer_ssnit_percent'],
			'ssnit_tier1_percent'    => $rateSet['ssnit_tier1_percent'],
			'employee_count'         => count($worked['lines']),
		];
		foreach (PayrollCalculator::FIGURES as $figure) {
			$figures['total_' . $figure] = $worked['totals'][$figure];
		}
		return $figures;
	}

	private function insertLines(int $payrollRunId, int $clientId, array $lines): void {
		foreach ($lines as $line) {
			$lineItems = $line['items'];
			unset($line['items']);

			$payrollRunLineId = PayrollRun::insertLine(['payroll_run_id' => $payrollRunId, 'client_id' => $clientId] + $line);
			foreach ($lineItems as $itemIndex => $lineItem) {
				PayrollRun::insertLineItem([
					'client_id'           => $clientId,
					'payroll_run_line_id' => $payrollRunLineId,
					'item_order'          => $itemIndex + 1,
				] + $lineItem);
			}
		}
	}

	private function parsePercent(mixed $rawPercent): ?float {
		$cleaned = trim(InputText::of($rawPercent));
		if ($cleaned === '' || !is_numeric($cleaned)) {
			return null;
		}
		$percent = round((float) $cleaned, 2);
		return ($percent < 0 || $percent > 100) ? null : $percent;
	}
}
