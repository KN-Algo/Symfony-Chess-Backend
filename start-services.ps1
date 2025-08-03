param(
    [Switch]$Force,
    [string]$JwtParam
)

# ---------------- Helpers ----------------
function Get-Timestamp { return (Get-Date).ToString("yyyy-MM-dd HH:mm:ss") }

# logging to console and file
$backendDir = $PSScriptRoot
$LogFile = Join-Path $backendDir "start-services.log"
function Write-LogLine {
    param($level, $msg)
    $line = "[{0}] [{1}] {2}" -f (Get-Timestamp), $level, $msg
    switch ($level) {
        'INFO' { Write-Host "[INFO] $msg" -ForegroundColor Cyan }
        'WARN' { Write-Warning "[WARN] $msg" }
        'ERROR' { Write-Host "[ERROR] $msg" -ForegroundColor Red }
        default { Write-Host "$msg" }
    }
    try {
        $line | Out-File -FilePath $LogFile -Encoding UTF8 -Append
    } catch { Write-Host "[WARN] Failed to write logs to file: $_" -ForegroundColor Yellow }
}
function Log { param($msg) Write-LogLine 'INFO' $msg }
function Warn { param($msg) Write-LogLine 'WARN' $msg }
function Err  { param($msg) Write-LogLine 'ERROR' $msg }

function Confirm-OrDefaultYes {
    param($prompt)
    if ($Force) { return $true }
    $r = Read-Host "$prompt (Y/n)"
    if ($r -match '^[Nn]') { return $false }
    return $true
}

function Test-PortInUse {
    param($port)
    try {
        $conn = Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue
        if ($conn) { return $true }
    } catch {
        $out = netstat -ano | Select-String "LISTENING" | Select-String ":$port\s"
        if ($out) { return $true }
    }
    return $false
}

function Retry-Test {
    param($scriptBlock, $tries = 5, $delay = 2)
    for ($i = 1; $i -le $tries; $i++) {
        if (& $scriptBlock) { return $true }
        Start-Sleep -Seconds $delay
    }
    return $false
}

function Test-HttpServer {
    param($url, $timeoutSeconds = 3)
    try {
        $req = [System.Net.WebRequest]::Create($url)
        $req.Timeout = $timeoutSeconds * 1000
        $resp = $req.GetResponse()
        $resp.Close()
        return $true
    } catch {
        return $false
    }
}

function Get-RunningSymfonyProcess {
    $found = $null
    $all = Get-CimInstance Win32_Process | Where-Object { $_.Name -match 'php.exe' }
    foreach ($p in $all) {
        if ($p.CommandLine -and ($p.CommandLine -match 'server:start' -or $p.CommandLine -match 'symfony' -or $p.CommandLine -match ':8000')) {
            $found = Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue
            break
        }
    }
    return $found
}

function Test-SymfonyServerStatus {
    param($backendDir)
    if (-not (Get-Command symfony -ErrorAction SilentlyContinue)) {
        return $false
    }
    
    try {
        Push-Location $backendDir
        $status = & symfony server:status 2>$null
        $exitCode = $LASTEXITCODE
        Pop-Location
        
        if ($exitCode -eq 0 -and $status -match "Web server listening") {
            return $true
        }
    } catch {
        if (Get-Location) { Pop-Location }
    }
    return $false
}

function Get-SymfonyServerInfo {
    param($backendDir)
    if (-not (Get-Command symfony -ErrorAction SilentlyContinue)) {
        return "Symfony CLI not available"
    }
    
    try {
        Push-Location $backendDir
        $status = & symfony server:status 2>$null
        Pop-Location
        return $status
    } catch {
        if (Get-Location) { Pop-Location }
        return "Error checking server status"
    }
}

function Get-RunningMqttListener {
    $found = $null
    $all = Get-CimInstance Win32_Process | Where-Object { $_.Name -match 'php.exe' }
    foreach ($p in $all) {
        if ($p.CommandLine -and ($p.CommandLine -match 'mqtt-listen' -or $p.CommandLine -match 'app:mqtt-listen')) {
            $found = Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue
            break
        }
    }
    return $found
}

function Start-MercureProcess {
    param($mercureDir, $jwtKey)
    $exe = Join-Path $mercureDir "mercure.exe"
    if (-not (Test-Path $exe)) {
        Err "mercure.exe not found in $mercureDir"
        Err "Please run setup.ps1 first to install Mercure."
        return $false
    }
    Log "Starting Mercure process..."
    $cmd = "Set-Location -LiteralPath '$mercureDir'; `$env:MERCURE_PUBLISHER_JWT_KEY='$jwtKey'; `$env:MERCURE_SUBSCRIBER_JWT_KEY='$jwtKey'; .\mercure.exe run --config dev.Caddyfile; Write-Host 'Mercure stopped. Press Enter'; Read-Host"
    Start-Process powershell -ArgumentList '-NoExit', "-Command", $cmd
    return $true
}

function Start-SymfonyServer {
    param($backendDir)
    if (-not (Get-Command symfony -ErrorAction SilentlyContinue)) {
        Err "Symfony CLI not found in PATH."
        Err "Please run setup.ps1 first to install Symfony CLI."
        return $false
    }
    
    # Check if dependencies are installed
    if (-not (Test-Path "$backendDir\vendor")) {
        Err "Vendor directory not found. Dependencies are missing."
        Err "Please run setup.ps1 first to install dependencies."
        return $false
    }
    
    Log "Starting Symfony development server..."
    $cmd = "Set-Location -LiteralPath '$backendDir'; symfony server:start; Write-Host 'Symfony stopped. Press Enter'; Read-Host"
    Start-Process powershell -ArgumentList '-NoExit', "-Command", $cmd
    return $true
}

function Start-MqttListener {
    param($backendDir)
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        Err "PHP not found in PATH."
        Err "Please run setup.ps1 first to install PHP."
        return $false
    }
    
    # Check if dependencies are installed
    if (-not (Test-Path "$backendDir\vendor")) {
        Err "Vendor directory not found. Dependencies are missing."
        Err "Please run setup.ps1 first to install dependencies."
        return $false
    }
    
    # Check if console exists
    if (-not (Test-Path "$backendDir\bin\console")) {
        Err "Symfony console not found at $backendDir\bin\console"
        return $false
    }
    
    Log "Starting MQTT listener..."
    $cmd = "Set-Location -LiteralPath '$backendDir'; php bin\console app:mqtt-listen; Write-Host 'MQTT listener stopped. Press Enter'; Read-Host"
    Start-Process powershell -ArgumentList '-NoExit', "-Command", $cmd
    return $true
}

function Test-Prerequisites {
    param($backendDir, $mercureDir)
    
    $issues = @()
    
    # Check PHP
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        $issues += "PHP not found in PATH"
    }
    
    # Check Symfony CLI
    if (-not (Get-Command symfony -ErrorAction SilentlyContinue)) {
        $issues += "Symfony CLI not found in PATH"
    } else {
        # Test if symfony server commands work
        try {
            Push-Location $backendDir
            $serverTest = & symfony server:status 2>$null
            Pop-Location
        } catch {
            if (Get-Location) { Pop-Location }
            $issues += "Symfony CLI found but server commands not working properly"
        }
    }
    
    # Check Composer dependencies
    if (-not (Test-Path "$backendDir\vendor")) {
        $issues += "Composer dependencies not installed (vendor directory missing)"
    }
    
    # Check Mercure
    $mercureExe = Join-Path $mercureDir "mercure.exe"
    if (-not (Test-Path $mercureExe)) {
        $issues += "Mercure executable not found at $mercureExe"
    }
    
    # Check Mercure config
    $caddyfile = Join-Path $mercureDir "dev.Caddyfile"
    if (-not (Test-Path $caddyfile)) {
        $issues += "Mercure configuration file not found at $caddyfile"
    }
    
    # Check .env file
    if (-not (Test-Path "$backendDir\.env")) {
        $issues += ".env file not found"
    }
    
    # Check Mosquitto service
    $mosquittoSvc = Get-Service Mosquitto -ErrorAction SilentlyContinue
    if (-not $mosquittoSvc) {
        $issues += "Mosquitto service not installed"
    } elseif ($mosquittoSvc.Status -ne 'Running') {
        $issues += "Mosquitto service is not running"
    }
    
    return $issues
}

# ---------- Main ----------
if ($env:OS -notlike "*Windows*") {
    Err "This script only runs on Windows."
    exit 1
}
if ($PSVersionTable.PSVersion.Major -lt 5) {
    Err "PowerShell 5.0+ required. Current: $($PSVersionTable.PSVersion)"
    exit 1
}

$OutputEncoding = [System.Text.Encoding]::UTF8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

Log "=== Starting Symfony Chess Backend Services ==="

# Handle JWT parameter
if ($JwtParam) {
    $jwtKey = $JwtParam
    Log "Using provided JWT key."
} else {
    $jwtKey = "00a563e20f5b32ce9e85fc801396be97"
    Log "Using default JWT key."
}

# Set paths
$mercureDir = "D:\mercure"
# TODO: Check path to Mercure directory

# Check prerequisites
Log "=== Checking prerequisites ==="
$issues = Test-Prerequisites -backendDir $backendDir -mercureDir $mercureDir

if ($issues.Count -gt 0) {
    Err "Prerequisites check failed:"
    foreach ($issue in $issues) {
        Err "  - $issue"
    }
    Err ""
    Err "Please run setup.ps1 first to install and configure all required components."
    exit 1
}

Log "Prerequisites check passed."

# Check for port conflicts and running processes
Log "=== Checking current service status ==="
$portConflicts = @()
if (Test-PortInUse -port 3000) { $portConflicts += "3000 (Mercure)" }
if (Test-PortInUse -port 8000) { $portConflicts += "8000 (Symfony)" }
if (Test-PortInUse -port 1883) { $portConflicts += "1883 (MQTT)" }

$mercureRunning = Get-Process -Name "mercure" -ErrorAction SilentlyContinue
$symfonyRunning = Get-RunningSymfonyProcess
$mqttListenerRunning = Get-RunningMqttListener

if ($portConflicts.Count -gt 0) {
    Warn "Port conflicts detected: $($portConflicts -join ', ')"
    if (-not $Force) {
        if (-not (Confirm-OrDefaultYes "Continue despite port conflicts?")) {
            Err "Aborted due to port conflicts."
            exit 0
        }
    } else {
        Log "Force mode: ignoring port conflicts."
    }
}

# Health checks for running services
$symfonyHealthy = Test-SymfonyServerStatus $backendDir
$mercureHealthy = Test-HttpServer "http://127.0.0.1:3000/.well-known/mercure"

# Handle existing processes
if ($symfonyHealthy) {
    Log "Symfony server is already running and healthy."
} elseif ($symfonyRunning) {
    if (-not $Force) {
        if (Confirm-OrDefaultYes "Symfony is running but not responding. Restart it?") {
            Log "Stopping existing Symfony process..."
            Stop-Process -Id $symfonyRunning.Id -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 2
        } else {
            Log "Leaving existing Symfony process as is."
        }
    } else {
        Log "Force mode: restarting Symfony process."
        Stop-Process -Id $symfonyRunning.Id -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
}

if ($mercureHealthy) {
    Log "Mercure server is already running and healthy."
} elseif ($mercureRunning) {
    if (-not $Force) {
        if (Confirm-OrDefaultYes "Mercure is running but not responding. Restart it?") {
            Log "Stopping existing Mercure process..."
            Stop-Process -Id $mercureRunning.Id -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 2
        } else {
            Log "Leaving existing Mercure process as is."
        }
    } else {
        Log "Force mode: restarting Mercure process."
        Stop-Process -Id $mercureRunning.Id -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
}

if ($mqttListenerRunning) {
    Log "MQTT listener is already running (PID: $($mqttListenerRunning.Id))."
    if ($Force) {
        Log "Force mode: restarting MQTT listener."
        Stop-Process -Id $mqttListenerRunning.Id -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
        $mqttListenerRunning = $null
    }
}

# Start services
Log "=== Starting services ==="

# Start Mercure if not healthy
if (-not $mercureHealthy) {
    if (Start-MercureProcess -mercureDir $mercureDir -jwtKey $jwtKey) {
        Log "Mercure process started."
    } else {
        Warn "Failed to start Mercure process."
    }
} else {
    Log "Skipping Mercure start - already healthy."
}

# Start Symfony if not healthy
if (-not $symfonyHealthy) {
    if (Start-SymfonyServer -backendDir $backendDir) {
        Log "Symfony server started."
    } else {
        Warn "Failed to start Symfony server."
    }
} else {
    Log "Skipping Symfony start - already healthy."
}

# Start MQTT listener if not running
if (-not $mqttListenerRunning) {
    if (Start-MqttListener -backendDir $backendDir) {
        Log "MQTT listener started."
    } else {
        Warn "Failed to start MQTT listener."
    }
} else {
    Log "Skipping MQTT listener start - already running."
}

# Wait a moment for services to start
Log "Waiting for services to initialize..."
Start-Sleep -Seconds 5

# Final health check
Log "=== Final service status check ==="
$symfonyOk = Test-SymfonyServerStatus $backendDir
$mercureOk = Retry-Test { Test-HttpServer "http://127.0.0.1:3000/.well-known/mercure" } 5 2
$runningMqtt = Get-RunningMqttListener

Log "=== Service Status Summary ==="
Write-Host "Mercure (http://127.0.0.1:3000): " -NoNewline
if ($mercureOk) { 
    Write-Host "✓ RUNNING" -ForegroundColor Green 
} else { 
    Write-Host "✗ FAILED" -ForegroundColor Red 
}

Write-Host "Symfony Server: " -NoNewline
if ($symfonyOk) { 
    Write-Host "✓ RUNNING" -ForegroundColor Green
    # Show detailed Symfony server info
    $symfonyInfo = Get-SymfonyServerInfo $backendDir
    if ($symfonyInfo -match "http://127\.0\.0\.1:(\d+)") {
        $port = $Matches[1]
        Log "  └─ Available at: http://127.0.0.1:$port"
    }
} else { 
    Write-Host "✗ FAILED" -ForegroundColor Red
    # Show what symfony server:status says
    $symfonyInfo = Get-SymfonyServerInfo $backendDir
    Log "  └─ Status: $symfonyInfo"
}

Write-Host "MQTT Listener: " -NoNewline
if ($runningMqtt) { 
    Write-Host "✓ RUNNING (PID: $($runningMqtt.Id))" -ForegroundColor Green 
} else { 
    Write-Host "✗ NOT FOUND" -ForegroundColor Red 
}

# Check Mosquitto status
$mosquittoSvc = Get-Service Mosquitto -ErrorAction SilentlyContinue
Write-Host "Mosquitto MQTT Broker: " -NoNewline
if ($mosquittoSvc -and $mosquittoSvc.Status -eq 'Running') {
    Write-Host "✓ RUNNING" -ForegroundColor Green
} else {
    Write-Host "✗ NOT RUNNING" -ForegroundColor Red
}

Log ""
if ($symfonyOk -and $mercureOk -and $runningMqtt) {
    Log "All services started successfully!"
    Log ""
    Log "Access points:"
    
    # Get actual Symfony server info
    $symfonyInfo = Get-SymfonyServerInfo $backendDir
    if ($symfonyInfo -match "http://127\.0\.0\.1:(\d+)") {
        $port = $Matches[1]
        Log "  - Symfony Backend: http://127.0.0.1:$port"
    } else {
        Log "  - Symfony Backend: Check symfony server:status for URL"
    }
    
    Log "  - Mercure Hub: http://127.0.0.1:3000"
    Log "  - Mercure UI: http://127.0.0.1:3000/.well-known/mercure/ui/"
    Log "  - MQTT Broker: 127.0.0.1:1883"
} else {
    Warn "Some services failed to start properly. Check the logs above for details."
    Log ""
    Log "To troubleshoot:"
    Log "  1. Ensure all prerequisites are installed"
    Log "  2. Check the individual service windows for error messages"
    Log "  3. Verify no other applications are using the required ports"
    if (Get-Command symfony -ErrorAction SilentlyContinue) {
        Log "  4. For Symfony server issues, try:"
        Log "     - symfony server:status (check current status)"
        Log "     - symfony server (list all server commands)"
        Log "     - symfony server:stop (stop server if needed)"
        Log "     - symfony list (show all available commands)"
    }
}

Log "=== Services startup completed ==="
