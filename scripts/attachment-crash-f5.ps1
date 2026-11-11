$driver=Join-Path $backend 'tests/Support/PhaseFiveAttachmentCrash.php'
$documentDir=$env:Storage__Path
if($env:MULTILIDER_TEST_ISOLATED -ne '1' -or
    $documentDir -ne (Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR 'documents')){
    throw 'An isolated attachment folder is required.'
}
$raw=& php $driver prepare
if($LASTEXITCODE -ne 0){throw 'Could not prepare the attachment crash fixture.'}
$exercise=$raw|ConvertFrom-Json
$command=[Guid]::ParseExact($exercise.command,'D')
$marker=Join-Path $env:MULTILIDER_F5_BOUNDARY_DIR ($command.ToString('N')+'-Adjunto-before')
if($marker -ne $exercise.marker -or $exercise.contract -le 0){throw 'Invalid attachment fixture.'}
$existing=@(Get-ChildItem -LiteralPath $documentDir -File).Name
$writer=Start-F5 'f5-attachment-writer' (Get-Command php).Source @($driver,'write',"$($exercise.contract)",$command.ToString('D')) $backend
$deadline=[DateTime]::UtcNow.AddSeconds(15)
while(!(Test-Path -LiteralPath ($marker+'.hit')) -and [DateTime]::UtcNow -lt $deadline -and !$writer.HasExited){
    Start-Sleep -Milliseconds 100
}
if(!(Test-Path -LiteralPath ($marker+'.hit')) -or (Get-Content -LiteralPath ($marker+'.hit') -Raw) -ne "$($started.comercial.Id)"){
    throw 'Attachment was not paused at the owned SQL transaction boundary.'
}
$orphan=@(Get-ChildItem -LiteralPath $documentDir -File | Where-Object Name -NotIn $existing)
if($orphan.Count -ne 1 -or $orphan[0].Name -notmatch '^[a-f0-9]{32}\.pdf$'){throw 'Expected one new private PDF before the SQL commit.'}
Stop-OwnedF5 $started.comercial
if(!$writer.WaitForExit(20000) -or $writer.ExitCode -ne 0){throw 'Attachment writer did not observe the process loss.'}
$processes.Remove($writer)|Out-Null
Restart-F5 comercial 'attachment-recovered'
& php $driver verify "$($exercise.contract)" $command.ToString('D')
if($LASTEXITCODE -ne 0){throw 'Attachment was not recovered after the cut.'}
[IO.File]::SetLastWriteTimeUtc($orphan[0].FullName,[DateTime]::UtcNow.AddDays(-3))
Set-F5 'Migrations__DatabaseName' ([regex]::Match($connections.comercial,'(?<=Database=)[^;]+').Value)
$preview=& $dotnet $launches.comercial.args[0] --inspect-document-orphans
if($LASTEXITCODE -ne 0 -or $preview -notmatch 'eligible: 1; removed: 0'){throw 'Dry run did not identify the old orphan.'}
$clean=& $dotnet $launches.comercial.args[0] --remove-document-orphans
if($LASTEXITCODE -ne 0 -or $clean -notmatch 'eligible: 1; removed: 1' -or (Test-Path -LiteralPath $orphan[0].FullName)){
    throw 'Attachment cleanup did not remove the orphan.'
}
foreach($name in $existing){
    if(!(Test-Path -LiteralPath (Join-Path $documentDir $name))){throw 'Cleanup removed a committed revision.'}
}
Write-Output 'F5-D04: owned Comercial killed before SQL commit; original and committed revisions preserved; aged orphan removed.'
