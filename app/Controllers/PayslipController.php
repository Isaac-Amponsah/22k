<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\PayrollService;
use App\Services\PayslipEmailService;
use App\Services\PayslipPdfService;

/**
 * Payslip Controller
 * The payslips of the Client in scope: one employee's month by month, one payslip as a PDF, and emailing
 * a run's payslips to its employees.
 */
final class PayslipController {

	private PayrollService $payrollService;
	private PayslipEmailService $payslipEmailService;

	public function __construct() {
		$this->payrollService      = new PayrollService();
		$this->payslipEmailService = new PayslipEmailService();
	}

	/** GET /api/clients/{clientId}/employees/{employeeId}/payslips */
	public function employeePayslips(Request $request, int $clientId, int $employeeId): void {
		$employeePayslips = $this->payrollService->payslipsForEmployee($employeeId, RequestContext::clientIdInScope());
		if ($employeePayslips === null) {
			throw new HttpError(404, 'Employee not found.');
		}
		JsonResponse::success($employeePayslips);
	}

	/** GET /api/clients/{clientId}/payroll-runs/{payrollRunId}/payslips/{payrollRunLineId}/pdf */
	public function downloadPdf(Request $request, int $clientId, int $payrollRunId, int $payrollRunLineId): void {
		$run = $this->findIssuedRunOrRefuse($payrollRunId);
		foreach ($run['lines'] as $line) {
			if ((int) $line['payroll_run_line_id'] === $payrollRunLineId) {
				(new PayslipPdfService())->streamPdf($run, $line, RequestContext::employerInScope());
			}
		}
		throw new HttpError(404, 'Payslip not found.');
	}

	/** GET /api/clients/{clientId}/payroll-runs/{payrollRunId}/payslip-emails — who gets theirs, who has. */
	public function showRunEmails(Request $request, int $clientId, int $payrollRunId): void {
		$this->findIssuedRunOrRefuse($payrollRunId);
		JsonResponse::success([
			'payslip_emails' => $this->payslipEmailService->payslipEmailStatusForRun($payrollRunId, RequestContext::clientIdInScope()),
		]);
	}

	/** POST /api/clients/{clientId}/payroll-runs/{payrollRunId}/payslip-emails — email those not yet sent theirs. */
	public function sendRunEmails(Request $request, int $clientId, int $payrollRunId): void {
		$run = $this->findIssuedRunOrRefuse($payrollRunId);

		try {
			$sendResult = $this->payslipEmailService->emailPayslipsForRun(
				$run,
				RequestContext::clientIdInScope(),
				RequestContext::employerInScope(),
				[
					'user_id' => RequestContext::signedInUserId(),
					'name'    => RequestContext::signedInUserName(),
					'email'   => RequestContext::signedInUserEmail(),
				]
			);
		} catch (\InvalidArgumentException $refusal) {
			// Our own refusal (a send already under way) - safe to show.
			throw new HttpError(409, $refusal->getMessage());
		} catch (\RuntimeException $mailError) {
			error_log('Payslip emails failed: ' . $mailError->getMessage());
			throw new HttpError(502, 'The payslips could not be emailed. Check the mail settings on the server, then try again.');
		}

		JsonResponse::success(
			['payslip_emails' => $this->payslipEmailService->payslipEmailStatusForRun($payrollRunId, RequestContext::clientIdInScope())],
			$this->payslipEmailService->summary($sendResult)
		);
	}

	/** Payslips are issued from frozen figures only: a draft's lines can still change. */
	private function findIssuedRunOrRefuse(int $payrollRunId): array {
		$run = $this->payrollService->findRun($payrollRunId, RequestContext::clientIdInScope());
		if ($run === null) {
			throw new HttpError(404, 'Payroll run not found.');
		}
		if ($run['status'] === PayrollService::STATUS_DRAFT) {
			throw new HttpError(422, 'Finalise this payroll run before issuing payslips.');
		}
		return $run;
	}
}
