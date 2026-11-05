<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OperationController extends Controller
{
    public function index(Request $request)
    {
        // Local operations commit atomically; there is no remote worker or token to restore.
        return response()->json([]);
    }
    public function show(Request $request, string $id)
    {
        $receipt = DB::table('business_commands')->where('operation_id', $id)->where('actor_id', $request->user()->getAuthIdentifier())->first();
        abort_unless($receipt, 404);
        $state = $receipt->status < 400 ? 'Completada' : 'Rechazada';
        return response()->json(['id' => $id, 'operacion_id' => $id, 'state' => $state, 'estado_operacion' => $state,
            'resultado' => json_decode($receipt->result, true)]);
    }
    public function retry()
    {
        return response()->json(['message' => 'Consulta el recibo local o reenvía el mismo comando.'], 409);
    }
}
