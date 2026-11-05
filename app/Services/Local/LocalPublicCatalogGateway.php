<?php

namespace App\Services\Local;

use App\Contracts\Architecture\PublicCatalogGateway;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocalPublicCatalogGateway implements PublicCatalogGateway
{
    public function handle(Request $request, Closure $local)
    {
        $response = $local($request);
        if ($response instanceof JsonResponse) {
            $clean = function (array $row) use (&$clean): array {
                if (isset($row['imagenes'])) {
                    $row['imagenes'] = array_values(array_filter($row['imagenes'], fn ($image) => (bool) ($image['publicada'] ?? true)));
                }
                unset($row['propietarios']);
                foreach ($row as $key => $value) {
                    if (is_array($value)) {
                        $row[$key] = $clean($value);
                    }
                }

                return $row;
            };
            $response->setData($clean($response->getData(true)));
        }

        return $response;
    }
}
