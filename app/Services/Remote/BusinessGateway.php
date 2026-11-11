<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\DelegatedTokenProvider;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class BusinessGateway
{
    public function __construct(private HttpClientFactory $clients, private DelegatedTokenProvider $tokens) {}

    public function handle(Request $request, array $route)
    {
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $target = $route['target'];
        $data = $request->except(['_method', '_token']);
        if ($method === 'GET' && ! isset($data['per_page'])) {
            $data['per_page'] = in_array($route['family'], ['contratos', 'entregas'], true) ? 15 : 10;
        }
        if ($route['family'] === 'ventas' && ($data['estado'] ?? null) === 'Completada') { $data['estado'] = 'Activa'; }
        if ($method === 'POST' && $target === 'api/ventas') {
            $request->validate(['operacion_id' => 'required|uuid', 'command_id' => 'required|uuid',
                'cliente_id' => 'required|integer|min:1', 'asesor_id' => 'required|integer|min:1', 'propiedad_id' => 'required|integer|min:1',
                'tipo_venta' => 'required|in:CONTADO,CREDITO', 'fecha' => 'required|date', 'monto_total' => 'required|numeric|min:0.01']);
            $target = 'api/operaciones/ventas';
        } elseif ($method === 'PUT' && preg_match('~^api/ventas/([1-9][0-9]*)/anular$~D', $target, $m)) {
            $request->validate(['operacion_id' => 'required|uuid', 'command_id' => 'required|uuid']);
            $target = 'api/operaciones/ventas/'.$m[1].'/anular';
            $method = 'POST';
        } elseif (in_array($route['family'], ['clientes', 'asesores'], true) && $method !== 'GET') {
            if ($method !== 'DELETE') {
                $rules = ['nombre_completo' => 'required|string|max:255', 'correo' => 'required|email|max:255',
                    'telefono' => 'nullable|string|max:50', 'direccion' => 'nullable|string|max:255', 'estado' => 'nullable|boolean'];
                if ($route['family'] === 'clientes') {
                    $rules += ['ci' => 'required|string|max:50', 'lugar_expedicion' => 'required|string|max:10'];
                } else {
                    $rules += ['porcentaje_comision' => 'nullable|numeric|min:0|max:100', 'foto' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048'];
                }
                $request->validate($rules);
            }
            $target = preg_replace('~^api/(clientes|asesores)~', 'api/perfiles/$1', $target);
        }
        if ($method !== 'GET') {
            $command = $request->header('Idempotency-Key', $request->input('command_id'));
            if ($command && ! Str::isUuid($command)) { throw new IntegrationException('Comando inválido.', 422); }
            $data['solicitud_id'] ??= $command;
        }
        if ($route['family'] === 'metodos-pago' && $method !== 'GET') {
            $data['nombre'] = $data['nombre_metodo'] ?? $data['nombre'] ?? '';
            $data['estado'] = ($data['estado'] ?? 'Activo') === 'Activo';
        }
        if ($route['family'] === 'cuentas-bancarias' && $method !== 'GET') {
            $data['moneda'] = self::currency($data['moneda'] ?? 'BOB');
            $data['estado'] = in_array($data['estado'] ?? 'Activa', ['Activa', true, 1], true);
            $data['titular'] ??= '';
            $data['numero_cuenta'] ??= '';
            $data['banco'] ??= '';
        }
        if ($target === 'api/pagos/bulk' && is_array($data['cuotas'] ?? null)) {
            foreach ($data['cuotas'] as &$quota) { if (is_array($quota)) { $quota['cuota_id'] ??= $quota['id'] ?? null; } }
            unset($quota);
        }
        if ($route['family'] === 'egresos' && str_ends_with($target, '/pagar')) { $data['cuenta_id'] = $data['cuenta_bancaria_id'] ?? null; }
        foreach (['cuenta_bancaria_id', 'nota_venta_id', 'asesor_id'] as $nullable) { if (($data[$nullable] ?? null) === '') { $data[$nullable] = null; } }
        if (isset($data['moneda'])) { $data['moneda'] = self::currency($data['moneda']); }
        $target = str_replace(['/impagas', '/pagadas', '/subir-acta'], ['/impagas', '/pagadas', '/acta'], $target);
        if (preg_match('~^api/pagos/venta/([1-9][0-9]*)/resumen$~D', $target, $m)) { $target = 'api/ventas/'.$m[1].'/resumen'; }
        if (preg_match('~^api/pagos/([1-9][0-9]*)/cancelar$~D', $target)) { $method = 'PUT'; }
        if ($target === 'api/pagos/pendientes/listar') { $target = 'api/pagos'; $data['estado'] = 'PENDIENTE_PAGO'; }
        if ($route['family'] === 'comisiones-asesores') { $target = str_replace(['/impagas', '/pagadas'], ['/impagas', '/pagadas'], $target); }
        if ($route['family'] === 'operaciones' && $method === 'GET' && preg_match('~^api/operaciones/[a-fA-F0-9-]{36}$~D', $target)) {
            try { $reply = $this->send($request, 'comercial', 'GET', $target); }
            catch (IntegrationException $error) {
                if ($error->status !== 404) { throw $error; }
                $reply = $this->send($request, 'finanzas', 'GET', $target);
            }
            return response()->json($reply['body'], $reply['status'])->header('Cache-Control', 'no-store');
        }
        if (str_ends_with($target, '/generar-pdf') && $route['family'] === 'contratos') {
            $packet = $this->send($request, 'comercial', 'GET', str_replace('/generar-pdf', '/datos-documento', $target))['body'];
            return app(RemoteBusinessDocuments::class)->contract($packet);
        }
        $binary = $method === 'GET' && preg_match('~/(descargar|descargar-acta|comprobante|foto)$~D', $target);
        if ($method === 'GET' && str_ends_with($target, '/comprobante')) {
            $packet = $this->send($request, 'finanzas', 'GET', str_replace('api/', 'api/compat/', str_replace('/comprobante', '/datos-comprobante', $target)))['body'];
            return app(RemoteBusinessDocuments::class)->receipt($packet);
        }
        if ($method === 'GET' && $route['service'] === 'finanzas'
            && (str_starts_with($target, 'api/pagos') || str_starts_with($target, 'api/ingresos') || str_starts_with($target, 'api/egresos') || str_starts_with($target, 'api/comisiones-asesores') || preg_match('~^api/ventas/[1-9][0-9]*/(?:plan-pago|resumen)$~D', $target))) {
            $target = 'api/compat/'.substr($target, 4);
        }
        $reply = $this->send($request, $route['service'], $method, $target, $data, (bool) $binary);
        if (isset($reply['bytes'])) {
            return response($reply['bytes'], 200, ['Content-Type' => $reply['type'], 'Content-Disposition' => $reply['disposition'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        $raw = $reply['body'];
        if ($method === 'GET' && $route['family'] === 'ventas' && preg_match('~^api/ventas(?:/[1-9][0-9]*)?$~D', $target)) {
            $rows = isset($raw['data']) ? $raw['data'] : [$raw];
            $ids = array_values(array_map(fn ($row) => $row['id'], array_filter($rows, fn ($row) => in_array($row['estado'] ?? '', ['Activa', 'Anulada'], true))));
            $financial = $ids ? $this->send($request, 'finanzas', 'GET', 'api/compat/ventas/lecturas', ['ids' => implode(',', $ids)])['body'] : [];
            foreach ($rows as &$row) {
                if (! in_array($row['estado'] ?? '', ['Activa', 'Anulada'], true)) { continue; }
                $reading = $financial[(string) $row['id']] ?? throw new IntegrationException('Falta la lectura financiera de la venta; requiere conciliación.');
                $row['pagos'] = $reading['pagos']; $row['plan_pago'] = $reading['plan_pago'];
                if ($row['tipo_venta'] === 'CREDITO') { $row['saldo_credito'] = $reading['resumen']['saldo_capital']; }
            }
            unset($row);
            if (isset($raw['data'])) { $raw['data'] = $rows; } else { $raw = $rows[0]; }
        }
        $body = $this->normalize($raw, $route['family']);
        if (isset($body['page_number'], $body['data'], $body['total'], $body['per_page'])) {
            $body = (new LengthAwarePaginator($body['data'], $body['total'], $body['per_page'], $body['page_number'],
                ['path' => $request->url(), 'query' => $request->query()]))->toArray();
        } elseif ($method === 'GET' && is_array($body) && array_is_list($body) && $route['family'] === 'metodos-pago') {
            $body = (new LengthAwarePaginator($body, count($body), max(1, count($body)), 1, ['path' => $request->url()]))->toArray();
        }
        if ($reply['status'] === 202) {
            $body['operacion_id'] = $body['id'] ?? $body['operacion_id'] ?? null;
            $body['estado_operacion'] = $body['state'] ?? 'Pendiente';
        } elseif ($method !== 'GET' && $reply['status'] < 300 && ! isset($body['data']) && ! isset($body['state'])) {
            $body = ['message' => 'Operación completada.', 'data' => $body];
        }
        return response()->json($body, $reply['status'])->header('Cache-Control', 'no-store');
    }

    public function send(Request $request, string $service, string $method, string $path, ?array $data = null, bool $binary = false): array
    {
        if (! preg_match('~^api/[a-z][a-z0-9/-]*$~D', $path) || str_contains($path, '..')) { throw new \InvalidArgumentException('Ruta de negocio inválida.'); }
        $correlation = $request->header('X-Correlation-ID') ?? '';
        $headers = ['Authorization' => 'Bearer '.$this->tokens->forRequest($request, $service),
            'X-Correlation-ID' => Str::isUuid($correlation) ? $correlation : (string) Str::uuid()];
        foreach (['Idempotency-Key', 'If-Match'] as $name) { if ($request->hasHeader($name)) { $headers[$name] = $request->header($name); } }
        $options = ['headers' => $headers];
        if ($method === 'GET') { $options['query'] = $data ?? $request->except(['password']); }
        elseif ($request->allFiles()) {
            $options['multipart'] = [];
            foreach ($data ?? [] as $name => $value) {
                if (! is_array($value) && ! is_object($value) && $value !== null) {
                    $options['multipart'][] = ['name' => $name, 'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
                }
            }
            foreach ($request->allFiles() as $name => $file) {
                abort_if(is_array($file) || $file->getSize() > 10 * 1024 * 1024, 422, 'Archivo inválido.');
                $field = $name === 'acta' && str_ends_with($path, '/acta') ? 'archivo' : $name;
                $options['multipart'][] = ['name' => $field, 'contents' => fopen($file->getRealPath(), 'rb'), 'filename' => $file->getClientOriginalName()];
            }
        } else { $options['json'] = $data ?? []; }
        try {
            $response = $this->clients->make($service)->request($method, $path, $options);
            $status = $response->getStatusCode();
            $bytes = (string) $response->getBody();
            if (strlen($bytes) > ($binary ? 11 * 1024 * 1024 : 2 * 1024 * 1024)) { throw new IntegrationException('Respuesta demasiado grande.'); }
        } catch (GuzzleException) { throw new IntegrationException('El servicio no respondió. Consulta o reintenta la misma operación.', 503, 'RESULTADO_INCIERTO'); }
        finally { foreach ($options['multipart'] ?? [] as $part) { if (is_resource($part['contents'])) { fclose($part['contents']); } } }
        if ($status >= 200 && $status < 300) {
            if ($binary) {
                $type = $response->getHeaderLine('Content-Type');
                if (! in_array(explode(';', $type)[0], ['application/pdf', 'image/png', 'image/jpeg', 'image/gif'], true)) { throw new IntegrationException('Tipo de documento inválido.'); }
                $disposition = 'inline';
                if (str_starts_with($type, 'application/pdf')) {
                    $name = preg_match('/filename="?([a-z0-9_.-]{1,100}\.pdf)"?/i', $response->getHeaderLine('Content-Disposition'), $match) ? $match[1] : 'documento.pdf';
                    $disposition = 'attachment; filename="'.$name.'"';
                }
                return ['status' => $status, 'bytes' => $bytes, 'type' => $type, 'disposition' => $disposition];
            }
            $body = $bytes === '' ? [] : json_decode($bytes, true);
            if (! is_array($body)) { throw new IntegrationException('Respuesta de negocio inválida.'); }
            return ['status' => $status, 'body' => $body];
        }
        $mapped = in_array($status, [401, 403, 404, 409, 412, 413, 422, 429], true) ? $status : ($status === 400 ? 422 : 503);
        $details = json_decode($bytes, true);
        $errors = [];
        foreach (($details['errors'] ?? []) as $field => $messages) {
            $field = Str::snake(preg_replace('/^\$\./', '', $field));
            if (preg_match('/^[a-z][a-z0-9_]{0,60}$/D', $field)) { $errors[$field] = ['Revisa el valor de este campo.']; }
        }
        $message = is_string($details['title'] ?? null) && strlen($details['title']) <= 300 ? $details['title'] : 'La operación fue rechazada. Revisa los datos, permisos y estado.';
        throw new IntegrationException($mapped === 503 ? 'Servicio de negocio no disponible; conserva el identificador de la operación.' : $message, $mapped,
            $mapped === 503 ? 'RESULTADO_INCIERTO' : 'BUSINESS_REQUEST_REJECTED', $errors);
    }

    private function normalize(array $data, string $family): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) { $value = $this->normalize($value, $key === 'nota_venta' ? 'ventas' : $family); }
            elseif (is_numeric($value) && preg_match('/^(monto.*|saldo.*|cuota_inicial|descuento|tasa_.*|porcentaje_comision)$/D', (string) $key)) {
                $value = number_format((float) $value, 2, '.', '');
            }
        }
        if (in_array($family, ['clientes', 'asesores'], true) && array_key_exists('estado', $data)) { $data['estado'] = $data['estado'] ? 1 : 0; }
        if ($family === 'metodos-pago' && isset($data['nombre'])) { $data['nombre_metodo'] = $data['nombre']; $data['estado'] = $data['estado'] ? 'Activo' : 'Inactivo'; }
        if ($family === 'cuentas-bancarias' && array_key_exists('estado', $data)) { $data['estado'] = $data['estado'] ? 'Activa' : 'Inactiva'; }
        if ($family === 'ventas' && ($data['estado'] ?? null) === 'Activa') { $data['estado'] = 'Completada'; }
        if (in_array($family, ['ingresos', 'egresos', 'comisiones-asesores'], true) && isset($data['moneda'])) {
            $data['moneda'] = ['BOB' => 'Bs', 'USD' => '$'][$data['moneda']] ?? $data['moneda'];
        }
        return $data;
    }
    private static function currency(string $value): string
    {
        return match ($value) { 'BOB', 'Bs' => 'BOB', 'USD', '$' => 'USD', default => throw new IntegrationException('Moneda desconocida.', 422) };
    }
}
