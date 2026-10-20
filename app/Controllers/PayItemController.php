<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\PayItemService;

/**
 * Pay Item Controller
 * The allowance and deduction types of the Client in scope.
 */
final class PayItemController {

	private PayItemService $payItemService;

	public function __construct() {
		$this->payItemService = new PayItemService();
	}

	/** GET /api/clients/{clientId}/pay-items */
	public function index(Request $request, int $clientId): void {
		JsonResponse::success(['pay_items' => $this->payItemService->listPayItems(RequestContext::clientIdInScope())]);
	}

	/** POST /api/clients/{clientId}/pay-items */
	public function store(Request $request, int $clientId): void {
		$result = $this->payItemService->createPayItem(RequestContext::clientIdInScope(), $request->body());
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['pay_item' => $result['pay_item']], $result['pay_item']['item_name'] . ' added.', 201);
	}

	/** PATCH /api/clients/{clientId}/pay-items/{payItemId} */
	public function update(Request $request, int $clientId, int $payItemId): void {
		$result = $this->payItemService->updatePayItem($payItemId, RequestContext::clientIdInScope(), $request->body());
		if (!$result['found']) {
			throw new HttpError(404, 'Pay item not found.');
		}
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['pay_item' => $result['pay_item']], $result['pay_item']['item_name'] . ' saved.');
	}

	/** DELETE /api/clients/{clientId}/pay-items/{payItemId} */
	public function delete(Request $request, int $clientId, int $payItemId): void {
		try {
			$this->payItemService->deletePayItem($payItemId, RequestContext::clientIdInScope());
		} catch (\InvalidArgumentException $e) {
			throw new HttpError(422, $e->getMessage());
		}
		JsonResponse::success(null, 'Pay item deleted.');
	}
}
