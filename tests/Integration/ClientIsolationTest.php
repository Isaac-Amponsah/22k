<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Helpers\Database;
use App\Helpers\Env;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
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
