# Przykład komunikacji podczas partii szachowej - Mat szewczyka w 4 ruchach

Ten dokument przedstawia szczegółowy przepływ komunikacji podczas krótkiej partii szachowej (mat szewczyka) wykonanej wyłącznie przez aplikację webową. Wszystkie ruchy są wykonywane przez UI - brak ruchów fizycznych na Raspberry Pi.

## 📋 Partia: Mat szewczyka (4 ruchy)

```
1. e2-e4  e7-e5
2. Bf1-c4 Nb8-c6
3. Qd1-h5 Ng8-f6??
4. Qh5xf7# (mat)
```

---

## 🎯 RUCH 1: e2-e4 (białe)

### 1.1 Kliknięcie na pionek e2 - żądanie możliwych ruchów

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/possible-moves
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "position": "e2"
}
```

**📤 Backend → MQTT Broker**

```mqtt
Topic: move/possible_moves/request
```

```json
{
    "position": "e2"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: engine/possible_moves/request
```

```json
{
    "position": "e2",
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/possible_moves/response
```

```json
{
    "position": "e2",
    "moves": ["e3", "e4"]
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Topic: http://127.0.0.1:8000/chess/updates
Event: message
```

```json
{
    "type": "possible_moves",
    "position": "e2",
    "moves": ["e3", "e4"],
    "timestamp": "2025-01-28T14:30:15Z"
}
```

**🔄 Web App Response**

-   Podświetli pole e2 jako wybrane
-   Podświetli pola e3 i e4 jako możliwe ruchy

### 1.2 Kliknięcie na pole e4 - wykonanie ruchu

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/move
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "from": "e2",
    "to": "e4"
}
```

**📤 Backend → MQTT Broker**

```mqtt
Topic: move/web
```

```json
{
    "from": "e2",
    "to": "e4",
    "physical": false
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: move/engine
```

```json
{
    "from": "e2",
    "to": "e4",
    "current_fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
    "type": "move_validation",
    "physical": false
}
```

**⚡ Backend → Web App (Mercure SSE)** - Ruch oczekujący

```http
Event: message
```

```json
{
    "type": "move_pending",
    "move": { "from": "e2", "to": "e4" },
    "physical": false,
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
        "moves": [],
        "turn": "white",
        "pending_moves": [{ "from": "e2", "to": "e4" }]
    }
}
```

**📥 Chess Engine → Backend (MQTT)** - Potwierdzenie ruchu

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "e2",
    "to": "e4",
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
    "next_player": "black",
    "physical": false
}
```

**📤 Backend → Raspberry Pi (MQTT)**

```mqtt
Topic: move/raspi
```

```json
{
    "from": "e2",
    "to": "e4",
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1"
}
```

**⚡ Backend → Web App (Mercure SSE)** - Ruch potwierdzony

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": { "from": "e2", "to": "e4" },
    "physical": false,
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
        "moves": [{ "from": "e2", "to": "e4" }],
        "turn": "black",
        "pending_moves": []
    }
}
```

---

## 🎯 RUCH 1: e7-e5 (czarne - ruch AI)

**📥 Chess Engine → Backend (MQTT)** - Ruch AI

```mqtt
Topic: move/ai
```

```json
{
    "from": "e7",
    "to": "e5",
    "fen": "rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2",
    "next_player": "white"
}
```

**📤 Backend → Raspberry Pi (MQTT)**

```mqtt
Topic: move/raspi
```

```json
{
    "from": "e7",
    "to": "e5",
    "fen": "rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2"
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "ai_move_executed",
    "move": { "from": "e7", "to": "e5" },
    "state": {
        "fen": "rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" }
        ],
        "turn": "white",
        "pending_moves": []
    }
}
```

---

## 🎯 RUCH 2: Bf1-c4 (białe)

### 2.1 Kliknięcie na gońca f1 - żądanie możliwych ruchów

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/possible-moves
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "position": "f1"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: engine/possible_moves/request
```

```json
{
    "position": "f1",
    "fen": "rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2"
}
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/possible_moves/response
```

```json
{
    "position": "f1",
    "moves": ["e2", "d3", "c4", "b5", "a6"]
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "possible_moves",
    "position": "f1",
    "moves": ["e2", "d3", "c4", "b5", "a6"],
    "timestamp": "2025-01-28T14:31:20Z"
}
```

### 2.2 Kliknięcie na pole c4 - wykonanie ruchu

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/move
Method: POST
```

```json
{
    "from": "f1",
    "to": "c4"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: move/engine
```

```json
{
    "from": "f1",
    "to": "c4",
    "current_fen": "rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2",
    "type": "move_validation",
    "physical": false
}
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "f1",
    "to": "c4",
    "fen": "rnbqkbnr/pppp1ppp/8/4p3/2B1P3/8/PPPP1PPP/RNBQK1NR b KQkq - 1 2",
    "next_player": "black",
    "physical": false
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": { "from": "f1", "to": "c4" },
    "physical": false,
    "state": {
        "fen": "rnbqkbnr/pppp1ppp/8/4p3/2B1P3/8/PPPP1PPP/RNBQK1NR b KQkq - 1 2",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "f1", "to": "c4" }
        ],
        "turn": "black"
    }
}
```

---

## 🎯 RUCH 2: Nb8-c6 (czarne - ruch AI)

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: move/ai
```

```json
{
    "from": "b8",
    "to": "c6",
    "fen": "r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/8/PPPP1PPP/RNBQK1NR w KQkq - 2 3",
    "next_player": "white"
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "ai_move_executed",
    "move": { "from": "b8", "to": "c6" },
    "state": {
        "fen": "r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/8/PPPP1PPP/RNBQK1NR w KQkq - 2 3",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "f1", "to": "c4" },
            { "from": "b8", "to": "c6" }
        ],
        "turn": "white"
    }
}
```

---

## 🎯 RUCH 3: Qd1-h5 (białe)

### 3.1 Żądanie możliwych ruchów dla hetmana d1

**🌐 Web App → Backend (HTTP POST)**

```json
{ "position": "d1" }
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/possible_moves/response
```

```json
{
    "position": "d1",
    "moves": ["e2", "f3", "g4", "h5"]
}
```

### 3.2 Wykonanie ruchu Qd1-h5

**🌐 Web App → Backend (HTTP POST)**

```json
{ "from": "d1", "to": "h5" }
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "d1",
    "to": "h5",
    "fen": "r1bqkbnr/pppp1ppp/2n5/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 3 3",
    "next_player": "black",
    "physical": false
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": { "from": "d1", "to": "h5" },
    "physical": false,
    "state": {
        "fen": "r1bqkbnr/pppp1ppp/2n5/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 3 3",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "f1", "to": "c4" },
            { "from": "b8", "to": "c6" },
            { "from": "d1", "to": "h5" }
        ],
        "turn": "black"
    }
}
```

---

## 🎯 RUCH 3: Ng8-f6?? (czarne - błąd AI)

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: move/ai
```

```json
{
    "from": "g8",
    "to": "f6",
    "fen": "r1bqkb1r/pppp1ppp/2n2n2/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR w KQkq - 4 4",
    "next_player": "white"
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "ai_move_executed",
    "move": { "from": "g8", "to": "f6" },
    "state": {
        "fen": "r1bqkb1r/pppp1ppp/2n2n2/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR w KQkq - 4 4",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "f1", "to": "c4" },
            { "from": "b8", "to": "c6" },
            { "from": "d1", "to": "h5" },
            { "from": "g8", "to": "f6" }
        ],
        "turn": "white"
    }
}
```

---

## 🎯 RUCH 4: Qh5xf7# (białe - mat szewczyka!)

### 4.1 Żądanie możliwych ruchów dla hetmana h5

**🌐 Web App → Backend (HTTP POST)**

```json
{ "position": "h5" }
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/possible_moves/response
```

```json
{
    "position": "h5",
    "moves": [
        "h4",
        "h3",
        "g5",
        "f5",
        "e5",
        "g4",
        "f3",
        "e2",
        "d1",
        "g6",
        "f7",
        "h6",
        "h7"
    ]
}
```

### 4.2 Wykonanie matującego ruchu Qh5xf7#

**🌐 Web App → Backend (HTTP POST)**

```json
{ "from": "h5", "to": "f7" }
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "h5",
    "to": "f7",
    "fen": "r1bqkb1r/pppp1Qpp/2n2n2/4p3/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 0 4",
    "next_player": "black",
    "physical": false,
    "game_status": "checkmate",
    "winner": "white"
}
```

**⚡ Backend → Web App (Mercure SSE)** - Mat!

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": { "from": "h5", "to": "f7" },
    "physical": false,
    "state": {
        "fen": "r1bqkb1r/pppp1Qpp/2n2n2/4p3/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 0 4",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "f1", "to": "c4" },
            { "from": "b8", "to": "c6" },
            { "from": "d1", "to": "h5" },
            { "from": "g8", "to": "f6" },
            { "from": "h5", "to": "f7" }
        ],
        "turn": "black",
        "game_status": "checkmate",
        "winner": "white"
    }
}
```

**⚡ Backend → Web App (Mercure SSE)** - Koniec gry

```http
Event: message
```

```json
{
    "type": "game_over",
    "result": "checkmate",
    "winner": "white",
    "final_position": "r1bqkb1r/pppp1Qpp/2n2n2/4p3/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 0 4",
    "moves_count": 7,
    "game_type": "scholar_mate"
}
```

---

## 📊 Podsumowanie komunikacji

### Statystyki partii:

-   **Ruchy białych**: 4 (wszystkie przez Web App)
-   **Ruchy czarnych**: 3 (wszystkie AI)
-   **Żądania możliwych ruchów**: 4
-   **Łączna liczba komunikatów MQTT**: ~28
-   **Łączna liczba komunikatów Mercure**: ~11
-   **Łączna liczba żądań HTTP**: 8

### Wykorzystane protokoły:

-   **HTTP REST API**: 8 żądań (ruchy + possible moves)
-   **MQTT**: ~28 komunikatów (walidacja, AI, status)
-   **Mercure SSE**: ~11 real-time updates (stan gry, ruchy)

### Kluczowe komponenty:

-   ✅ **Web App**: Inicjuje ruchy i odbiera real-time updates
-   ✅ **Backend**: Koordynuje komunikację między komponentami
-   ✅ **Chess Engine**: Waliduje ruchy, generuje AI, wykrywa mata
-   ✅ **Raspberry Pi**: Otrzymuje polecenia ruchów (symulacja)
-   ✅ **MQTT Broker**: Przekazuje komunikaty między komponentami
-   ✅ **Mercure Hub**: Dostarcza real-time updates do Web App

Mat szewczyka został pomyślnie wykonany w 4 ruchach z pełną synchronizacją między wszystkimi
