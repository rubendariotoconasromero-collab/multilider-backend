<?php

namespace App\Services\Remote;

use App\Architecture\CatalogRoutes;
use App\Contracts\Architecture\PublicCatalogGateway;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class RemotePublicCatalogGateway implements PublicCatalogGateway
{
    public function __construct(private CatalogClient $client) {}

    public function handle(Request $request, Closure $local)
    {
        $target = CatalogRoutes::publicTarget($request);
        if (! $target) {
            throw new IntegrationException;
        }
        $body = RemoteCatalogAdministrationGateway::adapt($this->client->request($request, 'GET', $target, true)['body'], true);
        if (isset($body['data'], $body['total'], $body['per_page'], $body['current_page'])) {
            $body = new LengthAwarePaginator($body['data'], $body['total'], $body['per_page'], $body['current_page'], ['path' => $request->url(), 'pageName' => 'page']);
        }

        return response()->json($body);
    }
}
