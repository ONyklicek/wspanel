<#
.SYNOPSIS
  A Hyper-V virtual machine for WSPanel: the best way on Windows (docs/hyper-v.md).

.DESCRIPTION
  Run once in an elevated PowerShell on the Windows host:

    .\New-WSPanelVM.ps1 -Name WSPanel -MemoryGB 4 -Cpus 2 -DiskGB 80

  What it does (idempotent):
    - the Hyper-V role (a restart when it was not there),
    - an external virtual switch on the LAN adapter: the VM gets its own
      address in the LAN (no port shared with Windows, no NAT),
    - the newest Debian 13 netinst ISO, checked against Debian's SHA256SUMS,
    - a generation 2 VM (Secure Boot with the template Debian's shim uses),
      started with Windows, no automatic checkpoints (a checkpoint of a
      running database server is no backup),
    - starts it and opens its console.

  Then install Debian in the console (only "SSH server" and "standard
  system utilities"), and in the VM:

    curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash

  As the mirror's standby: Server → Zrcadlo → Připojit jako zrcadlo
  (docs/zrcadlo.md), the VM's own LAN address on both sides.
#>
param(
    [string] $Name = 'WSPanel',
    [int] $MemoryGB = 4,
    [int] $Cpus = 2,
    [int] $DiskGB = 80,
    [string] $SwitchName = 'WSPanel LAN',
    # The physical adapter of the LAN (default: the one with the default route).
    [string] $NetAdapter = '',
    [string] $Path = ''
)
$ErrorActionPreference = 'Stop'
function Step($m) { Write-Host "==> $m" -ForegroundColor Cyan }

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this in an elevated PowerShell (as administrator).'
}

Step 'Hyper-V'
$server = (Get-CimInstance Win32_OperatingSystem).ProductType -ne 1
if ($server) {
    if ((Get-WindowsFeature -Name Hyper-V).InstallState -ne 'Installed') {
        Install-WindowsFeature -Name Hyper-V -IncludeManagementTools | Out-Null
        Write-Host 'Hyper-V was installed: restart Windows and run this script again.' -ForegroundColor Yellow
        exit 0
    }
} elseif ((Get-WindowsOptionalFeature -Online -FeatureName Microsoft-Hyper-V-All).State -ne 'Enabled') {
    Enable-WindowsOptionalFeature -Online -FeatureName Microsoft-Hyper-V-All -NoRestart | Out-Null
    Write-Host 'Hyper-V was enabled: restart Windows and run this script again.' -ForegroundColor Yellow
    exit 0
}
Import-Module Hyper-V
if (-not $Path) { $Path = (Get-VMHost).VirtualMachinePath }

Step "External switch '$SwitchName'"
if (-not (Get-VMSwitch -Name $SwitchName -ErrorAction SilentlyContinue)) {
    if (-not $NetAdapter) {
        $ifIndex = (Get-NetRoute -DestinationPrefix '0.0.0.0/0' | Sort-Object RouteMetric | Select-Object -First 1).ifIndex
        $NetAdapter = (Get-NetAdapter -InterfaceIndex $ifIndex).Name
    }
    Write-Host "The network of this machine drops for a few seconds while '$NetAdapter' becomes the switch's." -ForegroundColor Yellow
    New-VMSwitch -Name $SwitchName -NetAdapterName $NetAdapter -AllowManagementOS $true | Out-Null
}

Step 'Debian 13 netinst ISO'
$isoDir = 'C:\ProgramData\WSPanel\iso'
New-Item -ItemType Directory -Force -Path $isoDir | Out-Null
$base = 'https://cdimage.debian.org/debian-cd/current/amd64/iso-cd'
$sums = (Invoke-WebRequest -UseBasicParsing "$base/SHA256SUMS").Content
$line = ($sums -split "`n" | Where-Object { $_ -match '^[0-9a-f]{64}\s+(debian-13[\d.]*-amd64-netinst\.iso)$' } | Select-Object -First 1)
if (-not $line) { throw 'No Debian 13 netinst ISO in SHA256SUMS (is "current" still Debian 13?)' }
$hash, $file = $line -split '\s+'
$iso = Join-Path $isoDir $file
if (-not (Test-Path $iso) -or (Get-FileHash -Algorithm SHA256 $iso).Hash.ToLower() -ne $hash) {
    Invoke-WebRequest -UseBasicParsing "$base/$file" -OutFile $iso
    if ((Get-FileHash -Algorithm SHA256 $iso).Hash.ToLower() -ne $hash) { Remove-Item $iso; throw 'The ISO does not match Debian''s SHA256SUMS' }
}

Step "Virtual machine $Name"
if (-not (Get-VM -Name $Name -ErrorAction SilentlyContinue)) {
    $vhd = Join-Path $Path "$Name\$Name.vhdx"
    New-VM -Name $Name -Generation 2 -MemoryStartupBytes ([int64]$MemoryGB * 1GB) -NewVHDPath $vhd -NewVHDSizeBytes ([int64]$DiskGB * 1GB) -SwitchName $SwitchName -Path $Path | Out-Null
    Set-VMProcessor -VMName $Name -Count $Cpus
    # Fixed memory: the databases size their caches by what they see.
    Set-VMMemory -VMName $Name -DynamicMemoryEnabled $false
    # Debian boots through Microsoft's third-party UEFI CA (shim).
    Set-VMFirmware -VMName $Name -EnableSecureBoot On -SecureBootTemplate MicrosoftUEFICertificateAuthority
    Add-VMDvdDrive -VMName $Name -Path $iso
    Set-VMFirmware -VMName $Name -FirstBootDevice (Get-VMDvdDrive -VMName $Name)
    Set-VM -Name $Name -AutomaticStartAction Start -AutomaticStartDelay 30 -AutomaticStopAction ShutDown -AutomaticCheckpointsEnabled $false -CheckpointType Production
    Enable-VMIntegrationService -VMName $Name -Name 'Guest Service Interface'
}
if ((Get-VM -Name $Name).State -ne 'Running') { Start-VM -Name $Name }
Start-Process vmconnect.exe -ArgumentList 'localhost', $Name

Write-Host ''
Write-Host "Done: $Name runs (started with Windows, its own address in the LAN)." -ForegroundColor Green
Write-Host 'In its console: install Debian ("SSH server" + "standard system utilities" only), then in the VM:'
Write-Host '  curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash'
Write-Host "After the installation remove the ISO: Get-VMDvdDrive -VMName $Name | Remove-VMDvdDrive"
