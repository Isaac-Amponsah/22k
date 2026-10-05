<?php

namespace App\Controllers;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Helpers\JsonResponse;
use App\Services\ClientService;

/**
 * Client Controller
 * The signed-in accountant's Clients: list, add, edit, archive and restore.
 */
final class ClientController {

	private ClientService $clientService;

	public function __construct() {
		$this->clientService = new ClientService();
	}

	/** GET /api/clients */
	public function index(Request $request): void {
		JsonResponse::success(['clients' => $this->clientService->listClients(RequestContext::signedInUserId())]);
	}

	/** POST /api/clients */
	public function store(Request $request): void {
		$result = $this->clientService->createClient(RequestContext::signedInUserId(), $request->body());
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['client' => $result['client']], 'Client added.', 201);
	}

	/** PATCH /api/clients/{clientId} */
	public function update(Request $request, int $clientId): void {
		$result = $this->clientService->updateClient($clientId, RequestContext::signedInUserId(), $request->body());
		if (!$result['found']) {
			throw new HttpError(404, 'Client not found.');
		}
		if ($result['errors'] !== []) {
			throw new HttpError(422, 'Check the highlighted fields.', $result['errors']);
		}
		JsonResponse::success(['client' => $result['client']], 'Client saved.');
	}

	/** POST /api/clients/{clientId}/archive */
	public function archive(Request $request, int $clientId): void {
		$this->changeArchived($clientId, true, 'Client archived.');
	}

	/** POST /api/clients/{clientId}/restore */
	public function restore(Request $request, int $clientId): void {
		$this->changeArchived($clientId, false, 'Client restored.');
	}

	private function changeArchived(int $clientId, bool $isArchived, string $successMessage): void {
		$client = $this->clientService->setArchived($clientId, RequestContext::signedInUserId(), $isArchived);
		if ($client === null) {
			throw new HttpError(404, 'Client not found.');
		}
		JsonResponse::success(['client' => $client], $successMessage);
	}
}
