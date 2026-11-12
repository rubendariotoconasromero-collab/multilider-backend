param([Parameter(Mandatory)][string]$Source,[Parameter(Mandatory)][string]$TestingRoot)
$ErrorActionPreference='Stop'
$manifestPath=Join-Path $Source 'checkpoint-autoridades.json'
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if($manifest.dataset -ne 'multilider-local' -or !$manifest.files){throw 'Checkpoint F7 inválido.'}
$copy=Join-Path $TestingRoot ('f7-'+[Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $copy | Out-Null
foreach($item in $manifest.files){
    $path=[IO.Path]::GetFullPath((Join-Path $Source $item.file))
    if(!$path.StartsWith($Source+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -or
        (Get-FileHash -LiteralPath $path).Hash -ine $item.sha256){
        throw 'Un archivo del checkpoint de F7 no coincide con su huella.'
    }
    $target=Join-Path $copy $item.file
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
    Copy-Item -LiteralPath $path -Destination $target
    if((Get-FileHash -LiteralPath $target).Hash -ine $item.sha256){throw 'La copia de F7 difiere del manifiesto.'}
}
Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $copy 'checkpoint-origen-f8.json')
Copy-Item -LiteralPath (Join-Path $Source 'respaldo.sql.json') -Destination $copy
return $copy
