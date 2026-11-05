$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'isolated-f5' -or !$env:MULTILIDER_F5_BOUNDARY_DIR){throw 'Isolated F5 crash runner required.'}

function Read-F5CrashSql([string]$role,[string]$query) {
    $database=[regex]::Match($connections[$role],'(?<=Database=)[^;]+').Value
    if($database -notmatch '^multilider_(comercial_f4|finanzas_f5|properties_f3)_test_[a-f0-9]{32}$' -or $connections[$role] -notmatch '^Server=localhost;'){throw 'Unsafe crash SQL target.'}
    $result=& sqlcmd -S localhost -d $database -E -C -b -h -1 -W -l 5 -t 8 -Q "SET NOCOUNT ON; $query"
    if($LASTEXITCODE -ne 0){throw 'Isolated crash SQL inspection failed.'}
    "$result".Trim()
}

$stages=@(
    @{kind='sale';steps=@('Oferta','Reservar','PrepararVenta','PrepararFinanzas','AutorizarConfirmacion','ConfirmarPropiedad','ActivarFinanzas','Completar')}
    @{kind='cancel';steps=@('AutorizarAnulacion','CerrarFinanzas','RevertirPropiedad','CompletarAnulacion')}
    @{kind='abort';steps=@('Abortar','AbortarFinanzas','LiberarReserva')}
)
$remoteCommits=@('Reservar','PrepararFinanzas','AutorizarConfirmacion','ConfirmarPropiedad','ActivarFinanzas','AutorizarAnulacion',
    'CerrarFinanzas','RevertirPropiedad','Abortar','AbortarFinanzas','LiberarReserva')
$authorities=@{
    Reservar='propiedades';ConfirmarPropiedad='propiedades';RevertirPropiedad='propiedades';LiberarReserva='propiedades'
    PrepararFinanzas='finanzas';ActivarFinanzas='finanzas';CerrarFinanzas='finanzas';AbortarFinanzas='finanzas'
    AutorizarConfirmacion='seguridad';AutorizarAnulacion='seguridad';Abortar='seguridad'
}
$cases=@(foreach($stage in $stages){
    foreach($step in $stage.steps){
        foreach($side in @('before','after')){
            @{kind=$stage.kind;point="Guardar-$step";step=$step;side=$side}
        }
        if($step -in $remoteCommits){@{kind=$stage.kind;point="Respuesta-$step";step=$step;side='after'}}
    }
})
$cases+=@(
    @{kind='sale';point='PersistirVenta';step='PrepararVenta';side='before'}
    @{kind='sale';point='PersistirVenta';step='PrepararVenta';side='after'}
)
if($env:MULTILIDER_F5_CRASH_FILTER){
    $cases=@($cases | Where-Object {"$($_.point):$($_.side)" -match $env:MULTILIDER_F5_CRASH_FILTER})
}
if(!$cases.Count){throw 'No crash cases selected.'}

$driver=Join-Path $backend 'tests/Support/PhaseFiveCrash.php'
$index=0
foreach($case in $cases){
    $raw=& php $driver prepare $case.kind $case.point $case.side
    if($LASTEXITCODE -ne 0){throw "Could not reach $($case.point):$($case.side)."}
    $run=$raw|ConvertFrom-Json
    $id=[Guid]::ParseExact($run.id,'D').ToString('D')
    $worker=@('comercial','comercial-second') | Where-Object {$started[$_].Id -eq $run.pid}
    if(@($worker).Count -ne 1){throw 'Checkpoint was not held by an owned Comercial worker.'}
    Stop-OwnedF5 $started[$worker]
    $authority=if($case.point.StartsWith('Respuesta-')){$authorities[$case.step]}else{$null}
    if($authority){Stop-OwnedF5 $started[$authority]}
    if($index -eq 0 -or $authority){
        & php $driver local
        if($LASTEXITCODE -ne 0){throw 'Laravel local lost availability during a crash.'}
    }
    $expected=if($case.point.StartsWith('Guardar-') -and $case.side -eq 'after'){1}else{0}
    $step=$case.step
    $receipt=Read-F5CrashSql 'comercial' "SELECT COUNT(*) FROM dbo.OperationStep WHERE OperationId='$id' AND Step='$step';"
    if($receipt -ne "$expected"){throw "Receipt survived rollback or disappeared after commit at $($case.point):$($case.side)."}
    if($case.point -eq 'PersistirVenta'){
        $expectedSale=if($case.side -eq 'after'){1}else{0}
        $saleContract=Read-F5CrashSql 'comercial' "SELECT COUNT(*) FROM dbo.Ventas v JOIN dbo.Contratos c ON c.NotaVentaId=v.Id WHERE v.SolicitudId='$id';"
        if($saleContract -ne "$expectedSale"){throw 'Venta and contract did not commit atomically.'}
    }
    if($authority){Restart-F5 $authority "crash-$index"}
    Restart-F5 $worker "crash-$index"
    if($case.point -in @('Respuesta-CerrarFinanzas','Respuesta-RevertirPropiedad')){
        & php $driver between
        if($LASTEXITCODE -ne 0){throw 'Protection between financial close and reversal failed.'}
    }
    $index++
    Write-Output "F5 crash $index/$($cases.Count): $($case.kind) $($case.point) $($case.side), PID stopped and SQL receipt verified."
}
& php $driver verify
if($LASTEXITCODE -ne 0){throw 'F5 crash recovery verification failed.'}

$recordFile=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR 'crash-cases.jsonl'
$statements=[Collections.Generic.List[string]]::new()
foreach($line in (Get-Content -LiteralPath $recordFile)){
    $run=$line|ConvertFrom-Json
    $id=[Guid]::ParseExact($run.sale.operacion_id,'D').ToString('D')
    $state=@{sale='Activa';cancel='Cerrada';abort='Abortada'}[$run.kind]
    if(!$state){throw 'Invalid crash case kind.'}
    $count=if($run.kind -eq 'abort'){0}else{1}
    $statements.Add("IF NOT EXISTS (SELECT 1 FROM dbo.FinancialOperation WHERE Id='$id' AND State='$state') THROW 51000,'Incorrect recovered financial operation',1;")
    $statements.Add("IF (SELECT COUNT(*) FROM dbo.CuentasVentas c JOIN dbo.FinancialOperation f ON f.VentaId=c.NotaVentaId WHERE f.Id='$id')<>$count THROW 51000,'Duplicate or missing financial account',1;")
    if($count -eq 1){
        $statements.Add("IF (SELECT COUNT(*) FROM dbo.Egresos e JOIN dbo.CuentasVentas c ON c.Id=e.CuentaVentaId JOIN dbo.FinancialOperation f ON f.VentaId=c.NotaVentaId WHERE f.Id='$id')<>1 THROW 51000,'Duplicate or missing commission',1;")
        $statements.Add("IF EXISTS (SELECT 1 FROM dbo.Ingresos i JOIN dbo.CuentasVentas c ON c.Id=i.CuentaVentaId JOIN dbo.FinancialOperation f ON f.VentaId=c.NotaVentaId WHERE f.Id='$id') THROW 51000,'Premature income after recovery',1;")
    }
}
$sqlFile=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR 'crash-finance-audit.sql'
$statements | Set-Content -LiteralPath $sqlFile -Encoding utf8
$financeDatabase=[regex]::Match($connections.finanzas,'(?<=Database=)[^;]+').Value
if($financeDatabase -notmatch '^multilider_finanzas_f5_test_[a-f0-9]{32}$'){throw 'Unsafe finance audit target.'}
& sqlcmd -S localhost -d $financeDatabase -E -C -b -l 5 -t 15 -i $sqlFile
if($LASTEXITCODE -ne 0){throw 'F5 crash finance SQL invariants failed.'}
Write-Output 'F5 crashes: one account and commission per completed operation and no premature income; compensated holds released.'

if(!$env:MULTILIDER_F5_CRASH_FILTER){
    $stale=& php $driver prepare sale ActivarFinanzas after unlogged
    if($LASTEXITCODE -ne 0){throw 'Could not hold the stale worker fixture.'}
    $stale=$stale|ConvertFrom-Json
    $staleId=[Guid]::ParseExact($stale.id,'D')
    $staleMarker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($staleId.ToString('N')+'-ActivarFinanzas-after')
    $staleOwner=@('comercial','comercial-second') | Where-Object {$started[$_].Id -eq $stale.pid}
    if(@($staleOwner).Count -ne 1){throw 'Stale worker is not an owned fixture.'}
    try {
        & php $driver await-state $stale.id Completada
        if($LASTEXITCODE -ne 0 -or $started[$staleOwner].HasExited){throw 'Another live worker did not take over the expired lease.'}
        $stamp=Read-F5CrashSql 'comercial' "SELECT CONVERT(varchar(36),Stamp) FROM dbo.SaleOperation WHERE Id='$staleId' AND State='Completada';"
        [IO.File]::WriteAllText($staleMarker+'.watch',"$($stale.pid)")
    } finally { [IO.File]::WriteAllText($staleMarker+'.release','') }
    $watchUntil=[DateTime]::UtcNow.AddSeconds(10)
    while(!(Test-Path -LiteralPath ($staleMarker+'.finished')) -and [DateTime]::UtcNow -lt $watchUntil){Start-Sleep -Milliseconds 100}
    $after=Read-F5CrashSql 'comercial' "SELECT CONVERT(varchar(36),Stamp) FROM dbo.SaleOperation WHERE Id='$staleId' AND State='Completada';"
    if(!(Test-Path -LiteralPath ($staleMarker+'.finished')) -or !$stamp -or $after -ne $stamp){throw 'Expired worker changed the takeover result.'}
    Write-Output 'F5-O01: expired live worker tried to finish after the other process took over; durable result and stamp were unchanged.'

    $retry=& php $driver prepare sale PrepararFinanzas before unlogged
    if($LASTEXITCODE -ne 0){throw 'Could not hold retry-limit fixture.'}
    $retry=$retry|ConvertFrom-Json
    $retryId=[Guid]::ParseExact($retry.id,'D')
    $retryMarker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($retryId.ToString('N')+'-PrepararFinanzas-before')
    $null=Read-F5CrashSql 'comercial' "UPDATE dbo.SaleOperation SET Attempts=63 WHERE Id='$retryId' AND Step='PrepararFinanzas' AND State='Pendiente'; IF @@ROWCOUNT<>1 THROW 51000,'Retry fixture missing',1;"
    Stop-OwnedF5 $started.finanzas
    [IO.File]::WriteAllText($retryMarker+'.release','')
    & php $driver await-state $retry.id RequiereRevision
    if($LASTEXITCODE -ne 0){throw 'Actual Finance outage did not enforce the retry limit.'}
    Restart-F5 'finanzas' 'retry-limit'
    & php $driver retry $retry.id
    if($LASTEXITCODE -ne 0){throw 'Audited resume failed after restoring Finanzas.'}
    if((Read-F5CrashSql 'comercial' "SELECT COUNT(*) FROM dbo.OperationStep WHERE OperationId='$retryId' AND Step LIKE 'Reanudacion:%';") -ne '1'){
        throw 'Expected one audited resume receipt.'
    }
    Write-Output 'F5-O03: last permitted attempt failed during an actual Finance outage; authorized resume completed with one audit receipt.'
}
