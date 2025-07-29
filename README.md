# Symfony Chess Backend

System backendu dla inteligentnej szachownicy opartej na Raspberry Pi z silnikiem szachowym AI. Backend zarządza komunikacją między aplikacją webową, fizyczną szachownicą i silnikiem szachowym poprzez protokół MQTT oraz dostarcza REST API i powiadomienia real-time przez WebSocket.

## 🚀 Funkcjonalności

- **🌐 REST API** - Endpointy dla wykonywania ruchów, resetowania gry i sprawdzania stanu zdrowia
- **📡 MQTT Broker** - Komunikacja z Raspberry Pi i silnikiem szachowym
- **⚡ Real-time WebSocket** - Powiadomienia na żywo przez Mercure
- **🎯 Zarządzanie stanem gry** - Śledzenie ruchów, pozycji i historii partii
- **🏥 Health Check** - Monitorowanie stanu wszystkich komponentów systemu
- **📝 Logowanie** - Szczegółowe logi komunikacji i błędów
- **🔄 Synchronizacja** - Dwukierunkowa komunikacja między UI a fizyczną planszą

## 🏗️ Architektura systemu

```
┌─────────────┐    REST API     ┌─────────────┐    MQTT      ┌──────────────┐
│   Web App   │◄──────────────►│   Backend   │◄────────────►│ Raspberry Pi │
└─────────────┘                 └─────────────┘              └──────────────┘
       ▲                               │                              ▲
       │         WebSocket/Mercure     │ MQTT                         │
       └───────────────────────────────┘                              │
                                       │                              │
                                       ▼                              │
                              ┌─────────────┐                         │
                              │Chess Engine │                         │
                              │     AI      │◄────────────────────────┘
                              └─────────────┘        MQTT
```

## 📋 Wymagania

- **PHP 8.2+**
- **Composer** 2.0+
- **Symfony 7.3+**
- **MQTT Broker** (np. Mosquitto)
- **Mercure Hub** dla WebSocket na porcie 3000
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

Utwórz i edytuj plik `.env` i skonfiguruj:
```properties
# MQTT Configuration
MQTT_BROKER=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=szachmat_backend

# Mercure Configuration
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_JWT_SECRET=your-secret-key

# Database (opcjonalne)
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

### 4. Uruchomienie serwera Symfony
```bash
symfony server:start
```

### 5. Uruchomienie MQTT Listener
```bash
php bin/console app:mqtt:listen
```

## 🎮 Użytkowanie

### REST API Endpoints

- `POST /move` - Wykonaj ruch
- `POST /restart` - Zresetuj grę
- `POST /possible-moves` - Żądaj możliwych ruchów dla pozycji
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

Odpowiedź zostanie przesłana przez WebSocket w formacie:
```json
{
  "type": "possible_moves",
  "position": "e2",
  "moves": ["e3", "e4"]
}
```

### WebSocket Subscription
```javascript
const eventSource = new EventSource('http://localhost:3000/.well-known/mercure?topic=chess/updates');
eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Otrzymano:', data);
};
```

## 🔧 Komendy

- `php bin/console app:mqtt:listen` - Uruchom listener MQTT
- `php bin/console cache:clear` - Wyczyść cache
- `php bin/console debug:router` - Pokaż dostępne trasy

## 📊 Monitorowanie

System dostarcza endpoint `/health` który zwraca status wszystkich komponentów:

```json
{
  "status": "healthy",
  "timestamp": "2025-07-24T10:30:00Z",
  "components": {
    "mqtt": {"status": "healthy", "response_time": 12.5},
    "mercure": {"status": "healthy", "response_time": 45.2},
    "raspberry_pi": {"status": "warning", "response_time": null},
    "chess_engine": {"status": "healthy", "response_time": 89.1}
  }
}
```

---

# 📡 Dokumentacja komunikacji MQTT

| Komponent | Subskrybuje (MQTT topic) | Publikuje (MQTT topic) |
|-----------|--------------------------|------------------------|
| **Web App** | • `state/update` – pełny stan gry dla UI<br>• `log/update` – aktualizacja logów ruchów | • `move/web` – ruch wysłany przez UI<br>• `move/possible_moves/request` – żądanie możliwych ruchów |
| **Silnik szachowy** | • `move/engine` – żądanie analizy ruchu do silnika<br>• `engine/possible_moves/request` – żądanie możliwych ruchów | • `move/ai` – ruch AI (odpowiedź silnika)<br>• `status/engine` – `thinking` / `error` / `ready`<br>• `engine/possible_moves/response` – odpowiedź z możliwymi ruchami |
| **Raspberry Pi** | • `move/raspi` – polecenie fizycznego ruchu AI<br>• `control/restart` – sygnał resetu gry | • `move/player` – wykryty ruch gracza na fizycznej planszy<br>• `status/raspi` – `ready` / `moving` / `error` |
| **Backend** | • `move/player` – ruch fizyczny od RPi<br>• `move/web` – ruch z UI<br>• `move/ai` – ruch od silnika<br>• `move/possible_moves/request` – żądanie możliwych ruchów od UI<br>• `engine/possible_moves/response` – odpowiedź od silnika z możliwymi ruchami<br>• `status/raspi` – status RPi<br>• `status/engine` – status silnika<br>• `control/restart` – reset gry | • `move/engine` – żądanie analizy do silnika<br>• `move/raspi` – polecenie do RPi<br>• `engine/possible_moves/request` – żądanie możliwych ruchów do silnika<br>• `state/update` – pełny stan gry dla UI<br>• `log/update` – aktualizacja logów ruchów<br>• `control/restart` – reset gry |

## Przepływ komunikacji:

### 1. Ruch gracza z Web App:
```
Web App → move/web → Backend → move/engine (do silnika) + move/raspi (do RPi)
Backend → state/update + log/update (do Web App)
```

### 2. Ruch fizyczny gracza na planszy:
```
Raspberry Pi → move/player → Backend → move/engine (do silnika)
Backend → state/update + log/update (do Web App)
```

### 3. Odpowiedź AI:
```
Silnik → move/ai → Backend → move/raspi (do RPi)
Backend → state/update + log/update (do Web App)
```

### 4. Reset gry:
```
Web App (REST API) → Backend → control/restart → Raspberry Pi + Silnik
Backend → state/update + log/update (do Web App)
```

### 5. Statusy komponentów:
```
Raspberry Pi → status/raspi → Backend → UI (przez Mercure)
Silnik → status/engine → Backend → UI (przez Mercure)
```

### 6. Żądanie możliwych ruchów:
```
Web App → POST /possible-moves → Backend → move/possible_moves/request → 
Backend → engine/possible_moves/request → Silnik → engine/possible_moves/response → 
Backend → UI (przez Mercure)
```

## Dodatkowe kanały komunikacji:

- **Mercure WebSocket**: Backend → Web App (powiadomienia real-time)
- **REST API**: Web App → Backend (`/move`, `/restart`, `/possible-moves`, `/state`, `/health`)
- **Debugging**: Backend subskrybuje `move/+` (wszystkie move topiki)

## Mapowanie statusów dla UI:

### Status Raspberry Pi:
- `ready` → "Gotowy"
- `moving` → "Wykonuje ruch"  
- `error` → "Błąd"
- `busy` → "Zajęty"

### Status Silnika:
- `ready` → "Gotowy"
- `thinking` → "Myśli"
- `error` → "Błąd"
- `analyzing` → "Analizuje"

## Przykłady wiadomości MQTT:

### 1. Ruchy (`move/*`)

#### `move/web` (Web App → Backend)
```json
{
  "from": "e2",
  "to": "e4"
}
```

#### `move/player` (Raspberry Pi → Backend)
```json
{
  "from": "d7",
  "to": "d5"
}
```

#### `move/engine` (Backend → Silnik szachowy)
```json
{
  "from": "e2",
  "to": "e4"
}
```

#### `move/ai` (Silnik szachowy → Backend)
```json
{
  "from": "g8",
  "to": "f6"
}
```

#### `move/raspi` (Backend → Raspberry Pi)
```json
{
  "from": "g8",
  "to": "f6"
}
```

#### `move/possible_moves/request` (Web App → Backend)
```json
{
  "position": "e2"
}
```

#### `engine/possible_moves/request` (Backend → Silnik szachowy)
```json
{
  "position": "e2"
}
```

#### `engine/possible_moves/response` (Silnik szachowy → Backend)
```json
{
  "position": "e2",
  "moves": ["e3", "e4"]
}
```

### 2. Stany i logi (`state/*`, `log/*`)

#### `state/update` (Backend → Web App)
```json
{
  "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
  "moves": [
    {"from": "e2", "to": "e4"},
    {"from": "d7", "to": "d5"}
  ],
  "turn": "white",
  "status": "playing"
}
```

#### `log/update` (Backend → Web App)
```json
{
  "moves": [
    {"from": "e2", "to": "e4"},
    {"from": "d7", "to": "d5"},
    {"from": "g8", "to": "f6"}
  ]
}
```

### 3. Statusy komponentów (`status/*`)

#### `status/raspi` (Raspberry Pi → Backend)
```json
{
  "status": "ready",
  "timestamp": "2025-07-24T10:30:00Z",
  "details": {
    "board_detected": true,
    "last_move": {"from": "d7", "to": "d5"},
    "error_message": null
  }
}
```

Lub w formacie prostym:
```
ready
```

#### `status/engine` (Silnik szachowy → Backend)
```json
{
  "status": "thinking",
  "depth": 12,
  "nodes": 1500000,
  "time": 2.5,
  "best_move": "g8f6",
  "evaluation": "+0.15"
}
```

Lub w formacie prostym:
```
thinking
```

### 4. Kontrola (`control/*`)

#### `control/restart` (Backend → wszystkie komponenty)
```json
null
```

Lub pusty string:
```
""
```

### 5. Powiadomienia Mercure (Backend → Web App)

#### Wykonany ruch gracza
```json
{
  "type": "move_executed",
  "move": {"from": "e2", "to": "e4"},
  "physical": false,
  "state": {
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
    "moves": [{"from": "e2", "to": "e4"}],
    "turn": "black",
    "status": "playing"
  }
}
```

#### Wykonany ruch AI
```json
{
  "type": "ai_move_executed",
  "move": {"from": "g8", "to": "f6"},
  "state": {
    "fen": "rnbqkb1r/pppppppp/5n2/8/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 1 2",
    "moves": [
      {"from": "e2", "to": "e4"},
      {"from": "g8", "to": "f6"}
    ],
    "turn": "white",
    "status": "playing"
  }
}
```

#### Status Raspberry Pi
```json
{
  "type": "raspi_status",
  "data": {
    "status": "ready",
    "message": "Gotowy",
    "format": "json",
    "details": {
      "board_detected": true,
      "last_move": {"from": "d7", "to": "d5"}
    }
  },
  "timestamp": "10:30:15"
}
```

#### Status silnika szachowego
```json
{
  "type": "engine_status",
  "data": {
    "status": "thinking",
    "message": "Myśli",
    "format": "json",
    "details": {
      "depth": 12,
      "nodes": 1500000,
      "evaluation": "+0.15"
    }
  },
  "timestamp": "10:30:20"
}
```

#### Możliwe ruchy
```json
{
  "type": "possible_moves",
  "position": "e2",
  "moves": ["e3", "e4"]
}
```

#### Reset gry
```json
{
  "type": "game_reset",
  "state": {
    "fen": "startpos",
    "moves": [],
    "turn": "white",
    "status": "ready"
  }
}
```