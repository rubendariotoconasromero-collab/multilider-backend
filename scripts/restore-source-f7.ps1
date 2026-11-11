$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:DB_DATABASE -ne 'multilider_tests_isolated' -or $env:DB_PORT -eq '3306'){
    throw 'F7 source restoration requires the isolated MySQL runner.'
}
$mysql=Join-Path $MySqlBin 'mysql.exe'
$common=@('--no-defaults','--host=127.0.0.1',"--port=$env:DB_PORT")
$rootOptions=$common+@('--user=root','--skip-password')
$dump=Join-Path $f7Root 'respaldo.sql'
$manifest=Get-Content -LiteralPath ($dump+'.json') -Raw | ConvertFrom-Json
if($manifest.source -ne 'db_multilider' -or (Get-FileHash -LiteralPath $dump).Hash -ine $manifest.sha256){
    throw 'The local MySQL backup does not match its manifest.'
}
$port=& $mysql @rootOptions --batch --skip-column-names '--execute=SELECT @@port'
if($LASTEXITCODE -ne 0 -or "$port" -ne $env:DB_PORT){throw 'The restore target is not the isolated MySQL process.'}
& $mysql @rootOptions '--execute=DROP DATABASE IF EXISTS multilider_tests_isolated; CREATE DATABASE multilider_tests_isolated CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
if($LASTEXITCODE -ne 0){throw 'Could not prepare the isolated source schema.'}
$passwordBefore=[Environment]::GetEnvironmentVariable('MYSQL_PWD','Process')
try {
    $env:MYSQL_PWD=$env:DB_PASSWORD
    $options=@{FilePath=$mysql;ArgumentList=$common+@('--user=multilider_test','--database=multilider_tests_isolated','--binary-mode')
        RedirectStandardInput=$dump;RedirectStandardError=(Join-Path $f7Root 'restauracion-origen.err')
        WindowStyle='Hidden';PassThru=$true;Wait=$true}
    $process=Start-Process @options
    if($process.ExitCode -ne 0){throw 'MySQL could not restore the source. Inspect the private restore log.'}
} finally {
    [Environment]::SetEnvironmentVariable('MYSQL_PWD',$passwordBefore,'Process')
}
& php (Join-Path $backend 'scripts/migrate-data.php') verify-source multilider-local origen
if($LASTEXITCODE -ne 0){throw 'The real source did not reproduce its captured tables and files.'}
& php (Join-Path $backend 'tests/Support/IntegrationFixture.php') phase7-real
if($LASTEXITCODE -ne 0){throw 'The isolated Laravel and Seguridad schemas could not be prepared.'}
