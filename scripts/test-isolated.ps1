param(
    [string]$MySqlBin = 'C:\Program Files\MySQL\MySQL Server 8.0\bin',
    [switch]$Integration,
    [switch]$BrowserTests,
    [switch]$IntegrationOnly,
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$TestArguments
)
$ErrorActionPreference = 'Stop'
if ($IntegrationOnly -and !$Integration) { throw '-IntegrationOnly requiere -Integration.' }
$project = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $project 'storage\framework\testing'
$data = Join-Path $runtime 'mysql'
New-Item -ItemType Directory -Force $data | Out-Null
$server = Join-Path $MySqlBin 'mysqld.exe'
$client = Join-Path $MySqlBin 'mysql.exe'
if (!(Test-Path $server)) { throw 'Indica -MySqlBin con MySQL 8 instalado.' }
if (!(Test-Path (Join-Path $data 'mysql'))) {
    & $server --no-defaults --initialize-insecure "--datadir=$data" "--log-error=$runtime\initialize.log"
    if ($LASTEXITCODE -ne 0) { throw 'Falló la inicialización aislada; consultar initialize.log.' }
}
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
$process = Start-Process -FilePath $server -ArgumentList @('--no-defaults', "--datadir=`"$data`"", "--port=$port", '--bind-address=127.0.0.1', '--mysqlx=OFF', "--log-error=`"$runtime\mysql.log`"") -WindowStyle Hidden -PassThru
$old = @{}
try {
    $ready = $false
    for ($i = 0; $i -lt 80; $i++) {
        if ($process.HasExited) { throw 'El MySQL aislado terminó; consultar mysql.log.' }
        & $client --no-defaults --host=127.0.0.1 "--port=$port" --user=root --skip-password --execute='SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (!$ready) { throw 'El MySQL aislado no respondió.' }
    $password = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    $sql = "CREATE DATABASE IF NOT EXISTS multilider_tests_isolated; DROP USER IF EXISTS 'multilider_test'@'127.0.0.1'; CREATE USER 'multilider_test'@'127.0.0.1' IDENTIFIED BY '$password'; GRANT ALL PRIVILEGES ON multilider_tests_isolated.* TO 'multilider_test'@'127.0.0.1';"
    $sql | & $client --no-defaults --host=127.0.0.1 "--port=$port" --user=root --skip-password
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la base exclusiva.' }
    $identityPassword = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    if ($Integration) {
        "DROP DATABASE IF EXISTS multilider_identity_tests_isolated; CREATE DATABASE multilider_identity_tests_isolated; DROP DATABASE IF EXISTS multilider_identity_fresh_tests_isolated; CREATE DATABASE multilider_identity_fresh_tests_isolated; DROP USER IF EXISTS 'identity_test'@'127.0.0.1'; CREATE USER 'identity_test'@'127.0.0.1' IDENTIFIED BY '$identityPassword'; GRANT ALL PRIVILEGES ON multilider_identity_tests_isolated.* TO 'identity_test'@'127.0.0.1'; GRANT ALL PRIVILEGES ON multilider_identity_fresh_tests_isolated.* TO 'identity_test'@'127.0.0.1';" | & $client --no-defaults --host=127.0.0.1 "--port=$port" --user=root --skip-password
        if ($LASTEXITCODE -ne 0) { throw 'Falló la preparación SQL de integración.' }
    }
    $vars = @{
        APP_ENV='testing'; APP_KEY=('base64:' + [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))
        APP_ARCHITECTURE='local'; MICROSERVICE_SYNC_ENABLED='false'
        DB_CONNECTION='mysql'; DB_HOST='127.0.0.1'; DB_PORT="$port"; DB_DATABASE='multilider_tests_isolated'; DB_USERNAME='multilider_test'; DB_PASSWORD=$password
        MULTILIDER_TEST_PORT="$port"; MULTILIDER_TEST_ISOLATED='1'
        APP_CONFIG_CACHE='storage/framework/testing/config.php'; APP_ROUTES_CACHE='storage/framework/testing/routes.php'; APP_SERVICES_CACHE='storage/framework/testing/services.php'; APP_PACKAGES_CACHE='storage/framework/testing/packages.php'; APP_EVENTS_CACHE='storage/framework/testing/events.php'; DATABASE_URL=''
        CACHE_DRIVER='array'; SESSION_DRIVER='array'; MAIL_MAILER='array'; QUEUE_CONNECTION='sync'; LOG_CHANNEL='null'
        MULTILIDER_IDENTITY_USER='identity_test'; MULTILIDER_IDENTITY_PASSWORD=$identityPassword; MULTILIDER_IDENTITY_DATABASE='multilider_identity_tests_isolated'
    }
    # Private, ignored file for reproducing the run; never commit or print it.
    $vars.GetEnumerator() | ForEach-Object { "$($_.Key)=`"$($_.Value.Replace('\','/'))`"" } | Set-Content (Join-Path $project '.env.testing')
    foreach ($key in $vars.Keys) { $old[$key] = [Environment]::GetEnvironmentVariable($key, 'Process'); [Environment]::SetEnvironmentVariable($key, $vars[$key], 'Process') }
    Push-Location $project
    try {
        $result = 0
        if (!$IntegrationOnly) {
            & php vendor/phpunit/phpunit/phpunit --log-junit "$runtime\junit.xml" @TestArguments
            $result = $LASTEXITCODE
        }
        if ($result -eq 0 -and $Integration) {
            & (Join-Path $PSScriptRoot 'integration-f3.ps1') -BrowserTests:$BrowserTests
            $result = $LASTEXITCODE
        }
    } finally { Pop-Location }
} finally {
    foreach ($key in $old.Keys) { [Environment]::SetEnvironmentVariable($key, $old[$key], 'Process') }
    if (!$process.HasExited) {
        & $client --no-defaults --host=127.0.0.1 "--port=$port" --user=root --skip-password --execute=SHUTDOWN 2>$null | Out-Null
        if (!$process.WaitForExit(10000)) { Stop-Process -Id $process.Id }
    }
}
exit $result
