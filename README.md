# Symfony Chess Backend

System backendu dla inteligentnej szachownicy opartej na Raspberry Pi z silnikiem szachowym AI. Backend zarządza komunikacją między aplikacją webową, fizyczną szachownicą i silnikiem szachowym poprzez protokół MQTT oraz dostarcza REST API i powiadomienia real-time przez Mercure.

## 📖 Spis treści

-   [🚀 Funkcjonalności](#-funkcjonalności)
-   [🏗️ Architektura systemu](#-architektura-systemu)
-   [📋 Wymagania](#-wymagania)
-   [🛠️ Instalacja](#-instalacja)
-   [🎮 Użytkowanie](#-użytkowanie)
-   [📊 Monitorowanie](#-monitorowanie)
-   [🐛 Debugowanie](#-debugowanie)
-   [📡 Dokumentacja komunikacji MQTT](#-dokumentacja-komunikacji-mqtt)
-   [🔄 Przepływ walidacji ruchu](#-przepływ-walidacji-ruchu)
-   [🎯 Walidacja i synchronizacja](#-walidacja-i-synchronizacja)
-   [📨 Mercure Real-time Messages](#-mercure-real-time-messages)
-   [🔐 Mercure Konfiguracja](#-mercure-konfiguracja)
-   [🐳 Docker - Szybki start](#-docker---szybki-start)
-   [📝 Status implementacji](#-status-implementacji)

## 🚀 Funkcjonalności

-   **🌐 REST API** - Endpointy dla wykonywania ruchów, resetowania gry, możliwych ruchów i sprawdzania stanu zdrowia
-   **📡 MQTT Broker** - Komunikacja z Raspberry Pi i silnikiem szachowym z pełną walidacją
-   **⚡ Real-time Mercure** - Powiadomienia na żywo przez Server-Sent Events z bezpośrednią HTTP komunikacją
-   **🎯 Zarządzanie stanem gry** - Śledzenie ruchów, pozycji i historii partii z walidacją przez silnik
-   **🏥 Health Check** - Monitorowanie stanu wszystkich komponentów systemu
-   **📝 Logowanie** - Szczegółowe logi komunikacji i błędów
-   **🔄 Synchronizacja** - Dwukierunkowa komunikacja między UI a fizyczną planszą z walidacją ruchów
-   **♟️ Możliwe ruchy** - Real-time podpowiedzi ruchów z silnika szachowego
-   **🔐 JWT autoryzacja** - Bezpieczna komunikacja z Mercure Hub

## 🏗️ Architektura systemu

```
┌─────────────┐    REST API     ┌─────────────┐    MQTT      ┌──────────────┐
│   Web App   │◄──────────────►│   Backend   │◄────────────►│ Raspberry Pi │
└─────────────┘                 └─────────────┘              └──────────────┘
       ▲                               │                              ▲
       │      Mercure (HTTP+JWT)       │ MQTT                         │
       └───────────────────────────────┘                              │
                                       │                              │
                                       ▼                              │
                              ┌─────────────┐                         │
                              │Chess Engine │                         │
                              │     AI      │◄────────────────────────┘
                              └─────────────┘        MQTT
```

## 📋 Wymagania

-   **PHP 8.2+** z rozszerzeniami: mbstring, xml, ctype, json
-   **Composer** 2.0+
-   **Symfony 7.3+** z bundlami: Mercure, MQTT, HTTP Client
-   **MQTT Broker** (np. Mosquitto)
-   **Mercure Hub** dla Server-Sent Events na porcie 3000
-   **SQLite/MySQL/PostgreSQL** (opcjonalne)

## 🛠️ Instalacja

### 1. Klonowanie repozytorium

```bash
git clone https://github.com/KN-Algo/Symfony-Chess-Backend.git
cd Symfony-Chess-Backend
```

### 2. Instalacja zależności

```bash
composer install
```

### 3. Konfiguracja środowiska

Utwórz i edytuj plik `.env`:

```properties
# MQTT Configuration
MQTT_BROKER=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=szachmat_backend

# Mercure Configuration (z JWT autoryzacją)
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_JWT_SECRET=TWÓJ_TOKEN_JWT

# Database (opcjonalne)
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

### 4. Uruchomienie Mercure Hub

```bash
# W katalogu mercure
$env:MERCURE_PUBLISHER_JWT_KEY='TWÓJ_TOKEN_JWT'
$env:MERCURE_SUBSCRIBER_JWT_KEY='TWÓJ_TOKEN_JWT'
.\mercure.exe run --config dev.Caddyfile
```

### 5. Uruchomienie serwera Symfony

```bash
symfony server:start --no-tls
```

### 6. Uruchomienie MQTT Listener

```bash
php bin/console app:mqtt-listen
```

## 🎮 Użytkowanie

### REST API Endpoints

-   `POST /move` - Wykonaj ruch (walidowany przez silnik)
-   `POST /restart` - Zresetuj grę
-   `POST /possible-moves` - Żądaj możliwych ruchów dla pozycji
-   `GET /test-mercure` - Test endpointu Mercure
-   `GET /state` - Pobierz stan gry
-   `GET /health` - Sprawdź stan systemu

### Przykład wykonania ruchu

```bash
curl -X POST http://localhost:8000/move \
  -H "Content-Type: application/json" \
  -d '{"from": "e2", "to": "e4"}'
```

### Przykład żądania możliwych ruchów

```bash
curl -X POST http://localhost:8000/possible-moves \
  -H "Content-Type: application/json" \
  -d '{"position": "e2"}'
```

Odpowiedź zostanie przesłana przez Mercure w czasie rzeczywistym:

```json
{
    "type": "possible_moves",
    "position": "e2",
    "moves": ["e3", "e4"]
}
```

### Mercure Subscription

```javascript
const eventSource = new EventSource(
    "http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates"
);
eventSource.onmessage = function (event) {
    const data = JSON.parse(event.data);
    console.log("Otrzymano:", data);
};
```

## 🔧 Komendy

-   `php bin/console app:mqtt-listen` - Uruchom listener MQTT
-   `php bin/console cache:clear` - Wyczyść cache
-   `php bin/console debug:router` - Pokaż dostępne trasy
-   `php bin/console debug:container mercure` - Sprawdź konfigurację Mercure

## 📊 Monitorowanie

System dostarcza endpoint `/health` który zwraca status wszystkich komponentów:

> [!WARNING]
> Poniższe dane są przykładowe i mogą się różnić w zależności od stanu systemu.

```json
{
    "status": "healthy",
    "timestamp": "...",
    "components": {
        "mqtt": { "status": "healthy", "response_time": 12.5 },
        "mercure": { "status": "healthy", "response_time": 45.2 },
        "raspberry_pi": { "status": "warning", "response_time": null },
        "chess_engine": { "status": "healthy", "response_time": 89.1 }
    }
}
```

## 🐛 Debugowanie

### Mercure Debugging

System używa bezpośredniej HTTP komunikacji z Mercure Hub z JWT autoryzacją:

-   Logi zapisywane w `public/mercure-debug.log`
-   Test endpoint: `GET /test-mercure`
-   Sprawdź JWT token: `php generate_jwt.php`

### MQTT Debugging

-   MQTT Listener loguje wszystkie wiadomości
-   Subscribe na `move/+` dla wszystkich move topików
-   Szczegółowe logi w konsoli i pliku

---

# 📡 Dokumentacja komunikacji MQTT

| Komponent           | Subskrybuje (MQTT topic)                                                                                                                                                                                                                                                                                                                                                                                                                  | Publikuje (MQTT topic)                                                                                                                                                                                                                                                                                                     |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Web App**         | • Mercure WebSocket z chess/updates                                                                                                                                                                                                                                                                                                                                                                                                       | • `move/web` – ruch wysłany przez UI<br>• `move/possible_moves/request` – żądanie możliwych ruchów                                                                                                                                                                                                                         |
| **Silnik szachowy** | • `move/engine` – żądanie walidacji ruchu<br>• `engine/possible_moves/request` – żądanie możliwych ruchów                                                                                                                                                                                                                                                                                                                                 | • `move/ai` – ruch AI<br>• `status/engine` – `thinking`/`ready`/`error`/`analyzing`<br>• `engine/possible_moves/response` – odpowiedź z możliwymi ruchami<br>• `engine/move/confirmed` – potwierdzenie legalnego ruchu z FEN<br>• `engine/move/rejected` – odrzucenie nielegalnego ruchu                                   |
| **Raspberry Pi**    | • `move/raspi` – polecenie fizycznego ruchu<br>• `move/raspi/rejected` – polecenie cofnięcia ruchu<br>• `control/restart` – sygnał resetu gry                                                                                                                                                                                                                                                                                             | • `move/player` – wykryty ruch gracza na planszy<br>• `status/raspi` – `ready`/`moving`/`error`/`busy`                                                                                                                                                                                                                     |
| **Backend**         | • `move/player` – ruch fizyczny od RPi<br>• `move/web` – ruch z UI<br>• `move/ai` – ruch od silnika<br>• `move/possible_moves/request` – żądanie od UI<br>• `engine/possible_moves/response` – odpowiedź od silnika<br>• `engine/move/confirmed` – potwierdzenie od silnika<br>• `engine/move/rejected` – odrzucenie od silnika<br>• `status/raspi` – status RPi<br>• `status/engine` – status silnika<br>• `control/restart` – reset gry | • `move/engine` – żądanie walidacji do silnika<br>• `move/raspi` – polecenie ruchu do RPi<br>• `move/raspi/rejected` – polecenie cofnięcia do RPi<br>• `engine/possible_moves/request` – żądanie do silnika<br>• `state/update` – pełny stan gry<br>• `log/update` – aktualizacja logów<br>• `control/restart` – reset gry |

## 🔄 Przepływ walidacji ruchu:

### 1. Ruch gracza z Web App:

```
Web App → move/web → Backend → move/engine (walidacja + physical: false) → Silnik →
engine/move/confirmed → Backend → move/raspi (do RPi) + Mercure (do UI)
```

### 2. Ruch fizyczny gracza na planszy:

```
RPi → move/player → Backend → move/engine (walidacja + physical: true) → Silnik →
engine/move/confirmed → Backend → Mercure (do UI) [RPi nic nie robi - pionek już jest na miejscu]
```

### 3. Nielegalny ruch fizyczny:

```
RPi → move/player → Backend → move/engine (walidacja + physical: true) → Silnik →
engine/move/rejected → Backend → move/raspi/rejected (cofnij ruch) + Mercure (do UI)
```

### 4. Odpowiedź AI:

```
Silnik → move/ai {from, to, fen, next_player} → Backend → move/raspi (do RPi) + Mercure (do UI)
```

### 5. Żądanie możliwych ruchów:

```
Web App → POST /possible-moves → Backend → move/possible_moves/request →
Backend → engine/possible_moves/request → Silnik → engine/possible_moves/response →
Backend → Mercure (do UI z type: possible_moves)
```

### 6. Reset gry:

```
Web App (REST API) → Backend → control/restart → RPi + Silnik
Backend → state/update + log/update + Mercure
```

## 📦 Przykładowe wiadomości MQTT na kanałach

Poniżej znajdziesz przykładowe treści wiadomości przesyłanych na każdym z głównych topiców MQTT w systemie. Każdy topic ma przykład wiadomości z minimalnie wymaganymi polami:

### `move/web` (Web App → Backend)

```json
{
    "from": "e2",
    "to": "e4",
    "physical": false
}
```

### `move/player` (RPi → Backend)

```json
{
    "from": "g1",
    "to": "f3",
    "physical": true
}
```

### `move/engine` (Backend → Silnik szachowy)

```json
{
    "from": "e2",
    "to": "e4",
    "current_fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
    "type": "move_validation",
    "physical": false
}
```

### `move/ai` (Silnik szachowy → Backend)

```json
{
    "from": "e7",
    "to": "e5",
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1"
}
```

### `move/raspi` (Backend → Raspberry Pi)

```json
{
    "from": "e2",
    "to": "e4"
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1"
}
```

### `move/raspi/rejected` (Backend → Raspberry Pi)

```json
{
    "from": "e2",
    "reason": "Illegal move: pawn cannot move two squares from e2 to e5",
    "action": "revert_move"
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

### `engine/move/confirmed` (Silnik szachowy → Backend)

```json
{
    "from": "e2",
    "to": "e4",
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1",
    "physical": false
}
```

### `engine/move/rejected` (Silnik szachowy → Backend)

```json
{
    "from": "e2",
    "to": "e5",
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
    "physical": true,
    "reason": "Illegal move: pawn cannot move two squares from e2 to e5"
}
```

### `engine/possible_moves/request` (Backend → Silnik szachowy)

```json
{
    "position": "e2",
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

### `engine/possible_moves/response` (Silnik szachowy → Backend)

```json
{
    "position": "e2",
    "moves": ["e3", "e4"]
}
```

### `status/raspi` (RPi → Backend)

```json
{
    "status": "ready"
}
```

### `status/engine` (Silnik szachowy → Backend)

```json
{
    "status": "thinking"
}
```

### `control/restart` (Backend → RPi/Silnik)

```json
{
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

### `state/update` (Backend → Web App)

```json
{
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
    "moves": ["e2e4", "e7e5"],
    "turn": "white"
}
```

### `log/update` (Backend → Web App)

```json
{
    "moves": ["e2e4", "e7e5"]
}
```

## 🎯 Walidacja i synchronizacja:

### Zasady walidacji:

> [!IMPORTANT]
>
> 1. **WSZYSTKIE ruchy** (fizyczne i webowe) są walidowane przez silnik
> 2. **Silnik jest źródłem prawdy** o legalności ruchów i FEN
> 3. **Flaga `physical`** określa źródło ruchu i reakcję na walidację

### Reakcje na walidację:

| Typ ruchu    | Walidacja | Akcja po confirmed        | Akcja po rejected            |
| ------------ | --------- | ------------------------- | ---------------------------- |
| **Webowy**   | ✅        | Wyślij `move/raspi`       | Powiadom UI o błędzie        |
| **Fizyczny** | ✅        | Nic (pionek już tam jest) | Wyślij `move/raspi/rejected` |

## 📨 Mercure Real-time Messages:

### Możliwe ruchy (real-time):

```json
{
    "type": "possible_moves",
    "position": "e2",
    "moves": ["e3", "e4"]
}
```

### Ruch oczekujący na walidację:

```json
{
  "type": "move_pending",
  "move": {"from": "e2", "to": "e4"},
  "physical": false,
  "state": {...}
}
```

### Ruch potwierdzony przez silnik:

```json
{
  "type": "move_confirmed",
  "move": {"from": "e2", "to": "e4"},
  "physical": false,
  "state": {...}
}
```

### Ruch odrzucony przez silnik:

```json
{
  "type": "move_rejected",
  "move": {"from": "e2", "to": "e5"},
  "reason": "Illegal move: pawn cannot move two squares from e2 to e5",
  "physical": false,
  "state": {...}
}
```

### Ruch AI wykonany:

```json
{
  "type": "ai_move_executed",
  "move": {"from": "g8", "to": "f6"},
  "state": {...}
}
```

### Statusy komponentów:

```json
{
  "type": "raspi_status",
  "data": {...},
  "timestamp": "17:30:15"
}

{
  "type": "engine_status",
  "data": {...},
  "timestamp": "17:30:20"
}
```

### Reset gry:

```json
{
    "type": "game_reset",
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
        "moves": [],
        "turn": "white"
    }
}
```

## 🔐 Mercure Konfiguracja:

### Bezpośrednia HTTP komunikacja:

-   Backend używa HTTP Client zamiast Symfony Hub
-   JWT token generowany w locie z claims: `{"mercure": {"publish": ["*"]}}`
-   Publiczne updates bez autoryzacji subskrypcji
-   Topic: `http://127.0.0.1:8000/chess/updates`

### Caddy konfiguracja (dev.Caddyfile):

```caddyfile
(cors) {
	@cors_preflight method OPTIONS

	header {
		Access-Control-Allow-Origin "{header.origin}"
		Vary Origin
		Access-Control-Expose-Headers "Authorization"
		Access-Control-Allow-Credentials "true"
	}

	handle @cors_preflight {
		header {
			Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE"
			Access-Control-Max-Age "3600"
		}
		respond "" 204
	}
}

:80 {
  import cors {header.origin}
}

:8000 {
    import cors {header.origin}
}

http://localhost:3000 {
    encode zstd gzip
    mercure {
        publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}
        subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}
        cors_origins *
        publish_origins *
        demo
        anonymous
        subscriptions
    }
    redir / /.well-known/mercure/ui/
    respond /healthz 200
}

```

### Uruchomienie Mercure:

```powershell
$env:MERCURE_PUBLISHER_JWT_KEY='TWÓJ_TOKEN_JWT'
$env:MERCURE_SUBSCRIBER_JWT_KEY='TWÓJ_TOKEN_JWT'
.\mercure.exe run --config dev.Caddyfile
```

## 🐳 Docker - Szybki start

Jeśli chcesz szybko uruchomić cały system bez lokalnej instalacji PHP i zależności, możesz użyć Docker:

### Wymagania Docker

-   Docker Desktop lub Docker Engine
-   Docker Compose v2+

### Klonowanie i uruchomienie

1. **Sklonuj repozytorium:**

    ```bash
    git clone https://github.com/KN-Algo/Symfony-Chess-Backend.git
    cd Symfony-Chess-Backend
    ```

2. **Skonfiguruj zmienne środowiskowe:**

    ```bash
    # Windows
    copy .env.example .env

    # Linux/Mac
    cp .env.example .env
    ```

    **Opcjonalnie** edytuj `.env` aby dostosować:

    - `RASPBERRY_PI_URL` - adres URL Twojego Raspberry Pi
    - `CHESS_ENGINE_URL` - adres URL silnika szachowego
    - `MERCURE_JWT_SECRET` - zmień na własny secret key

3. **Uruchom kontenery:**

    ```bash
    # Windows
    docker-compose up --build -d

    # Linux/Mac
    docker compose up --build -d
    ```

4. **Sprawdź status:**
    ```bash
    docker-compose ps
    ```

### Dostępne usługi

Po uruchomieniu dostępne będą następujące usługi:

| Usługa               | URL                              | Opis                          |
| -------------------- | -------------------------------- | ----------------------------- |
| **Backend API**      | http://localhost:8000            | Główne API Symfony            |
| **Health Dashboard** | http://localhost:8000/health     | Dashboard monitoringu systemu |
| **API Health**       | http://localhost:8000/api/health | JSON endpoint stanu systemu   |
| **MQTT Broker**      | localhost:1883                   | Mosquitto MQTT (port 1883)    |
| **Mercure Hub**      | http://localhost:3000            | Hub dla real-time komunikacji |

### Konfiguracja zewnętrznych komponentów

System jest przygotowany na podłączenie zewnętrznych komponentów. Aby je skonfigurować:

1. **Edytuj plik `.env`** (utworzony z `.env.example`):

    ```bash
    # Raspberry Pi Configuration
    RASPBERRY_PI_URL=http://192.168.1.100:8080

    # Chess Engine Configuration
    CHESS_ENGINE_URL=http://192.168.1.101:5000
    ```

2. **Przebuduj kontenery po zmianie konfiguracji:**
    ```bash
    docker-compose up --build -d
    ```

> **💡 Wskazówka:** System będzie działał nawet bez zewnętrznych komponentów - w panelu zdrowia zobaczysz ich status jako "Niedostępny" z odpowiednimi instrukcjami konfiguracji.

### Użyteczne komendy Docker

```bash
# Zatrzymanie wszystkich kontenerów
docker-compose down

# Rebuild i restart
docker-compose up --build -d

# Podgląd logów
docker-compose logs -f

# Logi konkretnej usługi
docker-compose logs -f symfony-backend

# Wejście do kontenera backend
docker-compose exec symfony-backend bash

# Czyszczenie wszystkiego (UWAGA: usuwa również dane!)
docker-compose down -v --rmi all
```

### Debugging kontenerów

```bash
# Status kontenerów
docker-compose ps

# Sprawdzenie zasobów
docker stats

# Sprawdzenie sieci Docker
docker network ls
docker network inspect symfony-chess-backend_chess-network
```

### Struktura Docker

Projekt używa następujących kontenerów:

-   **symfony-backend** - główna aplikacja Symfony (PHP 8.4)
-   **symfony-mqtt-listener** - nasłuchiwanie MQTT w tle
-   **mqtt-broker** - Mosquitto MQTT broker v2
-   **mercure-hub** - Dunglas Mercure dla WebSocket

Wszystkie kontenery są połączone w sieci `chess-network` co umożliwia im wzajemną komunikację przez nazwy kontenerów.

---

**📝 Uwaga:** Więcej szczegółów dotyczących konfiguracji zewnętrznych komponentów znajdziesz w pliku `EXTERNAL_COMPONENTS.md`.
