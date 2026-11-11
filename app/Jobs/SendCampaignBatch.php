<?php

namespace App\Jobs;

use App\Mail\CorreoMasivo;
use App\Models\CampanaCorreo;
use App\Services\MailCampaigns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $campaign, public array $scope, public array $recipients) {}

    public function handle(MailCampaigns $campaigns): void
    {
        if ($campaigns->scope() !== $this->scope) {
            $this->fail(new \RuntimeException('La campaña pertenece a otra arquitectura o conjunto de datos.'));

            return;
        }
        $campaign = CampanaCorreo::where($this->scope)->find($this->campaign);
        if (! $campaign) {
            return;
        }
        $rows = DB::table('campana_destinatarios')->where('campana_id', $campaign->id)->whereIn('id', $this->recipients)->get();
        foreach ($rows as $recipient) {
            $row = DB::table('campana_destinatarios')->where('campana_id', $campaign->id)->where('id', $recipient->id);
            if ((clone $row)->where('estado', 'pendiente')->update(['estado' => 'enviando', 'updated_at' => now()]) !== 1) {
                continue;
            }
            $state = 'enviado';
            try {
                Mail::to($recipient->correo)->send(new CorreoMasivo($recipient->nombre, $campaign->asunto, $campaign->mensaje));
            } catch (\Throwable $error) {
                $state = 'incierto';
                Log::warning('Entrega de campaña sin confirmar.', ['campaign' => $campaign->id, 'error' => $error::class]);
            }
            $row->where('estado', 'enviando')->update(['estado' => $state, 'updated_at' => now()]);
        }
        $campaigns->refresh($campaign->id);
    }
}
