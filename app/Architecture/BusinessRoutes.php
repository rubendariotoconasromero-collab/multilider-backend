<?php

namespace App\Architecture;

use Illuminate\Http\Request;

final class BusinessRoutes
{
    public static function describe(Request $request): ?array
    {
        $path = $request->path();
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $families = ['clientes' => ['comercial', 'acceso_clientes'], 'asesores' => ['comercial', 'acceso_asesores'],
            'ventas' => ['comercial', $method === 'GET' && $path === 'api/ventas' ? 'acceso_historial_ventas' : 'acceso_ventas'],
            'contratos' => ['comercial', 'acceso_contratos'], 'entregas' => ['comercial', 'acceso_entregas'],
            'planes-pago' => ['finanzas', 'acceso_pagos'], 'pagos' => ['finanzas', 'acceso_pagos'],
            'metodos-pago' => ['finanzas', 'acceso_pagos'], 'cuentas-bancarias' => ['finanzas', 'acceso_pagos'],
            'mapeo-metodos-cuentas' => ['finanzas', 'acceso_pagos'], 'ingresos' => ['finanzas', 'acceso_ingresos'],
            'egresos' => ['finanzas', 'acceso_egresos'], 'comisiones-asesores' => ['finanzas', 'acceso_egresos']];
        foreach ($families as $family => [$service, $permission]) {
            // Explicit supported resources; an arbitrary suffix never enables an unfinished route.
            $resource = preg_quote($family, '~');
            if (preg_match('~^api/'.$resource.'(?:/([1-9][0-9]*))?$~D', $path, $m)
                && ($method === 'GET' || ($method === 'POST' && empty($m[1])) || (! empty($m[1]) && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)))) {
                return compact('service', 'permission', 'family') + ['target' => $path];
            }
        }
        $special = [
            ['GET', 'asesores/[1-9][0-9]*/foto', 'comercial', 'acceso_asesores', 'asesores'],
            ['PUT', 'ventas/[1-9][0-9]*/anular', 'comercial', 'acceso_ventas', 'ventas'],
            ['GET', 'ventas/[1-9][0-9]*/plan-pago', 'finanzas', 'acceso_pagos', 'ventas'],
            ['POST', 'contratos/[1-9][0-9]*/gestionar', 'comercial', 'acceso_contratos', 'contratos'],
            ['PUT', 'contratos/[1-9][0-9]*/anular', 'comercial', 'acceso_contratos', 'contratos'],
            ['GET', 'contratos/[1-9][0-9]*/(?:descargar|generar-pdf)', 'comercial', 'acceso_contratos', 'contratos'],
            ['POST', 'entregas/[1-9][0-9]*/subir-acta', 'comercial', 'acceso_entregas', 'entregas'],
            ['GET', 'entregas/[1-9][0-9]*/descargar-acta', 'comercial', 'acceso_entregas', 'entregas'],
            ['POST', 'planes-pago/[1-9][0-9]*/(?:amortizar|reprogramar)', 'finanzas', 'acceso_pagos', 'planes-pago'],
            ['GET', 'planes-pago/[1-9][0-9]*/reprogramaciones', 'finanzas', 'acceso_pagos', 'planes-pago'],
            ['POST', 'pagos/bulk', 'finanzas', 'acceso_pagos', 'pagos'],
            ['POST', 'pagos/[1-9][0-9]*/procesar', 'finanzas', 'acceso_pagos', 'pagos'],
            ['PUT', 'pagos/[1-9][0-9]*/cancelar', 'finanzas', 'acceso_pagos', 'pagos'],
            ['GET', 'pagos/(?:venta|cliente)/[1-9][0-9]*/resumen', 'finanzas', 'acceso_pagos', 'pagos'],
            ['GET', 'pagos/pendientes/listar', 'finanzas', 'acceso_pagos', 'pagos'],
            ['GET', 'pagos/[1-9][0-9]*/comprobante', 'finanzas', 'acceso_pagos', 'pagos'],
            ['GET', '(?:ingresos|egresos)/resumen', 'finanzas', null, 'movimientos'],
            ['PUT', '(?:ingresos|egresos)/[1-9][0-9]*/anular', 'finanzas', null, 'movimientos'],
            ['PUT', 'egresos/[1-9][0-9]*/pagar', 'finanzas', 'acceso_egresos', 'egresos'],
            ['GET', 'cuentas-bancarias/[1-9][0-9]*/saldo', 'finanzas', 'acceso_pagos', 'cuentas-bancarias'],
            ['GET', 'mapeo-metodos-cuentas/obtener-cuenta/[1-9][0-9]*', 'finanzas', 'acceso_pagos', 'mapeo-metodos-cuentas'],
            ['GET', 'comisiones-asesores/[1-9][0-9]*/(?:impagas|pagadas)', 'finanzas', 'acceso_egresos', 'comisiones-asesores'],
            ['GET', 'comisiones-asesores/egreso/[1-9][0-9]*/comprobante', 'finanzas', 'acceso_egresos', 'comisiones-asesores'],
            ['GET', 'operaciones(?:/[a-fA-F0-9-]{36})?', 'comercial', null, 'operaciones'],
            ['POST', 'operaciones/[a-fA-F0-9-]{36}/reintentar', 'comercial', 'acceso_ventas', 'operaciones'],
        ];
        foreach ($special as [$verb, $pattern, $service, $permission, $family]) {
            if ($method === $verb && preg_match('~^api/'.$pattern.'$~D', $path)) {
                if ($family !== 'operaciones') { $permission ??= str_starts_with($path, 'api/ingresos') ? 'acceso_ingresos' : 'acceso_egresos'; }
                return compact('service', 'permission', 'family') + ['target' => $path];
            }
        }
        return null;
    }
}
