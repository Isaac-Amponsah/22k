<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\PayrollExportService;
use App\Services\PayrollService;

/**
 * Payroll Controller
 * Monthly payroll runs of the Client in scope: draft → finalised (figures frozen) → paid, and the
 * Excel schedule. Payslips are laid out by the browser from a run's lines.
 */
final class PayrollController {

	private PayrollService $payrollService;

	public function __construct() {
		$this->payrollService = new PayrollService();
	}

	/** GET /api/clients/{clientId}/payroll-runs */
	public function index(Request $request, int $clientId): void {
		JsonResponse::success([
			'runs'         => $this->payrollService->listRuns(RequestContext::clientIdInScope()),
			'latest_month' => date('Y-m', strtotime('first day of next month')),
		]);
	}

	/** POST /api/clients/{clientId}/payroll-runs — create the draft run for a month. */
	public function store(Request $request, int $clientId): void {
		try {
			$payrollRunId = $this->payrollService->createRun(
				RequestContext::clientIdInScope(),
				RequestContext::signedInUserId(),
				(string) $request->input('pay_month', ''),
				(string) $request->input('notes', '')
			);
		} catch (\InvalidArgumentException $e) {
			// Our own validation — safe to show.
			throw new HttpError(422, $e->getMessage(), ['pay_month' => $e->getMessage()]);
		}

		JsonResponse::success(['payroll_run_id' => $payrollRunId], 'Payroll run created.', 201);
	}

	/** GET /api/clients/{clientId}/payroll-runs/{payrollRunId} */
	public function show(Request $request, int $clientId, int $payrollRunId): void {
		JsonResponse::success(['run' => $this->findRunOrRefuse($payrollRunId)]);
	}

	/** POST …/recalculate — rebuild a draft from current salaries and rates. */
	public function recalculate(Request $request, int $clientId, int $payrollRunId): void {
		$this->changeRun($payrollRunId, 'Payroll run recalculated.', function () use ($payrollRunId): void {
			$this->payrollService->recalculate($payrollRunId, RequestContext::clientIdInScope());
		});
	}

	/** POST …/finalise — freeze the figures. */
	public function finalise(Request $request, int $clientId, int $payrollRunId): void {
		$this->changeRun($payrollRunId, 'Payroll run finalised.', function () use ($payrollRunId): void {
			$this->payrollService->finalise($payrollRunId, RequestContext::clientIdInScope(), RequestContext::signedInUserId());
		});
	}

	/** POST …/mark-paid — record that salaries were paid. */
	public function markPaid(Request $request, int $clientId, int $payrollRunId): void {
		$this->changeRun($payrollRunId, 'Payroll run marked paid.', function () use ($payrollRunId): void {
			$this->payrollService->markPaid($payrollRunId, RequestContext::clientIdInScope(), RequestContext::signedInUserId());
		});
	}

	/** DELETE /api/clients/{clientId}/payroll-runs/{payrollRunId} — drafts only. */
	public function delete(Request $request, int $clientId, int $payrollRunId): void {
		try {
			$this->payrollService->deleteDraft($payrollRunId, RequestContext::clientIdInScope());
		} catch (\InvalidArgumentException $e) {
			throw new HttpError(422, $e->getMessage());
		}
		JsonResponse::success(null, 'Payroll run deleted.');
	}

	/** GET …/export — the monthly schedule as Excel. */
	public function export(Request $request, int $clientId, int $payrollRunId): void {
		$run = $this->findRunOrRefuse($payrollRunId);
		(new PayrollExportService())->streamXlsx($run, RequestContext::clientNameInScope());
	}

	private function findRunOrRefuse(int $payrollRunId): array {
		$run = $this->payrollService->findRun($payrollRunId, RequestContext::clientIdInScope());
		if ($run === null) {
			throw new HttpError(404, 'Payroll run not found.');
		}
		return $run;
	}

	/**
	 * Run a state change on one payroll run and answer with the run as it now stands.
	 */
	private function changeRun(int $payrollRunId, string $successMessage, callable $change): void {
		try {
			$change();
		} catch (\InvalidArgumentException $e) {
			// Our own validation (wrong status, a salary that cannot be worked out) — safe to show.
			throw new HttpError(422, $e->getMessage());
		}
		JsonResponse::success(['run' => $this->findRunOrRefuse($payrollRunId)], $successMessage);
	}
}
