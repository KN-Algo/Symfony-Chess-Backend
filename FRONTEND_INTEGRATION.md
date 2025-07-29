# Integracja możliwych ruchów - Instrukcje dla Frontend

Ten dokument opisuje jak zintegrować nową funkcjonalność możliwych ruchów w aplikacji webowej.

## 📡 Endpoint API

### `POST /possible-moves`

Endpoint do żądania możliwych ruchów dla wybranego pionka.

**Żądanie:**
```json
{
  "position": "e2"
}
```

**Odpowiedź:**
```json
{
  "status": "request_sent"
}
```

## 📨 WebSocket - Odbieranie odpowiedzi

Odpowiedź z możliwymi ruchami przychodzi przez WebSocket (Mercure):

```javascript
// Połączenie z Mercure
const eventSource = new EventSource('http://localhost:3000/.well-known/mercure?topic=https://127.0.0.1:8000/chess/updates');

eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    
    // Sprawdź czy to odpowiedź z możliwymi ruchami
    if (data.type === 'possible_moves') {
        console.log('Możliwe ruchy dla', data.position, ':', data.moves);
        // Tutaj wywołaj funkcję do podświetlenia możliwych ruchów na planszy
        highlightPossibleMoves(data.position, data.moves);
    }
};
```

**Format odpowiedzi WebSocket:**
```json
{
  "type": "possible_moves",
  "position": "e2",
  "moves": ["e3", "e4"]
}
```

## 🎯 Przykład implementacji w JavaScript

```javascript
class ChessBoard {
    constructor() {
        this.initWebSocket();
    }
    
    // Inicjalizacja WebSocket
    initWebSocket() {
        this.eventSource = new EventSource(
            'http://localhost:3000/.well-known/mercure?topic=https://127.0.0.1:8000/chess/updates'
        );
        
        this.eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleWebSocketMessage(data);
        };
    }
    
    // Obsługa wiadomości WebSocket
    handleWebSocketMessage(data) {
        switch(data.type) {
            case 'possible_moves':
                this.showPossibleMoves(data.position, data.moves);
                break;
            case 'move':
                this.updateBoard(data);
                break;
            // ... inne typy wiadomości
        }
    }
    
    // Żądanie możliwych ruchów po kliknięciu pionka
    async onPieceClick(position) {
        try {
            const response = await fetch('/possible-moves', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ position: position })
            });
            
            const result = await response.json();
            
            if (result.status === 'request_sent') {
                console.log('Żądanie wysłane, oczekiwanie na odpowiedź...');
                // Opcjonalnie pokaż loading indicator
                this.showLoadingForPosition(position);
            } else {
                console.error('Błąd żądania:', result.error);
            }
        } catch (error) {
            console.error('Błąd sieci:', error);
        }
    }
    
    // Podświetlenie możliwych ruchów na planszy
    showPossibleMoves(position, moves) {
        // Usuń poprzednie podświetlenia
        this.clearHighlights();
        
        // Podświetl wybrany pionek
        this.highlightSquare(position, 'selected');
        
        // Podświetl możliwe ruchy
        moves.forEach(move => {
            this.highlightSquare(move, 'possible-move');
        });
        
        console.log(`Podświetlone ${moves.length} możliwych ruchów dla ${position}`);
    }
}

// Inicjalizacja
const chessBoard = new ChessBoard();
```

## 🔧 Obsługa błędów

```javascript
// Obsługa błędów API
async onPieceClick(position) {
    try {
        const response = await fetch('/possible-moves', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ position: position })
        });
        
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || 'Nieznany błąd serwera');
        }
        
        const result = await response.json();
        // ... reszta logiki
        
    } catch (error) {
        console.error('Błąd żądania możliwych ruchów:', error);
        this.showErrorMessage(`Nie można pobrać możliwych ruchów: ${error.message}`);
    }
}

// Timeout dla WebSocket
setupWebSocketTimeout() {
    let timeoutId;
    
    this.requestPossibleMoves = (position) => {
        // Wyczyść poprzedni timeout
        if (timeoutId) clearTimeout(timeoutId);
        
        // Ustaw timeout na 5 sekund
        timeoutId = setTimeout(() => {
            this.showErrorMessage('Timeout: Nie otrzymano odpowiedzi od serwera');
            this.clearLoadingIndicators();
        }, 5000);
        
        // Wykonaj żądanie...
    };
    
    // W handleWebSocketMessage - wyczyść timeout po otrzymaniu odpowiedzi
    if (data.type === 'possible_moves') {
        if (timeoutId) clearTimeout(timeoutId);
        this.showPossibleMoves(data.position, data.moves);
    }
}
```

## 🧪 Testowanie

1. **Uruchom nasłuchwianie na ruch mqtt:** `php bin/console app:mqtt-listen`
2. **Uruchom serwer deweloperski:** `symfony server:start`
3. **Uruchom Mercure Hub** na porcie 3000
4. **Test API:** `php test_possible_moves.php e2`
5. **Test WebSocket:** Sprawdź logi w konsoli przeglądarki