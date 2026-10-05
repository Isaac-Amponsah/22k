<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\PayrollRateService;

/**
 * Payroll Rate Controller
 * SSNIT percentages and PAYE bands, effective-dated and shared by every Client.
 */
final class PayrollRateController {

	private PayrollRateService $rateService;

	public function __construct() {
		$this->rateService = new PayrollRateService();
	}

	/** GET /api/payroll-rates */
	public function index(Request $request): void {
		JsonResponse::success(['rate_sets' => $this->rateService->listRateSets()]);
	}

	/** POST /api/payroll-rates */
	public function store(Request $request): void {
		$result = $this->rateService->createRateSet($request->body(), RequestContext::signedInUserId());
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['statutory_rate_id' => $result['statutory_rate_id']], 'Rate set added.', 201);
	}

	/** DELETE /api/payroll-rates/{statutoryRateId} */
	public function delete(Request $request, int $statutoryRateId): void {
		try {
			$this->rateService->deleteRateSet($statutoryRateId);
		} catch (\InvalidArgumentException $e) {
			throw new HttpError(422, $e->getMessage());
		}
		JsonResponse::success(null, 'Rate set deleted.');
	}
}
