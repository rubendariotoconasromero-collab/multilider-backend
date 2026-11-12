$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:MULTILIDER_F7_REAL_COPY -ne '1' -or
    $env:Operations__Dataset -ne 'multilider-local' -or !(Test-Path -LiteralPath (Join-Path $f7Root 'checkpoint-origen-f8.json'))){
    throw 'F8 requiere una copia validada de F7 dentro del ejecutor aislado.'
}
$phase8Exercise=Join-Path $backend 'tests/Support/PhaseEightAcceptance.php'
function Invoke-Phase8([string]$stage) {
    & php $phase8Exercise $stage
    if($LASTEXITCODE -ne 0){throw "Falló F8 ($stage). Evidencia privada: $f7Root"}
}
function Restore-Phase8MySql([string]$source,[string]$owner,[string]$key) {
    if($source -notin @('multilider_tests_isolated',$restoreMySql.seguridad) -or $owner -notin @('identity_test','multilider_test')){
        throw 'Origen MySQL fuera de la copia aislada.'
    }
    $restored='multilider_f5_restore_'+[Guid]::NewGuid().ToString('N')
    $dumpPath=Join-Path $f7Root "f8-$key.sql"
    $verifiedPath=Join-Path $f7Root "f8-$key-verificado.sql"
    $options=@('--single-transaction','--skip-lock-tables','--skip-add-locks','--skip-comments','--skip-dump-date','--order-by-primary','--set-gtid-purged=OFF','--no-tablespaces','--hex-blob')
    & $dump @common @options "--result-file=$dumpPath" $source
    if($LASTEXITCODE -ne 0){throw "Falló el respaldo MySQL de $key."}
    $schema=& $mysql @common --batch --skip-column-names "--execute=SELECT CONCAT(DEFAULT_CHARACTER_SET_NAME,' ',DEFAULT_COLLATION_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$source'"
    if($LASTEXITCODE -ne 0 -or "$schema" -notmatch '^([a-z0-9]+) ([a-z0-9_]+)$'){throw 'Configuración de base inesperada.'}
    & $mysql @common "--execute=CREATE DATABASE $restored CHARACTER SET $($Matches[1]) COLLATE $($Matches[2]); GRANT ALL ON $restored.* TO '$owner'@'127.0.0.1';"
    if($LASTEXITCODE -ne 0){throw "Falló la preparación de $key."}
    $restoreMySql[$key]=$restored
    & $mysql @common "--database=$restored" "--execute=SOURCE $($dumpPath.Replace('\','/'))"
    if($LASTEXITCODE -ne 0){throw "Falló la restauración de $key."}
    & $dump @common @options "--result-file=$verifiedPath" $restored
    if($LASTEXITCODE -ne 0 -or (Normalize-F5Dump $dumpPath) -cne (Normalize-F5Dump $verifiedPath)){throw "El volcado restaurado de $key difiere."}
    return $restored
}
function Restore-Phase8Files([string]$folder) {
    $source=Join-Path $f7Root $folder
    $destination=Join-Path $f7Root "$folder-restored"
    New-Item -ItemType Directory -Path $destination | Out-Null
    if(!(Test-Path -LiteralPath $source)){return $destination}
    foreach($item in (Get-ChildItem -LiteralPath $source -File -Recurse)){
        $target=Join-Path $destination $item.FullName.Substring($source.Length+1)
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
        Copy-Item -LiteralPath $item.FullName -Destination $target
        if((Get-FileHash -LiteralPath $item.FullName).Hash -ne (Get-FileHash -LiteralPath $target).Hash){
            throw "Huella de archivo restaurado inválida en $folder."
        }
    }
    return $destination
}
function Start-Phase8Laravel([string]$mode,[string]$stage) {
    Set-F5 'APP_ARCHITECTURE' $mode
    $launch=if($mode -eq 'local'){'laravel-local'}else{'laravel'}
    Set-F5 'APP_CONFIG_CACHE' "storage/framework/testing/f5-restored-$(if($mode -eq 'local'){'local'}else{'remote'})-config.php"
    & php (Join-Path $backend 'tests/Support/CacheFixture.php')
    if($LASTEXITCODE -ne 0){throw "Falló la caché de $mode ($stage)."}
    Restart-F5 $launch $stage
}
function Test-Phase8Browser([string]$mode) {
    & node (Join-Path $frontend 'tests/e2e/fase8.mjs') $mode
    if($LASTEXITCODE -ne 0){throw "Falló el navegador F8 en $mode."}
}

$frontend=Join-Path $workspace 'frontend'
$vite=Join-Path $frontend 'node_modules/vite/bin/vite.js'
$artifact=Join-Path $f7Root 'frontend'
Set-F5 'PLAYWRIGHT_BROWSERS_PATH' (Join-Path $frontend '.phase3/browsers')
Set-F5 'PHASE8_FRONTEND' "http://127.0.0.1:$uiRemotePort"
Push-Location $frontend
try { & node $vite build --outDir $artifact --emptyOutDir --logLevel warn }
finally { Pop-Location }
if($LASTEXITCODE -ne 0){throw 'Falló la compilación única del frontend F8.'}
$null=Start-F5 'f8-frontend' (Get-Command node).Source @($vite,'preview','--host','127.0.0.1','--port',"$uiRemotePort",'--strictPort','--outDir',$artifact) $frontend
Wait-F5 $env:PHASE8_FRONTEND 200

$authorities=@('seguridad','propiedades','finanzas','comercial','comercial-second')
Invoke-Phase8 prepare
foreach($name in $authorities){Stop-OwnedF5 $started[$name]}
Invoke-Phase8 local-before
foreach($name in $authorities){Restart-F5 $name 'f8-before' $restoreSql}
Invoke-Phase8 account-remote
Test-Phase8Browser remote
Invoke-Phase8 remote
Invoke-Phase8 write
foreach($name in ($authorities+@('laravel','laravel-local'))){Stop-OwnedF5 $started[$name]}
$checkpointClock=[Diagnostics.Stopwatch]::StartNew()
$checkpointUtc=(Get-Date).ToUniversalTime().ToString('o')
Invoke-Phase8 capture
$deltaPath=Join-Path $f7Root 'f8-delta.json'
Set-F5 'MULTILIDER_F8_DELTA_SHA256' ((Get-FileHash -LiteralPath $deltaPath).Hash.ToLowerInvariant())

foreach($role in @('propiedades','comercial','finanzas')){
    $key=@{propiedades='PROPERTIES';comercial='COMERCIAL';finanzas='FINANZAS'}[$role]
    Set-F5 "MULTILIDER_${key}_TEST_CONNECTION" $restoreSql[$role]
    $database=[regex]::Replace($restoreSql[$role],'(?<=Database=)(multilider_\w+_test_)[a-f0-9]{32}(?=;)','${1}'+[Guid]::NewGuid().ToString('N'))
    $phase8Sql[$role]=$database
    Set-F5 "MULTILIDER_F5_RESTORE_$($role.ToUpperInvariant())" ([regex]::Match($database,'(?<=Database=)[^;]+').Value)
}
Push-Location (Join-Path $workspace 'multilider-ms-comercial')
try { & $dotnet $restoreTool verify-restoration-f7 }
finally { Pop-Location }
if($LASTEXITCODE -ne 0){throw 'Falló la restauración de SQL Server posterior al cambio.'}
$localCopy=Restore-Phase8MySql 'multilider_tests_isolated' 'multilider_test' 'laravel'
$identityCopy=Restore-Phase8MySql $restoreMySql.seguridad 'identity_test' 'identity'
foreach($role in @('propiedades','comercial','finanzas')){
    $key=@{propiedades='PROPERTIES';comercial='COMERCIAL';finanzas='FINANZAS'}[$role]
    Set-F5 "MULTILIDER_${key}_TEST_CONNECTION" $phase8Sql[$role]
}
$restoredStorage=Restore-Phase8Files 'laravel'
Set-F5 'MULTILIDER_F8_RESTORED_STORAGE' $restoredStorage
Set-F5 'Storage__Path' (Restore-Phase8Files 'documents')
Set-F5 'Storage__PhotosPath' (Restore-Phase8Files 'photos')
Set-F5 'Catalog__StorageRoot' (Restore-Phase8Files 'catalog')
$backupPaths=@($deltaPath,(Join-Path $f7Root 'f8-laravel.sql'),(Join-Path $f7Root 'f8-identity.sql'))
foreach($connection in $restoreSql.Values){
    $database=[regex]::Match($connection,'(?<=Database=)[^;]+').Value
    $backupPaths+=Join-Path $f7Root "$database.bak"
}
foreach($folder in @('laravel/app/public','laravel/app/private','documents','photos','catalog','frontend')){
    $directory=Join-Path $f7Root $folder
    if(Test-Path -LiteralPath $directory){
        $backupPaths+=@(Get-ChildItem -LiteralPath $directory -File -Recurse | Select-Object -ExpandProperty FullName)
    }
}
$checkpointFiles=@(Get-Item -LiteralPath $backupPaths | ForEach-Object {
    @{file=$_.FullName.Substring($f7Root.Length+1);sha256=(Get-FileHash -LiteralPath $_.FullName).Hash.ToLowerInvariant()}
})
@{dataset=$env:Operations__Dataset;cutoff_utc=$checkpointUtc;files=$checkpointFiles} |
    ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $f7Root 'checkpoint-f8.json') -Encoding utf8NoBOM
Set-F5 'DB_DATABASE' $localCopy
Set-F5 'MULTILIDER_F5_RESTORED_MYSQL' $localCopy
Set-F5 'MULTILIDER_IDENTITY_DATABASE' $identityCopy
Set-F5 'MULTILIDER_F7_RESTORED_IDENTITY' $identityCopy
Set-F5 'ConnectionStrings__MySQL' "Server=127.0.0.1;Port=$env:DB_PORT;Database=$identityCopy;User=$env:MULTILIDER_IDENTITY_USER;Password=$env:MULTILIDER_IDENTITY_PASSWORD"
foreach($name in $authorities){Restart-F5 $name 'f8-restored' $phase8Sql}
Start-Phase8Laravel microservices 'f8-restored'
Invoke-Phase8 remote-after
$remoteSeconds=[math]::Round($checkpointClock.Elapsed.TotalSeconds,2)
Write-Output "F8 recuperación remota verificada en $remoteSeconds s."

# A conflicting candidate must remain closed and the remote authorities stay available.
Stop-OwnedF5 $started['laravel']
Set-F5 'APP_ARCHITECTURE' 'local'
Set-F5 'APP_CONFIG_CACHE' 'storage/framework/testing/f5-initial-config.php'
$companyId=[int](Get-Content -LiteralPath $deltaPath -Raw | ConvertFrom-Json).company.id
& $mysql @common "--database=$localCopy" "--execute=INSERT INTO cuentas_bancarias(mi_empresa_id,nombre,tipo) VALUES($companyId,'Caja USD ensayo F8','EFECTIVO')"
if($LASTEXITCODE -ne 0){throw 'No se pudo preparar la colisión aislada.'}
Invoke-Phase8 reverse-conflict
& $mysql @common "--database=$localCopy" "--execute=DELETE FROM cuentas_bancarias WHERE nombre='Caja USD ensayo F8'"
if($LASTEXITCODE -ne 0){throw 'No se pudo restablecer el candidato aislado.'}
Start-Phase8Laravel microservices 'f8-conflict'
Invoke-Phase8 remote-after
Stop-OwnedF5 $started['laravel']
foreach($name in $authorities){Stop-OwnedF5 $started[$name]}
$reverseClock=[Diagnostics.Stopwatch]::StartNew()
Set-F5 'APP_ARCHITECTURE' 'local'
Set-F5 'APP_CONFIG_CACHE' 'storage/framework/testing/f5-initial-config.php'
Invoke-Phase8 reverse-interrupted
Invoke-Phase8 reverse
Invoke-Phase8 reverse-repeat
Start-Phase8Laravel local 'f8-return'
Invoke-Phase8 local-after
$localSeconds=[math]::Round($reverseClock.Elapsed.TotalSeconds,2)
Write-Output "F8 retorno a Laravel verificado en $localSeconds s."

# Exercise the local path independently on its original copy with no .NET listeners.
Stop-OwnedF5 $started['laravel-local']
Set-F5 'DB_DATABASE' 'multilider_tests_isolated'
Set-F5 'MULTILIDER_F8_RESTORED_STORAGE' $null
Start-Phase8Laravel local 'f8-local-usd'
Invoke-Phase8 account-local
Test-Phase8Browser local
Invoke-Phase8 local
Invoke-Phase8 reversal-local

# The confirmed cut and the local return are already verified. Use the
# disposable restored authority to exercise cancellation independently.
Stop-OwnedF5 $started['laravel-local']
Set-F5 'DB_DATABASE' $localCopy
Set-F5 'MULTILIDER_F8_RESTORED_STORAGE' $restoredStorage
foreach($name in $authorities){Restart-F5 $name 'f8-reversal' $phase8Sql}
Start-Phase8Laravel microservices 'f8-reversal'
Invoke-Phase8 reversal-remote
@{
    completed_utc=(Get-Date).ToUniversalTime().ToString('o')
    dataset=$env:Operations__Dataset
    delta_sha256=$env:MULTILIDER_F8_DELTA_SHA256
    remote_recovery_seconds=$remoteSeconds
    local_return_seconds=$localSeconds
    confirmed_writes_lost_in_sample=0
    libelula_external='pending'
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $f7Root 'f8-result.json') -Encoding utf8NoBOM
Write-Output "F8 evidencia privada: $f7Root"
