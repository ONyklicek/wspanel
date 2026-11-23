<#
.SYNOPSIS
  WSPanel on Windows in WSL2: a native Debian 13 installation (no Docker).

.DESCRIPTION
  Run once in an elevated PowerShell, as the Windows user the server runs
  under (WSL distributions belong to a user):

    .\Install-WSPanelWsl.ps1 -PanelDomain panel.firma.local -AdminEmail admin@firma.local

  What it does (idempotent):
    - WSL itself (wsl --install --no-distribution) and its newest version,
    - %UserProfile%\.wslconfig: mirrored networking (the distribution has
      the Windows host's own addresses: no NAT, real client addresses, the
      LAN reaches its ports directly) and no idle shutdown,
    - the distribution (Debian, upgraded to 13 "trixie" when older) with
      systemd and root as its default user,
    - Hyper-V firewall rules for its ports (mirrored networking filters
      there, not in the ordinary Windows firewall),
    - a scheduled task that starts it with Windows and keeps it running,
    - WSPanel itself, from the release's get.sh (checked against SHA256SUMS).

  Ports are shared with Windows: SSH of the sites goes to 2222 by default
  (Windows' own OpenSSH, which the mirror's Windows DNS uses, keeps 22).
  Nothing else on Windows may listen on 80, 443, 7443 or the panel's port.

  Afterwards: the panel at https://<PanelDomain>, the first account's
  password in the installer's output. As the mirror's standby: Server →
  Zrcadlo → Připojit jako zrcadlo (docs/zrcadlo.md).
#>
param(
    [string] $Distro = 'WSPanel',
    [string] $PanelDomain = '',
    [int] $PanelPort = 0,
    [Parameter(Mandatory)] [string] $AdminEmail,
    [string] $AcmeEmail = '',
    [string] $Db = 'mariadb,postgres',
    [string] $Php = '8.4',
    [int] $SshPort = 2222,
    [string] $Version = '',
    [string] $Repo = 'onyklicek/wspanel',
    [string[]] $ExtraPorts = @(),
    [string] $InstallerArgs = ''
)
$ErrorActionPreference = 'Stop'

function Step($m) { Write-Host "==> $m" -ForegroundColor Cyan }

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this in an elevated PowerShell (as administrator).'
}
$build = [int](Get-CimInstance Win32_OperatingSystem).BuildNumber
if ($build -lt 22621) {
    throw "Mirrored networking needs Windows 11 22H2 or Windows Server 2025 (build 22621+); this is $build. Use the Docker variant (docs/docker.md)."
}
if ($PanelDomain -eq '' -and $PanelPort -eq 0) { $PanelPort = 8443 }

Step 'WSL'
& wsl.exe --status *> $null
if ($LASTEXITCODE -ne 0) {
    & wsl.exe --install --no-distribution
    Write-Host 'WSL was installed: restart Windows and run this script again.' -ForegroundColor Yellow
    exit 0
}
& wsl.exe --update | Out-Null

Step '.wslconfig (mirrored networking, no idle shutdown)'
$cfgPath = Join-Path $env:USERPROFILE '.wslconfig'
$cfg = if (Test-Path $cfgPath) { Get-Content -Raw $cfgPath } else { '' }
$want = [ordered]@{ networkingMode = 'mirrored'; vmIdleTimeout = '-1'; firewall = 'true'; dnsTunneling = 'true'; hostAddressLoopback = 'true' }
if ($cfg -notmatch '(?m)^\[wsl2\]') { $cfg = $cfg.TrimEnd() + "`r`n[wsl2]`r`n" }
foreach ($k in $want.Keys) {
    if ($cfg -match "(?m)^$k\s*=") { $cfg = [regex]::Replace($cfg, "(?m)^$k\s*=.*$", "$k=$($want[$k])") }
    else { $cfg = [regex]::Replace($cfg, '(?m)^\[wsl2\]\s*$', "[wsl2]`r`n$k=$($want[$k])", 1) }
}
Set-Content -Encoding ascii -LiteralPath $cfgPath -Value $cfg.TrimEnd()

Step "The distribution $Distro (Debian 13)"
$have = (& wsl.exe --list --quiet) -replace "`0", '' | Where-Object { $_.Trim() -eq $Distro }
if (-not $have) {
    & wsl.exe --install Debian --name $Distro --no-launch
    if ($LASTEXITCODE -ne 0) { throw "wsl --install Debian --name $Distro failed" }
}
function InDistro([string] $cmd) {
    & wsl.exe -d $Distro -u root -- bash -lc $cmd
    if ($LASTEXITCODE -ne 0) { throw "in ${Distro}: $cmd" }
}
InDistro @'
set -e
cat > /etc/wsl.conf <<EOF
# Managed by Install-WSPanelWsl.ps1
[boot]
systemd=true
[user]
default=root
[interop]
appendWindowsPath=false
EOF
. /etc/os-release
if [ "$VERSION_CODENAME" != trixie ]; then
  echo "upgrading Debian $VERSION_CODENAME to 13 (trixie)"
  export DEBIAN_FRONTEND=noninteractive
  sed -i "s/$VERSION_CODENAME/trixie/g" /etc/apt/sources.list $(ls /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources 2>/dev/null) 2>/dev/null || true
  apt-get update -q && apt-get -y -q -o Dpkg::Options::=--force-confnew full-upgrade
fi
apt-get update -q >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y -q curl ca-certificates systemd-sysv >/dev/null
'@
& wsl.exe --terminate $Distro | Out-Null
& wsl.exe --shutdown | Out-Null

Step 'Start with Windows (scheduled task)'
$task = "WSPanel ($Distro)"
$action = New-ScheduledTaskAction -Execute "$env:WINDIR\System32\wsl.exe" -Argument "-d $Distro -u root -- /bin/sh -c 'exec sleep infinity'"
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $task
Start-Sleep -Seconds 5
for ($i = 0; $i -lt 30; $i++) {
    $state = (& wsl.exe -d $Distro -u root -- systemctl is-system-running 2>$null) -replace "`0", ''
    if ($state -match 'running|degraded') { break }
    Start-Sleep -Seconds 2
}

Step 'Hyper-V firewall (the ports of WSPanel)'
$vm = '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' # WSL's VM creator id
$ports = @(80, 443, $SshPort, 7443) + $ExtraPorts
if ($PanelPort -gt 0) { $ports += $PanelPort }
$ports = $ports | Sort-Object -Unique
Get-NetFirewallHyperVRule -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'WSPanel-*' } | Remove-NetFirewallHyperVRule
New-NetFirewallHyperVRule -Name 'WSPanel-TCP' -DisplayName 'WSPanel (WSL) TCP' -Direction Inbound -VMCreatorId $vm -Protocol TCP -LocalPorts $ports -Action Allow | Out-Null
New-NetFirewallHyperVRule -Name 'WSPanel-HTTP3' -DisplayName 'WSPanel (WSL) HTTP/3' -Direction Inbound -VMCreatorId $vm -Protocol UDP -LocalPorts 443 -Action Allow | Out-Null

Step 'WSPanel'
$setupFlags = @('--yes', "--admin-email=$AdminEmail", "--db=$Db", "--php=$Php", "--ssh-port=$SshPort")
if ($PanelDomain) { $setupFlags += "--panel-domain=$PanelDomain" }
if ($PanelPort -gt 0) { $setupFlags += "--panel-port=$PanelPort" }
if ($AcmeEmail) { $setupFlags += "--acme-email=$AcmeEmail" }
if ($InstallerArgs) { $setupFlags += $InstallerArgs }
$envv = if ($Version) { "WSPANEL_VERSION=$Version " } else { '' }
InDistro "curl -fsSL https://github.com/$Repo/releases/latest/download/get.sh -o /root/wspanel-get.sh && ${envv}bash /root/wspanel-get.sh $($setupFlags -join ' ')"

Write-Host ''
Write-Host "Done. WSPanel runs in WSL ($Distro), started with Windows." -ForegroundColor Green
$addr = if ($PanelDomain) { "https://$PanelDomain" + $(if ($PanelPort -gt 0) { ":$PanelPort" } else { '' }) } else { "https://<this server's IP>:$PanelPort" }
Write-Host "Panel: $addr  (the first account's password is above)"
Write-Host "A shell in it: wsl -d $Distro"
