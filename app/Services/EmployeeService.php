<?php

namespace App\Services;

use App\Models\Employee;

/**
 * EmployeeService
 * A Client's employees: who can be put on its payroll.
 */
final class EmployeeService {

	private const TEXT_FIELD_MAX_LENGTHS = [
		'employee_code'             => 50,
		'first_name'                => 100,
		'last_name'                 => 100,
		'job_title_name'            => 100,
		'department_name'           => 100,
		'ssnit_number'              => 30,
		'tax_identification_number' => 30,
		'bank_account_number'       => 30,
		'bank_name'                 => 100,
		'bank_branch'               => 100,
		'bank_sort_code'            => 10,
	];

	/** Bank identifiers are digits only; spaces and dashes typed for readability are dropped. */
	private const DIGIT_ONLY_FIELDS = ['bank_account_number' => 'account number', 'bank_sort_code' => 'sort code'];

	public function listEmployees(int $clientId): array {
		return Employee::listForClient($clientId);
	}

	/**
	 * @return array{errors: array<string, string>, employee: ?array}
	 */
	public function createEmployee(int $clientId, ?int $userId, array $input): array {
		$validated = $this->validateEmployeeInput($clientId, $input, null);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'employee' => null];
		}

		$employeeId = Employee::insertEmployee($clientId, $validated['employee_details'], $userId);
		return ['errors' => [], 'employee' => Employee::findForClient($employeeId, $clientId)];
	}

	/**
	 * @return array{errors: array<string, string>, employee: ?array, found: bool} found is false when the
	 *         employee is not this Client's.
	 */
	public function updateEmployee(int $employeeId, int $clientId, array $input): array {
		if (Employee::findForClient($employeeId, $clientId) === null) {
			return ['errors' => [], 'employee' => null, 'found' => false];
		}

		$validated = $this->validateEmployeeInput($clientId, $input, $employeeId);
		if ($validated['errors'] !== []) {
			return ['errors' => $validated['errors'], 'employee' => null, 'found' => true];
		}

		Employee::updateEmployee($employeeId, $clientId, $validated['employee_details']);
		return ['errors' => [], 'employee' => Employee::findForClient($employeeId, $clientId), 'found' => true];
	}

	/**
	 * @return array{errors: array<string, string>, employee_details: array}
	 */
	private function validateEmployeeInput(int $clientId, array $input, ?int $editedEmployeeId): array {
		$errors          = [];
		$employeeDetails = [];

		foreach (self::TEXT_FIELD_MAX_LENGTHS as $field => $maxLength) {
			$value = trim((string) ($input[$field] ?? ''));
			if (isset(self::DIGIT_ONLY_FIELDS[$field])) {
				$value = str_replace([' ', '-'], '', $value);
				if ($value !== '' && !ctype_digit($value)) {
					$errors[$field] = 'Enter the ' . self::DIGIT_ONLY_FIELDS[$field] . ' in digits only.';
				}
			}
			if (mb_strlen($value) > $maxLength) {
				$errors[$field] = "Keep this to {$maxLength} characters.";
			}
			$employeeDetails[$field] = $value === '' ? null : $value;
		}

		foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $field => $label) {
			if ($employeeDetails[$field] === null) {
				$errors[$field] = "Enter the {$label}.";
			}
		}

		$employeeCode = $employeeDetails['employee_code'];
		if ($employeeCode !== null && !isset($errors['employee_code']) && Employee::codeTaken($clientId, $employeeCode, $editedEmployeeId)) {
			$errors['employee_code'] = 'Another employee of this client has this code.';
		}

		$hireDateInput = trim((string) ($input['hire_date'] ?? ''));
		$employeeDetails['hire_date'] = null;
		if ($hireDateInput !== '') {
			$hireDate = \DateTime::createFromFormat('!Y-m-d', $hireDateInput);
			if (!$hireDate || $hireDate->format('Y-m-d') !== $hireDateInput) {
				$errors['hire_date'] = 'Enter the hire date as a date.';
			} else {
				$employeeDetails['hire_date'] = $hireDateInput;
			}
		}

		$employeeDetails['is_active'] = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

		return ['errors' => $errors, 'employee_details' => $employeeDetails];
	}
}
