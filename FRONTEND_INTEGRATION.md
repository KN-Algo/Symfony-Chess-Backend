# Integracja Frontend - Instrukcje dla developera

Ten dokument opisuje jak zintegrować aplikację webową z backendem Symfony Chess przy użyciu REST API i real-time komunikacji przez Mercure (Server-Sent Events).

## 🏗️ Architektura komunikacji

```
Frontend (JS) ←→ REST API (Symfony) ←→ MQTT ←→ RPi/Engine
     ↑                                           
     └─── Mercure SSE (real-time updates) ←──────┘
```

## 📡 REST API Endpoints

### `POST /move` - Wykonaj ruch
```javascript
const response = await fetch('/move', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ from: "e2", to: "e4" })
});
```

**Odpowiedź:**
```json
{
  "status": "ok"
}
```

### `POST /possible-moves` - Żądaj możliwych ruchów
```javascript
const response = await fetch('/possible-moves', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ position: "e2" })
});
```

**Odpowiedź:**
```json
{
  "status": "request_sent"
}
```

### `POST /restart` - Reset gry
```javascript
const response = await fetch('/restart', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
});
```

**Odpowiedź:**
```json
{
  "status": "reset"
}
```

### `GET /health` - Status systemu
```javascript
const response = await fetch('/health');
```

**Odpowiedź:** HTML dashboard lub:

### `GET /api/health` - Status systemu (JSON)
```javascript
const response = await fetch('/api/health');
```

**Odpowiedź:**
```json
{
  "status": "healthy",
  "components": {
    "mqtt": {"status": "healthy", "response_time": 12.5},
    "mercure": {"status": "healthy", "response_time": 45.2},
    "raspberry_pi": {"status": "warning", "response_time": null},
    "chess_engine": {"status": "healthy", "response_time": 89.1}
  }
}
```

### `GET /state` - Aktualny stan gry
```javascript
const response = await fetch('/state');
```

**Odpowiedź:**
```json
{
  "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
  "moves": [
    {"from": "e2", "to": "e4"},
    {"from": "e7", "to": "e5"}
  ]
}
```

## ⚡ Mercure Real-time Communication

### Połączenie z Mercure Hub
```javascript
const eventSource = new EventSource(
    'http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates'
);

eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    handleRealtimeMessage(data);
};

eventSource.onerror = function(event) {
    console.error('Mercure connection error:', event);
    // Implementuj logikę reconnect
};
```

### Formaty wiadomości Real-time

#### 1. Możliwe ruchy (real-time response)
```json
{
  "type": "possible_moves",
  "position": "e2",
  "moves": ["e3", "e4"],
  "timestamp": "2025-08-06T17:30:15Z"
}
```


#### 2. Ruch oczekujący na walidację
```json
{
    "type": "move_pending",
    "move": {"from": "e2", "to": "e4"},
    "physical": false,
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
        "turn": "black",
        "moves": [{"from": "e2", "to": "e4"}]
    },
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1"
}
```

#### 3. Ruch potwierdzony przez silnik
```json
{
    "type": "move_confirmed",
    "move": {"from": "e2", "to": "e4"},
    "physical": false,
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
        "turn": "black",
        "moves": [{"from": "e2", "to": "e4"}]
    },
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1"
}
```

#### 4. Ruch odrzucony przez silnik
```json
{
    "type": "move_rejected",
    "move": {"from": "e2", "to": "e5"},
    "reason": "Illegal move: pawn cannot move two squares from e2 to e5",
    "physical": false,
    "state": {...},
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

#### 5. Ruch AI wykonany
```json
{
  "type": "ai_move_executed",
  "move": {"from": "g8", "to": "f6"},
  "state": {
    "fen": "rnbqkb1r/pppppppp/5n2/8/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 1 2",
    "turn": "white",
    "moves": [
      {"from": "e2", "to": "e4"},
      {"from": "g8", "to": "f6"}
    ]
  }
}
```

#### 6. Status komponentów
```json
{
  "type": "raspi_status",
  "data": {"status": "ready", "last_move": "e2-e4"},
  "timestamp": "2025-08-06T17:30:15Z"
}

{
  "type": "engine_status", 
  "data": {"status": "thinking", "depth": 15},
  "timestamp": "2025-08-06T17:30:20Z"
}
```

#### 7. Reset gry
```json
{
    "type": "game_reset",
    "state": {
        "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
        "moves": [],
        "turn": "white"
    },
    "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1"
}
```

## 🎯 Kompletna implementacja JavaScript

```javascript
class ChessBoardIntegration {
    constructor() {
        this.gameState = null;
        this.pendingMoves = new Set();
        this.possibleMoves = {};
        this.initMercure();
    }
    
    // === MERCURE REAL-TIME ===
    initMercure() {
        this.eventSource = new EventSource(
            'http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates'
        );
        
        this.eventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleRealtimeMessage(data);
            } catch (error) {
                console.error('Error parsing Mercure message:', error);
            }
        };
        
        this.eventSource.onerror = (event) => {
            console.error('Mercure connection error:', event);
            setTimeout(() => this.initMercure(), 5000); // Reconnect po 5s
        };
    }
    
    handleRealtimeMessage(data) {
        console.log('Received real-time message:', data);
        
        switch(data.type) {
            case 'possible_moves':
                this.showPossibleMoves(data.position, data.moves);
                break;
                
            case 'move_pending':
                this.showMovePending(data.move, data.physical);
                break;
                
            case 'move_confirmed':
                this.confirmMove(data.move, data.state, data.physical);
                break;
                
            case 'move_rejected':
                this.rejectMove(data.move, data.reason, data.physical);
                break;
                
            case 'ai_move_executed':
                this.executeAiMove(data.move, data.state);
                break;
                
            case 'game_reset':
                this.resetGame(data.state);
                break;
                
            case 'raspi_status':
            case 'engine_status':
                this.updateComponentStatus(data.type, data.data);
                break;
                
            default:
                console.warn('Unknown message type:', data.type);
        }
    }
    
    // === REST API CALLS ===
    async makeMove(from, to) {
        try {
            const moveKey = `${from}-${to}`;
            this.pendingMoves.add(moveKey);
            this.showMoveLoading(from, to);
            
            const response = await fetch('/move', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ from, to })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                console.log('Move sent successfully:', result.status);
                // Real-time response przyjdzie przez Mercure
            } else {
                throw new Error(result.error || 'Unknown error');
            }
            
        } catch (error) {
            console.error('Error making move:', error);
            this.showErrorMessage(`Failed to make move: ${error.message}`);
            this.clearMoveLoading(from, to);
        }
    }
    
    async requestPossibleMoves(position) {
        try {
            this.showPossibleMovesLoading(position);
            
            const response = await fetch('/possible-moves', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ position })
            });
            
            const result = await response.json();
            
            if (response.ok) {
                console.log('Possible moves request sent successfully:', result.status);
                // Real-time response przyjdzie przez Mercure
            } else {
                throw new Error(result.error || 'Unknown error');
            }
            
        } catch (error) {
            console.error('Error requesting possible moves:', error);
            this.showErrorMessage(`Failed to get possible moves: ${error.message}`);
            this.clearPossibleMovesLoading(position);
        }
    }
    
    async restartGame() {
        try {
            this.showGameLoading();
            
            const response = await fetch('/restart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            const result = await response.json();
            
            if (response.ok) {
                console.log('Game restart sent successfully:', result.status);
                // Real-time response przyjdzie przez Mercure
            } else {
                throw new Error(result.error || 'Unknown error');
            }
            
        } catch (error) {
            console.error('Error restarting game:', error);
            this.showErrorMessage(`Failed to restart game: ${error.message}`);
            this.clearGameLoading();
        }
    }
    
    async checkSystemHealth() {
        try {
            const response = await fetch('/health');
            const health = await response.json();
            this.updateSystemHealth(health);
            return health;
        } catch (error) {
            console.error('Error checking system health:', error);
            this.updateSystemHealth({ status: 'error', error: error.message });
        }
    }
    
    // === EVENT HANDLERS ===
    showPossibleMoves(position, moves) {
        this.clearAllHighlights();
        this.possibleMoves[position] = moves;
        
        // Podświetl wybrany pionek
        this.highlightSquare(position, 'selected');
        
        // Podświetl możliwe ruchy
        moves.forEach(move => {
            this.highlightSquare(move, 'possible-move');
        });
        
        console.log(`Highlighted ${moves.length} possible moves for ${position}`);
    }
    
    showMovePending(move, physical) {
        const moveKey = `${move.from}-${move.to}`;
        this.showMoveInProgress(move.from, move.to, physical ? 'physical' : 'web');
        console.log(`Move ${moveKey} pending validation (${physical ? 'physical' : 'web'})`);
    }
    
    confirmMove(move, state, physical) {
        const moveKey = `${move.from}-${move.to}`;
        this.pendingMoves.delete(moveKey);
        
        // Aktualizuj stan gry
        this.gameState = state;
        this.executeMove(move.from, move.to);
        
        // Wyczyść UI
        this.clearMoveLoading(move.from, move.to);
        this.clearAllHighlights();
        
        console.log(`Move ${moveKey} confirmed (${physical ? 'physical' : 'web'})`);
        this.showSuccessMessage(`Move ${move.from} → ${move.to} executed`);
    }
    
    rejectMove(move, reason, physical) {
        const moveKey = `${move.from}-${move.to}`;
        this.pendingMoves.delete(moveKey);
        
        // Wyczyść UI
        this.clearMoveLoading(move.from, move.to);
        this.clearAllHighlights();
        
        // Pokaż błąd
        this.showErrorMessage(`Move rejected: ${reason}`);
        console.error(`Move ${moveKey} rejected (${physical ? 'physical' : 'web'}):`, reason);
        
        // Dla ruchów fizycznych - pokaż komunikat o cofnięciu
        if (physical) {
            this.showWarningMessage('Physical move rejected - please return piece to original position');
        }
    }
    
    executeAiMove(move, state) {
        // Aktualizuj stan gry
        this.gameState = state;
        this.executeMove(move.from, move.to, 'ai');
        
        console.log(`AI move executed: ${move.from} → ${move.to}`);
        this.showInfoMessage(`AI played: ${move.from} → ${move.to}`);
    }
    
    resetGame(state) {
        this.gameState = state;
        this.pendingMoves.clear();
        this.possibleMoves = {};
        
        // Reset UI
        this.clearAllHighlights();
        this.resetBoardToInitialState();
        this.clearAllMessages();
        
        console.log('Game reset to initial state');
        this.showSuccessMessage('Game restarted');
    }
    
    updateComponentStatus(type, data) {
        const component = type.replace('_status', '');
        this.updateStatusIndicator(component, data.status, data);
        console.log(`${component} status:`, data);
    }
    
    // === UI METHODS (implementuj według swojego frameworka) ===
    highlightSquare(square, className) {
        // Implementuj podświetlenie pola na planszy
        console.log(`Highlight ${square} with class ${className}`);
    }
    
    clearAllHighlights() {
        // Wyczyść wszystkie podświetlenia
        console.log('Clear all highlights');
    }
    
    executeMove(from, to, type = 'player') {
        // Animuj ruch na planszy
        console.log(`Execute move: ${from} → ${to} (${type})`);
    }
    
    showMoveLoading(from, to) {
        console.log(`Show loading for move: ${from} → ${to}`);
    }
    
    clearMoveLoading(from, to) {
        console.log(`Clear loading for move: ${from} → ${to}`);
    }
    
    showMoveInProgress(from, to, type) {
        console.log(`Show move in progress: ${from} → ${to} (${type})`);
    }
    
    showPossibleMovesLoading(position) {
        console.log(`Show possible moves loading for: ${position}`);
    }
    
    clearPossibleMovesLoading(position) {
        console.log(`Clear possible moves loading for: ${position}`);
    }
    
    resetBoardToInitialState() {
        console.log('Reset board to initial state');
    }
    
    // Messages
    showSuccessMessage(message) { console.log(`✅ ${message}`); }
    showErrorMessage(message) { console.error(`❌ ${message}`); }
    showWarningMessage(message) { console.warn(`⚠️ ${message}`); }
    showInfoMessage(message) { console.info(`ℹ️ ${message}`); }
    clearAllMessages() { console.log('Clear all messages'); }
    
    // Status indicators
    updateStatusIndicator(component, status, data) {
        console.log(`Update ${component} status: ${status}`, data);
    }
    
    updateSystemHealth(health) {
        console.log('System health:', health);
    }
    
    // Loading states
    showGameLoading() { console.log('Show game loading'); }
    clearGameLoading() { console.log('Clear game loading'); }
}

// === INICJALIZACJA ===
const chessIntegration = new ChessBoardIntegration();

// === PRZYKŁAD UŻYCIA ===
// Kliknięcie na pionek - pokaż możliwe ruchy
function onPieceClick(position) {
    chessIntegration.requestPossibleMoves(position);
}

// Kliknięcie na możliwy ruch - wykonaj ruch
function onSquareClick(from, to) {
    chessIntegration.makeMove(from, to);
}

// Reset gry
function onRestartClick() {
    chessIntegration.restartGame();
}

// Sprawdź status systemu
setInterval(() => {
    chessIntegration.checkSystemHealth();
}, 30000); // co 30 sekund
```

## 🔧 Konfiguracja i debugowanie

### 1. Wymagane serwisy
```bash
# Terminal 1: Mercure Hub
cd D:\mercure
$env:MERCURE_PUBLISHER_JWT_KEY='00a563e20f5b32ce9e85fc801396be97'
$env:MERCURE_SUBSCRIBER_JWT_KEY='00a563e20f5b32ce9e85fc801396be97'
.\mercure.exe run --config dev.Caddyfile

# Terminal 2: Symfony server
symfony server:start

# Terminal 3: MQTT Listener
php bin/console app:mqtt-listen
```

### 2. Test połączenia Mercure
```javascript
// Test w konsoli przeglądarki
const testEventSource = new EventSource('http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates');
testEventSource.onmessage = (e) => console.log('Test message:', JSON.parse(e.data));
```

### 3. Test REST API
```bash
# Test możliwych ruchów
curl -X POST http://localhost:8000/possible-moves \
  -H "Content-Type: application/json" \
  -d '{"position": "e2"}'

# Test ruchu
curl -X POST http://localhost:8000/move \
  -H "Content-Type: application/json" \
  -d '{"from": "e2", "to": "e4"}'

# Test health check
curl http://localhost:8000/api/health

# Test stanu gry
curl http://localhost:8000/state
```

### 4. Debug logi
- **Mercure logi**: `public/mercure-debug.log`
- **Symfony logi**: `var/log/dev.log`
- **MQTT logi**: Konsola gdzie uruchomiono `app:mqtt-listen`

## 🚨 Obsługa błędów i Edge Cases

### Timeout handling
```javascript
class ChessIntegration extends ChessBoardIntegration {
    constructor() {
        super();
        this.messageTimeouts = new Map();
    }
    
    async requestPossibleMoves(position) {
        // Ustaw timeout dla żądania
        const timeoutId = setTimeout(() => {
            this.showErrorMessage(`Timeout: No response for possible moves at ${position}`);
            this.clearPossibleMovesLoading(position);
        }, 10000); // 10 sekund
        
        this.messageTimeouts.set(`possible_moves_${position}`, timeoutId);
        
        await super.requestPossibleMoves(position);
    }
    
    showPossibleMoves(position, moves) {
        // Wyczyść timeout po otrzymaniu odpowiedzi
        const timeoutId = this.messageTimeouts.get(`possible_moves_${position}`);
        if (timeoutId) {
            clearTimeout(timeoutId);
            this.messageTimeouts.delete(`possible_moves_${position}`);
        }
        
        super.showPossibleMoves(position, moves);
    }
}
```

### Connection recovery
```javascript
initMercure() {
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 5;
    
    this.eventSource = new EventSource(
        'http://localhost:3000/.well-known/mercure?topic=http://127.0.0.1:8000/chess/updates'
    );
    
    this.eventSource.onopen = () => {
        console.log('Mercure connected');
        this.reconnectAttempts = 0;
        this.showSuccessMessage('Real-time connection established');
    };
    
    this.eventSource.onerror = (event) => {
        console.error('Mercure connection error:', event);
        
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            const delay = Math.pow(2, this.reconnectAttempts) * 1000; // Exponential backoff
            
            this.showWarningMessage(`Connection lost. Reconnecting in ${delay/1000}s... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
            
            setTimeout(() => {
                this.eventSource.close();
                this.initMercure();
            }, delay);
        } else {
            this.showErrorMessage('Connection failed. Please refresh the page.');
        }
    };
}
```.