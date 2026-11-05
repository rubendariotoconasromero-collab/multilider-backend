param([switch]$BrowserTests,[switch]$RecoveryTests,[switch]$RestoreTests,[switch]$AcceptanceTests,[switch]$RotationTests,[switch]$CrashTests,[string]$MySqlBin)
$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:DB_DATABASE -ne 'multilider_tests_isolated' -or $env:DB_PORT -eq '3306'){throw 'Run test-isolated.ps1 -Integration -Phase5.'}
$backend=Split-Path $PSScriptRoot -Parent
$workspace=Split-Path $backend -Parent
$runtime=Join-Path $backend 'storage\framework\testing'
$previous=@{};$processes=[Collections.Generic.List[Diagnostics.Process]]::new();$started=@{};$launches=@{}
$restoreSql=@{};$restoreMySql=@{};$mysqlRestoreArgs=$null
function Set-F5([string]$name,[string]$value) {
    if(!$previous.ContainsKey($name)){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
    [Environment]::SetEnvironmentVariable($name,$value,'Process')
}
function Port-F5 {
    $socket=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$socket.Start();$port=$socket.LocalEndpoint.Port;$socket.Stop();return $port
}
function Start-F5([string]$name,[string]$executable,[string[]]$arguments,[string]$directory) {
    $priorHook=$env:DOTNET_STARTUP_HOOKS
    try {
        if(($AcceptanceTests -or $CrashTests) -and $name -like 'f5-comercial*'){
            $env:DOTNET_STARTUP_HOOKS=Join-Path $workspace 'multilider-ms-comercial\tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll'
        }
        $p=Start-Process -FilePath $executable -ArgumentList $arguments -WorkingDirectory $directory -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime "$name.out.log") -RedirectStandardError (Join-Path $runtime "$name.err.log")
    } finally { $env:DOTNET_STARTUP_HOOKS=$priorHook }
    $processes.Add($p);return $p
}
function Wait-F5([string]$url,[int]$status) {
    for($i=0;$i -lt 60;$i++){
        foreach($candidate in $processes){if($candidate.HasExited){throw 'A fixture exited; inspect its private testing log.'}}
        try{$accept=if($url -match '/api/'){'application/json'}else{'text/html'};$r=Invoke-WebRequest $url -Headers @{Accept=$accept} -SkipHttpErrorCheck -TimeoutSec 2;if([int]$r.StatusCode -eq $status){return}}catch{}
        Start-Sleep -Milliseconds 200
    }
    throw "Fixture not ready: $url; inspect private testing logs."
}
function Normalize-F5Dump([string]$path) {
    # SHOW CREATE may expand a redundant CHARACTER SET before an explicit collation.
    # Normalize only column declarations; data, constraints and collation remain exact.
    [regex]::Replace([IO.File]::ReadAllText($path),'(?m)^(  \x60[^\r\n]+?) CHARACTER SET ([a-z0-9]+) COLLATE (\2_[a-z0-9_]+)','$1 COLLATE $3')
}
function Stop-OwnedF5([Diagnostics.Process]$process) {
    if(!$processes.Contains($process) -or $process.HasExited){throw 'Only an owned running fixture may be stopped.'}
    Stop-Process -InputObject $process -Force
    if(!$process.WaitForExit(5000)){throw 'Owned fixture did not stop.'}
    $processes.Remove($process)|Out-Null
}
function Set-F5Role([string]$name,[hashtable]$sqlConnections=$connections) {
    $role=if($name -eq 'comercial-second'){'comercial'}else{$name}
    if($role -notin $roles){throw 'Unknown fixture role.'}
    Set-F5 'Operations__ClientCertificatePath' $certs[$role]
    Set-F5 'Operations__ServerCertificatePath' $serverCerts[$role]
    Set-F5 'Operations__ServerCertificatePassword' $serverPasswords[$role]
    Set-F5 'Operations__WorkerEnabled' $(if($role -eq 'comercial'){'true'}else{'false'})
    if($role -ne 'seguridad'){Set-F5 'ConnectionStrings__SqlServer' $sqlConnections[$role]}
}
function Restart-F5([string]$name,[string]$suffix) {
    if($name -ne 'laravel'){Set-F5Role $name}
    $launch=$launches[$name]
    $started[$name]=Start-F5 "f5-$name-$suffix" $launch.file $launch.args $launch.folder
    Wait-F5 $launch.probe 401
}
$roles=@('seguridad','propiedades','comercial','finanzas')
$folders=@{seguridad='multilider-ms-seguridad';propiedades='multilider-ms-propiedades';comercial='multilider-ms-comercial';finanzas='multilider-ms-finanzas'}
$dlls=@{seguridad='MSMultilider.Seguridad';propiedades='MSMultilider.Propiedades';comercial='MSMultilider.Comercial';finanzas='MSMultilider.Finanzas'}
$http=@{};$tls=@{};$pins=@{};$certs=@{};$serverCerts=@{};$serverPasswords=@{}
foreach($role in $roles){$http[$role]=Port-F5;$tls[$role]=Port-F5}
$backendPort=Port-F5;$localPort=Port-F5;$uiLocalPort=Port-F5;$uiRemotePort=Port-F5;$uiDirectPort=Port-F5;$secondCommercialPort=Port-F5;$dotnet=(Get-Command dotnet).Source
$securityRuntime=Join-Path $workspace 'multilider-ms-seguridad\.artifacts\runtime8\dotnet.exe'
$password=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
$key=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$connections=@{
 propiedades="Server=localhost;Database=multilider_properties_f3_test_$([Guid]::NewGuid().ToString('N'));Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5"
 comercial="Server=localhost;Database=multilider_comercial_f4_test_$([Guid]::NewGuid().ToString('N'));Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5"
 finanzas="Server=localhost;Database=multilider_finanzas_f5_test_$([Guid]::NewGuid().ToString('N'));Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5"
}
try {
    if(!(Test-Path $securityRuntime)){throw 'Private ASP.NET8 runtime from F3 required.'}
    if($AcceptanceTests -or $CrashTests){
        $boundaryDir=Join-Path $runtime ('f5-boundary-'+[Guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $boundaryDir|Out-Null
        Set-F5 'MULTILIDER_F5_BOUNDARY_DIR' $boundaryDir
    }
    & (Join-Path $PSScriptRoot 'tls-fixture-f4.ps1') -Directory $runtime
    $server=Get-Content (Join-Path $runtime 'tls-f4.json') -Raw | ConvertFrom-Json
    foreach($role in $roles){
        $rsa=[Security.Cryptography.RSA]::Create(2048)
        $request=[Security.Cryptography.X509Certificates.CertificateRequest]::new("CN=F5-$role",$rsa,[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
        $eku=[Security.Cryptography.OidCollection]::new();$eku.Add([Security.Cryptography.Oid]::new('1.3.6.1.5.5.7.3.2'))|Out-Null
        $request.CertificateExtensions.Add([Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension]::new($eku,$true))
        $cert=$request.CreateSelfSigned([DateTimeOffset]::UtcNow.AddMinutes(-5),[DateTimeOffset]::UtcNow.AddDays(1))
        $path=Join-Path $runtime "f5-$role-client.pfx"
        [IO.File]::WriteAllBytes($path,$cert.Export([Security.Cryptography.X509Certificates.X509ContentType]::Pfx,$password))
        $certs[$role]=$path;$pins[$role]=$cert.GetCertHashString([Security.Cryptography.HashAlgorithmName]::SHA256)
        $serverCerts[$role]=$server.server_path;$serverPasswords[$role]=$server.password
        $cert.Dispose();$rsa.Dispose()
    }
    Set-F5 'ASPNETCORE_ENVIRONMENT' 'Testing';Set-F5 'Logging__LogLevel__Default' 'Error';Set-F5 'Logging__LogLevel__Multilider.Operations.OperationChannel' 'Warning'
    Set-F5 'Jwt__Key' $key;Set-F5 'Jwt__Issuer' 'phase3-isolated';Set-F5 'Jwt__Audience' 'phase3-isolated'
    Set-F5 'Identity__LoginPermitLimit' '500';Set-F5 'Operations__Dataset' 'isolated-f5'
    Set-F5 'Operations__ServerCertificatePath' $server.server_path;Set-F5 'Operations__ServerCertificatePassword' $server.password
    Set-F5 'Operations__ClientCertificatePassword' $password
    foreach($role in $roles){
        Set-F5 "Operations__Callers__$($role)__CertificateSha256" $pins[$role]
        Set-F5 "Operations__Peers__$($role)__Url" "https://127.0.0.1:$($tls[$role])"
        Set-F5 "Operations__Peers__$($role)__ServerCertificateSha256" $server.server_pin
    }
    Set-F5 'Cors__AllowedOrigin' "http://127.0.0.1:$uiDirectPort"
    Set-F5 'CORS_ALLOWED_ORIGINS' "http://127.0.0.1:$uiLocalPort,http://127.0.0.1:$uiRemotePort,http://127.0.0.1:$uiDirectPort"
    Set-F5 'Storage__Path' (Join-Path $runtime 'f5-private-documents')
    Set-F5 'Storage__PhotosPath' (Join-Path $runtime 'f5-private-photos')
    Set-F5 'Services__Seguridad'  "http://127.0.0.1:$($http.seguridad)"
    Set-F5 'ConnectionStrings__MySQL' "Server=127.0.0.1;Port=$env:DB_PORT;Database=$env:MULTILIDER_IDENTITY_DATABASE;User=$env:MULTILIDER_IDENTITY_USER;Password=$env:MULTILIDER_IDENTITY_PASSWORD"
    Set-F5 'APP_CONFIG_CACHE' 'storage/framework/testing/f5-initial-config.php';Set-F5 'APP_ARCHITECTURE' 'local'
    Set-F5 'LARAVEL_STORAGE_PATH' (Join-Path $runtime 'app')
    & php (Join-Path $backend 'tests\Support\IntegrationFixture.php') prepare
    if($LASTEXITCODE -ne 0){throw 'Local/identity fixtures failed.'}
    & php (Join-Path $backend 'tests\Support\IntegrationFixture.php') phase5
    if($LASTEXITCODE -ne 0){throw 'F5 permissions fixture failed.'}
    foreach($role in $roles){
        $folder=Join-Path $workspace $folders[$role]
        & dotnet build (Join-Path $folder "$($dlls[$role]).csproj") --no-restore -v q
        if($LASTEXITCODE -ne 0){throw "Build failed: $role"}
    }
    Set-F5 'Migrations__DatabaseName' $env:MULTILIDER_IDENTITY_DATABASE
    & $securityRuntime (Join-Path $workspace 'multilider-ms-seguridad\bin\Debug\net8.0\MSMultilider.Seguridad.dll') --migrate-identity
    if($LASTEXITCODE -ne 0){throw 'Identity migrations failed.'}
    foreach($role in @('propiedades','comercial','finanzas')){
        Set-F5 'ConnectionStrings__SqlServer' $connections[$role]
        $name=@{propiedades='MULTILIDER_PROPERTIES_TEST_CONNECTION';comercial='MULTILIDER_COMERCIAL_TEST_CONNECTION';finanzas='MULTILIDER_FINANZAS_TEST_CONNECTION'}[$role]
        Set-F5 $name $connections[$role]
        $fixtureArgs=if($AcceptanceTests -and $role -in @('comercial','finanzas')){@('--','upgrade-f4')}else{@()}
        & dotnet run --project (Join-Path (Join-Path $workspace $folders[$role]) 'tests\FixtureSetup\FixtureSetup.csproj') @fixtureArgs
        if($LASTEXITCODE -ne 0){throw "SQL fixture failed: $role"}
    }
    foreach($role in $roles){
        Set-F5Role $role
        $folder=Join-Path $workspace $folders[$role];$framework=if($role -eq 'seguridad'){'net8.0'}else{'net10.0'}
        $executable=if($role -eq 'seguridad'){$securityRuntime}else{$dotnet}
        $launch=@((Join-Path $folder "bin\Debug\$framework\$($dlls[$role]).dll"),'--urls',"http://127.0.0.1:$($http[$role]);https://127.0.0.1:$($tls[$role])")
        $started[$role]=Start-F5 "f5-$role" $executable $launch $folder
        $probe=if($role -eq 'seguridad'){'auth/me'}elseif($role -eq 'propiedades'){'propiedades'}elseif($role -eq 'comercial'){'ventas'}else{'pagos'}
        $launches[$role]=@{file=$executable;args=$launch;folder=$folder;probe="http://127.0.0.1:$($http[$role])/api/$probe"}
        Wait-F5 $launches[$role].probe 401
    }
    Set-F5Role 'comercial-second'
    $launches['comercial-second']=@{file=$dotnet;args=@((Join-Path $workspace 'multilider-ms-comercial\bin\Debug\net10.0\MSMultilider.Comercial.dll'),'--urls',"http://127.0.0.1:$secondCommercialPort");folder=(Join-Path $workspace 'multilider-ms-comercial');probe="http://127.0.0.1:$secondCommercialPort/api/ventas"}
    $second=$launches['comercial-second'];$started['comercial-second']=Start-F5 'f5-comercial-second' $second.file $second.args $second.folder
    Wait-F5 $second.probe 401
    $authorizeUrl="https://127.0.0.1:$($tls.seguridad)/internal/operations/v1/authorize"
    $unknown=[Guid]::NewGuid().ToString('D')
    $authBody=@{operation_id=$unknown;action='read'}|ConvertTo-Json -Compress
    $response=Invoke-WebRequest $authorizeUrl -Method Post -SkipCertificateCheck -SkipHttpErrorCheck -ContentType 'application/json' -Body $authBody -TimeoutSec 5
    if([int]$response.StatusCode -ne 403){throw 'Technical route accepted a request without mTLS identity.'}
    if($AcceptanceTests){
        $unlisted=[Security.Cryptography.X509Certificates.X509Certificate2]::new($server.client_path,$server.password)
        try {
            $denied=$false
            try {
                $result=Invoke-WebRequest $authorizeUrl -Method Post -SkipCertificateCheck -SkipHttpErrorCheck -Certificate $unlisted -ContentType 'application/json' -Body $authBody -TimeoutSec 5
                $denied=[int]$result.StatusCode -eq 403
            } catch {
                # Schannel can surface rejection after TLS 1.3 client auth as
                # an encrypted-alert/decryption failure on the first read.
                for($cause=$_.Exception;$null -ne $cause;$cause=$cause.InnerException){
                    if($cause -is [System.Security.Authentication.AuthenticationException] -or
                        $cause.Message -match '(?i)decryption operation failed|TLS alert|SSL connection could not be established'){
                        $denied=$true
                        break
                    }
                }
                if(!$denied){throw}
            }
            if(!$denied){throw 'Unlisted certificate reached the operation authority.'}
            Write-Output 'F5-A01: missing or unlisted client certificate denied by the real TLS listener.'
        } finally { $unlisted.Dispose() }
    }
    $knownCert=[Security.Cryptography.X509Certificates.X509Certificate2]::new($certs.finanzas,$password)
    try {
        $wrongScope=@{operation_id=$unknown;action='pivot'}|ConvertTo-Json -Compress
        $response=Invoke-WebRequest $authorizeUrl -Method Post -SkipCertificateCheck -SkipHttpErrorCheck -Certificate $knownCert -ContentType 'application/json' -Body $wrongScope -TimeoutSec 5
        if([int]$response.StatusCode -ne 403){throw 'Finance certificate received a Comercial scope.'}
        $response=Invoke-WebRequest $authorizeUrl -Method Post -SkipCertificateCheck -SkipHttpErrorCheck -Certificate $knownCert -ContentType 'application/json' -Body $authBody -TimeoutSec 5
        if([int]$response.StatusCode -ne 404){throw 'Technical certificate bypassed the durable grant.'}
        if($AcceptanceTests){
            $user=Invoke-WebRequest "https://127.0.0.1:$($tls.seguridad)/api/usuario" -SkipCertificateCheck -SkipHttpErrorCheck -Certificate $knownCert -TimeoutSec 5
            $pay=Invoke-WebRequest "https://127.0.0.1:$($tls.finanzas)/api/pagos" -Method Post -SkipCertificateCheck -SkipHttpErrorCheck -Certificate $knownCert -ContentType 'application/json' -Body '{}' -TimeoutSec 5
            if([int]$user.StatusCode -ne 401 -or [int]$pay.StatusCode -ne 401){throw 'Technical certificate authorized a user route.'}
            Write-Output 'F5-A11: real technical certificate cannot administer users or record payments.'
        }
    } finally {$knownCert.Dispose()}
    Write-Output 'F5 mTLS negative checks: missing certificate, wrong scope and missing grant rejected.'
    if($BrowserTests -or $RecoveryTests -or $AcceptanceTests -or $RotationTests -or $CrashTests) {
        Set-F5 'APP_ARCHITECTURE' 'local';Set-F5 'APP_CONFIG_CACHE' 'storage/framework/testing/f5-local-config.php'
        & php (Join-Path $backend 'tests/Support/CacheFixture.php')
        if($LASTEXITCODE -ne 0){throw 'Local config cache failed.'}
        $null=Start-F5 'f5-laravel-local' (Get-Command php).Source @('-S',"127.0.0.1:$localPort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php')) (Join-Path $backend 'public')
        $launches['laravel-local']=@{file=(Get-Command php).Source;args=@('-S',"127.0.0.1:$localPort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php'));folder=(Join-Path $backend 'public');probe="http://127.0.0.1:$localPort/api/me"}
        Wait-F5 "http://127.0.0.1:$localPort/api/me" 401
        if($AcceptanceTests){
            $secondLocalPort=Port-F5
            $null=Start-F5 'f5-laravel-local-second' (Get-Command php).Source @('-S',"127.0.0.1:$secondLocalPort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php')) (Join-Path $backend 'public')
            Wait-F5 "http://127.0.0.1:$secondLocalPort/api/me" 401
            Set-F5 'PHASE5_API_LOCAL_SECOND' "http://127.0.0.1:$secondLocalPort"
        }
        Set-F5 'PHASE5_API_LOCAL' "http://127.0.0.1:$localPort"
    }
    Set-F5 'APP_ARCHITECTURE' 'microservices';Set-F5 'BUSINESS_MICROSERVICES_ENABLED' 'true'
    Set-F5 'IDENTITY_SIGNING_KEY' $key;Set-F5 'IDENTITY_ISSUER' 'phase3-isolated';Set-F5 'IDENTITY_AUDIENCE' 'phase3-isolated'
    Set-F5 'SEGURIDAD_SERVICE_URL' "http://127.0.0.1:$($http.seguridad)";Set-F5 'PROPIEDADES_SERVICE_URL' "http://127.0.0.1:$($http.propiedades)"
    Set-F5 'COMERCIAL_SERVICE_URL' "http://127.0.0.1:$($http.comercial)";Set-F5 'FINANZAS_SERVICE_URL' "http://127.0.0.1:$($http.finanzas)"
    Set-F5 'APP_CONFIG_CACHE' 'storage/framework/testing/f5-remote-config.php'
    & php (Join-Path $backend 'tests/Support/CacheFixture.php')
    if($LASTEXITCODE -ne 0){throw 'Isolated remote config failed.'}
    $launches['laravel']=@{file=(Get-Command php).Source;args=@('-S',"127.0.0.1:$backendPort",'-t','.',(Join-Path $backend 'tests/Support/FixtureHttpRouter.php'));folder=(Join-Path $backend 'public');probe="http://127.0.0.1:$backendPort/api/me"}
    $laravel=$launches.laravel;$started.laravel=Start-F5 'f5-laravel' $laravel.file $laravel.args $laravel.folder
    Wait-F5 $laravel.probe 401
    Set-F5 'PHASE5_API' "http://127.0.0.1:$backendPort";Set-F5 'PHASE5_FINANCE' "http://127.0.0.1:$($http.finanzas)";Set-F5 'PHASE5_COMMERCIAL' "http://127.0.0.1:$($http.comercial)"
    & php (Join-Path $backend 'tests\Support\PhaseFiveIntegrationSmoke.php')
    if($LASTEXITCODE -ne 0){throw 'F5 integration failed; inspect private service logs.'}
    if($AcceptanceTests) {
        Set-F5 'PHASE5_SECURITY' "http://127.0.0.1:$($http.seguridad)"
        & php (Join-Path $backend 'tests/Support/PhaseFiveAcceptance.php')
        if($LASTEXITCODE -ne 0){throw 'F5 acceptance checks failed.'}
        & php (Join-Path $backend 'tests/Support/PhaseFiveFinanceComplements.php')
        if($LASTEXITCODE -ne 0){throw 'F5 additional finance checks failed.'}
        & (Join-Path $PSScriptRoot 'reservation-acceptance-f5.ps1')
    }
    if($RecoveryTests) {
        Set-F5 'PHASE5_API_LOCAL' "http://127.0.0.1:$localPort"
        $exercise=Join-Path $backend 'tests\Support\PhaseFiveRecoverySmoke.php'
        foreach($target in @('finanzas','propiedades','seguridad','comercial','laravel')) {
            & php $exercise prepare $target
            if($LASTEXITCODE -ne 0){throw "Recovery fixture failed: $target"}
            $stopped=@($target);if($target -eq 'comercial'){$stopped+=@('comercial-second')}
            foreach($name in $stopped) {
                Stop-OwnedF5 $started[$name]
            }
            & php $exercise offline $target
            if($LASTEXITCODE -ne 0){throw "Offline fixture failed: $target"}
            foreach($name in $stopped) {
                Restart-F5 $name 'restarted'
            }
            & php $exercise recover $target
            if($LASTEXITCODE -ne 0){throw "Recovery check failed: $target"}
        }
    }
    if($RotationTests){& (Join-Path $PSScriptRoot 'rotation-f5.ps1')}
    if($CrashTests){& (Join-Path $PSScriptRoot 'crash-f5.ps1')}
    if($BrowserTests) {
        $frontend=Join-Path $workspace 'frontend';$vite=Join-Path $frontend 'node_modules\vite\bin\vite.js'
        Set-F5 'PLAYWRIGHT_BROWSERS_PATH' (Join-Path $frontend '.phase3\browsers')
        foreach($variant in @('LOCAL','REMOTE','DIRECT')) {
            $uiPort=@{LOCAL=$uiLocalPort;REMOTE=$uiRemotePort;DIRECT=$uiDirectPort}[$variant]
            $apiPort=if($variant -eq 'LOCAL'){$localPort}else{$backendPort}
            Set-F5 "PHASE5_BASE_$variant" "http://127.0.0.1:$uiPort";Set-F5 "PHASE5_API_$variant" "http://127.0.0.1:$apiPort"
            Set-F5 'VITE_API_URL' "http://127.0.0.1:$apiPort";Set-F5 'VITE_SECURITY_URL' "http://127.0.0.1:$($http.seguridad)";Set-F5 'VITE_PROPIEDADES_URL' "http://127.0.0.1:$($http.propiedades)"
            Set-F5 'MULTILIDER_VITE_CACHE' (Join-Path $frontend ".phase3\vite-f5-$variant")
            $directory=if($variant -eq 'DIRECT'){Join-Path $frontend '.worktrees\fase3-microservices'}else{$frontend}
            $null=Start-F5 "f5-ui-$variant" (Get-Command node).Source @($vite,'--host','127.0.0.1','--port',"$uiPort",'--strictPort') $directory
            Wait-F5 "http://127.0.0.1:$uiPort" 200
        }
        Push-Location $frontend
        try {& node (Join-Path $frontend 'node_modules\@playwright\test\cli.js') test --config (Join-Path $frontend 'playwright.phase5.config.js');if($LASTEXITCODE -ne 0){throw 'F5 browser tests failed.'}}finally{Pop-Location}
    }
    if($RestoreTests) {
        $replay=$AcceptanceTests
        if($replay){
            & php (Join-Path $backend 'tests/Support/PhaseFiveReplay.php') checkpoint
            if($LASTEXITCODE -ne 0){throw 'Could not prepare replay checkpoint.'}
        }
        # Close every fixture listener and worker to obtain one checkpoint across authorities.
        foreach($p in $processes){if(!$p.HasExited){& taskkill.exe /PID $p.Id /T /F 2>$null|Out-Null;if(!$p.WaitForExit(5000)){throw 'Could not freeze fixture writers.'}}}
        $processes.Clear()
        $backupRoot=Join-Path $runtime ('f5-restore-'+[Guid]::NewGuid().ToString('N'))
        Set-F5 'MULTILIDER_F5_BACKUP_ROOT' $backupRoot
        $restoreTool=Join-Path $workspace 'multilider-ms-comercial\tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll'
        if($replay){
            foreach($role in @('propiedades','comercial','finanzas')){
                $prefix=@{propiedades='multilider_properties_f3_test_';comercial='multilider_comercial_f4_test_';finanzas='multilider_finanzas_f5_test_'}[$role]
                $name=$prefix+[Guid]::NewGuid().ToString('N')
                Set-F5 "MULTILIDER_F5_RESTORE_$($role.ToUpperInvariant())" $name
                $restoreSql[$role]=$connections[$role] -replace '(?<=Database=)[^;]+',$name
            }
        }
        & $dotnet $restoreTool verify-restoration
        if($LASTEXITCODE -ne 0){throw 'SQL Server restore verification failed.'}
        $mysql=Join-Path $MySqlBin 'mysql.exe';$dump=Join-Path $MySqlBin 'mysqldump.exe'
        if($env:DB_PORT -eq '3306' -or $env:MULTILIDER_IDENTITY_DATABASE -ne 'multilider_identity_tests_isolated'){throw 'Unsafe MySQL backup target.'}
        $common=@('--no-defaults','--host=127.0.0.1',"--port=$env:DB_PORT",'--user=root','--skip-password')
        $actual=& $mysql @common --batch --skip-column-names '--execute=SELECT @@port'
        if($LASTEXITCODE -ne 0 -or "$actual" -ne $env:DB_PORT){throw 'Unexpected MySQL test port.'}
        $mysqlRestoreArgs=$common
        foreach($database in @('multilider_tests_isolated','multilider_identity_tests_isolated')) {
            $restored='multilider_f5_restore_'+[Guid]::NewGuid().ToString('N')
            $sourcePath=Join-Path $backupRoot "$database.sql";$copyPath=Join-Path $backupRoot "$restored.sql"
            $options=@('--single-transaction','--skip-lock-tables','--skip-add-locks','--skip-comments','--skip-dump-date','--order-by-primary','--set-gtid-purged=OFF','--no-tablespaces')
            & $dump @common @options "--result-file=$sourcePath" $database
            if($LASTEXITCODE -ne 0){throw 'MySQL backup failed.'}
            $schema=& $mysql @common --batch --skip-column-names "--execute=SELECT CONCAT(DEFAULT_CHARACTER_SET_NAME,' ',DEFAULT_COLLATION_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'"
            if($LASTEXITCODE -ne 0 -or "$schema" -notmatch '^([a-z0-9]+) ([a-z0-9_]+)$'){throw 'Invalid source schema defaults.'}
            & $mysql @common "--execute=CREATE DATABASE $restored CHARACTER SET $($Matches[1]) COLLATE $($Matches[2])"
            if($LASTEXITCODE -ne 0){throw 'MySQL restore destination failed.'}
            $restoreMySql[$database]=$restored
            try {
                & $mysql @common "--database=$restored" "--execute=SOURCE $($sourcePath.Replace('\','/'))"
                if($LASTEXITCODE -ne 0){throw 'MySQL restore failed.'}
                & $dump @common @options "--result-file=$copyPath" $restored
                if($LASTEXITCODE -ne 0 -or (Normalize-F5Dump $sourcePath) -cne (Normalize-F5Dump $copyPath)){throw 'Restored MySQL dump differs.'}
                Write-Output "F5 MySQL backup/restore verified: $database (full logical fingerprint)."
            } finally {
                if($restored -notmatch '^multilider_f5_restore_[a-f0-9]{32}$'){throw 'Unsafe MySQL restore cleanup.'}
                if(!$replay){
                    & $mysql @common "--execute=DROP DATABASE $restored"
                    if($LASTEXITCODE -ne 0){throw 'Could not clean isolated MySQL restore.'}
                    $restoreMySql.Remove($database)
                }
            }
        }
        foreach($name in @('f5-private-documents','f5-private-photos')) {
            $source=Join-Path $runtime $name
            if(Test-Path -LiteralPath $source) {
                $dest=Join-Path $backupRoot $name;New-Item -ItemType Directory -Force $dest|Out-Null
                Get-ChildItem -LiteralPath $source -File|ForEach-Object {
                    $target=Join-Path $dest $_.Name;Copy-Item -LiteralPath $_.FullName -Destination $target
                    if((Get-FileHash -LiteralPath $_.FullName).Hash -ne (Get-FileHash -LiteralPath $target).Hash){throw 'Restored file fingerprint differs.'}
                }
            }
        }
        if($replay){
            $appSource=Join-Path $runtime 'app\app'
            $appDestination=Join-Path $backupRoot 'laravel-storage\app'
            New-Item -ItemType Directory -Force -Path $appDestination|Out-Null
            if(Test-Path -LiteralPath $appSource){
                Get-ChildItem -LiteralPath $appSource -File -Recurse|ForEach-Object {
                    $relative=$_.FullName.Substring($appSource.Length+1)
                    $target=Join-Path $appDestination $relative
                    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target)|Out-Null
                    Copy-Item -LiteralPath $_.FullName -Destination $target
                    if((Get-FileHash -LiteralPath $_.FullName).Hash -ne (Get-FileHash -LiteralPath $target).Hash){throw 'Restored Laravel storage fingerprint differs.'}
                }
            }
        }
        $files=@(Get-ChildItem -LiteralPath $backupRoot -File -Recurse|ForEach-Object {@{file=$_.FullName.Substring($backupRoot.Length+1);sha256=(Get-FileHash -LiteralPath $_.FullName).Hash}})
        @{dataset='isolated-f5';checkpoint=(Get-Date).ToUniversalTime().ToString('o');mode='fixture-only';files=$files}|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $backupRoot 'manifest.json') -Encoding utf8
        Write-Output 'F5 checkpoint verified across five temporary databases and private files.'
        if($replay){
            $localDb=$restoreMySql['multilider_tests_isolated'];$identityDb=$restoreMySql['multilider_identity_tests_isolated']
            & $mysql @common "--execute=GRANT ALL PRIVILEGES ON $localDb.* TO 'multilider_test'@'127.0.0.1'; GRANT ALL PRIVILEGES ON $identityDb.* TO 'identity_test'@'127.0.0.1';"
            if($LASTEXITCODE -ne 0){throw 'Restored test grants failed.'}
            Set-F5 'DB_DATABASE' $localDb;Set-F5 'MULTILIDER_F5_RESTORED_MYSQL' $localDb
            Set-F5 'MULTILIDER_IDENTITY_DATABASE' $identityDb;Set-F5 'MULTILIDER_F5_RESTORED_IDENTITY' $identityDb
            Set-F5 'MULTILIDER_F5_RESTORED_STORAGE' (Join-Path $backupRoot 'laravel-storage')
            Set-F5 'ConnectionStrings__MySQL' "Server=127.0.0.1;Port=$env:DB_PORT;Database=$identityDb;User=$env:MULTILIDER_IDENTITY_USER;Password=$env:MULTILIDER_IDENTITY_PASSWORD"
            Set-F5 'Storage__Path' (Join-Path $backupRoot 'f5-private-documents');Set-F5 'Storage__PhotosPath' (Join-Path $backupRoot 'f5-private-photos')
            foreach($role in $roles){
                Set-F5Role $role $restoreSql
                $launch=$launches[$role]
                $started[$role]=Start-F5 "f5-$role-restored" $launch.file $launch.args $launch.folder
                Wait-F5 $launch.probe 401
            }
            foreach($mode in @('local','remote')){
                Set-F5 'APP_ARCHITECTURE' $(if($mode -eq 'local'){'local'}else{'microservices'})
                Set-F5 'APP_CONFIG_CACHE' "storage/framework/testing/f5-restored-$mode-config.php"
                & php (Join-Path $backend 'tests/Support/CacheFixture.php')
                if($LASTEXITCODE -ne 0){throw 'Restored Laravel config failed.'}
                $launch=$launches[$(if($mode -eq 'local'){'laravel-local'}else{'laravel'})]
                $null=Start-F5 "f5-laravel-$mode-restored" $launch.file $launch.args $launch.folder
                Wait-F5 $launch.probe 401
            }
            & php (Join-Path $backend 'tests/Support/PhaseFiveReplay.php') verify
            if($LASTEXITCODE -ne 0){throw 'Restored set replay failed.'}
            & (Join-Path $PSScriptRoot 'rollback-f5.ps1')
        }
    }
} finally {
    $recoveryState=Join-Path $runtime 'f5-recovery-state.json'
    if(Test-Path -LiteralPath $recoveryState){Remove-Item -LiteralPath $recoveryState -Force}
    foreach($p in $processes){if(!$p.HasExited){& taskkill.exe /PID $p.Id /T /F 2>$null | Out-Null;$p.WaitForExit(5000)|Out-Null}}
    foreach($database in $restoreMySql.Values){
        if($database -notmatch '^multilider_f5_restore_[a-f0-9]{32}$' -or !$mysqlRestoreArgs){throw 'Unsafe MySQL restore cleanup.'}
        & $mysql @mysqlRestoreArgs "--execute=DROP DATABASE IF EXISTS $database"
        if($LASTEXITCODE -ne 0){throw 'Could not clean isolated MySQL restore.'}
    }
    foreach($role in @('propiedades','comercial','finanzas')){
        $name=@{propiedades='MULTILIDER_PROPERTIES_TEST_CONNECTION';comercial='MULTILIDER_COMERCIAL_TEST_CONNECTION';finanzas='MULTILIDER_FINANZAS_TEST_CONNECTION'}[$role]
        [Environment]::SetEnvironmentVariable($name,$connections[$role],'Process')
        $fixture=Join-Path (Join-Path $workspace $folders[$role]) 'tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll'
        if(Test-Path $fixture){
            & $dotnet $fixture drop
            if($restoreSql.ContainsKey($role)){
                [Environment]::SetEnvironmentVariable($name,$restoreSql[$role],'Process')
                & $dotnet $fixture drop
            }
        }
    }
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
}
