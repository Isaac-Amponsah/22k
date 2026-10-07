<?php

namespace App\Services;

use App\Helpers\Database;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryRate;

/**
 * PayrollService
 * A Client's salaries and monthly payroll runs: draft → finalised (figures frozen) → paid.
 * Queries live in the models; the arithmetic lives in PayrollCalculator.
 */
final class PayrollService {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_FINALISED = 'finalised';
	public const STATUS_PAID      = 'paid';

	/** Largest amount a numeric(12,2) salary column holds. */
	private const MAX_AMOUNT = 9999999999.99;

	/** Run total columns, keyed by the line figure each one sums. */
	private const TOTAL_COLUMNS = [
		'basic_salary'      => 'total_basic_salary',
		'employee_ssnit'    => 'total_employee_ssnit',
		'basic_less_ssnit'  => 'total_basic_less_ssnit',
		'allowance'         => 'total_allowance',
		'chargeable_income' => 'total_chargeable_income',
		'paye'              => 'total_paye',
		'net_pay'           => 'total_net_pay',
		'employer_ssnit'    => 'total_employer_ssnit',
	];

	// -------------------------------------------------------------------------
	// Salaries
	// -------------------------------------------------------------------------

	/**
	 * Active employees with their salary and, where one is set, the pay it works out to
	 * at the rates in force this month.
	 *
	 * @return array{employees: array, rate_set: ?array}
	 */
	public function salaryList(int $clientId): array {
		$rateSet   = $this->rateSetInForce(date('Y-m-01'));
		$employees = EmployeeSalary::listActiveForClient($clientId);

		foreach ($employees as $employeeIndex => $employee) {
			$employees[$employeeIndex]['pay'] = null;
			if ($rateSet === null || $employee['employee_salary_id'] === null) {
				continue;
			}
			try {
				$employees[$employeeIndex]['pay'] = $this->computePay($employee, $rateSet);
			} catch (\InvalidArgumentException $e) {
				// A stored figure the calculator refuses: shown as "needs attention".
				$employees[$employeeIndex]['pay_error'] = $e->getMessage();
			}
		}

		return ['employees' => $employees, 'rate_set' => $rateSet];
	}

	/**
	 * Save the salaries posted from the Salaries page. All or nothing: one bad row saves none.
	 * A row whose basic salary is left blank is skipped (that employee has no salary yet).
	 *
	 * @param array $postedSalaries [employee_id => [basic_salary, flat_allowance]]
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

		$validSalaries = [];
		$errors        = [];
		foreach ($postedSalaries as $employeeId => $postedSalary) {
			$employeeId = (int) $employeeId;
			if (!is_array($postedSalary) || trim((string) ($postedSalary['basic_salary'] ?? '')) === '') {
				continue;
			}
			if (!in_array($employeeId, $activeEmployeeIds, true)) {
				$errors["salaries[{$employeeId}][basic_salary]"] = 'This employee is not an active employee of this client.';
				continue;
			}

			$validated = $this->validateSalaryInput($postedSalary, $rateSet);
			if ($validated['error'] !== null) {
				$errors["salaries[{$employeeId}][{$validated['field']}]"] = $validated['error'];
				continue;
			}
			$validSalaries[$employeeId] = $validated['salary'];
		}

		if ($errors !== []) {
			return ['saved' => 0, 'errors' => $errors];
		}

		Database::beginTransaction();
		try {
			foreach ($validSalaries as $employeeId => $salary) {
				EmployeeSalary::upsertForEmployee($clientId, $employeeId, $salary, $userId);
			}
			Database::commit();
		} catch (\Throwable $e) {
			Database::rollback();
			throw $e;
		}

		return ['saved' => count($validSalaries), 'errors' => []];
	}

	/**
	 * Check one salary as typed or imported, and shape it for storage.
	 *
	 * @param array $input   basic_salary, flat_allowance
	 * @param array $rateSet Rates the salary is worked out against.
	 * @return array{salary: ?array, field: ?string, error: ?string}
	 */
	public function validateSalaryInput(array $input, array $rateSet): array {
		$fail = static fn(string $field, string $error): array => ['salary' => null, 'field' => $field, 'error' => $error];

		$basicSalary = $this->parseAmount($input['basic_salary'] ?? null);
		if ($basicSalary === null) {
			return $fail('basic_salary', 'Enter a basic salary of 0 or more.');
		}

		$flatAllowanceInput = trim((string) ($input['flat_allowance'] ?? ''));
		$flatAllowance      = $flatAllowanceInput === '' ? 0.0 : $this->parseAmount($flatAllowanceInput);
		if ($flatAllowance === null) {
			return $fail('flat_allowance', 'Enter an allowance of 0 or more.');
		}

		try {
			PayrollCalculator::compute($basicSalary, $flatAllowance, $rateSet);
		} catch (\InvalidArgumentException $e) {
			return $fail('flat_allowance', $e->getMessage());
		}

		return [
			'salary' => [
				'basic_salary'   => $basicSalary,
				'flat_allowance' => $flatAllowance,
			],
			'field' => null,
			'error' => null,
		];
	}

	// -------------------------------------------------------------------------
	// Runs
	// -------------------------------------------------------------------------

	public function listRuns(int $clientId): array {
		return PayrollRun::listForClient($clientId);
	}

	/**
	 * A Client's run with its lines, or null when it is not theirs.
	 */
	public function findRun(int $payrollRunId, int $clientId): ?array {
		$run = PayrollRun::findForClient($payrollRunId, $clientId);
		if ($run === null) {
			return null;
		}
		$run['lines'] = PayrollRun::getLines($payrollRunId, $clientId);
		return $run;
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

	private function computePay(array $salary, array $rateSet): array {
		return PayrollCalculator::compute((float) $salary['basic_salary'], (float) $salary['flat_allowance'], $rateSet);
	}

	/**
	 * One line per payable employee for the month, and the totals of each figure.
	 *
	 * @return array{lines: array, totals: array<string, float>}
	 * @throws \InvalidArgumentException Naming the employee whose salary cannot be worked out.
	 */
	private function workOutLines(int $clientId, string $payPeriod, array $rateSet): array {
		$periodEnd = date('Y-m-t', strtotime($payPeriod));
		$lines     = [];
		// Totals are summed in pesewas so they equal the sum of the lines exactly.
		$totalPesewas = array_fill_keys(array_keys(self::TOTAL_COLUMNS), 0);

		foreach (EmployeeSalary::payableForClient($clientId, $periodEnd) as $lineIndex => $employee) {
			$employeeName = trim($employee['first_name'] . ' ' . $employee['last_name']);
			try {
				$pay = $this->computePay($employee, $rateSet);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException("Salary for {$employeeName}: " . $e->getMessage());
			}

			foreach ($totalPesewas as $figure => $runningPesewas) {
				$totalPesewas[$figure] = $runningPesewas + (int) round($pay[$figure] * 100);
			}

			$lines[] = [
				'employee_id'     => (int) $employee['employee_id'],
				'line_order'      => $lineIndex + 1,
				'employee_name'   => $employeeName,
				'employee_code'   => $employee['employee_code'],
				'job_title_name'  => $employee['job_title_name'],
				'department_name' => $employee['department_name'],
			] + $pay;
		}

		return [
			'lines'  => $lines,
			'totals' => array_map(static fn(int $pesewas): float => (float) ($pesewas / 100), $totalPesewas),
		];
	}

	/**
	 * Would Recalculate change this draft? True when the employees paid or any of their figures
	 * differ from what the current salaries and rates give.
	 */
	private function draftIsStale(array $run, int $clientId): bool {
		$rateSet = $this->requireRateSet((string) $run['pay_period']);
		$current = $this->workOutLines($clientId, (string) $run['pay_period'], $rateSet)['lines'];
		$stored  = PayrollRun::getLines((int) $run['payroll_run_id'], $clientId);

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
			foreach (array_keys(self::TOTAL_COLUMNS) as $figure) {
				if ((int) round($currentLine[$figure] * 100) !== (int) round((float) $storedLine[$figure] * 100)) {
					return true;
				}
			}
		}
		return false;
	}

	/** Run columns that follow from the rate set and the worked-out lines. */
	private function runFigures(array $rateSet, array $worked): array {
		$figures = [
			'statutory_rate_id'      => (int) $rateSet['statutory_rate_id'],
			'employee_ssnit_percent' => $rateSet['employee_ssnit_percent'],
			'employer_ssnit_percent' => $rateSet['employer_ssnit_percent'],
			'employee_count'         => count($worked['lines']),
		];
		foreach (self::TOTAL_COLUMNS as $figure => $totalColumn) {
			$figures[$totalColumn] = $worked['totals'][$figure];
		}
		return $figures;
	}

	private function insertLines(int $payrollRunId, int $clientId, array $lines): void {
		foreach ($lines as $line) {
			PayrollRun::insertLine(['payroll_run_id' => $payrollRunId, 'client_id' => $clientId] + $line);
		}
	}

	/**
	 * A typed amount as a float, or null when it is not a number from 0 up to what the column holds.
	 * Thousands separators are accepted ("4,000.00").
	 */
	private function parseAmount(mixed $rawAmount): ?float {
		$cleaned = str_replace([',', ' '], '', trim((string) $rawAmount));
		if ($cleaned === '' || !is_numeric($cleaned)) {
			return null;
		}
		$amount = round((float) $cleaned, 2);
		return ($amount < 0 || $amount > self::MAX_AMOUNT) ? null : $amount;
	}
}
