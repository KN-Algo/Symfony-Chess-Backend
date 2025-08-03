#!/bin/bash

# Ustawienia – ścieżki i zmienne środowiskowe (do uzupełnienia przez użytkownika)
MERCURE_DIR="$HOME/mercure"                              # katalog Mercure
BACKEND_DIR="$(pwd)"                                     # katalog projektu Symfony (backend)
JWT_KEY="YourSecretJWTKey"                               # klucz JWT dla Mercure

if [[ $1 == "--help" || $1 == "-h" ]]; then
    echo "Użycie: $0 [--force] [--jwt <klucz>] "
    echo "  --force         Wymusza instalację potrzebnych narzędzi bez pytania"
    echo "  --jwt <klucz>   Podaje klucz JWT dla Mercure (domyślnie: $JWT_KEY)"
    exit 0
fi

if [[ "$1" == "--jwt" && -n "$3" ]]; then
    JWT_KEY="$3"
fi

# Parametr --force dla automatycznego trybu
FORCE=false
if [[ "$1" == "--force" ]]; then
    FORCE=true
fi

# Parametr --jwt dla podania klucza JWT
if [[ "$2" == "--jwt" ]]; then
    JWT_KEY="$3"
fi

# Kolory dla output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Funkcje pomocnicze
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

print_info() {
    echo -e "${BLUE}$1${NC}"
}

# Funkcja do sprawdzania czy proces działa
is_process_running() {
    pgrep -f "$1" > /dev/null 2>&1
}

# Funkcja do sprawdzania czy port jest zajęty
is_port_busy() {
    netstat -tuln | grep ":$1 " > /dev/null 2>&1
}

# Funkcja do sprawdzania czy serwer HTTP odpowiada
test_http_server() {
    local url="$1"
    local timeout="${2:-3}"
    curl -s --max-time "$timeout" "$url" > /dev/null 2>&1
}

# Funkcja loading bar
show_loading() {
    local duration="$1"
    local message="$2"
    echo -n "$message"
    for ((i=1; i<=duration; i++)); do
        echo -n "."
        sleep 1
    done
    echo ""
}

# 1. Sprawdzenie PHP (>= 8.2)
echo "=== Sprawdzanie PHP 8.2+ ==="
PHP_OK=false

if command -v php > /dev/null 2>&1; then
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    echo "Znaleziono PHP w wersji: $PHP_VERSION"
    if [[ $PHP_VERSION =~ ^8\.([2-9]|[1-9][0-9]) ]]; then
        PHP_OK=true
        print_success "PHP 8.2+ jest dostępne."
    fi
fi

if [ "$PHP_OK" = false ]; then
    print_error "PHP 8.2+ nie jest dostępne."
    if [ "$FORCE" = true ]; then
        echo "Instaluję PHP 8.4 (tryb --force)..."
        
        # Dodanie repozytorium ondrej/php
        echo "Dodaję repozytorium ondrej/php..."
        sudo LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
        sudo apt update
        
        # Instalacja PHP 8.4 z najważniejszymi rozszerzeniami
        echo "Instaluję PHP 8.4 z rozszerzeniami..."
        sudo apt install -y php8.4-cli php8.4-common php8.4-bcmath php8.4-bz2 php8.4-curl php8.4-gd php8.4-gmp php8.4-intl php8.4-mbstring php8.4-opcache php8.4-readline php8.4-xml php8.4-zip php8.4-sqlite3
        
        if command -v php > /dev/null 2>&1; then
            print_success "PHP 8.4 zainstalowane pomyślnie."
            PHP_OK=true
        else
            print_error "Nie udało się zainstalować PHP."
            exit 1
        fi
    else
        read -p "Brak PHP 8.2+. Czy chcesz zainstalować PHP 8.4 teraz? (T/n): " confirm
        if [[ ! $confirm =~ ^[Nn] ]]; then
            echo "Instalacja PHP 8.4 przez repozytorium ondrej/php..."
            
            # Dodanie repozytorium ondrej/php
            echo "Dodaję repozytorium ondrej/php..."
            sudo LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
            sudo apt update
            
            # Instalacja PHP 8.4 z najważniejszymi rozszerzeniami
            echo "Instaluję PHP 8.4 z rozszerzeniami..."
            sudo apt install -y php8.4-cli php8.4-common php8.4-bcmath php8.4-bz2 php8.4-curl php8.4-gd php8.4-gmp php8.4-intl php8.4-mbstring php8.4-opcache php8.4-readline php8.4-xml php8.4-zip php8.4-sqlite3
            
            if command -v php > /dev/null 2>&1; then
                print_success "PHP 8.4 zainstalowane pomyślnie."
                PHP_OK=true
            else
                print_warning "Sprawdź instalację PHP."
            fi
        else
            print_error "PHP 8.2+ jest wymagane do działania aplikacji. Instalacja przerwana."
            exit 1
        fi
    fi
fi

if [ "$PHP_OK" = false ]; then
    print_error "Nie udało się zainstalować lub znaleźć PHP 8.2+. Sprawdź instalację."
    exit 1
fi

# 2. Sprawdzenie Composer
echo -e "\n=== Sprawdzanie Composer ==="
if ! command -v composer > /dev/null 2>&1; then
    print_error "Composer nie jest zainstalowany."
    if [ "$FORCE" = true ]; then
        echo "Instaluję Composer (tryb --force)..."
        # Instalacja Composera
        curl -sS https://getcomposer.org/installer | php
        sudo mv composer.phar /usr/local/bin/composer
        sudo chmod +x /usr/local/bin/composer
        
        if command -v composer > /dev/null 2>&1; then
            print_success "Composer zainstalowany pomyślnie."
        else
            print_error "Nie udało się zainstalować Composera."
            exit 1
        fi
    else
        read -p "Composer nie jest zainstalowany. Czy chcesz go pobrać i zainstalować? (T/n): " confirm
        if [[ ! $confirm =~ ^[Nn] ]]; then
            echo "Pobieranie i instalacja Composera..."
            curl -sS https://getcomposer.org/installer | php
            sudo mv composer.phar /usr/local/bin/composer
            sudo chmod +x /usr/local/bin/composer
            
            if command -v composer > /dev/null 2>&1; then
                print_success "Composer zainstalowany pomyślnie."
            else
                print_error "Nie udało się zainstalować Composera."
                exit 1
            fi
        else
            print_error "Composer jest wymagany do działania aplikacji. Instalacja przerwana."
            exit 1
        fi
    fi
else
    print_success "Composer jest dostępny."
fi

# 3. Sprawdzenie Symfony CLI
echo -e "\n=== Sprawdzanie Symfony CLI ==="
if ! command -v symfony > /dev/null 2>&1; then
    print_error "Symfony CLI nie jest zainstalowany."
    if [ "$FORCE" = true ]; then
        echo "Instaluję Symfony CLI (tryb --force)..."
        # Instalacja Symfony CLI
        curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | sudo -E bash
        sudo apt install -y symfony-cli
        
        if command -v symfony > /dev/null 2>&1; then
            print_success "Symfony CLI zainstalowany pomyślnie."
        else
            print_error "Nie udało się zainstalować Symfony CLI."
            print_warning "Będzie używany php -S jako alternatywa."
        fi
    else
        read -p "Symfony CLI nie jest zainstalowany. Czy chcesz go pobrać i zainstalować? (T/n): " confirm
        if [[ ! $confirm =~ ^[Nn] ]]; then
            echo "Pobieranie i instalacja Symfony CLI..."
            curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | sudo -E bash
            sudo apt install -y symfony-cli
            
            if command -v symfony > /dev/null 2>&1; then
                print_success "Symfony CLI zainstalowany pomyślnie."
            else
                print_error "Nie udało się zainstalować Symfony CLI."
                print_warning "Będzie używany php -S jako alternatywa."
            fi
        else
            print_warning "Symfony CLI nie został zainstalowany. Będzie używany php -S jako alternatywa."
        fi
    fi
else
    print_success "Symfony CLI jest dostępny."
fi

# 4. Sprawdzenie Mercure
echo -e "\n=== Sprawdzanie Mercure ==="
if [ ! -f "$MERCURE_DIR/mercure" ]; then
    print_error "Mercure nie został znaleziony w katalogu: $MERCURE_DIR"
    if [ "$FORCE" = true ]; then
        echo "Tworzę katalog Mercure i pobieram plik wykonywalny..."
        mkdir -p "$MERCURE_DIR"
        
        # Pobieranie najnowszej wersji Mercure dla Linux
        echo "Pobieranie Mercure..."
        MERCURE_URL="https://github.com/dunglas/mercure/releases/latest/download/mercure_Linux_x86_64.tar.gz"
        MERCURE_TAR="$MERCURE_DIR/mercure.tar.gz"
        curl -L "$MERCURE_URL" -o "$MERCURE_TAR"
        
        # Rozpakowanie
        tar -xzf "$MERCURE_TAR" -C "$MERCURE_DIR"
        rm "$MERCURE_TAR"
        chmod +x "$MERCURE_DIR/mercure"
        print_success "Mercure pobrany i rozpakowany pomyślnie."
        
        # Tworzenie pliku dev.Caddyfile
        echo "Tworzę plik konfiguracyjny dev.Caddyfile..."
        cat > "$MERCURE_DIR/dev.Caddyfile" << 'EOF'
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{$GLOBAL_OPTIONS}
}

{$CADDY_EXTRA_CONFIG}

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
		{$MERCURE_EXTRA_DIRECTIVES}
	}

	{$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt `User-agent: *
	Disallow: /`
	respond "Not Found" 404
}
EOF
        print_success "Plik dev.Caddyfile utworzony pomyślnie."
    else
        print_warning "Mercure nie został znaleziony. Upewnij się, że mercure znajduje się w: $MERCURE_DIR"
        read -p "Czy chcesz pobrać Mercure automatycznie? (T/n): " confirm
        if [[ ! $confirm =~ ^[Nn] ]]; then
            mkdir -p "$MERCURE_DIR"
            echo "Pobieranie Mercure..."
            MERCURE_URL="https://github.com/dunglas/mercure/releases/latest/download/mercure_Linux_x86_64.tar.gz"
            MERCURE_TAR="$MERCURE_DIR/mercure.tar.gz"
            curl -L "$MERCURE_URL" -o "$MERCURE_TAR"
            
            tar -xzf "$MERCURE_TAR" -C "$MERCURE_DIR"
            rm "$MERCURE_TAR"
            chmod +x "$MERCURE_DIR/mercure"
            print_success "Mercure pobrany i rozpakowany pomyślnie."
            
            # Tworzenie pliku dev.Caddyfile
            echo "Tworzę plik konfiguracyjny dev.Caddyfile..."
            cat > "$MERCURE_DIR/dev.Caddyfile" << 'EOF'
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{$GLOBAL_OPTIONS}
}

{$CADDY_EXTRA_CONFIG}

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
		{$MERCURE_EXTRA_DIRECTIVES}
	}

	{$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt `User-agent: *
	Disallow: /`
	respond "Not Found" 404
}
EOF
            print_success "Plik dev.Caddyfile utworzony pomyślnie."
        fi
    fi
else
    print_success "Mercure jest dostępny."
    
    # Zawsze utwórz/nadpisz plik dev.Caddyfile najnowszą konfiguracją
    echo "Aktualizuję plik konfiguracyjny dev.Caddyfile..."
    cat > "$MERCURE_DIR/dev.Caddyfile" << 'EOF'
# Learn how to configure the Mercure.rocks Hub on https://mercure.rocks/docs/hub/config
{
	{$GLOBAL_OPTIONS}
}

{$CADDY_EXTRA_CONFIG}

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
		{$MERCURE_EXTRA_DIRECTIVES}
	}

	{$CADDY_SERVER_EXTRA_DIRECTIVES}

	redir / /.well-known/mercure/ui/

	respond /healthz 200
	respond /robots.txt `User-agent: *
	Disallow: /`
	respond "Not Found" 404
}
EOF
    print_success "Plik dev.Caddyfile zaktualizowany pomyślnie."
fi

# 5. Sprawdzenie serwera MQTT (Mosquitto)
echo -e "\n=== Sprawdzanie Mosquitto MQTT ==="
if ! systemctl is-active --quiet mosquitto 2>/dev/null && ! service mosquitto status > /dev/null 2>&1; then
    print_error "Broker Mosquitto MQTT nie jest zainstalowany lub nie działa."
    if [ "$FORCE" = true ]; then
        echo "Instaluję Mosquitto (tryb --force)..."
        sudo apt update
        sudo apt install -y mosquitto mosquitto-clients
        sudo systemctl enable mosquitto
        sudo systemctl start mosquitto
        print_success "Mosquitto zainstalowany i uruchomiony."
    else
        read -p "Czy chcesz zainstalować broker MQTT (Eclipse Mosquitto)? (T/n): " confirm
        if [[ ! $confirm =~ ^[Nn] ]]; then
            sudo apt update
            sudo apt install -y mosquitto mosquitto-clients
            sudo systemctl enable mosquitto
            sudo systemctl start mosquitto
            print_success "Mosquitto zainstalowany i uruchomiony."
        else
            print_warning "Mosquitto nie został zainstalowany. MQTT może nie działać poprawnie."
        fi
    fi
else
    print_success "Mosquitto jest zainstalowany i uruchomiony."
fi

# 6. Instalacja zależności Composer
echo -e "\n=== Instalacja zależności projektu ==="
cd "$BACKEND_DIR"
if [ -f "composer.json" ]; then
    echo "Instaluję zależności Composer..."
    composer install --no-interaction
    print_success "Zależności zainstalowane pomyślnie."
else
    print_warning "Nie znaleziono pliku composer.json w katalogu projektu."
fi

# 6.5. Sprawdzenie i tworzenie pliku .env
echo -e "\n=== Sprawdzanie pliku .env ==="
ENV_PATH="$BACKEND_DIR/.env"
if [ ! -f "$ENV_PATH" ]; then
    echo "Tworzę plik .env z domyślną konfiguracją..."
    cat > "$ENV_PATH" << EOF
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"

MQTT_BROKER=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=szachmat_backend

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_JWT_SECRET=$JWT_KEY
EOF
    print_success "Plik .env utworzony pomyślnie."
else
    print_success "Plik .env już istnieje."
fi

# 7. Uruchomienie procesów
echo -e "\n=== Uruchamianie usług ==="

# Sprawdzenie czy procesy już działają
echo "Sprawdzanie działających procesów..."
MERCURE_RUNNING=false
SYMFONY_RUNNING=false
MQTT_LISTENER_RUNNING=false

if is_process_running "mercure"; then
    MERCURE_PID=$(pgrep -f "mercure")
    echo "⚠️ Mercure już działa (PID: $MERCURE_PID)"
    MERCURE_RUNNING=true
fi

if is_process_running "symfony.*server" || is_process_running "php.*-S.*:8000"; then
    SYMFONY_PID=$(pgrep -f "symfony.*server\|php.*-S.*:8000")
    echo "⚠️ Serwer Symfony już działa (PID: $SYMFONY_PID)"
    SYMFONY_RUNNING=true
fi

if is_process_running "php.*mqtt-listen\|php.*app:mqtt-listen"; then
    MQTT_PID=$(pgrep -f "php.*mqtt-listen\|php.*app:mqtt-listen")
    echo "⚠️ MQTT Listener już działa (PID: $MQTT_PID)"
    MQTT_LISTENER_RUNNING=true
fi

if [ "$MERCURE_RUNNING" = true ] || [ "$SYMFONY_RUNNING" = true ] || [ "$MQTT_LISTENER_RUNNING" = true ]; then
    if [ "$FORCE" = false ]; then
        read -p "Czy chcesz zatrzymać istniejące procesy i uruchomić je ponownie? (T/n): " confirm
        if [[ $confirm =~ ^[Tt] ]]; then
            echo "Zatrzymywanie istniejących procesów..."
            if [ "$MERCURE_RUNNING" = true ]; then
                killall mercure 2>/dev/null
                print_success "Mercure zatrzymany"
            fi
            if [ "$SYMFONY_RUNNING" = true ]; then
                pkill -f "symfony.*server" 2>/dev/null
                pkill -f "php.*-S.*:8000" 2>/dev/null
                print_success "Serwer Symfony zatrzymany"
            fi
            if [ "$MQTT_LISTENER_RUNNING" = true ]; then
                pkill -f "php.*mqtt-listen" 2>/dev/null
                pkill -f "php.*app:mqtt-listen" 2>/dev/null
                print_success "MQTT Listener zatrzymany"
            fi
            sleep 3
            
            # Resetujemy zmienne
            MERCURE_RUNNING=false
            SYMFONY_RUNNING=false
            MQTT_LISTENER_RUNNING=false
        else
            echo "Pomijam uruchamianie - istniejące procesy pozostają aktywne."
            echo -e "\n=== Setup zakończony ==="
            exit 0
        fi
    else
        echo "Tryb --force: Zatrzymuję istniejące procesy automatycznie..."
        if [ "$MERCURE_RUNNING" = true ]; then
            killall mercure 2>/dev/null
            print_success "Mercure zatrzymany"
        fi
        if [ "$SYMFONY_RUNNING" = true ]; then
            pkill -f "symfony.*server" 2>/dev/null
            pkill -f "php.*-S.*:8000" 2>/dev/null
            print_success "Serwer Symfony zatrzymany"
        fi
        if [ "$MQTT_LISTENER_RUNNING" = true ]; then
            pkill -f "php.*mqtt-listen" 2>/dev/null
            pkill -f "php.*app:mqtt-listen" 2>/dev/null
            print_success "MQTT Listener zatrzymany"
        fi
        sleep 3
        
        # Resetujemy zmienne
        MERCURE_RUNNING=false
        SYMFONY_RUNNING=false
        MQTT_LISTENER_RUNNING=false
    fi
fi

echo "Uruchamianie usług w osobnych terminalach..."

# Sprawdzenie czy wszystkie ścieżki istnieją
ALL_PATHS_VALID=true

if [ ! -f "$MERCURE_DIR/mercure" ]; then
    print_warning "Nie znaleziono mercure w: $MERCURE_DIR"
    ALL_PATHS_VALID=false
fi

if [ ! -d "$BACKEND_DIR" ]; then
    print_warning "Nie znaleziono katalogu projektu: $BACKEND_DIR"
    ALL_PATHS_VALID=false
fi

if [ ! -f "$BACKEND_DIR/bin/console" ]; then
    print_warning "Nie znaleziono pliku console w: $BACKEND_DIR/bin/console"
    ALL_PATHS_VALID=false
fi

if [ "$ALL_PATHS_VALID" = true ]; then
    # Sprawdzenie portów przed uruchomieniem
    PORT_CONFLICTS=()
    
    if is_port_busy 3000; then
        PORT_CONFLICTS+=("Port 3000 (Mercure) jest już zajęty")
    fi
    
    if is_port_busy 8000; then
        PORT_CONFLICTS+=("Port 8000 (Symfony) jest już zajęty")
    fi
    
    if is_port_busy 1883; then
        PORT_CONFLICTS+=("Port 1883 (MQTT) jest już zajęty")
    fi
    
    if [ ${#PORT_CONFLICTS[@]} -gt 0 ]; then
        print_warning "Wykryto konflikty portów:"
        for conflict in "${PORT_CONFLICTS[@]}"; do
            print_warning "  $conflict"
        done
        
        if [ "$FORCE" = false ]; then
            read -p "Czy mimo to chcesz kontynuować uruchamianie? (T/n): " confirm
            if [[ $confirm =~ ^[Nn] ]]; then
                echo "Anulowano uruchamianie usług."
                exit 0
            fi
        else
            echo "Tryb --force: Kontynuuję mimo konfliktów portów..."
        fi
    fi
    
    # Mercure (tylko jeśli nie działa)
    if [ "$MERCURE_RUNNING" = false ]; then
        echo "Uruchamianie Mercure..."
        if command -v gnome-terminal > /dev/null 2>&1; then
            gnome-terminal -- bash -c "cd '$MERCURE_DIR'; export MERCURE_PUBLISHER_JWT_KEY='$JWT_KEY'; export MERCURE_SUBSCRIBER_JWT_KEY='$JWT_KEY'; echo 'Uruchamianie Mercure...'; ./mercure run --config dev.Caddyfile; echo 'Mercure zatrzymany. Naciśnij Enter aby zamknąć...'; read"
        elif command -v xterm > /dev/null 2>&1; then
            xterm -e "cd '$MERCURE_DIR'; export MERCURE_PUBLISHER_JWT_KEY='$JWT_KEY'; export MERCURE_SUBSCRIBER_JWT_KEY='$JWT_KEY'; echo 'Uruchamianie Mercure...'; ./mercure run --config dev.Caddyfile; echo 'Mercure zatrzymany. Naciśnij Enter aby zamknąć...'; read" &
        else
            # Fallback - uruchom w tle
            cd "$MERCURE_DIR"
            export MERCURE_PUBLISHER_JWT_KEY="$JWT_KEY"
            export MERCURE_SUBSCRIBER_JWT_KEY="$JWT_KEY"
            nohup ./mercure run --config dev.Caddyfile > mercure.log 2>&1 &
            echo "Mercure uruchomiony w tle (log: $MERCURE_DIR/mercure.log)"
        fi
        sleep 2
    else
        echo "⏭️ Mercure już działa - pomijam uruchomienie"
    fi
    
    # Symfony server (tylko jeśli nie działa)
    if [ "$SYMFONY_RUNNING" = false ]; then
        echo "Uruchamianie serwera Symfony..."
        cd "$BACKEND_DIR"
        
        # Zatrzymaj istniejący serwer na wszelki wypadek
        if command -v symfony > /dev/null 2>&1; then
            symfony server:stop > /dev/null 2>&1
        fi
        sleep 2
        
        # Sprawdź czy symfony CLI jest dostępny, jeśli nie to zainstaluj
        if ! command -v symfony > /dev/null 2>&1; then
            print_warning "Symfony CLI nie jest zainstalowany. Instaluję automatycznie..."
            
            # Instalacja Symfony CLI
            curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | sudo -E bash > /dev/null 2>&1
            sudo apt install -y symfony-cli > /dev/null 2>&1
            
            if ! command -v symfony > /dev/null 2>&1; then
                print_error "Nie udało się zainstalować Symfony CLI. Używam php -S jako fallback..."
                if command -v gnome-terminal > /dev/null 2>&1; then
                    gnome-terminal -- bash -c "cd '$BACKEND_DIR'; echo 'Uruchamianie serwera PHP (port 8000)...'; php -S 127.0.0.1:8000 -t public; echo 'Serwer PHP zatrzymany. Naciśnij Enter aby zamknąć...'; read"
                elif command -v xterm > /dev/null 2>&1; then
                    xterm -e "cd '$BACKEND_DIR'; echo 'Uruchamianie serwera PHP (port 8000)...'; php -S 127.0.0.1:8000 -t public; echo 'Serwer PHP zatrzymany. Naciśnij Enter aby zamknąć...'; read" &
                else
                    # Fallback - uruchom w tle
                    nohup php -S 127.0.0.1:8000 -t public > symfony.log 2>&1 &
                    echo "Serwer PHP uruchomiony w tle (log: $BACKEND_DIR/symfony.log)"
                fi
                sleep 3
            else
                print_success "Symfony CLI zainstalowany pomyślnie."
            fi
        fi
        
        # Używaj Symfony CLI jeśli jest dostępny
        if command -v symfony > /dev/null 2>&1; then
            if command -v gnome-terminal > /dev/null 2>&1; then
                gnome-terminal -- bash -c "cd '$BACKEND_DIR'; echo 'Uruchamianie serwera Symfony...'; symfony server:start; echo 'Serwer Symfony zatrzymany. Naciśnij Enter aby zamknąć...'; read"
            elif command -v xterm > /dev/null 2>&1; then
                xterm -e "cd '$BACKEND_DIR'; echo 'Uruchamianie serwera Symfony...'; symfony server:start; echo 'Serwer Symfony zatrzymany. Naciśnij Enter aby zamknąć...'; read" &
            else
                # Fallback - uruchom w tle
                nohup symfony server:start > symfony.log 2>&1 &
                echo "Serwer Symfony uruchomiony w tle (log: $BACKEND_DIR/symfony.log)"
            fi
        fi
        sleep 3
    else
        echo "⏭️ Serwer Symfony już działa - pomijam uruchomienie"
    fi
    
    # MQTT listener (tylko jeśli nie działa)
    if [ "$MQTT_LISTENER_RUNNING" = false ]; then
        echo "Uruchamianie nasłuchu MQTT..."
        if command -v gnome-terminal > /dev/null 2>&1; then
            gnome-terminal -- bash -c "cd '$BACKEND_DIR'; echo 'Uruchamianie nasłuchu MQTT...'; php bin/console app:mqtt-listen; echo 'MQTT Listener zatrzymany. Naciśnij Enter aby zamknąć...'; read"
        elif command -v xterm > /dev/null 2>&1; then
            xterm -e "cd '$BACKEND_DIR'; echo 'Uruchamianie nasłuchu MQTT...'; php bin/console app:mqtt-listen; echo 'MQTT Listener zatrzymany. Naciśnij Enter aby zamknąć...'; read" &
        else
            # Fallback - uruchom w tle
            cd "$BACKEND_DIR"
            nohup php bin/console app:mqtt-listen > mqtt.log 2>&1 &
            echo "MQTT Listener uruchomiony w tle (log: $BACKEND_DIR/mqtt.log)"
        fi
    else
        echo "⏭️ MQTT Listener już działa - pomijam uruchomienie"
    fi
    
    print_success "Sprawdzanie i uruchamianie usług zakończone."
    echo "Sprawdź okna terminali, aby upewnić się, że wszystkie usługi działają poprawnie."
    
    # Podsumowanie aktywnych usług
    echo -e "\n=== Status usług ==="
    show_loading 10 "Czekam na uruchomienie usług"
    
    # Sprawdzanie statusu usług
    CURRENT_MERCURE=false
    CURRENT_SYMFONY=false  
    CURRENT_MQTT=false
    
    if is_process_running "mercure"; then
        CURRENT_MERCURE=true
        MERCURE_PID=$(pgrep -f "mercure")
    fi
    
    # Sprawdzanie Symfony - HTTP i procesy
    SYMFONY_HTTP_WORKS=false
    if test_http_server "http://127.0.0.1:8000"; then
        SYMFONY_HTTP_WORKS=true
    fi
    
    if is_process_running "symfony.*server" || is_process_running "php.*-S.*:8000"; then
        CURRENT_SYMFONY=true
        SYMFONY_PID=$(pgrep -f "symfony.*server\|php.*-S.*:8000")
    elif [ "$SYMFONY_HTTP_WORKS" = true ]; then
        CURRENT_SYMFONY="HTTP_WORKING"
    fi
    
    if is_process_running "php.*mqtt-listen\|php.*app:mqtt-listen"; then
        CURRENT_MQTT=true
        MQTT_PID=$(pgrep -f "php.*mqtt-listen\|php.*app:mqtt-listen")
    fi
    
    # Wyświetlanie statusu
    if [ "$CURRENT_MERCURE" = true ]; then
        print_success "Mercure: Działa (PID: $MERCURE_PID)"
    else
        print_error "Mercure: Nie działa"
    fi
    
    if [ "$CURRENT_SYMFONY" = true ]; then
        print_success "Symfony: Działa (PID: $SYMFONY_PID)"
    elif [ "$CURRENT_SYMFONY" = "HTTP_WORKING" ]; then
        print_success "Symfony: Działa (HTTP 8000 odpowiada)"
    else
        print_error "Symfony: Nie działa"
    fi
    
    if [ "$CURRENT_MQTT" = true ]; then
        print_success "MQTT Listener: Działa (PID: $MQTT_PID)"
    else
        print_error "MQTT Listener: Nie działa"
    fi
else
    print_error "Nie można uruchomić usług - brakuje wymaganych plików lub katalogów."
fi

echo -e "\n=== Setup zakończony ==="
