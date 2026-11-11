<?php

use App\Jobs\SendCampaignBatch;
use App\Models\CampanaCorreo;
use App\Services\MailCampaigns;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedDatabaseGuard::check($app);
$scope = app(MailCampaigns::class)->scope();
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || config('mail.default') !== 'array'
    || $scope !== ['origen' => 'microservices', 'dataset' => 'isolated-f6']) {
    throw new RuntimeException('F6 isolated remote queue required.');
}
$key = '001b001b-0000-4000-8000-000000000006';
$action = $argv[1] ?? '';
if ($action === 'seed') {
    $campaign = CampanaCorreo::create($scope + ['request_key' => $key, 'actor_issuer' => 'fixture', 'actor_subject' => '1',
        'asunto' => 'F6 worker', 'mensaje' => 'Transport in memory.', 'tipo_destinatario' => 'seleccionados',
        'total_destinatarios' => 1]);
    DB::table('campana_destinatarios')->insert(['campana_id' => $campaign->id, 'nombre' => 'Fixture',
        'correo' => 'queue@example.test', 'created_at' => now(), 'updated_at' => now()]);
} else {
    $campaign = CampanaCorreo::where($scope)->where('request_key', $key)->firstOrFail();
}
$recipient = DB::table('campana_destinatarios')->where('campana_id', $campaign->id)->sole();
$dispatch = fn () => SendCampaignBatch::dispatch($campaign->id, $scope, [$recipient->id])->onConnection('database')->onQueue('correos');
match ($action) {
    'seed' => $dispatch(),
    'check-foreign' => $recipient->estado === 'pendiente' && $campaign->total_enviados === 0
        ? $dispatch() : throw new RuntimeException('Foreign worker touched campaign.'),
    'enqueue-replay' => $recipient->estado === 'enviado' && $campaign->total_enviados === 1
        ? $dispatch() : throw new RuntimeException('Authorized worker did not complete campaign.'),
    'check-replay' => $recipient->estado === 'enviado' && $campaign->total_enviados === 1 && $campaign->estado === 'completado'
        ? print "F6-A16: three isolated worker processes preserved campaign scope and prevented delivery replay.\n"
        : throw new RuntimeException('Restart duplicated the campaign or lost progress.'),
    default => throw new InvalidArgumentException('Unknown queue fixture step.'),
};
