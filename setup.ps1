# Parametr -Force dla automatycznego trybu
param(
    [Switch]$Force
)

# Ustawienia – ścieżki i zmienne środowiskowe (do uzupełnienia przez użytkownika)
$mercureDir = "C:\sciezka\do\mercure"    # katalog Mercure
$backendDir = "C:\sciezka\do\backend"    # katalog projektu Symfony (backend)
$jwtKey    = "TwójSekretnyJWTKey"        # klucz JWT dla Mercure
$phpPath   = "C:\PHP\php.exe"           # ścieżka do php.exe (jeśli nie w PATH)


# 1. Sprawdzenie PHP (>= 8.2)
Write-Host "=== Sprawdzanie PHP 8.2+ ==="
$phpOK = $false
$phpCommand = $null

# Sprawdzamy czy PHP jest dostępne w PATH
if (Get-Command php -ErrorAction SilentlyContinue) {
    $phpCommand = "php"
} elseif (Test-Path $phpPath) {
    $phpCommand = $phpPath
}

if ($phpCommand) {
    try {
        $verInfo = & $phpCommand -nr "echo PHP_VERSION;"
        Write-Host "Znaleziono PHP w wersji: $verInfo"
        if ($verInfo -match "^8\.([2-9]|\d{2,})") { 
            $phpOK = $true 
            Write-Host "✓ PHP 8.2+ jest dostępne."
        }
    } catch {
        Write-Host "Błąd podczas sprawdzania wersji PHP."
    }
}

if (-not $phpOK) {
    Write-Host "❌ PHP 8.2+ nie jest dostępne."
    if ($Force) {
        Write-Host "Instaluję PHP 8.4 (tryb -Force)..."
        if (Get-Command winget -ErrorAction SilentlyContinue) {
            Write-Host "Instalacja PHP 8.4 przez winget..."
            Start-Process -FilePath "winget" -ArgumentList "install --id=PHP.PHP.8.4 -e --silent" -Wait -NoNewWindow

            # Odświeżamy PATH po instalacji
            $env:PATH = [System.Environment]::GetEnvironmentVariable("PATH", "Machine") + ";" + [System.Environment]::GetEnvironmentVariable("PATH", "User")
            
            # Sprawdzamy ponownie czy PHP jest dostępne
            if (Get-Command php -ErrorAction SilentlyContinue) {
                Write-Host "✓ PHP 8.4 zainstalowane pomyślnie."
                $phpOK = $true
            } else {
                Write-Warning "PHP może wymagać ręcznego dodania do PATH. Sprawdź instalację."
            }
        } else {
            Write-Error "Winget niedostępny. Nie można automatycznie zainstalować PHP."
            exit 1
        }
    } else {
        $confirm = Read-Host "Brak PHP 8.2+. Czy chcesz zainstalować PHP 8.2 teraz? (T/n)"
        if ($confirm -notlike "n*") {
            if (Get-Command winget -ErrorAction SilentlyContinue) {
                Write-Host "Instalacja PHP 8.4 przez winget..."
                Start-Process -FilePath "winget" -ArgumentList "install --id=PHP.PHP.8.4 -e" -Wait -NoNewWindow
                
                # Odświeżamy PATH
                $env:PATH = [System.Environment]::GetEnvironmentVariable("PATH", "Machine") + ";" + [System.Environment]::GetEnvironmentVariable("PATH", "User")
                
                if (Get-Command php -ErrorAction SilentlyContinue) {
                    Write-Host "✓ PHP 8.4 zainstalowane pomyślnie."
                    $phpOK = $true
                } else {
                    Write-Warning "PHP może wymagać ręcznego dodania do PATH."
                }
            } else {
                Write-Host "Otwieram stronę pobierania PHP..."
                Start-Process "https://windows.php.net/download#php-8.4"
                Write-Host "Pobierz i zainstaluj PHP 8.4 ręcznie, a następnie dodaj PHP do zmiennej środowiskowej PATH."
                Pause
            }
        } else {
            Write-Error "PHP 8.2+ jest wymagane do działania aplikacji. Instalacja przerwana."
            exit 1
        }
    }
}

if (-not $phpOK) {
    Write-Error "Nie udało się zainstalować lub znaleźć PHP 8.2+. Sprawdź instalację."
    exit 1
}

# 2. Sprawdzenie Composer
Write-Host "`n=== Sprawdzanie Composer ==="
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    Write-Host "❌ Composer nie jest zainstalowany."
    if ($Force) {
        Write-Host "Instaluję Composer (tryb Force)..."
        # Instalacja Composera
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $expectedSig = (Invoke-WebRequest "https://composer.github.io/installer.sig").Content.Trim()
            php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
            $actualSig = php -r "echo hash_file('sha384', 'composer-setup.php');"
            if ($expectedSig -ne $actualSig) {
                Write-Error "Błąd: nieprawidłowy podpis instalatora Composera!"
                Remove-Item "composer-setup.php" -ErrorAction SilentlyContinue
                exit 1
            }
            php .\composer-setup.php --quiet --install-dir=. --filename=composer
            Remove-Item "composer-setup.php" -ErrorAction SilentlyContinue
            
            if (Test-Path "composer.phar") {
                # Tworzymy plik composer.bat w bieżącym katalogu
                $currentDir = Get-Location
                $phpExe = if (Get-Command php -ErrorAction SilentlyContinue) { (Get-Command php).Source } else { $phpPath }
                '@echo off' | Out-File "composer.bat" -Encoding ASCII
                "`"$phpExe`" `"$currentDir\composer.phar`" %*" | Out-File "composer.bat" -Encoding ASCII -Append
                
                # Dodajemy bieżący katalog do PATH tymczasowo
                $env:PATH = "$currentDir;$env:PATH"
                
                Write-Host "✓ Composer zainstalowany pomyślnie."
            } else {
                Write-Error "Nie udało się zainstalować Composera."
                exit 1
            }
        } catch {
            Write-Error "Błąd podczas instalacji Composera: $_"
            exit 1
        }
    } else {
        $confirm = Read-Host "Composer nie jest zainstalowany. Czy chcesz go pobrać i zainstalować? (T/n)"
        if ($confirm -notlike "n*") {
            Write-Host "Pobieranie i instalacja Composera..."
            try {
                [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
                $expectedSig = (Invoke-WebRequest "https://composer.github.io/installer.sig").Content.Trim()
                php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
                $actualSig = php -r "echo hash_file('sha384', 'composer-setup.php');"
                if ($expectedSig -ne $actualSig) {
                    Write-Error "Błąd: nieprawidłowy podpis instalatora Composera!"
                    Remove-Item "composer-setup.php" -ErrorAction SilentlyContinue
                    exit 1
                }
                php .\composer-setup.php --quiet --install-dir=. --filename=composer
                Remove-Item "composer-setup.php" -ErrorAction SilentlyContinue
                
                if (Test-Path "composer.phar") {
                    $currentDir = Get-Location
                    $phpExe = if (Get-Command php -ErrorAction SilentlyContinue) { (Get-Command php).Source } else { $phpPath }
                    '@echo off' | Out-File "composer.bat" -Encoding ASCII
                    "`"$phpExe`" `"$currentDir\composer.phar`" %*" | Out-File "composer.bat" -Encoding ASCII -Append
                    $env:PATH = "$currentDir;$env:PATH"
                    Write-Host "✓ Composer zainstalowany pomyślnie."
                } else {
                    Write-Error "Nie udało się zainstalować Composera."
                    exit 1
                }
            } catch {
                Write-Error "Błąd podczas instalacji Composera: $_"
                exit 1
            }
        } else {
            Write-Error "Composer jest wymagany do działania aplikacji. Instalacja przerwana."
            exit 1
        }
    }
} else {
    Write-Host "✓ Composer jest dostępny."
}

# 3. Sprawdzenie Mercure
Write-Host "`n=== Sprawdzanie Mercure ==="
$mercureExists = Test-Path "$mercureDir\mercure.exe"
if (-not $mercureExists) {
    Write-Host "❌ Mercure nie został znaleziony w katalogu: $mercureDir"
    if ($Force) {
        Write-Host "Tworzę katalog Mercure i pobieram plik wykonywalny..."
        try {
            New-Item -ItemType Directory -Path $mercureDir -Force | Out-Null
            # Pobieranie najnowszej wersji Mercure dla Windows
            Write-Host "Pobieranie Mercure..."
            $mercureUrl = "https://github.com/dunglas/mercure/releases/latest/download/mercure_Windows_x86_64.tar.gz"
            $mercureTar = "$mercureDir\mercure.tar.gz"
            Invoke-WebRequest -Uri $mercureUrl -OutFile $mercureTar
            
            # Rozpakowanie (wymagane 7-Zip lub tar w Windows 10+)
            if (Get-Command tar -ErrorAction SilentlyContinue) {
                tar -xzf $mercureTar -C $mercureDir
                Remove-Item $mercureTar
                Write-Host "✓ Mercure pobrany i rozpakowany pomyślnie."
                
                # Tworzenie pliku dev.Caddyfile
                Write-Host "Tworzę plik konfiguracyjny dev.Caddyfile..."
                $caddyfileContent = @"
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{`$GLOBAL_OPTIONS}
}

{`$CADDY_EXTRA_CONFIG}

http://localhost:3000 {
	log {
		format filter {
			fields {
				request>uri query {
					replace authorization REDACTED
				}
			}
		}
	}

	encode zstd gzip

	mercure {
		# Publisher JWT key
		publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY} {env.MERCURE_PUBLISHER_JWT_ALG}
		# Subscriber JWT key
		subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} {env.MERCURE_SUBSCRIBER_JWT_ALG}
		# Permissive configuration for the development environment
		cors_origins *
		publish_origins *
		demo
		anonymous
		subscriptions
		# Extra directives
		{`$MERCURE_EXTRA_DIRECTIVES}
	}

	{`$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt ``User-agent: *
	Disallow: /``
	respond "Not Found" 404
}
"@
                $caddyfileContent | Out-File "$mercureDir\dev.Caddyfile" -Encoding UTF8
                Write-Host "✓ Plik dev.Caddyfile utworzony pomyślnie."
            } else {
                Write-Warning "Nie można automatycznie rozpakować Mercure. Pobierz ręcznie z: https://github.com/dunglas/mercure/releases"
            }
        } catch {
            Write-Error "Błąd podczas pobierania Mercure: $_"
        }
    } else {
        Write-Warning "Mercure nie został znaleziony. Upewnij się, że mercure.exe znajduje się w: $mercureDir"
        $confirm = Read-Host "Czy chcesz pobrać Mercure automatycznie? (T/n)"
        if ($confirm -notlike "n*") {
            try {
                New-Item -ItemType Directory -Path $mercureDir -Force | Out-Null
                Write-Host "Pobieranie Mercure..."
                $mercureUrl = "https://github.com/dunglas/mercure/releases/latest/download/mercure_Windows_x86_64.tar.gz"
                $mercureTar = "$mercureDir\mercure.tar.gz"
                Invoke-WebRequest -Uri $mercureUrl -OutFile $mercureTar
                
                if (Get-Command tar -ErrorAction SilentlyContinue) {
                    tar -xzf $mercureTar -C $mercureDir
                    Remove-Item $mercureTar
                    Write-Host "✓ Mercure pobrany i rozpakowany pomyślnie."
                    
                    # Tworzenie pliku dev.Caddyfile
                    Write-Host "Tworzę plik konfiguracyjny dev.Caddyfile..."
                    $caddyfileContent = @"
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{`$GLOBAL_OPTIONS}
}

{`$CADDY_EXTRA_CONFIG}

http://localhost:3000 {
	log {
		format filter {
			fields {
				request>uri query {
					replace authorization REDACTED
				}
			}
		}
	}

	encode zstd gzip

	mercure {
		# Publisher JWT key
		publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY} {env.MERCURE_PUBLISHER_JWT_ALG}
		# Subscriber JWT key
		subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} {env.MERCURE_SUBSCRIBER_JWT_ALG}
		# Permissive configuration for the development environment
		cors_origins *
		publish_origins *
		demo
		anonymous
		subscriptions
		# Extra directives
		{`$MERCURE_EXTRA_DIRECTIVES}
	}

	{`$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt ``User-agent: *
	Disallow: /``
	respond "Not Found" 404
}
"@
                    $caddyfileContent | Out-File "$mercureDir\dev.Caddyfile" -Encoding UTF8
                    Write-Host "✓ Plik dev.Caddyfile utworzony pomyślnie."
                } else {
                    Write-Warning "Nie można automatycznie rozpakować. Rozpakuj ręcznie plik: $mercureTar"
                }
            } catch {
                Write-Error "Błąd podczas pobierania Mercure: $_"
            }
        }
    }
} else {
    Write-Host "✓ Mercure jest dostępny."
    
    # Zawsze utwórz/nadpisz plik dev.Caddyfile najnowszą konfiguracją
    Write-Host "Aktualizuję plik konfiguracyjny dev.Caddyfile..."
    $caddyfileContent = @"
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{`$GLOBAL_OPTIONS}
}

{`$CADDY_EXTRA_CONFIG}

http://localhost:3000 {
	log {
		format filter {
			fields {
				request>uri query {
					replace authorization REDACTED
				}
			}
		}
	}

	encode zstd gzip

	mercure {
		# Publisher JWT key
		publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY} {env.MERCURE_PUBLISHER_JWT_ALG}
		# Subscriber JWT key
		subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} {env.MERCURE_SUBSCRIBER_JWT_ALG}
		# Permissive configuration for the development environment
		cors_origins *
		publish_origins *
		demo
		anonymous
		subscriptions
		# Extra directives
		{`$MERCURE_EXTRA_DIRECTIVES}
	}

	{`$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt ``User-agent: *
	Disallow: /``
	respond "Not Found" 404
}
"@
    $caddyfileContent | Out-File "$mercureDir\dev.Caddyfile" -Encoding UTF8
    Write-Host "✓ Plik dev.Caddyfile zaktualizowany pomyślnie."
}

# 4. Sprawdzenie serwera MQTT (Mosquitto)
Write-Host "`n=== Sprawdzanie Mosquitto MQTT ==="
$mosqService = Get-Service "Mosquitto" -ErrorAction SilentlyContinue
$mosquittoRunning = $mosqService -and ($mosqService.Status -eq 'Running')

if (-not $mosqService) {
    Write-Host "❌ Broker Mosquitto MQTT nie jest zainstalowany."
    if ($Force) {
        Write-Host "Instaluję Mosquitto (tryb -Force)..."
        if (Get-Command winget -ErrorAction SilentlyContinue) {
            Start-Process -FilePath "winget" -ArgumentList "install -e --id EclipseFoundation.Mosquitto" -Wait -NoNewWindow
            Write-Host "✓ Mosquitto zainstalowany. Próbuję uruchomić usługę..."
            Start-Sleep -Seconds 5  # Czekamy na ukończenie instalacji
            Start-Service "Mosquitto" -ErrorAction SilentlyContinue
        } else {
            Write-Error "Winget niedostępny. Nie można automatycznie zainstalować Mosquitto."
            exit 1
        }
    } else {
        $confirm = Read-Host "Czy chcesz zainstalować broker MQTT (Eclipse Mosquitto)? (T/n)"
        if ($confirm -notlike "n*") {
            if (Get-Command winget -ErrorAction SilentlyContinue) {
                Start-Process -FilePath "winget" -ArgumentList "install -e --id EclipseFoundation.Mosquitto" -Wait -NoNewWindow
                Write-Host "✓ Mosquitto zainstalowany. Próbuję uruchomić usługę..."
                Start-Sleep -Seconds 5
                Start-Service "Mosquitto" -ErrorAction SilentlyContinue
            } else {
                Write-Host "Otwieram stronę pobierania Mosquitto..."
                Start-Process "https://mosquitto.org/download/"
                Write-Host "Zainstaluj Mosquitto ręcznie, a następnie upewnij się, że usługa jest uruchomiona."
                Pause
            }
        } else {
            Write-Warning "Mosquitto nie został zainstalowany. MQTT może nie działać poprawnie."
        }
    }
} elseif (-not $mosquittoRunning) {
    Write-Host "⚠️ Usługa Mosquitto jest zainstalowana, ale nie jest uruchomiona."
    Write-Host "Uruchamiam usługę Mosquitto..."
    try {
        Start-Service "Mosquitto"
        Write-Host "✓ Usługa Mosquitto uruchomiona pomyślnie."
    } catch {
        Write-Warning "Nie udało się uruchomić usługi Mosquitto: $_"
    }
} else {
    Write-Host "✓ Mosquitto jest zainstalowany i uruchomiony."
}

# 5. Instalacja zależności Composer
Write-Host "`n=== Instalacja zależności projektu ==="
Set-Location -LiteralPath $backendDir
if (Test-Path "composer.json") {
    Write-Host "Instaluję zależności Composer..."
    try {
        if (Get-Command composer -ErrorAction SilentlyContinue) {
            & composer install --no-interaction
        } else {
            # Używamy lokalnego composer.phar jeśli composer nie jest w PATH
            $currentDir = Get-Location
            if (Test-Path "$currentDir\composer.phar") {
                & php "$currentDir\composer.phar" install --no-interaction
            } else {
                Write-Warning "Nie znaleziono Composera. Pomijam instalację zależności."
            }
        }
        Write-Host "✓ Zależności zainstalowane pomyślnie."
    } catch {
        Write-Warning "Błąd podczas instalacji zależności: $_"
    }
} else {
    Write-Warning "Nie znaleziono pliku composer.json w katalogu projektu."
}

# 5.5. Sprawdzenie i tworzenie pliku .env
Write-Host "`n=== Sprawdzanie pliku .env ==="
$envPath = "$backendDir\.env"
if (-not (Test-Path $envPath)) {
    Write-Host "Tworzę plik .env z domyślną konfiguracją..."
    $envContent = @"
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"

MQTT_BROKER=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=szachmat_backend

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_JWT_SECRET=$jwtKey
"@
    $envContent | Out-File $envPath -Encoding UTF8
    Write-Host "✓ Plik .env utworzony pomyślnie."
} else {
    Write-Host "✓ Plik .env już istnieje."
}

# 6. Uruchomienie procesów w nowych oknach terminala
Write-Host "`n=== Uruchamianie usług ==="

# Sprawdzenie czy procesy już działają
Write-Host "Sprawdzanie działających procesów..."
$mercureRunning = Get-Process -Name "mercure" -ErrorAction SilentlyContinue
$symfonyRunning = Get-Process | Where-Object { $_.ProcessName -eq "php" -and ($_.CommandLine -like "*server:start*" -or $_.CommandLine -like "*symfony*server*") } -ErrorAction SilentlyContinue
$mqttListenerRunning = Get-Process | Where-Object { $_.ProcessName -eq "php" -and ($_.CommandLine -like "*mqtt-listen*" -or $_.CommandLine -like "*app:mqtt-listen*") } -ErrorAction SilentlyContinue

# Dodatkowo sprawdź procesy z poziomu systemu
if (-not $symfonyRunning) {
    $allPhpProcesses = Get-WmiObject Win32_Process | Where-Object { $_.Name -eq "php.exe" }
    foreach ($proc in $allPhpProcesses) {
        if ($proc.CommandLine -and ($proc.CommandLine -like "*server:start*" -or $proc.CommandLine -like "*symfony*server*" -or $proc.CommandLine -like "*:8000*")) {
            $symfonyRunning = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
            break
        }
    }
}

if (-not $mqttListenerRunning) {
    $allPhpProcesses = Get-WmiObject Win32_Process | Where-Object { $_.Name -eq "php.exe" }
    foreach ($proc in $allPhpProcesses) {
        if ($proc.CommandLine -and ($proc.CommandLine -like "*mqtt-listen*" -or $proc.CommandLine -like "*app:mqtt-listen*")) {
            $mqttListenerRunning = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
            break
        }
    }
}

if ($mercureRunning) {
    Write-Host "⚠️ Mercure już działa (PID: $($mercureRunning.Id))"
}
if ($symfonyRunning) {
    Write-Host "⚠️ Serwer Symfony już działa (PID: $($symfonyRunning.Id))"
}
if ($mqttListenerRunning) {
    Write-Host "⚠️ MQTT Listener już działa (PID: $($mqttListenerRunning.Id))"
}

if ($mercureRunning -or $symfonyRunning -or $mqttListenerRunning) {
    if (-not $Force) {
        $confirm = Read-Host "`nCzy chcesz zatrzymać istniejące procesy i uruchomić je ponownie? (T/n)"
        
        if ($confirm -match "^[Tt]") {
            Write-Host "Zatrzymywanie istniejących procesów..."
            if ($mercureRunning) { 
                Stop-Process -Id $mercureRunning.Id -Force -ErrorAction SilentlyContinue
                Write-Host "✓ Mercure zatrzymany"
            }
            if ($symfonyRunning) { 
                Stop-Process -Id $symfonyRunning.Id -Force -ErrorAction SilentlyContinue
                Write-Host "✓ Serwer Symfony zatrzymany"
            }
            if ($mqttListenerRunning) { 
                Stop-Process -Id $mqttListenerRunning.Id -Force -ErrorAction SilentlyContinue
                Write-Host "✓ MQTT Listener zatrzymany"
            }
            Start-Sleep -Seconds 3  # Czekamy na pełne zatrzymanie procesów
            
            # Resetujemy zmienne po zatrzymaniu
            $mercureRunning = $null
            $symfonyRunning = $null
            $mqttListenerRunning = $null
        } else {
            Write-Host "Pomijam uruchamianie - istniejące procesy pozostają aktywne."
            Write-Host "`n=== Setup zakończony ==="
            exit 0
        }
    } else {
        Write-Host "Tryb -Force: Zatrzymuję istniejące procesy automatycznie..."
        if ($mercureRunning) { 
            Stop-Process -Id $mercureRunning.Id -Force -ErrorAction SilentlyContinue
            Write-Host "✓ Mercure zatrzymany"
        }
        if ($symfonyRunning) { 
            Stop-Process -Id $symfonyRunning.Id -Force -ErrorAction SilentlyContinue
            Write-Host "✓ Serwer Symfony zatrzymany"
        }
        if ($mqttListenerRunning) { 
            Stop-Process -Id $mqttListenerRunning.Id -Force -ErrorAction SilentlyContinue
            Write-Host "✓ MQTT Listener zatrzymany"
        }
        Start-Sleep -Seconds 3
        
        # Resetujemy zmienne po zatrzymaniu
        $mercureRunning = $null
        $symfonyRunning = $null
        $mqttListenerRunning = $null
    }
}

Write-Host "Uruchamianie usług w osobnych oknach..."

# Sprawdzenie czy wszystkie ścieżki istnieją
$allPathsValid = $true

if (-not (Test-Path "$mercureDir\mercure.exe")) {
    Write-Warning "Nie znaleziono mercure.exe w: $mercureDir"
    $allPathsValid = $false
}

if (-not (Test-Path $backendDir)) {
    Write-Warning "Nie znaleziono katalogu projektu: $backendDir"
    $allPathsValid = $false
}

if (-not (Test-Path "$backendDir\bin\console")) {
    Write-Warning "Nie znaleziono pliku console w: $backendDir\bin\console"
    $allPathsValid = $false
}

if ($allPathsValid) {
    try {
        # Sprawdzenie portów przed uruchomieniem
        $portConflicts = @()
        
        # Sprawdź port 3000 (Mercure)
        $mercurePort = Get-NetTCPConnection -LocalPort 3000 -ErrorAction SilentlyContinue
        if ($mercurePort) {
            $portConflicts += "Port 3000 (Mercure) jest już zajęty"
        }
        
        # Sprawdź port 8000 (Symfony)
        $symfonyPort = Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue
        if ($symfonyPort) {
            $portConflicts += "Port 8000 (Symfony) jest już zajęty"
        }
        
        # Sprawdź port 1883 (MQTT)
        $mqttPort = Get-NetTCPConnection -LocalPort 1883 -ErrorAction SilentlyContinue
        if ($mqttPort) {
            $portConflicts += "Port 1883 (MQTT) jest już zajęty"
        }
        
        if ($portConflicts.Count -gt 0) {
            Write-Warning "Wykryto konflikty portów:"
            $portConflicts | ForEach-Object { Write-Warning "  $_" }
            
            if (-not $Force) {
                do {
                    Write-Host "`nCzy mimo to chcesz kontynuować uruchamianie? (T/n): " -NoNewline
                    $confirm = [Console]::ReadKey($true).KeyChar
                    Write-Host $confirm
                } while ($confirm -notmatch "^[TtNn]")
                
                if ($confirm -match "^[Nn]") {
                    Write-Host "`nAnulowano uruchamianie usług."
                    exit 0
                }
            } else {
                Write-Host "`nTryb -Force: Kontynuuję mimo konfliktów portów..."
            }
        }
        
        # Mercure (tylko jeśli nie działa)
        if (-not $mercureRunning) {
            Write-Host "Uruchamianie Mercure..."
            Start-Process powershell -ArgumentList '-NoExit', '-Command', "Write-Host 'Uruchamianie Mercure...'; Set-Location -LiteralPath '$mercureDir'; `$env:MERCURE_PUBLISHER_JWT_KEY='$jwtKey'; `$env:MERCURE_SUBSCRIBER_JWT_KEY='$jwtKey'; .\mercure.exe run --config dev.Caddyfile; Write-Host 'Mercure zatrzymany. Naciśnij Enter aby zamknąć...'; Read-Host"
            Start-Sleep -Seconds 2
        } else {
            Write-Host "⏭️ Mercure już działa - pomijam uruchomienie"
        }
        
        # Symfony server (tylko jeśli nie działa)
        if (-not $symfonyRunning) {
            Write-Host "Uruchamianie serwera Symfony..."
            # Najpierw zatrzymaj istniejący serwer na wszelki wypadek
            Set-Location -LiteralPath $backendDir
            try {
                & symfony server:stop *>$null 2>$null
                Start-Sleep -Seconds 2
            } catch {
                # Ignorujemy błędy - może nie być uruchomiony
            }
            
            # Sprawdź czy symfony jest dostępny
            if (-not (Get-Command symfony -ErrorAction SilentlyContinue)) {
                Write-Warning "Symfony CLI nie jest zainstalowany. Używam php -S do uruchomienia serwera..."
                $phpCommand = if (Get-Command php -ErrorAction SilentlyContinue) { "php" } else { "`"$phpPath`"" }
                Start-Process powershell -ArgumentList '-NoExit', '-Command', "Write-Host 'Uruchamianie serwera PHP (port 8000)...'; Set-Location -LiteralPath '$backendDir'; $phpCommand -S 127.0.0.1:8000 -t public; Write-Host 'Serwer PHP zatrzymany. Naciśnij Enter aby zamknąć...'; Read-Host"
            } else {
                Start-Process powershell -ArgumentList '-NoExit', '-Command', "Write-Host 'Uruchamianie serwera Symfony...'; Set-Location -LiteralPath '$backendDir'; symfony server:start; Write-Host 'Serwer Symfony zatrzymany. Naciśnij Enter aby zamknąć...'; Read-Host"
            }
            Start-Sleep -Seconds 3
        } else {
            Write-Host "⏭️ Serwer Symfony już działa - pomijam uruchomienie"
        }
        
        # MQTT listener (tylko jeśli nie działa)
        if (-not $mqttListenerRunning) {
            Write-Host "Uruchamianie nasłuchu MQTT..."
            $phpCommand = if (Get-Command php -ErrorAction SilentlyContinue) { "php" } else { "`"$phpPath`"" }
            Start-Process powershell -ArgumentList '-NoExit', '-Command', "Write-Host 'Uruchamianie nasłuchu MQTT...'; Set-Location -LiteralPath '$backendDir'; $phpCommand bin\console app:mqtt-listen; Write-Host 'MQTT Listener zatrzymany. Naciśnij Enter aby zamknąć...'; Read-Host"
        } else {
            Write-Host "⏭️ MQTT Listener już działa - pomijam uruchomienie"
        }
        
        Write-Host "`n✓ Sprawdzanie i uruchamianie usług zakończone."
        Write-Host "Sprawdź okna terminali, aby upewnić się, że wszystkie usługi działają poprawnie."
        
        # Podsumowanie aktywnych usług
        Write-Host "`n=== Status usług ==="
        Write-Host "`nCzekam na uruchomienie usług..."
        
        # Loading bar - czekamy 10 sekund z paskiem postępu
        for ($i = 1; $i -le 10; $i++) {
            Write-Progress -Activity "Oczekiwanie na uruchomienie usług" -Status "Oczekiwanie..." -PercentComplete ($i * 10)
            Start-Sleep -Seconds 1
        }
        Write-Progress -Activity "Oczekiwanie na uruchomienie usług" -Completed

        # Funkcja do sprawdzania czy serwer HTTP odpowiada
        function Test-HttpServer($url, $timeoutSeconds = 3) {
            try {
                $request = [System.Net.WebRequest]::Create($url)
                $request.Timeout = $timeoutSeconds * 1000
                $response = $request.GetResponse()
                $response.Close()
                return $true
            } catch {
                return $false
            }
        }
        
        $currentMercure = Get-Process -Name "mercure" -ErrorAction SilentlyContinue
        $currentSymfony = $null
        $currentMqtt = Get-Process | Where-Object { $_.ProcessName -eq "php" -and ($_.CommandLine -like "*mqtt-listen*" -or $_.CommandLine -like "*app:mqtt-listen*") } -ErrorAction SilentlyContinue
        
        # Sprawdzanie Symfony - najpierw sprawdź HTTP, potem procesy
        $symfonyHttpWorks = Test-HttpServer "http://127.0.0.1:8000"
        $symfonyServerStatus = $null
        
        # Sprawdź status przez symfony server:status jeśli symfony CLI jest dostępny
        if (Get-Command symfony -ErrorAction SilentlyContinue) {
            try {
                Set-Location -LiteralPath $backendDir
                $statusOutput = & symfony server:status 2>$null
                if ($statusOutput -match "listening" -or $statusOutput -match "running") {
                    $symfonyServerStatus = "SYMFONY_CLI_RUNNING"
                }
            } catch {
                # Ignorujemy błędy
            }
        }
        
        if ($symfonyHttpWorks) {
            # Jeśli HTTP działa, spróbuj znaleźć odpowiedni proces
            $allPhpProcesses = Get-WmiObject Win32_Process | Where-Object { $_.Name -eq "php.exe" }
            foreach ($proc in $allPhpProcesses) {
                if ($proc.CommandLine) {
                    if ($proc.CommandLine -like "*server:start*" -or $proc.CommandLine -like "*symfony*server*" -or $proc.CommandLine -like "*:8000*" -or $proc.CommandLine -like "*-S*127.0.0.1:8000*" -or $proc.CommandLine -like "*-S*8000*") {
                        $currentSymfony = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
                        break
                    }
                }
            }
            # Jeśli nie znaleziono procesu PHP, może to być symfony serve w tle
            if (-not $currentSymfony) {
                if ($symfonyServerStatus -eq "SYMFONY_CLI_RUNNING") {
                    $currentSymfony = "SYMFONY_CLI_WORKING"
                } else {
                    $currentSymfony = "HTTP_WORKING" # Placeholder oznaczający że HTTP działa
                }
            }
        } else {
            # Nawet jeśli HTTP nie odpowiada, sprawdź czy symfony CLI pokazuje że serwer działa
            if ($symfonyServerStatus -eq "SYMFONY_CLI_RUNNING") {
                $currentSymfony = "SYMFONY_CLI_STARTING" # Może się dopiero uruchamiać
            }
        }
        
        # Dodatkowe sprawdzenie MQTT z WMI
        if (-not $currentMqtt) {
            $allPhpProcesses = Get-WmiObject Win32_Process | Where-Object { $_.Name -eq "php.exe" }
            foreach ($proc in $allPhpProcesses) {
                if ($proc.CommandLine -and ($proc.CommandLine -like "*mqtt-listen*" -or $proc.CommandLine -like "*app:mqtt-listen*")) {
                    $currentMqtt = Get-Process -Id $proc.ProcessId -ErrorAction SilentlyContinue
                    break
                }
            }
        }
        
        Write-Host "Mercure: $(if ($currentMercure) { "✓ Działa (PID: $($currentMercure.Id))" } else { "❌ Nie działa" })"
        Write-Host "Symfony: $(if ($currentSymfony -eq "SYMFONY_CLI_WORKING") { "✓ Działa (Symfony CLI + HTTP 8000)" } elseif ($currentSymfony -eq "SYMFONY_CLI_STARTING") { "⚠️ Uruchamianie (Symfony CLI)" } elseif ($currentSymfony -eq "HTTP_WORKING") { "✓ Działa (HTTP 8000 odpowiada)" } elseif ($currentSymfony) { "✓ Działa (PID: $($currentSymfony.Id))" } else { "❌ Nie działa" })"
        Write-Host "MQTT Listener: $(if ($currentMqtt) { "✓ Działa (PID: $($currentMqtt.Id))" } else { "❌ Nie działa" })"
        
    } catch {
        Write-Error "Błąd podczas uruchamiania usług: $_"
    }
} else {
    Write-Error "Nie można uruchomić usług - brakuje wymaganych plików lub katalogów."
}

Write-Host "`n=== Setup zakończony ==="
