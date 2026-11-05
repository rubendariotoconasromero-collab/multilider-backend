$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
$database=[regex]::Match($restoreSql.comercial,'(?<=Database=)[^;]+').Value
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'isolated-f5' -or
    $database -notmatch '^multilider_comercial_f4_test_[a-f0-9]{32}$' -or $database -ne $env:MULTILIDER_F5_RESTORE_COMERCIAL){
    throw 'Rollback test requires the separate restored Comercial fixture.'
}

function Invoke-RollbackFixtureSql([string]$statement) {
    $value=& sqlcmd -S localhost -d $database -E -C -b -h -1 -W -l 5 -t 8 -Q "SET NOCOUNT ON; $statement"
    if($LASTEXITCODE -ne 0){throw 'Rollback fixture SQL failed.'}
    "$value".Trim()
}

$replayed=Get-Content -LiteralPath (Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR 'replay.json') -Raw | ConvertFrom-Json
$cancelId=[Guid]::ParseExact($replayed.cancel.operacion_id,'D').ToString('D')
$fence=[long](Invoke-RollbackFixtureSql "SELECT JSON_VALUE(Reservation,'$.fencing_token') FROM dbo.SaleOperation WHERE Id='$cancelId';")
if($fence -le 0){throw 'Missing historical reversal fence.'}
$digest=[Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes("$cancelId|revertir"))
$command=[Guid]::new([byte[]]($digest[0..15]))
$cert=[Security.Cryptography.X509Certificates.X509Certificate2]::new($certs.comercial,$password)
try {
    $endpoint="https://127.0.0.1:$($tls.propiedades)/internal/catalogo/v2/operaciones/$cancelId/revertir-confirmacion"
    $options=@{Method='Post';SkipCertificateCheck=$true;SkipHttpErrorCheck=$true;Certificate=$cert;ContentType='application/json';TimeoutSec=5}
    $original=Invoke-WebRequest $endpoint @options -Body (@{command_id=$command;fencing_token=$fence}|ConvertTo-Json -Compress)
    $stale=Invoke-WebRequest $endpoint @options -Body (@{command_id=[Guid]::NewGuid();fencing_token=$fence}|ConvertTo-Json -Compress)
    if([int]$original.StatusCode -ne 200 -or [int]$stale.StatusCode -ne 409 -or ($original.Content|ConvertFrom-Json).estado_reserva -ne 'Liberada'){
        throw 'Replayed historical technical command or stale fence returned an invalid outcome.'
    }
    $identity=Invoke-RestMethod "$env:PHASE5_API/api/login" -Method Post -ContentType 'application/json' -Body '{"correo":"operator@example.test","password":"Test-only-Password-42!"}' -TimeoutSec 5
    $live=Invoke-RestMethod "$env:PHASE5_API/api/propiedades/$($replayed.old.propiedad_id)" -Headers @{Authorization="Bearer $($identity.access_token)";Accept='application/json'} -TimeoutSec 5
    if($live.estado -ne 'Vendido'){throw 'An old cancellation touched a later confirmed property generation.'}
    Write-Output 'F5-N09: old technical command replayed its original receipt; a new command with the old fence was denied and the new sale stayed confirmed.'
} finally { $cert.Dispose() }

Stop-OwnedF5 $started.comercial
Set-F5Role 'comercial' $restoreSql
Set-F5 'Migrations__DatabaseName' $database
$dll=$launches.comercial.args[0]
$valid=& $dotnet $dll --preflight-f5
if($LASTEXITCODE -ne 0 -or ($valid|ConvertFrom-Json).pending.Count -ne 0){throw 'Current binary did not accept the verified restored schema.'}

$snapshot='SELECT CONCAT((SELECT COUNT(*) FROM dbo.Ventas),''/'',(SELECT COUNT(*) FROM dbo.SaleOperation),''/'',(SELECT COUNT(*) FROM dbo.OperationStep));'
$before=Invoke-RollbackFixtureSql $snapshot
$future='20990101000000_IncompatibleFixture'
$null=Invoke-RollbackFixtureSql "INSERT dbo.__EFMigrationsHistory (MigrationId,ProductVersion) VALUES ('$future','99.0.0');"
try {
    # An unknown migration makes this exact binary older than the fixture schema.
    $rejection=& $dotnet $dll --preflight-f5 2>&1
    if($LASTEXITCODE -eq 0 -or "$rejection" -notmatch 'Unknown migration history'){throw 'Older binary did not refuse the newer schema.'}
    $local=& php (Join-Path $backend 'tests/Support/PhaseFiveCrash.php') local
    if($LASTEXITCODE -ne 0){throw 'Laravel local lost availability while incompatible admission was stopped.'}
    if((Invoke-RollbackFixtureSql $snapshot) -ne $before){throw 'Incompatible preflight modified sales or receipts.'}
} finally {
    $null=Invoke-RollbackFixtureSql "DELETE FROM dbo.__EFMigrationsHistory WHERE MigrationId='$future';"
}
Write-Output 'F5-O07: compatible preflight passed, newer restored schema refused the older binary, and local Laravel stayed available.'
