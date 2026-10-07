# Creates the HTTPS certificate for the LAN site (port 8443), which the kiosk webcam needs on every device other
# than the server itself.
#
# The first run creates a local certificate authority (sjchs-ca.crt / sjchs-ca.key). Install sjchs-ca.crt ONCE on
# each kiosk device as a trusted root certificate. Never copy sjchs-ca.key off this PC: anyone holding it can make
# certificates those devices will trust.
#
# Every run then issues server.crt / server.key, signed by that CA, for this PC's current IPv4 addresses plus
# localhost, 127.0.0.1, <pc-name> and <pc-name>.local. Because the kiosks trust the CA, not the server certificate,
# reissuing (new IP, new network) needs no change on the kiosks; just restart Apache.
#
# Usage (from the project folder, in PowerShell):
#   powershell -ExecutionPolicy Bypass -File deploy\make-ssl-cert.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\make-ssl-cert.ps1 -ExtraIp 192.168.1.80,192.168.8.10
#   powershell -ExecutionPolicy Bypass -File deploy\make-ssl-cert.ps1 -ExtraIp 192.168.1.80,192.168.8.10 -IfNeeded
#
#   -ExtraIp   IPs the PC is not on right now (e.g. the reserved address on the other network).
#   -IfNeeded  Only reissue when an address/name is missing from server.crt or it expires within 30 days.

param(
    [string[]] $ExtraIp = @(),
    [switch] $IfNeeded,
    [string] $OutDir = 'C:\xampp\apache\conf\sjchs-ssl',
    [string] $OpenSsl = 'C:\xampp\apache\bin\openssl.exe',
    [int] $Days = 825
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $OpenSsl)) { throw "openssl.exe not found at $OpenSsl (pass -OpenSsl <path>)." }
# XAMPP's openssl.exe looks for a config file at a build-time path that does not exist.
if (-not $env:OPENSSL_CONF) {
    foreach ($cnf in (Join-Path (Split-Path $OpenSsl) 'openssl.cnf'), 'C:\xampp\apache\conf\openssl.cnf') {
        if (Test-Path $cnf) { $env:OPENSSL_CONF = $cnf; break }
    }
}

function Invoke-OpenSsl {
    & $OpenSsl @args
    if ($LASTEXITCODE -ne 0) { throw "openssl $($args[0]) failed (exit code $LASTEXITCODE)." }
}

# --- Names and addresses the certificate must cover -------------------------------------------------------------

# Accept "-ExtraIp a,b" whether PowerShell passes it as an array or as one comma-separated string.
$extra = @($ExtraIp | ForEach-Object { $_ -split '[,\s]+' } | Where-Object { $_ })
foreach ($ip in $extra) {
    $parsed = $null
    if (-not [System.Net.IPAddress]::TryParse($ip, [ref] $parsed) -or $parsed.AddressFamily -ne 'InterNetwork') {
        throw "-ExtraIp: '$ip' is not an IPv4 address."
    }
}

$current = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -ExpandProperty IPAddress)

$ips = @(@('127.0.0.1') + $current + $extra | Select-Object -Unique)
$pc = $env:COMPUTERNAME.ToLower()
$dnsNames = @('localhost', $pc, "$pc.local")

$serverCrt = Join-Path $OutDir 'server.crt'
$serverKey = Join-Path $OutDir 'server.key'
$caCrt = Join-Path $OutDir 'sjchs-ca.crt'
$caKey = Join-Path $OutDir 'sjchs-ca.key'

# --- -IfNeeded: keep the current certificate when it already covers everything ------------------------------------

if ($IfNeeded -and (Test-Path $serverCrt) -and (Test-Path $serverKey) -and (Test-Path $caCrt)) {
    $san = (& $OpenSsl x509 -in $serverCrt -noout -ext subjectAltName) -join ' '
    $haveIps = @([regex]::Matches($san, 'IP Address:([0-9.]+)') | ForEach-Object { $_.Groups[1].Value })
    $haveDns = @([regex]::Matches($san, 'DNS:([^,\s]+)') | ForEach-Object { $_.Groups[1].Value.ToLower() })
    $missing = @($ips | Where-Object { $haveIps -notcontains $_ }) + @($dnsNames | Where-Object { $haveDns -notcontains $_ })
    & $OpenSsl x509 -in $serverCrt -noout -checkend (30 * 86400) | Out-Null
    $expiring = $LASTEXITCODE -ne 0
    if ($missing.Count -eq 0 -and -not $expiring) {
        Write-Host "server.crt already covers $($ips -join ', '); nothing to do."
        exit 0
    }
    if ($missing.Count) { Write-Host "server.crt is missing: $($missing -join ', ')" }
    if ($expiring) { Write-Host 'server.crt expires within 30 days.' }
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# --- Local certificate authority (first run only) ---------------------------------------------------------------

if (-not (Test-Path $caCrt) -or -not (Test-Path $caKey)) {
    if ((Test-Path $caCrt) -xor (Test-Path $caKey)) {
        throw "Only one of sjchs-ca.crt / sjchs-ca.key exists in $OutDir. Restore the missing file, or move both away to start a new CA (every kiosk then has to trust the new sjchs-ca.crt)."
    }
    Write-Host 'Creating the local certificate authority (sjchs-ca.crt)...'
    Invoke-OpenSsl req -x509 -newkey rsa:2048 -sha256 -days 3650 -nodes `
        -keyout $caKey -out $caCrt `
        -subj "/O=San Jose CHS/CN=SJCHS Attendance Local CA ($pc)" `
        -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' `
        -addext 'keyUsage=critical,keyCertSign,cRLSign' `
        -addext 'subjectKeyIdentifier=hash'
    # Only administrators, SYSTEM and the current user may read the CA key.
    & icacls $caKey /inheritance:r /grant:r '*S-1-5-32-544:F' '*S-1-5-18:F' "$($env:USERDOMAIN)\$($env:USERNAME):F" | Out-Null
}

# --- Server certificate -----------------------------------------------------------------------------------------

$alt = @()
for ($i = 0; $i -lt $dnsNames.Count; $i++) { $alt += "DNS.$($i + 1) = $($dnsNames[$i])" }
for ($i = 0; $i -lt $ips.Count; $i++) { $alt += "IP.$($i + 1) = $($ips[$i])" }

$ext = @"
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
subjectAltName = @alt

[alt]
$($alt -join "`n")
"@

$work = Join-Path $OutDir '.issue'
New-Item -ItemType Directory -Force -Path $work | Out-Null
try {
    $extFile = Join-Path $work 'server.ext'
    [System.IO.File]::WriteAllText($extFile, $ext)  # no BOM; openssl rejects one

    $newKey = Join-Path $work 'server.key'
    $newCsr = Join-Path $work 'server.csr'
    $newCrt = Join-Path $work 'server.crt'

    Write-Host "Issuing server.crt for: $(($dnsNames + $ips) -join ', ')"
    Invoke-OpenSsl req -new -newkey rsa:2048 -sha256 -nodes -keyout $newKey -out $newCsr -subj "/O=San Jose CHS/CN=$pc"
    Invoke-OpenSsl x509 -req -in $newCsr -CA $caCrt -CAkey $caKey -CAserial (Join-Path $OutDir 'sjchs-ca.srl') `
        -CAcreateserial -out $newCrt -days $Days -sha256 -extfile $extFile

    # Swap key and certificate in together so Apache never sees a mismatched pair.
    Move-Item -Force $newKey $serverKey
    Move-Item -Force $newCrt $serverCrt
} finally {
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host "Done. Files in $OutDir"
Write-Host '  server.crt / server.key  -> SSLCertificateFile / SSLCertificateKeyFile in httpd-sjchs.conf'
Write-Host '  sjchs-ca.crt             -> install once on each kiosk as a trusted root certificate'
Write-Host '  sjchs-ca.key             -> keep on this PC only'
Write-Host 'Restart Apache to use the new certificate.'
