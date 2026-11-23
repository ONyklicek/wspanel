<#
.SYNOPSIS
  Prepares a Windows DNS server (Active Directory) for the WSPanel mirror.

.DESCRIPTION
  Run once, as a domain administrator, in an elevated PowerShell on the
  domain controller that runs DNS (the Windows host of the standby):

    .\Install-WSPanelDns.ps1 -Account 'FIRMA\wspanel-dns' -Zones firma.local `
        -PublicKey 'ssh-ed25519 AAAA… wspanel-node-…', 'ssh-ed25519 AAAA… wspanel-node-…'

  The public keys are on each node's Server → Zrcadlo → Windows DNS page.

  What it does (idempotent):
    - OpenSSH Server (a Windows capability), started automatically,
    - the account (created when missing, with a random password nobody
      needs: it signs in with the keys only) with rights on the listed zones
      only: write access to each zone's object in Active Directory and the
      DNS server's management (WMI) namespace. Not DnsAdmins: a member of
      DnsAdmins can make the DNS service load a DLL, i.e. run code as SYSTEM
      on the domain controller. -UseDnsAdmins falls back to the group where
      the delegation is not enough (the panel's "Vyzkoušet Windows DNS"
      reports access denied),
    - C:\ProgramData\WSPanel\wspanel-dns.ps1 and dns.json (the zones),
    - an sshd block for the account: keys from C:\ProgramData\WSPanel\
      authorized_keys only, the script as its only command (ForceCommand),
      no forwarding, no terminal,
    - prints the host key's fingerprint to compare in the panel.

  Over SSH the account can do nothing but run the script, which changes
  only A/AAAA records of the listed zones.
#>
param(
    [Parameter(Mandatory)] [string] $Account,
    [Parameter(Mandatory)] [string[]] $Zones,
    [Parameter(Mandatory)] [string[]] $PublicKey,
    [string] $DnsServer = 'localhost',
    [switch] $UseDnsAdmins
)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$dir = 'C:\ProgramData\WSPanel'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this in an elevated PowerShell (as administrator).'
}
foreach ($k in $PublicKey) {
    if ($k -notmatch '^ssh-ed25519 [A-Za-z0-9+/=]+( \S+)?$') { throw "not an ed25519 public key: $k" }
}
foreach ($z in $Zones) {
    if (-not (Get-DnsServerZone -ComputerName $DnsServer -Name $z -ErrorAction SilentlyContinue)) { throw "zone $z does not exist on $DnsServer" }
}

Write-Host '==> OpenSSH Server'
$cap = Get-WindowsCapability -Online -Name 'OpenSSH.Server*' | Select-Object -First 1
if ($cap.State -ne 'Installed') { Add-WindowsCapability -Online -Name $cap.Name | Out-Null }
Set-Service -Name sshd -StartupType Automatic
Start-Service sshd
if (-not (Get-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -DisplayName 'OpenSSH Server (sshd)' -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22 | Out-Null
}

Write-Host "==> The account $Account"
Import-Module ActiveDirectory
$sam = ($Account -split '\\')[-1]
$user = Get-ADUser -Filter "SamAccountName -eq '$sam'" -ErrorAction SilentlyContinue
if (-not $user) {
    Add-Type -AssemblyName System.Web
    $pw = ConvertTo-SecureString ([System.Web.Security.Membership]::GeneratePassword(40, 8)) -AsPlainText -Force
    New-ADUser -Name $sam -SamAccountName $sam -AccountPassword $pw -Enabled $true -PasswordNeverExpires $true `
        -Description 'WSPanel mirror: A/AAAA records over SSH (wspanel-dns.ps1 only)' | Out-Null
    $user = Get-ADUser -Identity $sam
}
if ($UseDnsAdmins) {
    Write-Warning 'DnsAdmins: the account can make the DNS service load code (SYSTEM on this DC). Use it only where the delegation does not work.'
    if (-not (Get-ADGroupMember -Identity 'DnsAdmins' | Where-Object { $_.SID -eq $user.SID })) {
        Add-ADGroupMember -Identity 'DnsAdmins' -Members $user
    }
} else {
    if (Get-ADGroupMember -Identity 'DnsAdmins' | Where-Object { $_.SID -eq $user.SID }) {
        Remove-ADGroupMember -Identity 'DnsAdmins' -Members $user -Confirm:$false
    }
    Write-Host '==> Rights on the zones only'
    $sid = $user.SID
    foreach ($z in $Zones) {
        $dn = (Get-DnsServerZone -ComputerName $DnsServer -Name $z).DistinguishedName
        if (-not $dn) { throw "zone $z is not stored in Active Directory: delegation works for AD-integrated zones (or use -UseDnsAdmins)" }
        $acl = Get-Acl -Path "AD:$dn"
        $rights = [System.DirectoryServices.ActiveDirectoryRights]'CreateChild, DeleteChild, ListChildren, ReadProperty, WriteProperty, GenericRead, DeleteTree'
        $rule = New-Object System.DirectoryServices.ActiveDirectoryAccessRule($sid, $rights, 'Allow', [System.DirectoryServices.ActiveDirectorySecurityInheritance]::All)
        if (-not ($acl.Access | Where-Object { $_.IdentityReference -eq $sid -or ($_.IdentityReference.Value -like "*\$sam") })) {
            $acl.AddAccessRule($rule)
            Set-Acl -Path "AD:$dn" -AclObject $acl
        }
    }
    # The DnsServer cmdlets go through the DNS server's WMI namespace:
    # enable, execute methods, provider write, remote enable (not the
    # namespace's security itself).
    $sd = Invoke-CimMethod -Namespace 'root/MicrosoftDNS' -ClassName '__SystemSecurity' -MethodName GetSecurityDescriptor
    $sddl = (Invoke-CimMethod -ClassName Win32_SecurityDescriptorHelper -MethodName Win32SDToSDDL -Arguments @{ Descriptor = $sd.Descriptor }).SDDL
    $ace = "(A;CI;CCDCWPRP;;;$($sid.Value))"
    if ($sddl -notlike "*$($sid.Value)*") {
        $new = (Invoke-CimMethod -ClassName Win32_SecurityDescriptorHelper -MethodName SDDLToWin32SD -Arguments @{ SDDL = ($sddl + $ace) }).Descriptor
        Invoke-CimMethod -Namespace 'root/MicrosoftDNS' -ClassName '__SystemSecurity' -MethodName SetSecurityDescriptor -Arguments @{ Descriptor = $new } | Out-Null
    }
}

Write-Host '==> The script and its zones'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Copy-Item -Force -LiteralPath (Join-Path $here 'wspanel-dns.ps1') -Destination (Join-Path $dir 'wspanel-dns.ps1')
@{ zones = @($Zones | ForEach-Object { $_.ToLowerInvariant() }); server = $DnsServer } | ConvertTo-Json | Set-Content -Encoding UTF8 -LiteralPath (Join-Path $dir 'dns.json')
$keys = Join-Path $dir 'authorized_keys'
$PublicKey -join "`n" | Set-Content -Encoding ascii -LiteralPath $keys
# sshd reads key files only when SYSTEM and Administrators alone may change them.
foreach ($f in @($dir, $keys, (Join-Path $dir 'wspanel-dns.ps1'), (Join-Path $dir 'dns.json'))) {
    & icacls $f /inheritance:r /grant 'SYSTEM:(OI)(CI)F' /grant 'Administrators:(OI)(CI)F' /grant "${sam}:(OI)(CI)RX" | Out-Null
}

Write-Host '==> sshd'
$cfg = 'C:\ProgramData\ssh\sshd_config'
$begin = '# BEGIN WSPanel mirror DNS'
$end = '# END WSPanel mirror DNS'
$domain = (($Account -split '\\')[0]).ToLowerInvariant()
$names = @($sam.ToLowerInvariant())
if ($Account -match '\\') { $names += "$domain\$($sam.ToLowerInvariant())" }
# The panel signs in as name@domain (UPN).
$names += "$($sam.ToLowerInvariant())@$((Get-ADDomain).DNSRoot.ToLowerInvariant())"
$block = @(
    $begin,
    "Match User $($names -join ',')",
    "    AuthorizedKeysFile __PROGRAMDATA__/WSPanel/authorized_keys",
    '    PasswordAuthentication no',
    '    AllowTcpForwarding no',
    '    AllowAgentForwarding no',
    '    PermitTTY no',
    '    X11Forwarding no',
    "    ForceCommand powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$dir\wspanel-dns.ps1`"",
    $end
) -join "`r`n"
$text = Get-Content -Raw -LiteralPath $cfg
$text = [regex]::Replace($text, "(?s)\r?\n?$([regex]::Escape($begin)).*?$([regex]::Escape($end))", '')
# A Match block must be the last thing in the file.
Set-Content -LiteralPath $cfg -Encoding ascii -Value ($text.TrimEnd() + "`r`n`r`n" + $block + "`r`n")
Restart-Service sshd

Write-Host ''
Write-Host 'Done. The host key to compare in the panel (Zrcadlo → Windows DNS → Ověřit hostitele):'
& 'C:\Windows\System32\OpenSSH\ssh-keygen.exe' -l -f 'C:\ProgramData\ssh\ssh_host_ed25519_key.pub'
Write-Host "Account for the panel: $sam@$((Get-ADDomain).DNSRoot)"
