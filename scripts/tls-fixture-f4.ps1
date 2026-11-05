param([Parameter(Mandatory)][string]$Directory)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath($Directory)
if($root -ne [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\storage\framework\testing'))){throw 'Unsafe TLS fixture directory.'}
$password=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
$metadata=@{password=$password}
foreach($kind in @('server','client')) {
    $rsa=[Security.Cryptography.RSA]::Create(2048)
    $request=[Security.Cryptography.X509Certificates.CertificateRequest]::new("CN=localhost F4 $kind",$rsa,[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
    $eku=[Security.Cryptography.OidCollection]::new()
    $oid=if($kind -eq 'server'){'1.3.6.1.5.5.7.3.1'}else{'1.3.6.1.5.5.7.3.2'}
    $eku.Add([Security.Cryptography.Oid]::new($oid)) | Out-Null
    $request.CertificateExtensions.Add([Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension]::new($eku,$true))
    if($kind -eq 'server') {
        $san=[Security.Cryptography.X509Certificates.SubjectAlternativeNameBuilder]::new()
        $san.AddDnsName('localhost');$san.AddIpAddress([Net.IPAddress]::Loopback)
        $request.CertificateExtensions.Add($san.Build())
    }
    $cert=$request.CreateSelfSigned([DateTimeOffset]::UtcNow.AddMinutes(-5),[DateTimeOffset]::UtcNow.AddDays(1))
    $pfx=Join-Path $root "f4-$kind.pfx";$pem=Join-Path $root "f4-$kind.pem"
    [IO.File]::WriteAllBytes($pfx,$cert.Export([Security.Cryptography.X509Certificates.X509ContentType]::Pfx,$password))
    [IO.File]::WriteAllText($pem,$cert.ExportCertificatePem())
    [IO.File]::WriteAllText((Join-Path $root "f4-$kind-key.pem"),$rsa.ExportPkcs8PrivateKeyPem())
    $metadata["${kind}_path"]=$pfx;$metadata["${kind}_pem"]=$pem
    $metadata["${kind}_key"]=(Join-Path $root "f4-$kind-key.pem")
    $metadata["${kind}_pin"]=$cert.GetCertHashString([Security.Cryptography.HashAlgorithmName]::SHA256)
    $cert.Dispose();$rsa.Dispose()
}
$metadata | ConvertTo-Json | Set-Content (Join-Path $root 'tls-f4.json')
'Isolated TLS fixtures prepared; system certificate stores unchanged.'
