<?php

namespace App\Architecture;

use Illuminate\Http\Request;

final class AggregateRoutes
{
    public const REPORTS = [
        'ventas-cobros' => ['acceso_historial_ventas', 'acceso_pagos'],
        'cartera-mora' => ['acceso_pagos'],
        'comisiones' => ['acceso_egresos'],
        'desempeno-asesores' => ['acceso_historial_ventas', 'acceso_pagos'],
        'inventario-propiedades' => ['acceso_propiedades'],
    ];

    public static function enabled(Request $request): bool
    {
        if (app(ArchitectureMode::class)->isLocal()
            || ! config('architecture.business_enabled') || ! config('architecture.aggregates_enabled')) {
            return false;
        }
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $path = $request->path();

            return in_array($path, ['api/landing', 'api/mi-cartera', 'api/dashboard/admin', 'api/dashboard/asesor',
                'api/reportes/destinatarios', 'api/correo-masivo/grupos', 'api/correo-masivo/destinatarios', 'api/correo-masivo/historial'], true)
                || preg_match('~^api/reportes/('.implode('|', array_keys(self::REPORTS)).')(?:/(pdf|excel))?$~D', $path)
                || preg_match('~^api/(?:mi-cartera/pagos/[1-9][0-9]*/comprobante|landing/asesores/[1-9][0-9]*/foto|correo-masivo/estado/[1-9][0-9]*)$~D', $path);
        }

        return $request->isMethod('POST') && in_array($request->path(), [
            'api/reportes/enviar-informe', 'api/correo-masivo/preview-destinatarios', 'api/correo-masivo/enviar',
        ], true);
    }

    public static function canReport(Request $request, string $report): bool
    {
        $permissions = self::REPORTS[$report] ?? [];

        return $permissions !== [] && collect($permissions)->every(fn ($permission) => $request->user()?->hasPermission($permission));
    }
}
