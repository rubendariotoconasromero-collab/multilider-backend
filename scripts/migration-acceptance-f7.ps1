$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'isolated-f7'){
    throw 'F7 requires the isolated migration runner.'
}
$migration=Join-Path $backend 'scripts/migrate-data.php'
function Invoke-F7([string]$action,[string]$label,[string]$previous,[int]$expected=0) {
    if($previous){ & php $migration $action isolated-f7 $label $previous }
    else { & php $migration $action isolated-f7 $label }
    if($LASTEXITCODE -ne $expected){
        Write-Output "F7 diagnostic reports: $f7Root"
        throw "F7 $action $label returned $LASTEXITCODE, expected $expected."
    }
}
Invoke-F7 capture inicial
Invoke-F7 plan inicial
$collision=Join-Path $f7Root 'colision'
Copy-Item -LiteralPath (Join-Path $f7Root 'inicial') -Destination $collision -Recurse
$identityRows=Join-Path $collision 'users.jsonl'
$lines=@(Get-Content -LiteralPath $identityRows)
$first=$lines[0] | ConvertFrom-Json -AsHashtable
$first.correo='native-f7@example.test'
$lines[0]=$first | ConvertTo-Json -Compress
$lines | Set-Content -LiteralPath $identityRows -Encoding utf8NoBOM
$manifestPath=Join-Path $collision 'manifest.json'
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json -AsHashtable
$manifest.tables.users.sha256=(Get-FileHash -LiteralPath $identityRows).Hash.ToLowerInvariant()
$manifest | ConvertTo-Json -Depth 12 -Compress | Set-Content -LiteralPath $manifestPath -Encoding utf8NoBOM
Invoke-F7 plan colision '' 2
$issues=(Get-Content -LiteralPath (Join-Path $f7Root 'colision-plan.json') -Raw | ConvertFrom-Json).issues
if(-not ($issues | Where-Object { $_.entity -eq 'users' -and $_.reason -like 'Colisión*' })){
    throw 'F7 did not identify an unmapped identity collision.'
}
Set-F5 'MULTILIDER_F7_PAUSE_BEFORE_COMMIT' '1'
Invoke-F7 apply inicial '' 75
Set-F5 'MULTILIDER_F7_PAUSE_BEFORE_COMMIT' '0'
Invoke-F7 plan inicial
$rolledBack=Get-Content (Join-Path $f7Root 'inicial-plan.json') -Raw | ConvertFrom-Json -AsHashtable
foreach($stats in $rolledBack.summary.services.Values){
    if($stats.unchanged -ne 0){throw 'The uncommitted test row survived rollback.'}
}
Set-F5 'MULTILIDER_F7_PAUSE_AFTER' '12'
Invoke-F7 apply inicial '' 75
Set-F5 'MULTILIDER_F7_PAUSE_AFTER' '0'
Invoke-F7 apply inicial
Invoke-F7 verify inicial
Invoke-F7 apply inicial
$replay=Get-Content (Join-Path $f7Root 'inicial-apply.json') -Raw | ConvertFrom-Json -AsHashtable
foreach($stats in $replay.summary.services.Values){
    if($stats.inserted -ne 0 -or $stats.updated -ne 0 -or $stats.removed -ne 0){throw 'F7 replay wrote new rows.'}
}
Write-Output 'F7 base verified and replayed without duplicate writes.'
$backup=Join-Path $f7Root 'checkpoint'
Copy-Item -LiteralPath (Join-Path $f7Root 'inicial') -Destination $backup -Recurse
Invoke-F7 verify checkpoint
$bad=Join-Path $f7Root 'alterado'
Copy-Item -LiteralPath $backup -Destination $bad -Recurse
Add-Content -LiteralPath (Join-Path $bad 'users.jsonl') -Value 'x'
Invoke-F7 plan alterado '' 1
& php (Join-Path $backend 'tests/Support/IntegrationFixture.php') phase7-delta
if($LASTEXITCODE -ne 0){throw 'Could not prepare isolated delta.'}
Invoke-F7 capture delta
Invoke-F7 plan delta '' 2
Invoke-F7 plan delta inicial
Invoke-F7 apply delta inicial
$applied=Get-Content (Join-Path $f7Root 'delta-apply.json') -Raw | ConvertFrom-Json -AsHashtable
if($applied.summary.services.comercial.updated -ne 0 -or $applied.summary.services.finanzas.updated -ne 0){
    throw 'F7 did not preserve historical sale snapshots.'
}
Invoke-F7 verify delta inicial
Invoke-F7 apply delta inicial
$replay=Get-Content (Join-Path $f7Root 'delta-apply.json') -Raw | ConvertFrom-Json -AsHashtable
foreach($stats in $replay.summary.services.Values){
    if($stats.inserted -ne 0 -or $stats.updated -ne 0 -or $stats.removed -ne 0){throw 'F7 delta replay changed the target.'}
}
Write-Output 'F7 verified delta inserts, edits, revocations and retired image without replaying writes.'
