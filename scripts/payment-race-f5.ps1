$driver=Join-Path $backend 'tests/Support/PhaseFivePaymentRace.php'
$raw=& php $driver prepare
if($LASTEXITCODE -ne 0){throw 'Could not prepare payment crash fixture.'}
$exercise=$raw|ConvertFrom-Json
$command=[Guid]::ParseExact($exercise.command,'D')
$marker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($command.ToString('N')+'-Cobro-after')
if($marker -ne $exercise.marker -or $exercise.sale -le 0 -or $exercise.payment -le 0){throw 'Invalid payment fixture.'}
$params=@("$($exercise.sale)","$($exercise.payment)",$command.ToString('D'))
$writer=Start-F5 'f5-payment-writer' (Get-Command php).Source (@($driver,'write')+$params) $backend
$deadline=[DateTime]::UtcNow.AddSeconds(15)
while(!(Test-Path -LiteralPath ($marker+'.hit')) -and [DateTime]::UtcNow -lt $deadline -and !$writer.HasExited){
    Start-Sleep -Milliseconds 100
}
if(!(Test-Path -LiteralPath ($marker+'.hit')) -or (Get-Content -LiteralPath ($marker+'.hit') -Raw) -ne "$($started.finanzas.Id)"){
    throw 'Payment did not pause after the owned finance transaction committed.'
}
Stop-OwnedF5 $started.finanzas
if(!$writer.WaitForExit(20000) -or $writer.ExitCode -ne 0){throw 'Payment client did not observe the lost response.'}
$processes.Remove($writer)|Out-Null
Restart-F5 finanzas 'payment-recovered'
& php $driver verify @params
if($LASTEXITCODE -ne 0){throw 'Payment could not be recovered after the process cut.'}

$port=Port-F5
$finance=$launches.finanzas
$second=Start-F5 'f5-finanzas-second' $finance.file @($finance.args[0],'--urls',"http://127.0.0.1:$port") $finance.folder
try {
    Wait-F5 "http://127.0.0.1:$port/api/pagos" 401
    Set-F5 'PHASE5_FINANCE_SECOND' "http://127.0.0.1:$port"
    & php $driver race
    if($LASTEXITCODE -ne 0){throw 'Manual payment and simulated callback race failed.'}
} finally {
    Stop-OwnedF5 $second
}
