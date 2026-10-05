<?php

/**
 * API routes.
 *
 * Three levels of access, set by each route's middleware:
 *   $open         — no sign-in (the session probe and sign-in itself).
 *   $signedIn     — any signed-in accountant; sees only their own Clients.
 *   $clientScoped — one Client's books. EVERY route under /api/clients/{clientId}/… that reads or
 *                   writes employees, salaries or payroll MUST use this list: ClientScopeMiddleware
 *                   is what checks the Client is the accountant's and confines the database to it.
 */

use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\EmployeeController;
use App\Controllers\PayrollController;
use App\Controllers\PayrollRateController;
use App\Controllers\PayrollSalaryController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\ClientScopeMiddleware;
use App\Middleware\CsrfMiddleware;

return static function (Router $router): void {
	$open         = [CsrfMiddleware::class];
	$signedIn     = [CsrfMiddleware::class, AuthMiddleware::class];
	$clientScoped = [CsrfMiddleware::class, AuthMiddleware::class, ClientScopeMiddleware::class];

	// Session
	$router->add('GET', '/api/auth/session', [AuthController::class, 'session'], $open);
	$router->add('POST', '/api/auth/login', [AuthController::class, 'login'], $open);
	$router->add('POST', '/api/auth/logout', [AuthController::class, 'logout'], $open);

	// The accountant's Clients
	$router->add('GET', '/api/clients', [ClientController::class, 'index'], $signedIn);
	$router->add('POST', '/api/clients', [ClientController::class, 'store'], $signedIn);
	$router->add('PATCH', '/api/clients/{clientId}', [ClientController::class, 'update'], $signedIn);
	$router->add('POST', '/api/clients/{clientId}/archive', [ClientController::class, 'archive'], $signedIn);
	$router->add('POST', '/api/clients/{clientId}/restore', [ClientController::class, 'restore'], $signedIn);

	// Statutory rates (shared by every Client)
	$router->add('GET', '/api/payroll-rates', [PayrollRateController::class, 'index'], $signedIn);
	$router->add('POST', '/api/payroll-rates', [PayrollRateController::class, 'store'], $signedIn);
	$router->add('DELETE', '/api/payroll-rates/{statutoryRateId}', [PayrollRateController::class, 'delete'], $signedIn);

	// One Client's employees
	$router->add('GET', '/api/clients/{clientId}/employees', [EmployeeController::class, 'index'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/employees', [EmployeeController::class, 'store'], $clientScoped);
	$router->add('PATCH', '/api/clients/{clientId}/employees/{employeeId}', [EmployeeController::class, 'update'], $clientScoped);

	// One Client's salaries
	$router->add('GET', '/api/clients/{clientId}/salaries', [PayrollSalaryController::class, 'index'], $clientScoped);
	$router->add('PUT', '/api/clients/{clientId}/salaries', [PayrollSalaryController::class, 'update'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/salaries/import/parse', [PayrollSalaryController::class, 'importParse'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/salaries/import/confirm', [PayrollSalaryController::class, 'importConfirm'], $clientScoped);

	// One Client's payroll runs: draft → finalised → paid
	$router->add('GET', '/api/clients/{clientId}/payroll-runs', [PayrollController::class, 'index'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/payroll-runs', [PayrollController::class, 'store'], $clientScoped);
	$router->add('GET', '/api/clients/{clientId}/payroll-runs/{payrollRunId}', [PayrollController::class, 'show'], $clientScoped);
	$router->add('DELETE', '/api/clients/{clientId}/payroll-runs/{payrollRunId}', [PayrollController::class, 'delete'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/payroll-runs/{payrollRunId}/recalculate', [PayrollController::class, 'recalculate'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/payroll-runs/{payrollRunId}/finalise', [PayrollController::class, 'finalise'], $clientScoped);
	$router->add('POST', '/api/clients/{clientId}/payroll-runs/{payrollRunId}/mark-paid', [PayrollController::class, 'markPaid'], $clientScoped);
	$router->add('GET', '/api/clients/{clientId}/payroll-runs/{payrollRunId}/export', [PayrollController::class, 'export'], $clientScoped);
};
