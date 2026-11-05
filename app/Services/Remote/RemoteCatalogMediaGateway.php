<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\CatalogMediaGateway;
use Illuminate\Http\Request;

final class RemoteCatalogMediaGateway implements CatalogMediaGateway
{
    public function __construct(private CatalogClient $client) {}

    public function content(Request $request, string $id, ?string $revision = null)
    {
        if ($revision === null) {
            abort_unless($request->user()?->hasPermission('acceso_propiedades') && ctype_digit($id), 403);
            $target = 'api/catalogo/imagenes/'.$id.'/contenido';
        } else {
            abort_unless(preg_match('/^[a-fA-F0-9-]{36}$/D', $id) && preg_match('/^[a-fA-F0-9]{64}$/D', $revision), 404);
            $target = 'api/public/catalogo/imagenes/'.$id.'/'.$revision;
        }
        $data = $this->client->request($request, 'GET', $target, $revision !== null, true);
        $mime = strtolower(explode(';', $data['content_type'])[0]);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            throw new IntegrationException('Contenido de imagen inválido.', 503, 'INVALID_SERVICE_RESPONSE');
        }

        return response($data['bytes'])->header('Content-Type', $mime)->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', $revision === null ? 'private, no-store' : 'public, no-cache, must-revalidate');
    }
}
