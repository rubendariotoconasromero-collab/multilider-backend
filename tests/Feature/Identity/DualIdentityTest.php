<?php

namespace Tests\Feature\Identity;

use App\Architecture\ArchitectureMode;
use App\Auth\RemotePrincipal;
use App\Contracts\Architecture\IdentityGateway;
use App\Contracts\Architecture\ServiceIdentityProvider;
use App\Services\Local\LocalIdentityGateway;
use App\Services\ProfileMailbox;
use App\Services\ProfileMailSession;
use App\Services\Remote\ExternalIdentityMap;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\IntegrationException;
use App\Services\Remote\RemoteIdentityGateway;
use Firebase\JWT\JWT;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeMicroserviceClients;
use Tests\TestCase;

class DualIdentityTest extends TestCase
{
    use BusinessFixtures, RefreshDatabase;

    private const KEY = 'identity-fixture-key-for-tests-only-012345678901234567890123456789';

    private function remote(array $responses = []): FakeMicroserviceClients
    {
        config(['architecture.mode' => 'microservices', 'identity.key' => self::KEY, 'identity.issuer' => 'fixture-authority', 'identity.audience' => 'fixture-audience']);
        app()->forgetInstance(ArchitectureMode::class);
        $fake = new FakeMicroserviceClients($responses);
        app()->instance(HttpClientFactory::class, $fake);

        return $fake;
    }

    private function claims(array $changes = []): array
    {
        return array_replace(['iss' => 'fixture-authority', 'aud' => 'fixture-audience', 'sub' => '100', 'jti' => (string) Str::uuid(),
            'ver' => 0, 'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 3600, 'email' => 'remote@example.test'], $changes);
    }

    private function token(array $changes = []): string
    {
        return JWT::encode($this->claims($changes), self::KEY, 'HS256');
    }

    private function dto(int $id = 100, string $email = 'remote@example.test'): array
    {
        return ['id' => $id, 'nombre' => 'Usuario remoto ficticio', 'correo' => $email, 'estado' => true, 'rolesPermisos' => [
            ['rolPermisoId' => 21, 'rolId' => 3, 'permisoId' => 6, 'nombreRol' => 'Operador', 'nombrePermiso' => 'acceso_propiedades'],
            ['rolPermisoId' => 22, 'rolId' => 3, 'permisoId' => 7, 'nombreRol' => 'Operador', 'nombrePermiso' => 'acceso_usuarios'],
        ]];
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode($body));
    }

    public function test_container_selects_identity_without_mixing_domains(): void
    {
        $this->assertInstanceOf(LocalIdentityGateway::class, app(IdentityGateway::class));
        $this->remote();
        $this->assertInstanceOf(RemoteIdentityGateway::class, app(IdentityGateway::class));
    }

    public function test_real_jwt_login_and_me_never_create_local_users_or_tokens(): void
    {
        $token = $this->token();
        $fake = $this->remote([$this->ok(['accessToken' => $token, 'user' => $this->dto()]), $this->ok($this->dto()), $this->ok($this->dto())]);
        $this->postJson('/api/login', ['correo' => 'remote@example.test', 'password' => 'fixture-not-saved'])->assertOk()
            ->assertJsonPath('access_token', $token)->assertJsonPath('user.roles_permisos.0.rol_permiso.permiso.nombre', 'acceso_propiedades');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('id', 100);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertCount(3, $fake->history);
    }

    public function test_remote_login_never_falls_back_to_local_credentials(): void
    {
        $user = $this->user();
        $fake = $this->remote([new Response(401)]);
        $this->postJson('/api/login', ['correo' => $user->correo, 'password' => 'Test-only-Password-42!'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertCount(1, $fake->history);
    }

    /** @dataProvider invalidClaims */
    public function test_jwt_validation_rejects_bad_claims_before_any_http(string $invalid): void
    {
        $claims = $this->claims();
        switch ($invalid) {
            case 'issuer': $claims['iss'] = 'foreign';
                break;
            case 'audience': $claims['aud'] = 'foreign';
                break;
            case 'expired': $claims['exp'] = time() - 60;
                break;
            case 'future': $claims['nbf'] = time() + 120;
                break;
            case 'issued': $claims['iat'] = time() + 120;
                break;
            case 'subject': $claims['sub'] = '';
                break;
            case 'jti': $claims['jti'] = 'not-a-uuid';
                break;
            case 'missing-exp': unset($claims['exp']);
                break;
            case 'missing-version': unset($claims['ver']);
                break;
        }
        $token = JWT::encode($claims, $invalid === 'key' ? str_repeat('X', 64) : self::KEY, $invalid === 'algorithm' ? 'HS512' : 'HS256');
        $fake = $this->remote();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame([], $fake->services);
    }

    public static function invalidClaims(): array
    {
        return array_map(fn ($x) => [$x], ['issuer', 'audience', 'expired', 'future', 'issued', 'subject', 'jti', 'missing-exp', 'missing-version', 'key', 'algorithm']);
    }

    public function test_sanctum_cannot_enter_remote_and_remote_cannot_enter_local(): void
    {
        $user = $this->user();
        $local = $user->createToken('pre-existing')->plainTextToken;
        $this->withToken($this->token())->getJson('/api/me')->assertUnauthorized();
        $fake = $this->remote();
        $this->withToken($local)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame([], $fake->services);
    }

    public function test_remote_profile_follows_subject_when_email_changes(): void
    {
        $token = $this->token();
        $this->remote([$this->ok($this->dto()), $this->ok(['data' => $this->dto(100, 'new@example.test')]), $this->ok($this->dto(100, 'new@example.test'))]);
        $this->withToken($token)->putJson('/api/perfil', ['nombre' => 'Nuevo', 'correo' => 'new@example.test'])->assertOk()->assertJsonPath('data.id', 100)->assertJsonPath('data.correo', 'new@example.test');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('correo', 'new@example.test');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_profile_rejects_other_ids_roles_and_password_fields(): void
    {
        $fake = $this->remote([$this->ok($this->dto())]);
        $this->withToken($this->token())->putJson('/api/perfil', ['nombre' => 'Fake', 'correo' => 'remote@example.test', 'id' => 1, 'estado' => true, 'roles' => [1], 'password' => 'fake'])
            ->assertUnprocessable()->assertJsonValidationErrors(['id', 'estado', 'roles', 'password']);
        $this->assertCount(1, $fake->history);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_logout_is_persisted_and_idempotent_without_reusing_token(): void
    {
        $token = $this->token();
        $fake = $this->remote([$this->ok(['message' => 'ok'])]);
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertCount(1, $fake->history);
        $this->assertDatabaseCount('identity_revocations', 1);
        $this->assertNull(DB::table('identity_revocations')->first()->credential);
    }

    public function test_logout_network_failure_is_pending_durable_encrypted_and_retried(): void
    {
        $token = $this->token();
        $fake = $this->remote([new ConnectException('SECRET fixture', new HttpRequest('POST', 'https://fixture.example.test')), $this->ok(['message' => 'ok'])]);
        $this->withToken($token)->postJson('/api/logout')->assertStatus(503)->assertJsonPath('code', 'REVOCATION_PENDING');
        $row = DB::table('identity_revocations')->first();
        $this->assertNotSame($token, $row->credential);
        $this->assertSame($token, Crypt::decryptString($row->credential));
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->artisan('identity:retry-revocations')->assertExitCode(0);
        $this->assertNotNull(DB::table('identity_revocations')->first()->confirmed_at);
        $this->assertNull(DB::table('identity_revocations')->first()->credential);
    }

    public function test_unavailable_authority_never_uses_local_permissions(): void
    {
        $fake = $this->remote([new Response(500, [], 'SECRET SQL')]);
        $this->withToken($this->token())->getJson('/api/me')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $this->assertCount(1, $fake->history);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_remote_login_invalid_issued_token_is_service_error(): void
    {
        $this->remote([$this->ok(['accessToken' => 'not-a-jwt'])]);
        $this->postJson('/api/login', ['correo' => 'remote@example.test', 'password' => 'fixture'])->assertStatus(503)->assertJsonPath('code', 'INVALID_SERVICE_RESPONSE');
    }

    public function test_tokens_are_not_shared_between_requests_in_same_process(): void
    {
        $first = $this->token();
        $second = $this->token(['sub' => '200', 'email' => 'second@example.test']);
        $fake = $this->remote([$this->ok($this->dto()), $this->ok($this->dto(200, 'second@example.test'))]);
        $this->withToken($first)->getJson('/api/me')->assertOk()->assertJsonPath('id', 100);
        $this->withToken($second)->getJson('/api/me')->assertOk()->assertJsonPath('id', 200);
        $this->assertSame('Bearer '.$first, $fake->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer '.$second, $fake->history[1]['request']->getHeaderLine('Authorization'));
        $this->withHeaders(['Authorization' => ''])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_local_password_and_state_revoke_only_affected_users_without_http(): void
    {
        $first = $this->user();
        $second = $this->user('second@example.test');
        $firstToken = $first->createToken('old')->plainTextToken;
        $otherToken = $second->createToken('old')->plainTextToken;
        $fake = new FakeMicroserviceClients([]);
        app()->instance(HttpClientFactory::class, $fake);
        $this->withToken($firstToken)->putJson('/api/perfil/cambiar-password', ['password_actual' => 'Test-only-Password-42!', 'password_nuevo' => 'New-test-Password-43!', 'password_nuevo_confirmation' => 'New-test-Password-43!'])
            ->assertOk()->assertJsonPath('reauth_required', true);
        $this->withToken($firstToken)->getJson('/api/me')->assertUnauthorized();
        $this->withToken($otherToken)->getJson('/api/me')->assertOk()->assertJsonPath('id', $second->id);
        $second->update(['estado' => false]);
        $this->withToken($otherToken)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame([], $fake->services);
    }

    public function test_existing_local_token_remains_valid_and_no_header_does_not_reuse_it(): void
    {
        $user = $this->user();
        $token = $user->createToken('before-f3')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_no_automatic_mapping_by_email_or_same_numeric_id(): void
    {
        $local = $this->user('remote@example.test');
        $principal = new RemotePrincipal($this->dto($local->id), $this->claims(['sub' => (string) $local->id]), $this->token());
        try {
            app(ExternalIdentityMap::class)->localUserId($principal);
            $this->fail('Expected explicit mapping.');
        } catch (IntegrationException $e) {
            $this->assertSame('IDENTITY_MAPPING_REQUIRED', $e->errorCode);
        }
        $this->assertDatabaseCount('external_identities', 0);
        DB::table('external_identities')->insert(['issuer' => 'fixture-authority', 'subject' => (string) $local->id, 'local_user_id' => $local->id, 'review_status' => 'approved', 'provenance' => 'test-only']);
        $this->assertSame($local->id, app(ExternalIdentityMap::class)->localUserId($principal));
    }

    public function test_mailbox_credential_is_scoped_to_identity_source_subject_and_session(): void
    {
        $request = Request::create('/');
        $request->headers->set('Authorization', 'Bearer fake');
        $local = $this->user('remote@example.test');
        $request->setUserResolver(fn () => $local);
        $localKey = ProfileMailSession::key($request);
        $remote = new RemotePrincipal(['id' => $local->id, 'correo' => $local->correo], $this->claims(['sub' => (string) $local->id]), $this->token());
        $request->setUserResolver(fn () => $remote);
        $remoteKey = ProfileMailSession::key($request);
        $this->assertNotSame($localKey, $remoteKey);
        Cache::put($remoteKey, 'encrypted-fixture', 1200);
        ProfileMailSession::forget($request);
        $this->assertFalse(Cache::has($remoteKey));
    }

    public function test_no_technical_admin_identity_issued_and_retry_command_noops_locally(): void
    {
        $fake = new FakeMicroserviceClients([]);
        app()->instance(HttpClientFactory::class, $fake);
        $this->artisan('identity:retry-revocations')->assertExitCode(0);
        $this->assertSame([], $fake->services);
        $this->expectException(IntegrationException::class);
        app(ServiceIdentityProvider::class)->forOperation('comercial', 'anything');
    }

    public function test_user_management_can_read_assignment_catalog_without_role_creation_privilege(): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_usuarios');
        $this->withToken($user->createToken('manager')->plainTextToken)->getJson('/api/asignar-permisos')->assertOk();
        $this->postJson('/api/roles', ['nombre' => 'Not allowed'])->assertForbidden();
        $this->postJson('/api/usuarios/'.$user->id.'/asignaciones/sync', [])->assertUnprocessable();
        $this->assertDatabaseCount('rol_permiso_usuario', 1);
    }

    /** @dataProvider inactiveAssignments */
    public function test_inactive_local_role_or_permission_is_not_used(string $table): void
    {
        $user = $this->user();
        $this->grant($user, 'acceso_pagos');
        DB::table($table)->update(['estado' => false]);
        $this->withToken($user->createToken('valid')->plainTextToken)->getJson('/api/me')->assertOk()->assertJsonPath('roles_permisos', []);
        $this->getJson('/api/pagos')->assertForbidden();
    }

    public static function inactiveAssignments(): array
    {
        return [['roles'], ['permisos']];
    }

    /** @dataProvider apiModes */
    public function test_api_authentication_returns_json_without_an_accept_header(string $mode): void
    {
        if ($mode === 'microservices') {
            $this->remote();
        }
        $this->get('/api/me')->assertUnauthorized()->assertJsonPath('message', 'Autenticación requerida.');
    }

    public static function apiModes(): array
    {
        return [['local'], ['microservices']];
    }

    /** @dataProvider apiModes */
    public function test_profile_mail_uses_a_separate_encrypted_credential_in_both_modes(string $mode): void
    {
        $email = 'mail-fixture@tecnoweb.space';
        if ($mode === 'microservices') {
            $dto = $this->dto(100, $email);
            $this->remote([...array_fill(0, 4, $this->ok($dto)), $this->ok([])]);
            // The mutable email claim may predate a profile update; sub remains stable.
            $token = $this->token(['email' => 'previous-address@tecnoweb.space']);
        } else {
            $token = $this->user($email)->createToken('mail-test')->plainTextToken;
        }
        $mail = \Mockery::mock(ProfileMailbox::class);
        $mail->shouldReceive('open')->times(3)->with($email, 'separate-mail-fixture-secret')->andReturn('fixture-connection');
        $mail->shouldReceive('close')->times(3)->with('fixture-connection');
        $mail->shouldReceive('messages')->once()->with('fixture-connection', 'INBOX', 1)->andReturn(['data' => [], 'total' => 0]);
        $mail->shouldReceive('send')->once()->with('fixture-connection', $email, \Mockery::type('string'), 'separate-mail-fixture-secret', \Mockery::type('array'))->andReturn(['message' => 'Sent fixture']);
        app()->instance(ProfileMailbox::class, $mail);
        $this->withToken($token)->postJson('/api/perfil/correo/conectar', ['password' => 'separate-mail-fixture-secret'])->assertOk()->assertJsonPath('connected', true);
        $key = ProfileMailSession::key(request());
        $this->assertNotSame('separate-mail-fixture-secret', Cache::get($key));
        $this->assertSame('separate-mail-fixture-secret', Crypt::decryptString(Cache::get($key)));
        $this->getJson('/api/perfil/correo/estado')->assertOk()->assertJsonPath('connected', true);
        $this->getJson('/api/perfil/correo/mensajes?folder=INBOX')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/perfil/correo/enviar', ['to' => 'receiver@example.test', 'subject' => 'Fixture', 'body' => 'Test only'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->assertFalse(Cache::has($key));
    }
}
