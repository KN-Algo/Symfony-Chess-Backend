
# Symfony Chess Backend

System backendu dla inteligentnej szachownicy opartej na Raspberry Pi z silnikiem szachowym AI. Backend zarządza komunikacją między aplikacją webową, fizyczną szachownicą i silnikiem szachowym poprzez protokół MQTT oraz dostarcza REST API i powiadomienia real-time przez Mercure.

## 📖 Spis treści
- [🚀 Funkcjonalności](#-funkcjonalności)
- [🏗️ Architektura systemu](#-architektura-systemu)
- [📋 Wymagania](#-wymagania)    
- [🛠️ Instalacja](#-instalacja)
- [🎮 Użytkowanie](#-użytkowanie)
- [📊 Monitorowanie](#-monitorowanie)
- [🐛 Debugowanie](#-debugowanie)
- [📡 Dokumentacja komunikacji MQTT](#-dokumentacja-komunikacji-mqtt)
- [🔄 Przepływ walidacji ruchu](#-przepływ-walidacji-ruchu)
- [🎯 Walidacja i synchronizacja](#-walidacja-i-synchronizacja)
- [📨 Mercure Real-time Messages](#-mercure-real-time-messages)
- [🔐 Mercure Konfiguracja](#-mercure-konfiguracja)
- [🚀 Status implementacji](#-status-implementacji)

## 🚀 Funkcjonalności

- **🌐 REST API** - Endpointy dla wykonywania ruchów, resetowania gry, możliwych ruchów i sprawdzania stanu zdrowia
- **📡 MQTT Broker** - Komunikacja z Raspberry Pi i silnikiem szachowym z pełną walidacją
- **⚡ Real-time Mercure** - Powiadomienia na żywo przez Server-Sent Events z bezpośrednią HTTP komunikacją
- **🎯 Zarządzanie stanem gry** - Śledzenie ruchów, pozycji i historii partii z walidacją przez silnik
- **🏥 Health Check** - Monitorowanie stanu wszystkich komponentów systemu
- **📝 Logowanie** - Szczegółowe logi komunikacji i błędów
- **🔄 Synchronizacja** - Dwukierunkowa komunikacja między UI a fizyczną planszą z walidacją ruchów
- **♟️ Możliwe ruchy** - Real-time podpowiedzi ruchów z silnika szachowego
- **🔐 JWT autoryzacja** - Bezpieczna komunikacja z Mercure Hub

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

- **PHP 8.2+** z rozszerzeniami: mbstring, xml, ctype, json
- **Composer** 2.0+
- **Symfony 7.3+** z bundlami: Mercure, MQTT, HTTP Client
- **MQTT Broker** (np. Mosquitto)
- **Mercure Hub** dla Server-Sent Events na porcie 3000
- **SQLite/MySQL/PostgreSQL** (opcjonalne)

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

- `POST /move` - Wykonaj ruch (walidowany przez silnik)
- `POST /restart` - Zresetuj grę
- `POST /possible-moves` - Żądaj możliwych ruchów dla pozycji
- `GET /test-mercure` - Test endpointu Mercure
- `GET /state` - Pobierz stan gry
- `GET /health` - Sprawdź stan systemu

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
const eventSource = new EventSource('http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates');
eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Otrzymano:', data);
};
```

## 🔧 Komendy

- `php bin/console app:mqtt-listen` - Uruchom listener MQTT
- `php bin/console cache:clear` - Wyczyść cache
- `php bin/console debug:router` - Pokaż dostępne trasy
- `php bin/console debug:container mercure` - Sprawdź konfigurację Mercure

## 📊 Monitorowanie

System dostarcza endpoint `/health` który zwraca status wszystkich komponentów:
> [!WARNING]
> Poniższe dane są przykładowe i mogą się różnić w zależności od stanu systemu.

```json
{
  "status": "healthy",
  "timestamp": "...",
  "components": {
    "mqtt": {"status": "healthy", "response_time": 12.5},
    "mercure": {"status": "healthy", "response_time": 45.2},
    "raspberry_pi": {"status": "warning", "response_time": null},
    "chess_engine": {"status": "healthy", "response_time": 89.1}
  }
}
```

## 🐛 Debugowanie

### Mercure Debugging
System używa bezpośredniej HTTP komunikacji z Mercure Hub z JWT autoryzacją:
- Logi zapisywane w `public/mercure-debug.log`
- Test endpoint: `GET /test-mercure`
- Sprawdź JWT token: `php generate_jwt.php`

### MQTT Debugging
- MQTT Listener loguje wszystkie wiadomości
- Subscribe na `move/+` dla wszystkich move topików
- Szczegółowe logi w konsoli i pliku

---

# 📡 Dokumentacja komunikacji MQTT

| Komponent | Subskrybuje (MQTT topic) | Publikuje (MQTT topic) |
|-----------|--------------------------|------------------------|
| **Web App** | • Mercure WebSocket z chess/updates | • `move/web` – ruch wysłany przez UI<br>• `move/possible_moves/request` – żądanie możliwych ruchów |
| **Silnik szachowy** | • `move/engine` – żądanie walidacji ruchu<br>• `engine/possible_moves/request` – żądanie możliwych ruchów | • `move/ai` – ruch AI<br>• `status/engine` – `thinking`/`ready`/`error`/`analyzing`<br>• `engine/possible_moves/response` – odpowiedź z możliwymi ruchami<br>• `engine/move/confirmed` – potwierdzenie legalnego ruchu z FEN<br>• `engine/move/rejected` – odrzucenie nielegalnego ruchu |
| **Raspberry Pi** | • `move/raspi` – polecenie fizycznego ruchu<br>• `move/raspi/rejected` – polecenie cofnięcia ruchu<br>• `control/restart` – sygnał resetu gry | • `move/player` – wykryty ruch gracza na planszy<br>• `status/raspi` – `ready`/`moving`/`error`/`busy` |
| **Backend** | • `move/player` – ruch fizyczny od RPi<br>• `move/web` – ruch z UI<br>• `move/ai` – ruch od silnika<br>• `move/possible_moves/request` – żądanie od UI<br>• `engine/possible_moves/response` – odpowiedź od silnika<br>• `engine/move/confirmed` – potwierdzenie od silnika<br>• `engine/move/rejected` – odrzucenie od silnika<br>• `status/raspi` – status RPi<br>• `status/engine` – status silnika<br>• `control/restart` – reset gry | • `move/engine` – żądanie walidacji do silnika<br>• `move/raspi` – polecenie ruchu do RPi<br>• `move/raspi/rejected` – polecenie cofnięcia do RPi<br>• `engine/possible_moves/request` – żądanie do silnika<br>• `state/update` – pełny stan gry<br>• `log/update` – aktualizacja logów<br>• `control/restart` – reset gry |

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

### 7. Statusy komponentów:
```
RPi/Silnik → status/* → Backend → Mercure (do UI)
```

## 🎯 Walidacja i synchronizacja:

### Zasady walidacji:
> [!IMPORTANT] 
> 1. **WSZYSTKIE ruchy** (fizyczne i webowe) są walidowane przez silnik
> 2. **Silnik jest źródłem prawdy** o legalności ruchów i FEN
> 3. **Flaga `physical`** określa źródło ruchu i reakcję na walidację

### Reakcje na walidację:
| Typ ruchu | Walidacja | Akcja po confirmed | Akcja po rejected |
|-----------|-----------|-------------------|-------------------|
| **Webowy** | ✅ | Wyślij `move/raspi` | Powiadom UI o błędzie |
| **Fizyczny** | ✅ | Nic (pionek już tam jest) | Wyślij `move/raspi/rejected` |

### Nowe kanały MQTT (na dzień 06.08.2025):
- `move/raspi/rejected` - Backend → RPi (cofnij nielegalny ruch fizyczny)
- `engine/move/confirmed` - Silnik → Backend (z flagą `physical`)
- `engine/move/rejected` - Silnik → Backend (z flagą `physical`)

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
- Backend używa HTTP Client zamiast Symfony Hub
- JWT token generowany w locie z claims: `{"mercure": {"publish": ["*"]}}`
- Publiczne updates bez autoryzacji subskrypcji  
- Topic: `http://127.0.0.1:8000/chess/updates`

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

## 🚀 Status implementacji:

✅ **Działające komponenty:**
- MQTT komunikacja Backend ↔ RPi/Silnik
- Walidacja wszystkich ruchów przez silnik  
- Mercure real-time z HTTP+JWT
- Możliwe ruchy w czasie rzeczywistym
- Proper handling fizycznych i webowych ruchów
- Cofanie nielegalnych ruchów fizycznych
- Health check wszystkich komponentów
- Szczegółowe logowanie i debugging

🔄 **Gotowe do testowania:**
- Pełny flow walidacji ruchów
- Real-time komunikacja Web ↔ Backend ↔ RPi
- Synchronizacja stanu przez silnik szachowy
- Obsługa błędów i recovery
