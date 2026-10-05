<?php

namespace App\Middleware;

use App\Core\HttpError;
use App\Core\Request;
use App\Core\RequestContext;
use App\Services\ClientService;

/**
 * ClientScopeMiddleware
 * Guards every /api/clients/{clientId}/… route. The Client named in the address must be one of
 * the signed-in accountant's; the database connection is then confined to it, so nothing the
 * request does can read or write another Client's rows. Runs after AuthMiddleware.
 *
 * A Client that is not the accountant's answers 404, the same as one that does not exist.
 */
final class ClientScopeMiddleware {

	public function handle(Request $request, array $pathParameters): void {
		$clientService = new ClientService();
		$client        = $clientService->findOwnedClient((int) ($pathParameters['clientId'] ?? 0), RequestContext::signedInUserId());

		if ($client === null) {
			throw new HttpError(404, 'Client not found.');
		}
		if ($client['is_archived'] && !$request->isReadOnly()) {
			throw new HttpError(409, 'This client is archived. Restore it before changing its payroll.');
		}

		$clientService->confineConnectionToClient((int) $client['client_id']);
		RequestContext::setClientInScope($client);
	}
}
