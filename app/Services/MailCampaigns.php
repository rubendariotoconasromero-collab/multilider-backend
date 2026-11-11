<?php

namespace App\Services;

use App\Architecture\ArchitectureMode;
use App\Models\CampanaCorreo;
use Illuminate\Support\Facades\DB;

final class MailCampaigns
{
    public function scope(): array
    {
        $mode = app(ArchitectureMode::class)->value;
        $dataset = config('architecture.aggregate_dataset');
        if (! is_string($dataset) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $dataset)) {
            throw new \LogicException('AGGREGATE_DATASET no es válido.');
        }

        return ['origen' => $mode, 'dataset' => $dataset];
    }

    public function dispatch(CampanaCorreo $campaign): void
    {
        DB::table('campana_destinatarios')->where('campana_id', $campaign->id)->where('estado', 'pendiente')
            ->select('id')->chunkById(20, function ($rows) use ($campaign) {
                \App\Jobs\SendCampaignBatch::dispatch($campaign->id, $this->scope(), $rows->pluck('id')->all())->onQueue('correos');
            });
    }

    public function refresh(int $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $campaign = CampanaCorreo::where($this->scope())->lockForUpdate()->findOrFail($campaign);
            $totals = DB::table('campana_destinatarios')->where('campana_id', $campaign->id)
                ->select('estado', DB::raw('COUNT(*) as total'))->groupBy('estado')->pluck('total', 'estado');
            $sent = (int) ($totals['enviado'] ?? 0);
            $failed = (int) ($totals['fallido'] ?? 0);
            $uncertain = (int) ($totals['incierto'] ?? 0);
            $complete = $sent + $failed + $uncertain === $campaign->total_destinatarios;
            $campaign->update(['total_enviados' => $sent, 'total_fallidos' => $failed, 'total_inciertos' => $uncertain,
                'estado' => $complete ? ($sent === 0 ? 'fallido' : 'completado') : 'procesando']);
        }, 3);
    }
}
