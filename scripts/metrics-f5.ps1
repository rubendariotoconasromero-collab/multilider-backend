$database=[regex]::Match($connections.comercial,'(?<=Database=)[^;]+').Value
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $database -notmatch '^multilider_comercial_f4_test_[a-f0-9]{32}$'){
    throw 'Unsafe outbox audit target.'
}
$cert=[Security.Cryptography.X509Certificates.X509Certificate2]::new($certs.comercial,$password)
try {
    $uri="https://127.0.0.1:$($tls.comercial)/internal/comercial/v1/metricas-operaciones"
    $anonymous=Invoke-WebRequest $uri -SkipCertificateCheck -SkipHttpErrorCheck -TimeoutSec 5
    $scrape=Invoke-WebRequest $uri -SkipCertificateCheck -Certificate $cert -TimeoutSec 5
    if([int]$anonymous.StatusCode -ne 403 -or [int]$scrape.StatusCode -ne 200 -or "$($scrape.Headers['Cache-Control'])" -notmatch 'no-store'){
        throw 'Operation metrics were public, cached or unavailable.'
    }
    $body=$scrape.Content|ConvertFrom-Json
    if(@($body.ventas|Where-Object {$_.state -eq 'Completada' -and $_.count -ge 1}).Count -ne 1 -or
       @($body.perfiles|Where-Object {$_.state -eq 'Completada' -and $_.count -ge 1}).Count -ne 1 -or
       @($body.perfiles|Where-Object {$_.state -ne 'Completada' -and $_.count -ge 1 -and $_.oldest_update_seconds -ge 0}).Count -lt 1){
        throw 'Metrics do not reflect persisted sale and profile operations.'
    }
    $row=& sqlcmd -S localhost -d $database -E -C -b -h -1 -W -l 5 -t 8 -Q "SET NOCOUNT ON; SELECT TOP 1 CONVERT(varchar(36),s.Id) FROM dbo.SaleOperation s JOIN dbo.Ventas v ON v.Id=s.VentaId WHERE s.Kind='venta' AND s.State='Completada' AND v.Estado='Activa' ORDER BY s.CreatedAt DESC;"
    if($LASTEXITCODE -ne 0){throw 'Could not identify a persisted sale to trace.'}
    $operation=[Guid]::ParseExact("$row".Trim(),'D')
    $query=@{operation_id=$operation;action='read'}|ConvertTo-Json -Compress
    $grant=Invoke-RestMethod "https://127.0.0.1:$($tls.seguridad)/internal/operations/v1/authorize" -Method Post -Body $query -ContentType 'application/json' -SkipCertificateCheck -Certificate $cert -TimeoutSec 5
    $reservation=Invoke-RestMethod "https://127.0.0.1:$($tls.propiedades)/internal/catalogo/v2/operaciones/$operation/reserva" -SkipCertificateCheck -Certificate $cert -TimeoutSec 5
    $account=Invoke-RestMethod "https://127.0.0.1:$($tls.finanzas)/internal/finanzas/v1/operaciones/$operation" -SkipCertificateCheck -Certificate $cert -TimeoutSec 5
    if([Guid]$grant.operation_id -ne $operation -or [Guid]$reservation.operacion_id -ne $operation -or [Guid]$account.id -ne $operation -or
        $reservation.propiedad_id -ne $grant.resource_id -or $account.propiedad_id -ne $grant.resource_id){
        throw 'The UUID or resource does not correlate across the authorities.'
    }
} finally { $cert.Dispose() }
$query=@'
SET NOCOUNT ON;
IF EXISTS (SELECT 1 FROM dbo.ProfileOperation WHERE JSON_VALUE(Payload,'$.ci') IS NOT NULL OR JSON_VALUE(Payload,'$.password') IS NOT NULL OR JSON_VALUE(Payload,'$.correo') IS NOT NULL)
    THROW 51000,'Sensitive profile data persisted in outbox',1;
IF EXISTS (SELECT 1 FROM dbo.OperationStep WHERE Result LIKE '%F5-CLIENT-NEW%' OR Result LIKE '%BEGIN PRIVATE KEY%' OR Result LIKE '%Bearer %')
    THROW 51000,'Sensitive data persisted in a step receipt',1;
'@
& sqlcmd -S localhost -d $database -E -C -b -l 5 -t 10 -Q $query
if($LASTEXITCODE -ne 0){throw 'Outbox privacy audit failed.'}
$needles=@($key,$password,$server.password,'Test-only-Password-42!','F5-CLIENT-NEW','BEGIN PRIVATE KEY')
foreach($file in (Get-ChildItem -LiteralPath $runtime -File | Where-Object {
    $_.Name -match '^f5-(seguridad|propiedades|comercial|finanzas|laravel)(-second|-local|-local-second|-payment-recovered|-attachment-recovered)?\.(err|out)\.log$'
})){
    $stream=[IO.File]::Open($file.FullName,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite)
    $reader=[IO.StreamReader]::new($stream)
    try {$content=$reader.ReadToEnd()} finally {$reader.Dispose()}
    foreach($needle in $needles){
        if($needle -and $content.Contains($needle)){throw "Secret canary appeared in $($file.Name)."}
    }
}
Write-Output 'F5-A12/O08: protected metrics reflect SQL state; isolated service logs and durable receipts passed secret canary checks.'
