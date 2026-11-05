$ErrorActionPreference='Stop'
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or $env:Operations__Dataset -ne 'isolated-f5'){throw 'Isolated F5 rotation required.'}

function New-F5RotationCertificate([string]$role,[string]$purpose,[bool]$expired=$false) {
    $rsa=[Security.Cryptography.RSA]::Create(2048)
    try {
        $request=[Security.Cryptography.X509Certificates.CertificateRequest]::new("CN=F5-rotation-$role-$purpose",$rsa,[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
        $serverLeaf=$purpose -eq 'server'
        $oid=if($serverLeaf){'1.3.6.1.5.5.7.3.1'}else{'1.3.6.1.5.5.7.3.2'}
        $eku=[Security.Cryptography.OidCollection]::new()
        $eku.Add([Security.Cryptography.Oid]::new($oid))|Out-Null
        $request.CertificateExtensions.Add([Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension]::new($eku,$true))
        if($serverLeaf){
            $san=[Security.Cryptography.X509Certificates.SubjectAlternativeNameBuilder]::new()
            $san.AddIpAddress([Net.IPAddress]::Loopback)
            $request.CertificateExtensions.Add($san.Build())
        }
        $now=[DateTimeOffset]::UtcNow
        $leaf=if($expired){$request.CreateSelfSigned($now.AddDays(-2),$now.AddDays(-1))}else{$request.CreateSelfSigned($now.AddMinutes(-5),$now.AddDays(1))}
        try {
            $path=Join-Path $runtime ("f5-rotation-$role-$purpose-"+[Guid]::NewGuid().ToString('N')+'.pfx')
            [IO.File]::WriteAllBytes($path,$leaf.Export([Security.Cryptography.X509Certificates.X509ContentType]::Pfx,$password))
            @{file=$path;pin=$leaf.GetCertHashString([Security.Cryptography.HashAlgorithmName]::SHA256)}
        } finally { $leaf.Dispose() }
    } finally { $rsa.Dispose() }
}

function Restart-F5Rotation([string]$name,[string]$stage) {
    Stop-OwnedF5 $started[$name]
    Restart-F5 $name "rotation-$stage"
}

function Assert-F5RotationBusiness([string]$stage) {
    $result=& php (Join-Path $backend 'tests/Support/PhaseFiveRotationBusiness.php')
    if($LASTEXITCODE -ne 0 -or "$result" -notmatch '^[a-f0-9-]{36}$'){throw "Business flow failed during rotation: $stage"}
    Write-Output "F5-A10: coordinated sale, financial obligation and Laravel local verified during $stage."
    $script:rotationOperation=$result
}

function Assert-F5RotationPeers([bool]$oldAccepted,[bool]$newAccepted,[bool]$probeExpired) {
    $probes=@(foreach($target in $roles){
        $caller=$allowedCaller[$target]
        $presentation=$presentedServerPin[$target]
        @{target=$target;port=$tls[$target];caller='old';certificate=$oldClient[$caller];allowed=$oldAccepted;serverPin=$presentation;operation=$rotationOperation}
        @{target=$target;port=$tls[$target];caller='new';certificate=$replacementClient[$caller].file;allowed=$newAccepted;serverPin=$presentation;operation=$rotationOperation}
        if($probeExpired){
            @{target=$target;port=$tls[$target];caller='expired';certificate=$expiredClient[$caller].file;allowed=$false;serverPin=$presentation;operation=$rotationOperation}
        }
    })
    Set-F5 'MULTILIDER_F5_ROTATION_PROBES' (ConvertTo-Json -InputObject $probes -Compress)
    & node (Join-Path $backend 'tests/Support/phase-five-rotation-probe.mjs')
    if($LASTEXITCODE -ne 0){throw 'Real TLS peer rotation probe failed.'}
}

$oldClient=$certs.Clone()
$replacementClient=@{};$expiredClient=@{};$replacementServer=@{};$presentedServerPin=@{}
$allowedCaller=@{seguridad='propiedades';propiedades='comercial';comercial='finanzas';finanzas='comercial'}
foreach($role in $roles){
    $replacementClient[$role]=New-F5RotationCertificate $role 'client'
    $expiredClient[$role]=New-F5RotationCertificate $role 'expired-client' $true
    $replacementServer[$role]=New-F5RotationCertificate $role 'server'
    $presentedServerPin[$role]=$server.server_pin
}
Assert-F5RotationBusiness 'original trust'
Assert-F5RotationPeers $true $false $false

$commercialCert=[Security.Cryptography.X509Certificates.X509Certificate2]::new($oldClient.comercial,$password)
try {
    $query=@{operation_id=$rotationOperation;action='read'}|ConvertTo-Json -Compress
    $authority="https://127.0.0.1:$($tls.seguridad)"
    $options=@{Method='Post';SkipCertificateCheck=$true;SkipHttpErrorCheck=$true;Certificate=$commercialCert;ContentType='application/json';TimeoutSec=5}
    $granted=Invoke-WebRequest "$authority/internal/operations/v1/authorize" @options -Body $query
    if([int]$granted.StatusCode -ne 200){throw 'Grant missing before cross-dataset test.'}
    $grant=$granted.Content|ConvertFrom-Json
    $session=Invoke-RestMethod "$env:PHASE5_API/api/login" -Method Post -ContentType 'application/json' -Body '{"correo":"operator@example.test","password":"Test-only-Password-42!"}' -TimeoutSec 5
    $changed=@{operation_id=$rotationOperation;kind='venta';resource_id=$grant.resource_id;payload_hash=('0'*64)}|ConvertTo-Json -Compress
    $conflict=Invoke-WebRequest "$authority/api/auth/operaciones" @options -Headers @{Authorization="Bearer $($session.access_token)"} -Body $changed
    if([int]$conflict.StatusCode -ne 409){throw 'Existing operation accepted a changed payload hash.'}
    $foreignHttp=Port-F5;$foreignTls=Port-F5
    Set-F5Role 'seguridad'
    Set-F5 'Operations__Dataset' 'isolated-f5-other'
    $launch=$launches.seguridad
    $foreign=Start-F5 'f5-seguridad-foreign-dataset' $launch.file @($launch.args[0],'--urls',"http://127.0.0.1:$foreignHttp;https://127.0.0.1:$foreignTls") $launch.folder
    try {
        Wait-F5 "http://127.0.0.1:$foreignHttp/api/auth/me" 401
        $denied=Invoke-WebRequest "https://127.0.0.1:$foreignTls/internal/operations/v1/authorize" @options -Body $query
        if([int]$denied.StatusCode -ne 403){throw 'A grant from the original dataset was accepted by another dataset.'}
    } finally {
        Stop-OwnedF5 $foreign
        Set-F5 'Operations__Dataset' 'isolated-f5'
    }
    $replayed=Invoke-WebRequest "$authority/internal/operations/v1/authorize" @options -Body $query
    if([int]$replayed.StatusCode -ne 200 -or ($replayed.Content|ConvertFrom-Json).payload_hash -ne $grant.payload_hash){throw 'Negative requests changed the original grant.'}
    Write-Output 'F5-A04: real mTLS rejected altered payload (409) and a foreign dataset (403); original grant stayed intact.'
} finally { $commercialCert.Dispose() }

foreach($role in $roles){
    Set-F5 "Operations__Callers__$($role)__CertificateSha256" "$($pins[$role]),$($replacementClient[$role].pin),$($expiredClient[$role].pin)"
    Set-F5 "Operations__Peers__$($role)__ServerCertificateSha256" "$($server.server_pin),$($replacementServer[$role].pin)"
}
foreach($role in $roles+@('comercial-second')){Restart-F5Rotation $role 'overlap'}
Assert-F5RotationPeers $true $true $true

foreach($role in $roles){
    $certs[$role]=$replacementClient[$role].file
    $serverCerts[$role]=$replacementServer[$role].file
    $serverPasswords[$role]=$password
    $presentedServerPin[$role]=$replacementServer[$role].pin
    Restart-F5Rotation $role 'replacement'
    if($role -eq 'comercial'){Restart-F5Rotation 'comercial-second' 'replacement'}
    Assert-F5RotationBusiness "replacement of $role"
}
Assert-F5RotationPeers $true $true $true

foreach($role in $roles){
    Set-F5 "Operations__Callers__$($role)__CertificateSha256" $replacementClient[$role].pin
    Set-F5 "Operations__Peers__$($role)__ServerCertificateSha256" $replacementServer[$role].pin
}
foreach($role in $roles+@('comercial-second')){Restart-F5Rotation $role 'retired'}
Assert-F5RotationBusiness 'retirement of original pins'
Assert-F5RotationPeers $false $true $false
Write-Output 'F5-A10: all four server certificates and all four client identities rotated with overlapping pins; retired and expired credentials were rejected.'
