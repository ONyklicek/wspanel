<#
  WSPanel mirror: the only command the WSPanel DNS account may run over SSH
  (sshd ForceCommand). One JSON request on stdin, one JSON answer on stdout.

    {"action":"list","zone":"firma.local"}
    {"action":"apply","records":[{"zone":"firma.local","name":"intranet","type":"A","ttl":60,"values":["192.168.1.10"]}]}

  It changes only A and AAAA records, only in the zones listed in
  C:\ProgramData\WSPanel\dns.json, and only the names it is asked about.
  A name that holds a CNAME (or anything but A/AAAA of that type) is left
  alone and reported. Nothing is ever deleted except the old values of a
  record that is being set.
#>
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Answer($obj) {
    [Console]::Out.Write(($obj | ConvertTo-Json -Depth 6 -Compress))
    exit 0
}

try {
    $config = Get-Content -Raw -LiteralPath 'C:\ProgramData\WSPanel\dns.json' | ConvertFrom-Json
    $zones = @($config.zones | ForEach-Object { $_.ToLowerInvariant() })
    $server = if ($config.server) { [string]$config.server } else { 'localhost' }
    $raw = [Console]::In.ReadToEnd()
    if ($raw.Length -gt 1MB) { Answer @{ ok = $false; error = 'request too large' } }
    $req = $raw | ConvertFrom-Json
} catch {
    Answer @{ ok = $false; error = "bad request: $($_.Exception.Message)" }
}

$nameRe = '^(@|[A-Za-z0-9_]([A-Za-z0-9_-]{0,62})(\.[A-Za-z0-9_]([A-Za-z0-9_-]{0,62}))*)$'

function Assert-Zone([string]$zone) {
    $z = $zone.ToLowerInvariant()
    if ($zones -notcontains $z) { throw "zone $zone is not allowed for WSPanel" }
    return $z
}

function Get-Values($zone, $name, $type) {
    $rr = Get-DnsServerResourceRecord -ComputerName $server -ZoneName $zone -Name $name -RRType $type -ErrorAction SilentlyContinue
    $vals = @()
    foreach ($r in @($rr)) {
        if ($type -eq 'A') { $vals += $r.RecordData.IPv4Address.IPAddressToString }
        else { $vals += $r.RecordData.IPv6Address.IPAddressToString }
    }
    return ,@($vals | Sort-Object)
}

switch ($req.action) {
    'list' {
        try {
            $zone = Assert-Zone $req.zone
            $out = @()
            foreach ($r in Get-DnsServerResourceRecord -ComputerName $server -ZoneName $zone | Where-Object { $_.RecordType -in 'A', 'AAAA' }) {
                $v = if ($r.RecordType -eq 'A') { $r.RecordData.IPv4Address.IPAddressToString } else { $r.RecordData.IPv6Address.IPAddressToString }
                $out += @{ zone = $zone; name = $r.HostName; type = [string]$r.RecordType; ttl = [int]$r.TimeToLive.TotalSeconds; values = @($v) }
            }
            Answer @{ ok = $true; records = $out }
        } catch {
            Answer @{ ok = $false; error = $_.Exception.Message }
        }
    }
    'apply' {
        $changed = @()
        $errors = @()
        foreach ($rec in @($req.records)) {
            try {
                $zone = Assert-Zone $rec.zone
                $name = [string]$rec.name
                if ($name -notmatch $nameRe) { throw "bad name $name" }
                $type = [string]$rec.type
                if ($type -notin 'A', 'AAAA') { throw "type $type is not A or AAAA" }
                $ttl = [int]$rec.ttl
                if ($ttl -lt 30 -or $ttl -gt 86400) { throw "ttl $ttl out of range" }
                $want = @()
                foreach ($v in @($rec.values)) {
                    $ip = [System.Net.IPAddress]::Parse([string]$v)
                    if (($type -eq 'A') -ne ($ip.AddressFamily -eq 'InterNetwork')) { throw "$v is not an $type address" }
                    $want += $ip.ToString()
                }
                $want = @($want | Sort-Object)
                $cname = Get-DnsServerResourceRecord -ComputerName $server -ZoneName $zone -Name $name -RRType CName -ErrorAction SilentlyContinue
                if ($cname) { throw "$name.$zone is a CNAME: left alone" }
                $have = Get-Values $zone $name $type
                $existing = Get-DnsServerResourceRecord -ComputerName $server -ZoneName $zone -Name $name -RRType $type -ErrorAction SilentlyContinue
                $ttlOk = $true
                foreach ($r in @($existing)) { if ([int]$r.TimeToLive.TotalSeconds -ne $ttl) { $ttlOk = $false } }
                if ((($have -join ',') -eq ($want -join ',')) -and $ttlOk) { continue }
                foreach ($r in @($existing)) {
                    Remove-DnsServerResourceRecord -ComputerName $server -ZoneName $zone -InputObject $r -Force
                }
                foreach ($v in $want) {
                    if ($type -eq 'A') {
                        Add-DnsServerResourceRecordA -ComputerName $server -ZoneName $zone -Name $name -IPv4Address $v -TimeToLive ([TimeSpan]::FromSeconds($ttl))
                    } else {
                        Add-DnsServerResourceRecordAAAA -ComputerName $server -ZoneName $zone -Name $name -IPv6Address $v -TimeToLive ([TimeSpan]::FromSeconds($ttl))
                    }
                }
                $label = if ($name -eq '@') { $zone } else { "$name.$zone" }
                $changed += "$label $type $($want -join ',')"
            } catch {
                $errors += "$($rec.name).$($rec.zone): $($_.Exception.Message)"
            }
        }
        Answer @{ ok = $true; changed = $changed; errors = $errors }
    }
    default {
        Answer @{ ok = $false; error = "unknown action $($req.action)" }
    }
}
