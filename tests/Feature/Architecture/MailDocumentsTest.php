<?php

namespace Tests\Feature\Architecture;

use App\Architecture\ArchitectureMode;
use App\Auth\RemotePrincipal;
use App\Models\CampanaCorreo;
use App\Models\MailDispatch;
use App\Services\MailArchive;
use App\Services\MailDocumentContent;
use App\Services\MailMime;
use App\Services\ProfileMailbox;
use App\Services\ProfileMailSession;
use App\Services\Remote\HttpClientFactory;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

class MailDocumentsTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;
    private const PDF = "%PDF-1.4\nArchived fixture bytes\n%%EOF";
    private const KEY = 'mail-documents-fixture-only-key-012345678901234567890123456789012345';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('mail_private');
        Mail::fake();
    }

    public static function modes(): array { return [['local'], ['microservices']]; }

    private function principal(string $mode, int $id = 100, string $issuer = 'mail-authority'): array
    {
        if ($mode === 'local') {
            $user = $this->user('mail-'.$id.'@tecnoweb.space');
            $this->grant($user, 'acceso_propiedades');
            $token = $user->createToken('documents')->plainTextToken;
        } else {
            config(['architecture.mode' => 'microservices', 'architecture.business_enabled' => true,
                'identity.key' => self::KEY, 'identity.issuer' => $issuer, 'identity.audience' => 'mail-fixture']);
            app()->forgetInstance(ArchitectureMode::class);
            $dto = ['id' => $id, 'nombre' => 'Mail fixture', 'correo' => 'mail-'.$id.'@tecnoweb.space', 'estado' => true,
                'rolesPermisos' => [
                    ['rolPermisoId' => 1, 'rolId' => 1, 'permisoId' => 1, 'nombreRol' => 'Operador', 'nombrePermiso' => 'acceso_propiedades'],
                    ['rolPermisoId' => 2, 'rolId' => 1, 'permisoId' => 2, 'nombreRol' => 'Operador', 'nombrePermiso' => 'acceso_correo_masivo'],
                ]];
            $claims = ['iss' => $issuer, 'aud' => 'mail-fixture', 'sub' => (string)$id, 'jti' => (string)Str::uuid(), 'ver' => 0,
                'iat' => time() - 1, 'nbf' => time() - 1, 'exp' => time() + 3600, 'email' => $dto['correo']];
            $token = JWT::encode($claims, self::KEY, 'HS256');
            $user = new RemotePrincipal($dto, $claims, $token);
            app()->instance(HttpClientFactory::class, new FakeMicroserviceClients(array_fill(0, 30, new Response(200, [], json_encode($dto)))));
        }
        $request = Request::create('/'); $request->setUserResolver(fn () => $user);
        $request->headers->set('Authorization', 'Bearer '.$token);
        $request->merge(['request_key' => (string)Str::uuid(), 'params' => []]);
        $this->withToken($token);
        return [$user, $token, $request];
    }

    private function archive(Request $request): MailDispatch
    {
        return app(MailArchive::class)->report($request, 'inventario-propiedades', [(object)['nombre' => 'Recipient', 'correo' => 'recipient@example.test']], fn () => [self::PDF, 'inventario.pdf']);
    }

    /** @dataProvider modes */
    public function test_exact_archive_is_private_authorized_and_stable_in_both_modes(string $mode): void
    {
        [$user, $token, $request] = $this->principal($mode);
        $dispatch = $this->archive($request);
        $file = $dispatch->documents()->sole();
        $this->assertSame(hash('sha256', self::PDF), $file->sha256);
        $this->assertSame(self::PDF, Storage::disk('mail_private')->get($file->ruta));
        $this->getJson('/api/correo/envios')->assertOk()->assertJsonPath('data.0.id', $dispatch->id)->assertDontSee($file->ruta);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertOk()->assertJsonPath('adjuntos.0.sha256', $file->sha256);
        $response = $this->get('/api/correo/envios/'.$dispatch->id.'/archivos/'.$file->id);
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::PDF, $response->streamedContent());
        $this->getJson('/api/correo/envios/'.$dispatch->id.'/archivos/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/correo/envios?desde=2026-11-18&hasta=2026-11-17')->assertUnprocessable();
        $this->getJson('/api/correo/envios?hasta=2099-01-01')->assertOk()->assertJsonPath('total', 1);
        $again = app(MailArchive::class)->report($request, 'inventario-propiedades', [(object)['nombre' => 'Recipient', 'correo' => 'recipient@example.test']], function () { $this->fail('The archived document must not be regenerated.'); });
        $this->assertSame($dispatch->id, $again->id);
        $this->assertDatabaseCount('correo_adjuntos', 1);
        config(['architecture.aggregate_dataset' => 'another-dataset']);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertNotFound();
        config(['architecture.aggregate_dataset' => 'default']);
        $this->principal($mode, 101);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertNotFound();
        if ($mode === 'microservices') $this->assertDatabaseCount('users', 0);
    }

    public function test_same_remote_subject_from_another_issuer_has_no_archive_access(): void
    {
        [, , $request] = $this->principal('microservices');
        $dispatch = $this->archive($request);
        $this->principal('microservices', 100, 'another-authority');
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertNotFound();
    }

    public function test_identity_and_dataset_are_case_sensitive_even_on_mysql_default_collation(): void
    {
        [, , $request] = $this->principal('microservices');
        $dispatch = $this->archive($request);
        $this->principal('microservices', 100, 'MAIL-AUTHORITY');
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertNotFound();
        $this->principal('microservices');
        config(['architecture.aggregate_dataset' => 'DEFAULT']);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertNotFound();
    }

    public function test_report_request_replays_without_sending_or_regenerating_after_data_changes(): void
    {
        [$user] = $this->principal('local');
        $property = $this->property();
        $recipient = $this->user('another@example.test');
        $payload = ['reporte' => 'inventario-propiedades', 'usuario_ids' => [$user->id, $recipient->id], 'request_key' => (string)Str::uuid()];
        $response = $this->postJson('/api/reportes/enviar-informe', $payload)->assertOk()->assertJsonPath('enviados', 2);
        $id = $response->json('envio_id'); $document = MailDispatch::findOrFail($id)->documents()->sole();
        $hash = $document->sha256;
        $property->update(['precio_venta' => 999999]);
        $this->postJson('/api/reportes/enviar-informe', $payload)->assertOk()->assertJsonPath('envio_id', $id)->assertJsonPath('enviados', 2);
        Mail::assertSentCount(2);
        $this->assertDatabaseCount('correo_envios', 1); $this->assertDatabaseCount('correo_adjuntos', 1);
        $this->assertSame($hash, hash('sha256', Storage::disk('mail_private')->get($document->ruta)));
        Mail::assertSent(\App\Mail\InformeReporte::class, fn ($mail) => hash('sha256', $mail->pdfBinario) === $hash);
        $payload['usuario_ids'] = [$user->id];
        $this->postJson('/api/reportes/enviar-informe', $payload)->assertConflict();
        $this->getJson('/api/correo/envios?estado=aceptado_smtp')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/correo/envios?estado=incierto')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_uncertain_smtp_is_recorded_and_never_automatically_resent(): void
    {
        [$user] = $this->principal('local'); $this->property();
        Mail::swap(\Mockery::mock(\Illuminate\Mail\MailManager::class));
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic SMTP timeout'));
        $payload = ['reporte' => 'inventario-propiedades', 'usuario_ids' => [$user->id], 'request_key' => (string)Str::uuid()];
        $first = $this->postJson('/api/reportes/enviar-informe', $payload)->assertOk()->assertJsonPath('inciertos', 1);
        $this->postJson('/api/reportes/enviar-informe', $payload)->assertOk()->assertJsonPath('envio_id', $first->json('envio_id'))->assertJsonPath('enviados', 0);
        $this->getJson('/api/correo/envios?estado=incierto')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_failed_private_storage_aborts_before_smtp_and_rolls_back_the_archive(): void
    {
        [$user] = $this->principal('local'); $this->property();
        $disk = \Mockery::mock(); $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('Synthetic disk full'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('mail_private')->andReturn($disk);
        $this->postJson('/api/reportes/enviar-informe', ['reporte' => 'inventario-propiedades', 'usuario_ids' => [$user->id]])->assertServerError();
        Mail::assertNothingSent(); $this->assertDatabaseCount('correo_envios', 0); $this->assertDatabaseCount('correo_adjuntos', 0);
    }

    /** @dataProvider modes */
    public function test_campaign_uses_existing_delivery_states_in_both_modes(string $mode): void
    {
        [$user, , $request] = $this->principal($mode);
        if ($mode === 'local') $this->grant($user, 'acceso_correo_masivo');
        $scope = app(\App\Services\MailCampaigns::class)->scope();
        $campaign = CampanaCorreo::create($scope + ['user_id' => $mode === 'local' ? $user->id : null,
            'actor_issuer' => $mode === 'microservices' ? 'mail-authority' : null, 'actor_subject' => $mode === 'microservices' ? '100' : null,
            'actor_name' => 'Fixture', 'request_key' => (string)Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'asunto' => 'Campaign fixture', 'mensaje' => 'Text only', 'tipo_destinatario' => 'activos', 'total_destinatarios' => 1]);
        $id = DB::table('campana_destinatarios')->insertGetId(['campana_id' => $campaign->id, 'nombre' => 'Recipient', 'correo' => 'recipient@example.test', 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()]);
        $dispatch = app(MailArchive::class)->campaign($campaign);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertOk()->assertJsonPath('estado', 'pendiente')->assertJsonCount(0, 'adjuntos');
        (new \App\Jobs\SendCampaignBatch($campaign->id, $scope, [$id]))->handle(app(\App\Services\MailCampaigns::class));
        $this->getJson('/api/correo/envios?estado=aceptado_smtp')->assertOk()->assertJsonPath('total', 1);
        DB::table('campana_destinatarios')->where('id', $id)->update(['estado' => 'incierto']);
        $this->getJson('/api/correo/envios?estado=incierto')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/correo/envios?estado=aceptado_smtp')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/correo/envios/'.$dispatch->id)->assertOk()->assertJsonPath('destinatarios.0.estado', 'incierto');
    }

    /** @dataProvider modes */
    public function test_imap_download_uses_the_scoped_session_and_cleans_up_without_reading_message(string $mode): void
    {
        [$user, $token, $request] = $this->principal($mode);
        Cache::put(ProfileMailSession::key($request), Crypt::encryptString('fixture-mail-secret'), 1200);
        $directory = storage_path('framework/cache');
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $path = tempnam($directory, 'attachment-'); file_put_contents($path, self::PDF);
        $mail = \Mockery::mock(ProfileMailbox::class);
        $mail->shouldReceive('open')->once()->with($user->correo, 'fixture-mail-secret')->andReturn('connection');
        $mail->shouldReceive('attachment')->once()->with('connection', 'Sent', 7, 42, '1.2')->andReturn(['path' => $path, 'nombre' => 'test.pdf']);
        $mail->shouldReceive('close')->once()->with('connection');
        $mail->shouldNotReceive('read');
        app()->instance(ProfileMailbox::class, $mail);
        $response = $this->get('/api/perfil/correo/mensajes/7/adjuntos/1.2?folder=Sent&uidvalidity=42&inline=1')->assertOk();
        $this->assertSame(self::PDF, $response->streamedContent()); $this->assertFileDoesNotExist($path);
        Cache::forget(ProfileMailSession::key($request));
        $this->getJson('/api/perfil/correo/mensajes/7/adjuntos/1.2?folder=Sent&uidvalidity=42')->assertStatus(409);
    }

    public function test_mime_tree_filenames_and_decoding_preserve_attachment_bytes(): void
    {
        $plain = (object)['type' => 0, 'subtype' => 'PLAIN'];
        $file = (object)['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 3, 'bytes' => 40,
            'parameters' => [(object)['attribute' => 'name*', 'value' => "UTF-8''informe%20%C3%B1.txt"]]];
        $nested = (object)['type' => 1, 'parts' => [$plain, $file]];
        $files = MailMime::attachments((object)['type' => 1, 'parts' => [$plain, $nested]]);
        $this->assertSame('2.2', $files[0]['id']); $this->assertSame('informe ñ.txt', $files[0]['nombre']);
        $this->assertFalse(MailMime::isAttachment($plain)); $this->assertTrue(MailMime::isAttachment($file));
        $segments = fn ($values) => (object)['dparameters' => array_map(fn ($key, $value) => (object)['attribute' => $key, 'value' => $value], array_keys($values), array_values($values))];
        $this->assertSame('informe largo.pdf', MailMime::filename($segments(['filename*0' => 'informe ', 'filename*1' => 'largo.pdf'])));
        $this->assertSame('informe ñ 10%.pdf', MailMime::filename($segments(['filename*0*' => "UTF-8''informe%20%C3%B1%20", 'filename*1' => '10%.pdf'])));
        $this->assertSame('evil.pdf', MailDocumentContent::name("../../bad\\evil.pdf\r\n"));
        foreach ([3 => base64_encode(self::PDF), 4 => quoted_printable_encode(self::PDF)] as $encoding => $encoded) {
            $input = tempnam(storage_path('framework/cache'), 'encoded-'); $output = tempnam(storage_path('framework/cache'), 'decoded-');
            try { file_put_contents($input, $encoded); MailMime::decodeFile($input, $encoding, $output); $this->assertSame(self::PDF, file_get_contents($output)); }
            finally { @unlink($input); @unlink($output); }
        }
    }

    public function test_csv_text_and_xlsx_previews_are_bounded_and_never_evaluate_formulas(): void
    {
        $service = app(MailDocumentContent::class);
        $file = tempnam(storage_path('framework/cache'), 'preview-');
        try {
            file_put_contents($file, "name;value\nfixture;=HYPERLINK(\"https://example.test\")\n");
            $csv = $service->preview($file, 'file.csv');
            $this->assertSame('name', $csv['rows'][0][0]); $this->assertStringStartsWith('=HYPERLINK', $csv['rows'][1][1]);
            file_put_contents($file, 'A plain text fixture');
            $this->assertSame('A plain text fixture', $service->preview($file, 'file.txt')['text']);
            $zip = new \ZipArchive; $zip->open($file, \ZipArchive::OVERWRITE);
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet" r:id="r1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="r1" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Fixture</t></si></sst>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><f>2+2</f><v>4</v></c></row><row r="101"><c r="A101"><v>9</v></c></row></sheetData></worksheet>');
            $zip->close();
            $data = $service->preview($file, 'file.xlsx');
            $this->assertSame([['Fixture', '4']], $data['rows']); $this->assertTrue($data['has_more']);
            $next = $service->preview($file, 'file.xlsx', 2); $this->assertSame('9', $next['rows'][0][0]);
            $this->assertNull($service->capabilities('archive.zip', 'application/zip', 12)['preview_type']);
        } finally { @unlink($file); }
    }

    public function test_active_content_cannot_be_opened_inline_even_with_a_pdf_filename(): void
    {
        $file = tempnam(storage_path('framework/cache'), 'active-');
        file_put_contents($file, '<html><script>alert(1)</script></html>');
        try {
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            app(MailDocumentContent::class)->response($file, 'invoice.pdf', true);
        } finally { @unlink($file); }
    }

    public function test_document_integrity_permission_revocation_and_preview_limit_are_enforced(): void
    {
        [$user, , $request] = $this->principal('local');
        $dispatch = $this->archive($request); $file = $dispatch->documents()->sole();
        $url = '/api/correo/envios/'.$dispatch->id.'/archivos/'.$file->id;
        config(['mail_documents.preview_bytes' => 4]);
        $this->getJson($url.'?inline=1')->assertUnprocessable();
        $this->get($url)->assertOk();
        DB::table('permisos')->where('nombre', 'acceso_propiedades')->update(['estado' => false]);
        $this->getJson($url)->assertNotFound();
        DB::table('permisos')->where('nombre', 'acceso_propiedades')->update(['estado' => true]);
        Storage::disk('mail_private')->put($file->ruta, 'Altered document');
        $this->getJson($url)->assertConflict();
        Storage::disk('mail_private')->delete($file->ruta);
        $this->getJson($url)->assertNotFound();
    }

    public function test_changed_imap_uidvalidity_does_not_discard_valid_mail_credentials(): void
    {
        [$user, , $request] = $this->principal('local');
        $key = ProfileMailSession::key($request);
        Cache::put($key, Crypt::encryptString('fixture-mail-secret'), 1200);
        $mail = \Mockery::mock(ProfileMailbox::class);
        $mail->shouldReceive('open')->once()->andReturn('connection');
        $mail->shouldReceive('attachment')->once()->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'La carpeta cambió.'));
        $mail->shouldReceive('close')->once(); app()->instance(ProfileMailbox::class, $mail);
        $this->getJson('/api/perfil/correo/mensajes/7/adjuntos/1?folder=Sent&uidvalidity=42')->assertConflict();
        $this->assertTrue(Cache::has($key));
        $this->getJson('/api/perfil/correo/mensajes/7/adjuntos/1?folder=../other&uidvalidity=42')->assertUnprocessable();
    }

    public function test_unsafe_or_oversized_spreadsheet_xml_is_rejected(): void
    {
        $file = tempnam(storage_path('framework/cache'), 'unsafe-');
        try {
            $zip = new \ZipArchive; $zip->open($file, \ZipArchive::OVERWRITE);
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///unavailable">]><Relationships>&x;</Relationships>'); $zip->close();
            try { app(MailDocumentContent::class)->preview($file, 'file.xlsx'); $this->fail('Unsafe XML was accepted.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
            config(['mail_documents.zip_bytes' => 8]);
            try { app(MailDocumentContent::class)->preview($file, 'file.xlsx'); $this->fail('Oversized ZIP was accepted.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        } finally { @unlink($file); }
    }

    /** @dataProvider modes */
    public function test_backup_restores_exact_documents_and_preserves_newer_delivery_states(string $mode): void
    {
        [, , $request] = $this->principal($mode);
        $dispatch = $this->archive($request); $document = $dispatch->documents()->sole();
        $backup = app(\App\Services\MailArchiveBackup::class);
        $path = $backup->create();
        $this->assertCount(1, $backup->verify($path)['documents']);
        app(MailArchive::class)->transition($dispatch->id, 'recipient@example.test', 'pendiente', 'aceptado_smtp');
        $backup->restore($path);
        $this->assertSame('aceptado_smtp', MailDispatch::findOrFail($dispatch->id)->destinatarios[0]['estado']);
        DB::table('correo_envio_adjuntos')->delete(); DB::table('correo_adjuntos')->delete(); DB::table('correo_envios')->delete();
        Storage::disk('mail_private')->delete($document->ruta);
        $backup->restore($path);
        $this->assertSame(self::PDF, Storage::disk('mail_private')->get($document->ruta));
        $this->assertDatabaseCount('correo_envios', 1);
        config(['architecture.aggregate_dataset' => 'different']);
        $this->expectException(\RuntimeException::class); $backup->verify($path);
    }
}
