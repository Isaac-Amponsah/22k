<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\BankEmailTemplate;
use App\Services\BankPaymentFileService;
use App\Services\BankPayrollEmailService;
use App\Services\PayrollService;

/**
 * Bank Payroll Email Controller
 * The bank email settings of the Client in scope, and sending one of its payroll runs to the bank.
 */
final class BankPayrollEmailController {

	private BankPayrollEmailService $bankPayrollEmailService;
	private PayrollService $payrollService;

	public function __construct() {
		$this->bankPayrollEmailService = new BankPayrollEmailService();
		$this->payrollService          = new PayrollService();
	}

	/** GET /api/clients/{clientId}/bank-email-settings */
	public function showSettings(Request $request, int $clientId): void {
		JsonResponse::success([
			'settings'     => $this->bankPayrollEmailService->settingsForClient(RequestContext::clientIdInScope()),
			'placeholders' => BankEmailTemplate::PLACEHOLDERS,
		]);
	}

	/** PUT /api/clients/{clientId}/bank-email-settings */
	public function saveSettings(Request $request, int $clientId): void {
		$errors = $this->bankPayrollEmailService->saveSettings(
			RequestContext::clientIdInScope(),
			RequestContext::signedInUserId(),
			$request->body()
		);
		if ($errors !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $errors);
		}
		JsonResponse::success(
			['settings' => $this->bankPayrollEmailService->settingsForClient(RequestContext::clientIdInScope())],
			'Bank email settings saved.'
		);
	}

	/** GET /api/clients/{clientId}/payroll-runs/{payrollRunId}/bank-email — the email as it would go out. */
	public function showRunEmail(Request $request, int $clientId, int $payrollRunId): void {
		JsonResponse::success(['bank_email' => $this->emailDraftFor($this->findRunOrRefuse($payrollRunId))]);
	}

	/** POST /api/clients/{clientId}/payroll-runs/{payrollRunId}/bank-email — send it. */
	public function sendRunEmail(Request $request, int $clientId, int $payrollRunId): void {
		$run = $this->findRunOrRefuse($payrollRunId);

		try {
			$this->bankPayrollEmailService->sendRunToBank(
				$run,
				RequestContext::clientIdInScope(),
				RequestContext::clientNameInScope(),
				[
					'user_id' => RequestContext::signedInUserId(),
					'name'    => RequestContext::signedInUserName(),
					'email'   => RequestContext::signedInUserEmail(),
				],
				(string) $request->input('email_subject', ''),
				(string) $request->input('email_body', '')
			);
		} catch (\InvalidArgumentException $e) {
			// Our own validation — safe to show.
			throw new HttpError(422, $e->getMessage());
		} catch (\RuntimeException $mailError) {
			// The mail server's own words stay in the log: they can name hosts and accounts.
			error_log('Payroll email to bank failed: ' . $mailError->getMessage());
			throw new HttpError(502, 'The email could not be sent. Check the mail settings on the server, then try again.');
		}

		JsonResponse::success(['bank_email' => $this->emailDraftFor($run)], 'Payroll sent to the bank.');
	}

	/** GET /api/clients/{clientId}/payroll-runs/{payrollRunId}/bank-file — the file the bank pays from, as Excel. */
	public function downloadBankFile(Request $request, int $clientId, int $payrollRunId): void {
		$run = $this->findRunOrRefuse($payrollRunId);
		if ($run['status'] === PayrollService::STATUS_DRAFT) {
			throw new HttpError(422, 'Finalise this payroll run before preparing its bank file.');
		}
		(new BankPaymentFileService())->streamXlsx($run, RequestContext::clientIdInScope(), RequestContext::clientNameInScope());
	}

	private function emailDraftFor(array $run): array {
		return $this->bankPayrollEmailService->emailDraftForRun(
			$run,
			RequestContext::clientIdInScope(),
			RequestContext::clientNameInScope(),
			RequestContext::signedInUserName()
		);
	}

	private function findRunOrRefuse(int $payrollRunId): array {
		$run = $this->payrollService->findRun($payrollRunId, RequestContext::clientIdInScope());
		if ($run === null) {
			throw new HttpError(404, 'Payroll run not found.');
		}
		return $run;
	}
}
