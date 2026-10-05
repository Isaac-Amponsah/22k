<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Helpers\Database;
use App\Helpers\Env;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\PayrollRunBankEmail;
use App\Models\PayrollStatutoryRate;
use App\Models\User;
use App\Services\BankPaymentFileService;
use App\Services\BankPayrollEmailService;
use App\Services\DashboardService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One Client's rows must never reach another Client's books — not through a query that forgets its
 * WHERE, not through a guessed id, not through a row written with the wrong client_id.
 *
 * Runs against the migrated database as the application role, exactly as a request does. Two
 * accountants are set up, the first with two Clients (Alpha, Beta) and the second with one (Gamma),
 * each Client with one employee and a salary. Everything happens inside a transaction that is
 * rolled back, so the database is left as it was found.
 */
final class ClientIsolationTest extends TestCase {

	private const SALARY = [
		'basic_salary'             => 1000.00,
		'allowance_mode'           => 'flat',
		'flat_allowance'           => 0.0,
		'target_chargeable_income' => null,
	];

	private bool $transactionOpened = false;
	private int $firstAccountantId;
	private int $secondAccountantId;
	private int $alphaClientId;
	private int $betaClientId;
	private int $gammaClientId;
	/** @var array<int, int> employee_id keyed by client_id */
	private array $employeeIdByClient = [];

	protected function setUp(): void {
		try {
			Env::loadForEnvironment(dirname(__DIR__, 2));
		} catch (\RuntimeException $environmentError) {
			$this->markTestSkipped($environmentError->getMessage());
		}
		// Rolled back or not, a test never writes to a database real payroll lives in.
		if (!Env::isLocal()) {
			$this->markTestSkipped('Integration tests run only when APP_ENV is local.');
		}
		try {
			Database::getConnection();
		} catch (\PDOException $connectionError) {
			$this->markTestSkipped('No database reachable with the settings in .env: ' . $connectionError->getMessage());
		}

		Database::beginTransaction();
		$this->transactionOpened = true;

		$uniqueSuffix             = bin2hex(random_bytes(6));
		$this->firstAccountantId  = User::insertUser("first-{$uniqueSuffix}@isolation.test", 'not-a-real-hash', 'First', 'Accountant');
		$this->secondAccountantId = User::insertUser("second-{$uniqueSuffix}@isolation.test", 'not-a-real-hash', 'Second', 'Accountant');

		$this->alphaClientId = $this->createClientWithEmployee($this->firstAccountantId, "Alpha {$uniqueSuffix}");
		$this->betaClientId  = $this->createClientWithEmployee($this->firstAccountantId, "Beta {$uniqueSuffix}");
		$this->gammaClientId = $this->createClientWithEmployee($this->secondAccountantId, "Gamma {$uniqueSuffix}");
	}

	protected function tearDown(): void {
		// A skipped test never opened the transaction, and may have no connection to roll back on.
		if ($this->transactionOpened) {
			Database::rollback();
		}
		Database::disconnect();
	}

	#[Test]
	public function a_query_with_no_where_clause_returns_only_the_client_in_scope(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);

		$this->assertSame([$this->alphaClientId], $this->distinctClientIdsIn('employees'));
		$this->assertSame([$this->alphaClientId], $this->distinctClientIdsIn('employee_salaries'));
	}

	#[Test]
	public function switching_client_switches_every_row_that_is_visible(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);
		$this->openBooks($this->firstAccountantId, $this->betaClientId);

		$this->assertSame([$this->betaClientId], $this->distinctClientIdsIn('employees'));
		$this->assertSame([], Employee::listForClient($this->alphaClientId), 'Asking for Alpha by id while Beta is open must return nothing.');
		$this->assertSame([], EmployeeSalary::listActiveForClient($this->alphaClientId));
	}

	#[Test]
	public function with_no_client_in_scope_no_client_rows_are_visible(): void {
		User::scopeConnectionToUser($this->firstAccountantId);
		Database::setConnectionSetting('app.client_id', '');

		$this->assertSame([], $this->distinctClientIdsIn('employees'));
		$this->assertSame([], $this->distinctClientIdsIn('employee_salaries'));
	}

	#[Test]
	public function an_accountant_sees_only_their_own_clients(): void {
		User::scopeConnectionToUser($this->secondAccountantId);

		$visibleClientIds = array_map('intval', array_column(Database::fetchAll('SELECT client_id FROM clients'), 'client_id'));
		$this->assertSame([$this->gammaClientId], $visibleClientIds);
		$this->assertNull(Client::findForAccountant($this->alphaClientId, $this->secondAccountantId));
	}

	#[Test]
	public function scoping_to_another_accountants_client_shows_nothing(): void {
		// Even if a bug set the scope to a Client that is not the signed-in accountant's.
		$this->openBooks($this->secondAccountantId, $this->alphaClientId);

		$this->assertSame([], $this->distinctClientIdsIn('employees'));
	}

	#[Test]
	public function a_row_cannot_be_written_into_another_client(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);

		$this->assertDatabaseRefuses(function (): void {
			Employee::insertEmployee($this->betaClientId, ['first_name' => 'Stray', 'last_name' => 'Row'], $this->firstAccountantId);
		});
	}

	#[Test]
	public function a_salary_cannot_point_at_another_clients_employee(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);
		$betaEmployeeId = $this->employeeIdByClient[$this->betaClientId];

		// Refused outright, or a no-op: either way nothing may be written.
		Database::query('SAVEPOINT stray_salary');
		try {
			EmployeeSalary::upsertForEmployee($this->alphaClientId, $betaEmployeeId, ['basic_salary' => 9999.00] + self::SALARY, $this->firstAccountantId);
		} catch (\PDOException $refusal) {
			Database::query('ROLLBACK TO SAVEPOINT stray_salary');
		}

		$alphaSalaries = Database::fetchAll('SELECT employee_id FROM employee_salaries');
		$this->assertSame([$this->employeeIdByClient[$this->alphaClientId]], array_map('intval', array_column($alphaSalaries, 'employee_id')));

		$this->openBooks($this->firstAccountantId, $this->betaClientId);
		$betaSalary = Database::fetchOne('SELECT client_id, basic_salary FROM employee_salaries WHERE employee_id = ?', [$betaEmployeeId]);
		$this->assertSame($this->betaClientId, (int) $betaSalary['client_id']);
		$this->assertSame('1000.00', $betaSalary['basic_salary']);
	}

	#[Test]
	public function another_clients_rows_cannot_be_changed_or_moved(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);

		$changedRows = Database::query(
			'UPDATE employees SET last_name = ? WHERE employee_id = ?',
			['Overwritten', $this->employeeIdByClient[$this->betaClientId]]
		)->rowCount();
		$this->assertSame(0, $changedRows);

		// Moving Alpha's own employee into Beta is refused too.
		$this->assertDatabaseRefuses(function (): void {
			Database::query(
				'UPDATE employees SET client_id = ? WHERE employee_id = ?',
				[$this->betaClientId, $this->employeeIdByClient[$this->alphaClientId]]
			);
		});
	}

	#[Test]
	public function the_dashboard_month_covers_only_the_accountants_own_clients_and_leaves_no_client_in_scope(): void {
		User::scopeConnectionToUser($this->firstAccountantId);

		$monthOverview = (new DashboardService())->monthOverview($this->firstAccountantId, '');

		$overviewClientIds = array_column($monthOverview['clients'], 'client_id');
		sort($overviewClientIds);
		$this->assertSame([$this->alphaClientId, $this->betaClientId], $overviewClientIds);
		$this->assertSame([1, 1], array_column($monthOverview['clients'], 'active_employee_count'));

		// The last Client read must not stay open on the connection.
		$this->assertSame([], $this->distinctClientIdsIn('employees'));
	}

	#[Test]
	public function bank_email_settings_are_kept_per_client(): void {
		$bankPayrollEmailService = new BankPayrollEmailService();

		$this->openBooks($this->firstAccountantId, $this->alphaClientId);
		$errors = $bankPayrollEmailService->saveSettings($this->alphaClientId, $this->firstAccountantId, [
			'bank_name'              => 'Alpha Bank',
			'recipient_emails'       => [' payroll@alphabank.test ', 'PAYROLL@alphabank.test', '', 'ops@alphabank.test'],
			'email_subject_template' => 'Payroll {pay_month}',
			'email_body_template'    => 'Please pay {client_name}.',
		]);
		$this->assertSame([], $errors);
		$this->assertSame([], PayrollRunBankEmail::listForRun(0, $this->alphaClientId));
		$this->assertSame(
			['payroll@alphabank.test', 'ops@alphabank.test'],
			$bankPayrollEmailService->settingsForClient($this->alphaClientId)['recipient_emails']
		);

		$this->openBooks($this->firstAccountantId, $this->betaClientId);
		$this->assertSame([], $this->distinctClientIdsIn('client_bank_email_settings'));
		$this->assertSame([], $bankPayrollEmailService->settingsForClient($this->alphaClientId)['recipient_emails']);
		$this->assertSame([], $bankPayrollEmailService->settingsForClient($this->betaClientId)['recipient_emails']);
	}

	#[Test]
	public function bank_email_settings_with_a_bad_address_are_not_saved(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);

		$errors = (new BankPayrollEmailService())->saveSettings($this->alphaClientId, $this->firstAccountantId, [
			'recipient_emails'       => ['not-an-email'],
			'email_subject_template' => '',
			'email_body_template'    => 'Body',
		]);

		$this->assertSame(['recipient_emails', 'email_subject_template'], array_keys($errors));
		$this->assertSame([], $this->distinctClientIdsIn('client_bank_email_settings'));
	}

	#[Test]
	public function the_bank_file_lists_each_paid_employee_with_their_account_in_the_banks_layout(): void {
		$this->openBooks($this->firstAccountantId, $this->alphaClientId);
		$alphaEmployeeId = $this->employeeIdByClient[$this->alphaClientId];
		$rateSet         = PayrollStatutoryRate::findInForceOn('2026-10-31');

		$payrollRunId = PayrollRun::insertRun([
			'client_id'              => $this->alphaClientId,
			'pay_period'             => '2026-10-01',
			'status'                 => 'finalised',
			'statutory_rate_id'      => $rateSet['statutory_rate_id'],
			'employee_ssnit_percent' => $rateSet['employee_ssnit_percent'],
			'employer_ssnit_percent' => $rateSet['employer_ssnit_percent'],
			'employee_count'         => 1,
		]);
		PayrollRun::insertLine([
			'payroll_run_id' => $payrollRunId,
			'client_id'      => $this->alphaClientId,
			'employee_id'    => $alphaEmployeeId,
			'employee_name'  => 'Cecilia Anto',
			'allowance_mode' => 'flat',
			'basic_salary'   => 1000, 'employee_ssnit' => 55, 'basic_less_ssnit' => 945, 'allowance' => 0,
			'chargeable_income' => 945, 'paye' => 44.5, 'net_pay' => 900.5, 'employer_ssnit' => 130,
		]);

		$bankPaymentFileService = new BankPaymentFileService();
		$this->assertSame(['Cecilia Anto'], $bankPaymentFileService->employeesMissingAccountDetails($payrollRunId, $this->alphaClientId));

		Employee::updateEmployee($alphaEmployeeId, $this->alphaClientId, [
			'bank_account_number' => '1151010036156',
			'bank_name'           => 'GCB Bank',
			'bank_branch'         => 'Dome',
			'bank_sort_code'      => '040132',
		]);
		$this->assertSame([], $bankPaymentFileService->employeesMissingAccountDetails($payrollRunId, $this->alphaClientId));

		$workbookPath = tempnam(sys_get_temp_dir(), 'bank-file-test');
		file_put_contents($workbookPath, $bankPaymentFileService->xlsxContents($payrollRunId, $this->alphaClientId));
		try {
			$sheetRows = IOFactory::load($workbookPath)->getActiveSheet()->toArray(null, false, false);
		} finally {
			unlink($workbookPath);
		}

		$this->assertSame(
			[
				['Name', 'AccountNumber', 'BankName', 'BankBranch', 'SortCode', 'Amount'],
				['CECILIA ANTO', '1151010036156', 'GCB BANK', 'DOME', '040132', 900.5],
			],
			$sheetRows
		);

		// Another Client's books see none of it.
		$this->openBooks($this->firstAccountantId, $this->betaClientId);
		$this->assertSame([], PayrollRun::getBankPaymentLines($payrollRunId, $this->alphaClientId));
	}

	// -------------------------------------------------------------------------

	private function createClientWithEmployee(int $accountantUserId, string $clientName): int {
		User::scopeConnectionToUser($accountantUserId);
		$clientId = Client::insertClient($accountantUserId, ['client_name' => $clientName]);

		Client::scopeConnectionToClient($clientId);
		$employeeId = Employee::insertEmployee($clientId, ['first_name' => 'Employee', 'last_name' => "Of {$clientName}"], $accountantUserId);
		EmployeeSalary::upsertForEmployee($clientId, $employeeId, self::SALARY, $accountantUserId);

		$this->employeeIdByClient[$clientId] = $employeeId;
		return $clientId;
	}

	/** What the middleware does for a request to /api/clients/{clientId}/… */
	private function openBooks(int $accountantUserId, int $clientId): void {
		User::scopeConnectionToUser($accountantUserId);
		Client::scopeConnectionToClient($clientId);
	}

	/** @return int[] */
	private function distinctClientIdsIn(string $table): array {
		$rows = Database::fetchAll('SELECT DISTINCT client_id FROM ' . Database::guardIdentifier($table) . ' ORDER BY client_id');
		return array_map('intval', array_column($rows, 'client_id'));
	}

	/**
	 * The write must fail in the database. A savepoint keeps the surrounding transaction usable.
	 */
	private function assertDatabaseRefuses(callable $write): void {
		Database::query('SAVEPOINT refused_write');
		try {
			$write();
			$this->fail('The database accepted a write that crosses Clients.');
		} catch (\PDOException $refusal) {
			Database::query('ROLLBACK TO SAVEPOINT refused_write');
			$this->addToAssertionCount(1);
		}
	}
}
