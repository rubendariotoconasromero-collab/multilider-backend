<?php

namespace App\Architecture;

use Illuminate\Http\Request;

final class PublicPaymentRoutes
{
    public static function target(Request $request): ?string
    {
        $path = $request->path();
        if ($request->isMethod('POST') && in_array($path, ['api/public/clientes/buscar', 'api/public/pagos/procesar',
            'api/public/pagos/callback', 'api/public/pagos/confirmar-retorno'], true)) { return $path; }
        if (in_array($request->method(), ['GET', 'HEAD'], true) && preg_match('~^api/public/pagos/verificar/TEMP-[a-fA-F0-9-]{36}$~D', $path)) { return $path; }
        return null;
    }
}
