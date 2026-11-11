$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'isolated-f5'){throw 'Isolated F5 reservation acceptance required.'}

function Invoke-ReservationFixtureSql([string]$role,[string]$query) {
    $db=[regex]::Match($connections[$role],'(?<=Database=)[^;]+').Value
    if($db -notmatch '^multilider_(properties_f3|finanzas_f5)_test_[a-f0-9]{32}$'){throw 'Unsafe reservation fixture database.'}
    $result=& sqlcmd -S localhost -d $db -E -C -I -b -h -1 -W -l 5 -t 8 -Q "SET NOCOUNT ON; $query"
    if($LASTEXITCODE -ne 0){throw "Reservation fixture SQL failed: $result"}
    "$result".Trim()
}

$driver=Join-Path $backend 'tests/Support/PhaseFiveCrash.php'
$stale=& php $driver prepare sale Reservar before unlogged
if($LASTEXITCODE -ne 0){throw 'Could not pause before the catalog write.'}
$stale=$stale|ConvertFrom-Json
$staleId=[Guid]::ParseExact($stale.id,'D')
$marker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($staleId.ToString('N')+'-Reservar-before')
$certificate=[Security.Cryptography.X509Certificates.X509Certificate2]::new($certs.comercial,$password)
try {
    $base="https://127.0.0.1:$($tls.propiedades)/internal/catalogo/v2/operaciones/$staleId"
    $offer=Invoke-RestMethod "$base/oferta" -SkipCertificateCheck -Certificate $certificate -TimeoutSec 5
    $data=@{operacion_id=$staleId;command_id=[Guid]::NewGuid();expected_version=$offer.version;
        precio_esperado=[decimal]$offer.precio_venta+1;moneda_esperada=$offer.moneda}
    $options=@{Method='Post';SkipCertificateCheck=$true;SkipHttpErrorCheck=$true;Certificate=$certificate;ContentType='application/json';TimeoutSec=5}
    $wrongPrice=Invoke-WebRequest "$base/reservar" @options -Body ($data|ConvertTo-Json -Compress)
    $data.precio_esperado=$offer.precio_venta;$data.moneda_esperada=if($offer.moneda -eq 'BOB'){'USD'}else{'BOB'}
    $wrongCurrency=Invoke-WebRequest "$base/reservar" @options -Body ($data|ConvertTo-Json -Compress)
    $data.moneda_esperada=$offer.moneda;$data.expected_version='stale'
    $wrongVersion=Invoke-WebRequest "$base/reservar" @options -Body ($data|ConvertTo-Json -Compress)
    if([int]$wrongPrice.StatusCode -ne 409 -or [int]$wrongCurrency.StatusCode -ne 409 -or [int]$wrongVersion.StatusCode -ne 412 -or
        (Invoke-ReservationFixtureSql 'propiedades' "SELECT COUNT(*) FROM dbo.catalog_reservas WHERE OperationId='$staleId';") -ne '0'){
        throw 'A stale version, price or currency crossed the catalog confirmation gate.'
    }
} finally {
    [IO.File]::WriteAllText($marker+'.release','')
    $certificate.Dispose()
}
& php $driver await-state $stale.id Completada
if($LASTEXITCODE -ne 0){throw 'Valid offer could not recover after the rejected probes.'}
Write-Output 'F5-S05: real catalog denied stale price/currency (409) and version (412) without writing any reservation.'

$held=& php $driver prepare sale PrepararFinanzas after unlogged
if($LASTEXITCODE -ne 0){throw 'Could not pause after finance preparation.'}
$held=$held|ConvertFrom-Json
$heldId=[Guid]::ParseExact($held.id,'D')
$marker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($heldId.ToString('N')+'-PrepararFinanzas-after')
try {
    if ($AggregateTests) {
        $propertyId=Invoke-ReservationFixtureSql 'propiedades' "SELECT PropiedadId FROM dbo.catalog_reservas WHERE OperationId='$heldId' AND Estado='Retenida';"
        if($propertyId -notmatch '^[1-9][0-9]*$'){throw 'F6 reservation fixture property is missing.'}
        & php (Join-Path $backend 'tests\Support\PhaseSixAcceptance.php') reservation $propertyId
        if($LASTEXITCODE -ne 0){throw 'F6 inventory did not reflect the live reservation.'}
    }
    # Only advance this synthetic reservation's clock. The real catalogue
    # expiration, grant and compensation run unchanged.
    $null=Invoke-ReservationFixtureSql 'propiedades' "UPDATE dbo.catalog_reservas SET ExpiresAt=DATEADD(second,-1,SYSUTCDATETIME()) WHERE OperationId='$heldId' AND Estado='Retenida'; IF @@ROWCOUNT<>1 THROW 51000,'Expected one held reservation',1;"
} finally { [IO.File]::WriteAllText($marker+'.release','') }
& php $driver await-state $held.id Compensada
if($LASTEXITCODE -ne 0 -or (Invoke-ReservationFixtureSql 'propiedades' "SELECT Estado FROM dbo.catalog_reservas WHERE OperationId='$heldId';") -ne 'Vencida' -or
    (Invoke-ReservationFixtureSql 'finanzas' "SELECT State FROM dbo.FinancialOperation WHERE Id='$heldId';") -ne 'Abortada'){
    throw 'Expired reservation confirmed or retained prepared debt.'
}
Write-Output 'F5-S06: a hold expired after finance prepared; real workers compensated it without confirming or leaving payable debt.'

$race=& php $driver prepare sale AutorizarConfirmacion before unlogged
if($LASTEXITCODE -ne 0){throw 'Could not pause before the authorization pivot.'}
$race=$race|ConvertFrom-Json
$raceId=[Guid]::ParseExact($race.id,'D')
$marker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($raceId.ToString('N')+'-AutorizarConfirmacion-before')
$cert=[Security.Cryptography.X509Certificates.X509Certificate2]::new($certs.comercial,$password)
$handler=[Net.Http.HttpClientHandler]::new()
$handler.ServerCertificateCustomValidationCallback=[Net.Http.HttpClientHandler]::DangerousAcceptAnyServerCertificateValidator
$handler.ClientCertificates.Add($cert)|Out-Null
$client=[Net.Http.HttpClient]::new($handler)
$client.Timeout=[TimeSpan]::FromSeconds(5)
$requests=[Collections.Generic.List[Net.Http.StringContent]]::new()
$responses=[Collections.Generic.List[Net.Http.HttpResponseMessage]]::new()
try {
    $endpoint="https://127.0.0.1:$($tls.seguridad)/internal/operations/v1/authorize"
    $attempts=@(foreach($action in @('pivot','abort')){
        $body=@{operation_id=$race.id;action=$action}|ConvertTo-Json -Compress
        $content=[Net.Http.StringContent]::new($body,[Text.Encoding]::UTF8,'application/json')
        $requests.Add($content)
        $client.PostAsync($endpoint,$content)
    })
    foreach($attempt in $attempts){$responses.Add($attempt.GetAwaiter().GetResult())}
    $pivot=[int]$responses[0].StatusCode;$abort=[int]$responses[1].StatusCode
    if(!(($pivot -eq 200 -and $abort -eq 403) -or ($pivot -eq 409 -and $abort -eq 200))){
        throw "Competing authorization decisions returned $pivot/$abort."
    }
} finally {
    [IO.File]::WriteAllText($marker+'.release','')
    foreach($message in $responses){$message.Dispose()}
    foreach($content in $requests){$content.Dispose()}
    $client.Dispose();$cert.Dispose()
}
$expected=if($pivot -eq 200){'Completada'}else{'Compensada'}
& php $driver await-state $race.id $expected
if($LASTEXITCODE -ne 0){throw 'Worker did not respect the winning authorization decision.'}
Write-Output 'F5-S12: concurrent mTLS confirm/abort chose a single durable decision and workers followed it.'
