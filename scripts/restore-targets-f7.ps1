$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'multilider-local'){
    throw 'F7 real-copy restoration requires an isolated dataset.'
}
$migration=Join-Path $backend 'scripts/migrate-data.php'
foreach($action in @('plan','apply','verify')){
    & php $migration $action multilider-local origen
    if($LASTEXITCODE -ne 0){throw "F7 real-copy $action did not reconcile. Inspect origen-$action.json."}
}
Copy-Item -LiteralPath (Join-Path $f7Root 'origen-apply.json') -Destination (Join-Path $f7Root 'origen-primera-carga.json')
& php $migration apply multilider-local origen
if($LASTEXITCODE -ne 0){throw 'F7 real-copy replay failed.'}
$replay=Get-Content -LiteralPath (Join-Path $f7Root 'origen-apply.json') -Raw | ConvertFrom-Json -AsHashtable
foreach($stats in $replay.summary.services.Values){
    if($stats.inserted -ne 0 -or $stats.updated -ne 0 -or $stats.removed -ne 0){throw 'F7 replay changed real-copy rows.'}
}
foreach($role in @('propiedades','comercial','finanzas')){
    $prefix=@{propiedades='multilider_properties_f3_test_';comercial='multilider_comercial_f4_test_';finanzas='multilider_finanzas_f5_test_'}[$role]
    $destination=$prefix+[Guid]::NewGuid().ToString('N')
    Set-F5 "MULTILIDER_F5_RESTORE_$($role.ToUpperInvariant())" $destination
    $restoreSql[$role]=$connections[$role] -replace '(?<=Database=)[^;]+',$destination
}
$restoreTool=Join-Path $workspace 'multilider-ms-comercial\tests\FixtureSetup\bin\Debug\net10.0\FixtureSetup.dll'
Push-Location (Join-Path $workspace 'multilider-ms-comercial')
try { & $dotnet $restoreTool verify-restoration-f7 }
finally { Pop-Location }
if($LASTEXITCODE -ne 0){throw 'F7 SQL Server backups could not be restored.'}
$mysql=Join-Path $MySqlBin 'mysql.exe'
$dump=Join-Path $MySqlBin 'mysqldump.exe'
$common=@('--no-defaults','--host=127.0.0.1',"--port=$env:DB_PORT",'--user=root','--skip-password')
$mysqlRestoreArgs=$common
$identityBackup=Join-Path $f7Root 'seguridad.sql'
& $dump @common --single-transaction --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF "--result-file=$identityBackup" multilider_identity_tests_isolated
if($LASTEXITCODE -ne 0){throw 'F7 Seguridad backup failed.'}
$identityClone='multilider_f5_restore_'+[Guid]::NewGuid().ToString('N')
$restoreMySql.seguridad=$identityClone
& $mysql @common "--execute=CREATE DATABASE $identityClone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $identityClone.* TO 'identity_test'@'127.0.0.1';"
if($LASTEXITCODE -ne 0){throw 'F7 Seguridad restore target failed.'}
$passwordBefore=[Environment]::GetEnvironmentVariable('MYSQL_PWD','Process')
try {
    $env:MYSQL_PWD=$env:MULTILIDER_IDENTITY_PASSWORD
    $options=@{FilePath=$mysql;ArgumentList=@('--no-defaults','--host=127.0.0.1',"--port=$env:DB_PORT",
        '--user=identity_test',"--database=$identityClone",'--binary-mode');RedirectStandardInput=$identityBackup
        RedirectStandardError=(Join-Path $f7Root 'restauracion-seguridad.err');WindowStyle='Hidden';PassThru=$true;Wait=$true}
    $process=Start-Process @options
    if($process.ExitCode -ne 0){throw 'F7 Seguridad restore failed.'}
} finally {
    [Environment]::SetEnvironmentVariable('MYSQL_PWD',$passwordBefore,'Process')
}
Set-F5 'MULTILIDER_F7_RESTORED_IDENTITY' $identityClone
Set-F5 'MULTILIDER_IDENTITY_DATABASE' $identityClone
foreach($role in @('propiedades','comercial','finanzas')){
    $name=@{propiedades='PROPERTIES';comercial='COMERCIAL';finanzas='FINANZAS'}[$role]
    Set-F5 ('MULTILIDER_'+$name+'_TEST_CONNECTION') $restoreSql[$role]
}
& php $migration verify multilider-local origen
if($LASTEXITCODE -ne 0){throw 'F7 restored authorities differ from the migration.'}
$backupFiles=@((Join-Path $f7Root 'respaldo.sql'),$identityBackup)
foreach($connection in $connections.Values){
    $database=[regex]::Match($connection,'(?<=Database=)[^;]+').Value
    $backupFiles+=Join-Path $f7Root ($database+'.bak')
}
foreach($folder in @('origen','catalog','photos','documents','laravel/app/public')){
    $assetRoot=Join-Path $f7Root $folder
    if(Test-Path -LiteralPath $assetRoot){$backupFiles+=@(Get-ChildItem -LiteralPath $assetRoot -File -Recurse | Select-Object -ExpandProperty FullName)}
}
$files=@(Get-Item -LiteralPath $backupFiles | ForEach-Object {
    @{file=$_.FullName.Substring($f7Root.Length+1);sha256=(Get-FileHash -LiteralPath $_.FullName).Hash.ToLowerInvariant()}
})
@{dataset='multilider-local';checkpoint=(Get-Date).ToUniversalTime().ToString('o');files=$files} | ConvertTo-Json -Depth 4 |
    Set-Content -LiteralPath (Join-Path $f7Root 'checkpoint-autoridades.json') -Encoding utf8NoBOM
Write-Output "F7 real copy and restored authority checkpoints verified in $f7Root"
