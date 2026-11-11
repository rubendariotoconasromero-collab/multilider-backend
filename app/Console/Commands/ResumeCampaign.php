<?php

namespace App\Console\Commands;

use App\Models\CampanaCorreo;
use App\Services\MailCampaigns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ResumeCampaign extends Command
{
    protected $signature = 'correo:reanudar {id}';

    protected $description = 'Encola destinatarios pendientes de la campaña en el modo y dataset actuales';

    public function handle(MailCampaigns $campaigns): int
    {
        $campaign = CampanaCorreo::where($campaigns->scope())->whereNotNull('request_key')->find($this->argument('id'));
        if (! $campaign) {
            $this->error('Campaña no encontrada para el modo y dataset actuales.');

            return self::FAILURE;
        }
        DB::table('campana_destinatarios')->where('campana_id', $campaign->id)->where('estado', 'enviando')
            ->where('updated_at', '<=', now()->subMinutes(10))->update(['estado' => 'incierto', 'updated_at' => now()]);
        $campaigns->refresh($campaign->id);
        $campaigns->dispatch($campaign);
        $this->info('Campaña revisada y destinatarios pendientes encolados.');

        return self::SUCCESS;
    }
}
