param([switch]$BrowserTests)
$ErrorActionPreference='Stop'
if ($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:DB_DATABASE -ne 'multilider_tests_isolated' -or $env:DB_PORT -eq '3306') { throw 'Ejecutar exclusivamente desde test-isolated.ps1 -Integration.' }
$backend=Split-Path $PSScriptRoot -Parent
$workspace=Split-Path $backend -Parent
$runtime=Join-Path $backend 'storage\framework\testing'
$security=Join-Path $workspace 'multilider-ms-seguridad'
$properties=Join-Path $workspace 'multilider-ms-propiedades'
$frontend=Join-Path $workspace 'frontend'
$commercial=Join-Path $workspace 'multilider-ms-comercial'
$commercialDatabase='multilider_comercial_f4_test_'+[Guid]::NewGuid().ToString('N')
$commercialConnection="Server=localhost;Database=$commercialDatabase;Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5"
$propertyRelease=Join-Path $runtime ('release-propiedades-f4-'+[Guid]::NewGuid().ToString('N'))

$processes=[Collections.Generic.List[Diagnostics.Process]]::new()
$previous=@{}
function Set-FixtureEnv([string]$Name,[string]$Value) {
    if (!$previous.ContainsKey($Name)) { $previous[$Name]=[Environment]::GetEnvironmentVariable($Name,'Process') }
    [Environment]::SetEnvironmentVariable($Name,$Value,'Process')
}
function Get-FixturePort {
    $socket=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$socket.Start();$value=$socket.LocalEndpoint.Port;$socket.Stop();return $value
}
function Start-FixtureProcess([string]$Name,[string]$Executable,[string[]]$Arguments,[string]$Directory) {
    $p=Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $Directory -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime "$Name.out.log") -RedirectStandardError (Join-Path $runtime "$Name.err.log")
    $processes.Add($p);return $p
}
function Wait-Fixture([string]$Url,[int]$Status) {
    for($i=0;$i -lt 40;$i++) {
        try {
            $accept=if($Url -match '/api/'){'application/json'}else{'text/html'}
            $r=Invoke-WebRequest $Url -Headers @{Accept=$accept} -SkipHttpErrorCheck -TimeoutSec 2
            if([int]$r.StatusCode -eq $Status){return}
        } catch { }
        Start-Sleep -Milliseconds 200
    }
    throw "Servidor de prueba no respondió: $Url; revisar logs privados de testing."
}
function Stop-Fixture([Diagnostics.Process]$Process) {
    if(!$Process.HasExited) { & taskkill.exe /PID $Process.Id /T /F 2>$null | Out-Null;$Process.WaitForExit(5000) | Out-Null }
}
$identityPort=Get-FixturePort;$propertyPort=Get-FixturePort;$localPort=Get-FixturePort;$remotePort=Get-FixturePort
$localPort2=Get-FixturePort;$commercialPort=Get-FixturePort;$commercialPort2=Get-FixturePort;$propertyPort2=Get-FixturePort;$propertyTlsPort=Get-FixturePort;$propertyTlsPort2=Get-FixturePort
$uiLocalPort=Get-FixturePort;$uiRemotePort=Get-FixturePort;$uiLegacyPort=Get-FixturePort
$database='multilider_properties_f3_test_'+[Guid]::NewGuid().ToString('N')
$key=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$identityConnection="Server=127.0.0.1;Port=$env:DB_PORT;Database=$env:MULTILIDER_IDENTITY_DATABASE;User=$env:MULTILIDER_IDENTITY_USER;Password=$env:MULTILIDER_IDENTITY_PASSWORD"
$propertyConnection="Server=localhost;Database=$database;Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5"
try {
    & (Join-Path $PSScriptRoot 'tls-fixture-f4.ps1') -Directory $runtime
    $tls=Get-Content (Join-Path $runtime 'tls-f4.json') -Raw | ConvertFrom-Json
    Set-FixtureEnv 'Reservations__ServerCertificatePath' $tls.server_path
    Set-FixtureEnv 'Reservations__ServerCertificatePassword' $tls.password
    Set-FixtureEnv 'Reservations__ComercialCertificateSha256' $tls.client_pin
    Set-FixtureEnv 'Reservations__HttpsPort' "$propertyTlsPort"
    Set-FixtureEnv 'Catalog__StorageRoot' (Join-Path $runtime 'catalog-media-f4')
    Set-FixtureEnv 'Availability__ClientCertificatePath' $tls.client_path
    Set-FixtureEnv 'Availability__ClientCertificatePassword' $tls.password
    Set-FixtureEnv 'Availability__ServerCertificateSha256' $tls.server_pin
    Set-FixtureEnv 'Availability__BaseUrl' "https://127.0.0.1:$propertyTlsPort"
    Set-FixtureEnv 'PHASE4_API_LOCAL2' "http://127.0.0.1:$localPort2"
    Set-FixtureEnv 'PHASE4_COMMERCIAL' "http://127.0.0.1:$commercialPort"
    Set-FixtureEnv 'PHASE4_COMMERCIAL2' "http://127.0.0.1:$commercialPort2"
    Set-FixtureEnv 'PHASE4_PROPERTIES2' "http://127.0.0.1:$propertyPort2"
    Set-FixtureEnv 'PHASE4_PROPERTIES_TLS' "https://127.0.0.1:$propertyTlsPort"
    Set-FixtureEnv 'PHASE4_TLS_METADATA' (Join-Path $runtime 'tls-f4.json')
    Set-FixtureEnv 'MULTILIDER_COMERCIAL_TEST_CONNECTION' $commercialConnection
    Set-FixtureEnv 'DOTNET_ENVIRONMENT' 'Testing'
    Set-FixtureEnv 'ASPNETCORE_ENVIRONMENT' 'Testing'
    Set-FixtureEnv 'Jwt__Key' $key;Set-FixtureEnv 'Jwt__Issuer' 'phase3-isolated';Set-FixtureEnv 'Jwt__Audience' 'phase3-isolated'
    Set-FixtureEnv 'Logging__LogLevel__Default' 'Error'
    Set-FixtureEnv 'Services__Seguridad' "http://127.0.0.1:$identityPort"
    Set-FixtureEnv 'Identity__LoginPermitLimit' '200'
    Set-FixtureEnv 'Cors__AllowedOrigin' "http://127.0.0.1:$uiLegacyPort"
    Set-FixtureEnv 'ConnectionStrings__MySQL' $identityConnection
    Set-FixtureEnv 'ConnectionStrings__SqlServer' $propertyConnection
    Set-FixtureEnv 'MULTILIDER_PROPERTIES_TEST_CONNECTION' $propertyConnection
    Set-FixtureEnv 'SEGURIDAD_SERVICE_URL' "http://127.0.0.1:$identityPort"
    Set-FixtureEnv 'PROPIEDADES_SERVICE_URL' "http://127.0.0.1:$propertyPort"
    Set-FixtureEnv 'IDENTITY_ISSUER' 'phase3-isolated';Set-FixtureEnv 'IDENTITY_AUDIENCE' 'phase3-isolated'
    Set-FixtureEnv 'LARAVEL_STORAGE_PATH' (Join-Path $runtime 'app')
    Set-FixtureEnv 'APP_CONFIG_CACHE' 'storage/framework/testing/live-no-config.php'
    Set-FixtureEnv 'CORS_ALLOWED_ORIGINS' "http://127.0.0.1:$uiLocalPort,http://127.0.0.1:$uiRemotePort,http://127.0.0.1:$uiLegacyPort"
    Set-FixtureEnv 'PHASE3_API_LOCAL' "http://127.0.0.1:$localPort"
    Set-FixtureEnv 'PHASE3_API_REMOTE' "http://127.0.0.1:$remotePort"
    Set-FixtureEnv 'PHASE3_SECURITY' "http://127.0.0.1:$identityPort"
    Set-FixtureEnv 'PHASE3_PROPERTIES' "http://127.0.0.1:$propertyPort"
    Set-FixtureEnv 'PHASE3_BASE_LOCAL' "http://127.0.0.1:$uiLocalPort"
    Set-FixtureEnv 'PHASE3_BASE_REMOTE' "http://127.0.0.1:$uiRemotePort"
    Set-FixtureEnv 'PHASE3_BASE_LEGACY' "http://127.0.0.1:$uiLegacyPort"
    & dotnet run --project (Join-Path $security 'tests\FixtureSetup\FixtureSetup.csproj')
    if($LASTEXITCODE -ne 0){throw 'Validación del esquema nuevo y recuperación falló.'}
    & php (Join-Path $backend 'tests\Support\IntegrationFixture.php') prepare
    if($LASTEXITCODE -ne 0){throw 'Preparación de fixtures falló.'}
    $runtime8=Join-Path $security '.artifacts\runtime8\dotnet.exe'
    if(!(Test-Path $runtime8)){throw 'Preparar el runtime ASP.NET 8 privado según la guía de F3.'}
    $securityDll=Join-Path $security 'bin\Debug\net8.0\MSMultilider.Seguridad.dll'
    & $runtime8 $securityDll --migrate-identity
    if($LASTEXITCODE -ne 0){throw 'Migración MySQL de Seguridad falló.'}
    & php (Join-Path $backend 'tests\Support\IntegrationFixture.php') verify-migration
    if($LASTEXITCODE -ne 0){throw 'La migración alteró identidades previas.'}
    & dotnet run --project (Join-Path $properties 'tests\FixtureSetup\FixtureSetup.csproj') -- prepare
    if($LASTEXITCODE -ne 0){throw 'Preparación SQL Server de Propiedades falló.'}
    $identity=Start-FixtureProcess 'security-live' $runtime8 @($securityDll,'--urls',"http://127.0.0.1:$identityPort") $security
    $props=Start-FixtureProcess 'properties-live' (Get-Command dotnet).Source @((Join-Path $properties 'bin\Debug\net10.0\MSMultilider.Propiedades.dll'),'--urls',"http://127.0.0.1:$propertyPort") $properties
    Wait-Fixture "http://127.0.0.1:$identityPort/api/auth/me" 401
    Wait-Fixture "http://127.0.0.1:$propertyPort/api/propiedades" 401
    Set-FixtureEnv 'ConnectionStrings__SqlServer' $commercialConnection
    & dotnet run --project (Join-Path $commercial 'tests\FixtureSetup\FixtureSetup.csproj')
    if($LASTEXITCODE -ne 0){throw 'Preparación Comercial F4 falló.'}
    $com=Start-FixtureProcess 'commercial-f4' (Get-Command dotnet).Source @((Join-Path $commercial 'bin\Debug\net10.0\MSMultilider.Comercial.dll'),'--urls',"http://127.0.0.1:$commercialPort") $commercial
    Set-FixtureEnv 'ConnectionStrings__SqlServer' $propertyConnection
    Set-FixtureEnv 'Reservations__HttpsPort' "$propertyTlsPort2"
    New-Item -ItemType Directory -Path $propertyRelease -Force | Out-Null
    $propertyBuild=Join-Path $properties 'bin\Debug\net10.0'
    Get-ChildItem -LiteralPath $propertyBuild -File | Where-Object { $_.Extension -eq '.dll' -or $_.Name -like '*.deps.json' -or $_.Name -like '*.runtimeconfig.json' } | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $propertyRelease }
    if(Test-Path (Join-Path $propertyBuild 'runtimes')){Copy-Item -LiteralPath (Join-Path $propertyBuild 'runtimes') -Destination $propertyRelease -Recurse}
    $props2=Start-FixtureProcess 'properties-f4-second' (Get-Command dotnet).Source @((Join-Path $propertyRelease 'MSMultilider.Propiedades.dll'),'--urls',"http://127.0.0.1:$propertyPort2") $propertyRelease
    Wait-Fixture "http://127.0.0.1:$propertyPort2/api/propiedades" 401
    Set-FixtureEnv 'ConnectionStrings__SqlServer' $commercialConnection
    Set-FixtureEnv 'Availability__BaseUrl' "https://127.0.0.1:$propertyTlsPort2"
    $com2=Start-FixtureProcess 'commercial-f4-second' (Get-Command dotnet).Source @((Join-Path $commercial 'bin\Debug\net10.0\MSMultilider.Comercial.dll'),'--urls',"http://127.0.0.1:$commercialPort2") $commercial
    Wait-Fixture "http://127.0.0.1:$commercialPort/api/ventas" 401
    Wait-Fixture "http://127.0.0.1:$commercialPort2/api/ventas" 401

    Set-FixtureEnv 'APP_ARCHITECTURE' 'local';Set-FixtureEnv 'IDENTITY_SIGNING_KEY' ''
    Set-FixtureEnv 'APP_CONFIG_CACHE' 'storage/framework/testing/live-local-config.php'
    & php (Join-Path $backend 'tests/Support/CacheFixture.php')
    if($LASTEXITCODE -ne 0){throw 'Caché local aislada falló.'}
    $local=Start-FixtureProcess 'laravel-local-live' (Get-Command php).Source @('-S',"127.0.0.1:$localPort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php')) (Join-Path $backend 'public')
    $local2=Start-FixtureProcess 'laravel-local-second' (Get-Command php).Source @('-S',"127.0.0.1:$localPort2",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php')) (Join-Path $backend 'public')
    Set-FixtureEnv 'APP_ARCHITECTURE' 'microservices';Set-FixtureEnv 'IDENTITY_SIGNING_KEY' $key
    Set-FixtureEnv 'APP_CONFIG_CACHE' 'storage/framework/testing/live-remote-config.php'
    & php (Join-Path $backend 'tests/Support/CacheFixture.php')
    if($LASTEXITCODE -ne 0){throw 'Caché remota aislada falló.'}
    $remote=Start-FixtureProcess 'laravel-remote-live' (Get-Command php).Source @('-S',"127.0.0.1:$remotePort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php')) (Join-Path $backend 'public')
    Wait-Fixture "http://127.0.0.1:$localPort/api/me" 401
    Wait-Fixture "http://127.0.0.1:$remotePort/api/me" 401
    & php (Join-Path $backend 'tests\Support\IntegrationSmoke.php') online
    if($LASTEXITCODE -ne 0){throw 'Prueba integrada online falló.'}
    & php (Join-Path $backend 'tests\Support\CatalogIntegrationSmoke.php')
    if($LASTEXITCODE -ne 0){throw 'Pruebas reales F4 fallaron.'}
    & dotnet (Join-Path $commercial 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll') verify
    if($LASTEXITCODE -ne 0){throw 'Invariantes de Comercial fallaron.'}
    if($BrowserTests) {
        Set-FixtureEnv 'PLAYWRIGHT_BROWSERS_PATH' (Join-Path $frontend '.phase3\browsers')
        $vite=Join-Path $frontend 'node_modules\vite\bin\vite.js'
        Set-FixtureEnv 'VITE_API_URL' "http://127.0.0.1:$localPort"
        Set-FixtureEnv 'MULTILIDER_VITE_CACHE' (Join-Path $frontend '.phase3\vite-local')
        $uiLocal=Start-FixtureProcess 'ui-local-live' (Get-Command node).Source @($vite,'--host','127.0.0.1','--port',"$uiLocalPort",'--strictPort') $frontend
        Set-FixtureEnv 'VITE_API_URL' "http://127.0.0.1:$remotePort"
        Set-FixtureEnv 'MULTILIDER_VITE_CACHE' (Join-Path $frontend '.phase3\vite-remote')
        $uiRemote=Start-FixtureProcess 'ui-remote-live' (Get-Command node).Source @($vite,'--host','127.0.0.1','--port',"$uiRemotePort",'--strictPort') $frontend
        Set-FixtureEnv 'VITE_SECURITY_URL' "http://127.0.0.1:$identityPort"
        Set-FixtureEnv 'VITE_PROPIEDADES_URL' "http://127.0.0.1:$propertyPort"
        Set-FixtureEnv 'MULTILIDER_VITE_CACHE' (Join-Path $frontend '.phase3\vite-legacy')
        $uiLegacy=Start-FixtureProcess 'ui-legacy-live' (Get-Command node).Source @($vite,'--host','127.0.0.1','--port',"$uiLegacyPort",'--strictPort') (Join-Path $frontend '.worktrees\fase3-microservices')
        foreach($url in @($env:PHASE3_BASE_LOCAL,$env:PHASE3_BASE_REMOTE,$env:PHASE3_BASE_LEGACY)){Wait-Fixture $url 200}
        Push-Location $frontend
        try { & node (Join-Path $frontend 'node_modules\@playwright\test\cli.js') test --config (Join-Path $frontend 'playwright.phase3.config.js');if($LASTEXITCODE -ne 0){throw 'Pruebas de navegador fallaron.'} } finally {Pop-Location}
    }
    Stop-Fixture $identity
    & php (Join-Path $backend 'tests\Support\IntegrationSmoke.php') outage
    if($LASTEXITCODE -ne 0){throw 'Prueba de caída falló.'}
    $identity=Start-FixtureProcess 'security-restarted' $runtime8 @($securityDll,'--urls',"http://127.0.0.1:$identityPort") $security
    Wait-Fixture "http://127.0.0.1:$identityPort/api/auth/me" 401
    & php (Join-Path $backend 'artisan') identity:retry-revocations
    if($LASTEXITCODE -ne 0){throw 'No se confirmó la revocación tras recuperar Seguridad.'}
    & php (Join-Path $backend 'tests\Support\IntegrationSmoke.php') recovered
    if($LASTEXITCODE -ne 0){throw 'Prueba de recuperación falló.'}
    'Integración F3 completada: Laravel local/remoto, Seguridad .NET8 y Propiedades SQL Server.'
} finally {
    foreach($process in $processes){Stop-Fixture $process}
    if(Test-Path (Join-Path $properties 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll')) {
        & dotnet (Join-Path $properties 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll') drop
    }
    if(Test-Path (Join-Path $commercial 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll')) { & dotnet (Join-Path $commercial 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll') drop }
    $releaseTarget=[IO.Path]::GetFullPath($propertyRelease)
    $releaseParent=[IO.Path]::GetFullPath($runtime)
    if([IO.Path]::GetDirectoryName($releaseTarget) -ne $releaseParent -or [IO.Path]::GetFileName($releaseTarget) -notmatch '^release-propiedades-f4-[a-f0-9]{32}$'){throw 'Unsafe release fixture cleanup.'}
    if(Test-Path -LiteralPath $releaseTarget){Remove-Item -LiteralPath $releaseTarget -Recurse -Force}
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
}
