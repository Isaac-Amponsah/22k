<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\DashboardService;

/**
 * Dashboard Controller
 * One month across all of the signed-in accountant's Clients.
 */
final class DashboardController {

	private DashboardService $dashboardService;

	public function __construct() {
		$this->dashboardService = new DashboardService();
	}

	/** GET /api/dashboard?month=YYYY-MM — the current month when none is named. */
	public function show(Request $request): void {
		try {
			$monthOverview = $this->dashboardService->monthOverview(RequestContext::signedInUserId(), $request->queryParameter('month'));
		} catch (\InvalidArgumentException $e) {
			// Our own validation — safe to show.
			throw new HttpError(422, $e->getMessage());
		}
		JsonResponse::success($monthOverview);
	}
}
