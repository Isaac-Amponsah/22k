<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\EmployeeService;

/**
 * Employee Controller
 * The employees of the Client in scope.
 */
final class EmployeeController {

	private EmployeeService $employeeService;

	public function __construct() {
		$this->employeeService = new EmployeeService();
	}

	/** GET /api/clients/{clientId}/employees */
	public function index(Request $request, int $clientId): void {
		JsonResponse::success(['employees' => $this->employeeService->listEmployees(RequestContext::clientIdInScope())]);
	}

	/** POST /api/clients/{clientId}/employees */
	public function store(Request $request, int $clientId): void {
		$result = $this->employeeService->createEmployee(
			RequestContext::clientIdInScope(),
			RequestContext::signedInUserId(),
			$request->body()
		);
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['employee' => $result['employee']], 'Employee added.', 201);
	}

	/** PATCH /api/clients/{clientId}/employees/{employeeId} */
	public function update(Request $request, int $clientId, int $employeeId): void {
		$result = $this->employeeService->updateEmployee($employeeId, RequestContext::clientIdInScope(), $request->body());
		if (!$result['found']) {
			throw new HttpError(404, 'Employee not found.');
		}
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['employee' => $result['employee']], 'Employee saved.');
	}
}
