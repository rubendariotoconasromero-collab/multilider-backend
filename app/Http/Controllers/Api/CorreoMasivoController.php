<?php

namespace App\Http\Controllers\Api;

use App\Architecture\AggregateRoutes;
use App\Auth\RemotePrincipal;
use App\Http\Controllers\Controller;
use App\Models\CampanaCorreo;
use App\Models\Ciudad;
use App\Models\Cliente;
use App\Services\MailCampaigns;
use App\Services\MailAddresses;
use App\Services\Remote\AggregateReader;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorreoMasivoController extends Controller
{
    public function __construct(private AggregateReader $reader, private MailCampaigns $campaigns) {}

    // GET /api/correo-masivo/grupos
    // Devuelve los grupos disponibles con conteo de destinatarios
    public function grupos(Request $request)
    {
        $audience = $this->audience($request, 'todos', false);
        $grupos = [
            [
                'value'       => 'todos',
                'label'       => 'Todos los clientes',
                'descripcion' => 'Clientes activos e inactivos',
                'total'       => $audience->unique('correo')->count(),
            ],
            [
                'value'       => 'activos',
                'label'       => 'Clientes activos',
                'descripcion' => 'Solo clientes con cuenta activa',
                'total'       => $audience->where('estado', true)->unique('correo')->count(),
            ],
            [
                'value'       => 'inactivos',
                'label'       => 'Clientes inactivos',
                'descripcion' => 'Solo clientes con cuenta inactiva',
                'total'       => $audience->where('estado', false)->unique('correo')->count(),
            ],
            [
                'value'       => 'ciudad',
                'label'       => 'Por ciudad',
                'descripcion' => 'Filtrar por ciudad del cliente',
                'total'       => null,
            ],
            [
                'value'       => 'seleccionados',
                'label'       => 'Selección manual',
                'descripcion' => 'Elige los clientes uno por uno',
                'total'       => null,
            ],
        ];

        $ciudades = AggregateRoutes::enabled($request)
            ? collect($this->reader->read($request, 'propiedades', 'api/public/catalogo/ciudades'))->sortBy('nombre')->values()
            : Ciudad::where('estado', true)->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json([
            'grupos'   => $grupos,
            'ciudades' => $ciudades,
        ]);
    }

    // POST /api/correo-masivo/preview-destinatarios
    // Retorna lista de destinatarios según filtros (preview antes de enviar)
    public function previewDestinatarios(Request $request)
    {
        $request->validate($this->audienceRules($request));
        $destinatarios = $this->audience($request, $request->tipo);

        return response()->json([
            'total'         => $destinatarios->count(),
            'destinatarios' => $destinatarios,
            'preview_token' => $this->fingerprint($request, $destinatarios),
        ]);
    }

    public function destinatarios(Request $request)
    {
        return response()->json(['data' => $this->audience($request, 'todos', false)]);
    }

    // POST /api/correo-masivo/enviar
    // Crea la campaña y despacha jobs en lotes
    public function enviar(Request $request)
    {
        $data = $request->validate($this->audienceRules($request) + [
            'asunto'      => 'required|string|max:255',
            'mensaje'     => 'required|string|max:10000',
            'preview_token' => (AggregateRoutes::enabled($request) ? 'required' : 'nullable').'|size:64|string',
            'request_key' => (AggregateRoutes::enabled($request) ? 'required' : 'nullable').'|uuid',
        ]);
        $filtros = match ($request->tipo) {
            'ciudad' => ['ciudad_id' => $request->integer('ciudad_id')],
            'seleccionados' => ['cliente_ids' => collect($data['cliente_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all()],
            default => null,
        };
        $scope = $this->campaigns->scope() + ['request_key' => $data['request_key'] ?? (string) Str::uuid()];
        $actor = $this->actor($request);
        $hash = hash('sha256', json_encode([$actor, $data['asunto'], $data['mensaje'], $data['tipo'], $filtros, $data['preview_token'] ?? null], JSON_THROW_ON_ERROR));
        $campana = CampanaCorreo::where($scope)->first();
        if (! $campana) {
            $destinatarios = $this->audience($request, $request->tipo);
            abort_if(isset($data['preview_token']) && ! hash_equals($this->fingerprint($request, $destinatarios), $data['preview_token']),
                409, 'La lista de destinatarios cambió. Vuelve a previsualizarla.');
            abort_if($destinatarios->isEmpty(), 422, 'No se encontraron destinatarios con correo válido.');
            $campana = DB::transaction(function () use ($scope, $actor, $data, $hash, $filtros, $destinatarios) {
                $campaign = CampanaCorreo::query()->createOrFirst($scope, $actor + [
                    'asunto' => $data['asunto'], 'mensaje' => $data['mensaje'], 'request_hash' => $hash,
                    'tipo_destinatario' => $data['tipo'], 'filtros' => $filtros, 'total_destinatarios' => $destinatarios->count(),
                ]);
                if ($campaign->wasRecentlyCreated) {
                    foreach ($destinatarios->chunk(100) as $chunk) {
                        DB::table('campana_destinatarios')->insert($chunk->map(fn ($row) => [
                            'campana_id' => $campaign->id, 'nombre' => $row['nombre'], 'correo' => $row['correo'],
                            'created_at' => now(), 'updated_at' => now(),
                        ])->all());
                    }
                }

                return $campaign;
            }, 3);
        }
        abort_unless(hash_equals($campana->request_hash, $hash), 409, 'La solicitud de campaña ya fue utilizada.');
        if ($campana->wasRecentlyCreated) {
            $this->campaigns->dispatch($campana);
        }

        return response()->json([
            'message'   => 'Campaña creada. Los correos se están enviando en segundo plano.',
            'campana_id'=> $campana->id,
            'total'     => $campana->total_destinatarios,
        ], $campana->wasRecentlyCreated ? 201 : 200);
    }

    // GET /api/correo-masivo/historial
    public function historial(Request $request)
    {
        $campanas = CampanaCorreo::where($this->campaigns->scope())->with('usuario:id,nombre')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json($campanas);
    }

    // GET /api/correo-masivo/estado/{id}
    public function estado($id)
    {
        $campana = CampanaCorreo::where($this->campaigns->scope())->findOrFail($id);

        $progreso = $campana->total_destinatarios > 0
            ? round(($campana->total_enviados + $campana->total_fallidos + $campana->total_inciertos) / $campana->total_destinatarios * 100)
            : 0;

        return response()->json([
            'id'                  => $campana->id,
            'asunto'              => $campana->asunto,
            'estado'              => $campana->estado,
            'total_destinatarios' => $campana->total_destinatarios,
            'total_enviados'      => $campana->total_enviados,
            'total_fallidos'      => $campana->total_fallidos,
            'total_inciertos'     => $campana->total_inciertos,
            'progreso'            => $progreso,
            'created_at'          => $campana->created_at,
        ]);
    }

    // ---- Helper privado ----

    private function audienceRules(Request $request): array
    {
        return [
            'tipo' => 'required|in:todos,activos,inactivos,ciudad,seleccionados',
            'ciudad_id' => 'required_if:tipo,ciudad|nullable|integer|min:1'.(AggregateRoutes::enabled($request) ? '' : '|exists:ciudades,id'),
            'cliente_ids' => 'required_if:tipo,seleccionados|nullable|array|max:500',
            'cliente_ids.*' => 'integer|min:1|distinct',
        ];
    }

    private function audience(Request $request, string $type, bool $unique = true): Collection
    {
        $rows = AggregateRoutes::enabled($request)
            ? $this->remoteAudience($request, $type) : $this->localAudience($request, $type);
        $rows = $rows->filter(fn ($row) => filter_var(trim($row['correo']), FILTER_VALIDATE_EMAIL))
            ->map(fn ($row) => array_replace($row, ['correo' => MailAddresses::resolve($row['correo'])]))->sortBy('id');
        if ($type === 'seleccionados') {
            abort_unless($rows->unique('id')->count() === count($request->input('cliente_ids')), 422,
                'Uno de los clientes seleccionados ya no tiene un correo válido.');
        }

        return ($unique ? $rows->unique('correo') : $rows)->values();
    }

    private function remoteAudience(Request $request, string $type): Collection
    {
        $path = 'api/lecturas/destinatarios';
        if ($type === 'ciudad') {
            $ids = $this->reader->all($request, 'propiedades', 'api/catalogo/propiedades-ciudad', ['ciudad_id' => $request->integer('ciudad_id')])->pluck('id');

            return $ids->chunk(100)->flatMap(fn ($chunk) => $this->reader->all($request, 'comercial', $path, ['propiedades' => $chunk->implode(',')]))->unique('id');
        }
        if ($type === 'seleccionados') {
            return collect($request->input('cliente_ids'))->chunk(100)->flatMap(fn ($chunk) =>
                $this->reader->all($request, 'comercial', $path, ['ids' => $chunk->implode(',')]));
        }
        $filters = match ($type) { 'activos' => ['activo' => 'true'], 'inactivos' => ['activo' => 'false'], default => [] };

        return $this->reader->all($request, 'comercial', $path, $filters);
    }

    private function localAudience(Request $request, string $type): Collection
    {
        $query = Cliente::whereNotNull('correo')->where('correo', '!=', '');
        $query = match ($type) {
            'activos'      => $query->where('estado', true),
            'inactivos'    => $query->where('estado', false),
            'ciudad'       => $query->whereHas('notasVentas', fn ($sale) => $sale->where('estado', 'Completada')
                ->whereHas('propiedad.sectorUrbano.distrito', fn ($district) => $district->where('ciudad_id', $request->ciudad_id))),
            'seleccionados'=> $query->whereIn('id', $request->cliente_ids ?? []),
            default        => $query,
        };

        return $query->get(['id', 'nombre_completo', 'correo', 'estado'])->map(fn ($client) => [
            'id' => $client->id, 'nombre' => $client->nombre_completo, 'correo' => $client->correo, 'estado' => (bool) $client->estado,
        ]);
    }

    private function actor(Request $request): array
    {
        $user = $request->user();
        if ($user instanceof RemotePrincipal) {
            return ['user_id' => null, 'actor_issuer' => $user->claims['iss'], 'actor_subject' => (string) $user->claims['sub'], 'actor_name' => $user->nombre];
        }

        return ['user_id' => $user->id];
    }

    private function fingerprint(Request $request, Collection $rows): string
    {
        $data = [$this->campaigns->scope(), $this->actor($request), $rows->map(fn ($row) => [$row['id'], $row['correo']])->all()];

        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
