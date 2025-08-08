# Konfiguracja zewnętrznych komponentów - Plug & Play 🔌

Ten dokument opisuje jak skonfigurować zewnętrzne komponenty systemu szachowego (Raspberry Pi i Chess Engine), aby działały w trybie "plug & play" z głównym backendem.

## 🎯 Przegląd architektury

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Frontend      │    │   Docker Stack   │    │  External       │
│                 │◄──►│   Symfony+MQTT   │◄──►│  Components     │
│   localhost:80  │    │   +Mercure       │    │  (Host Network) │
└─────────────────┘    └──────────────────┘    └─────────────────┘
                                                │ • Raspberry Pi  │
                                                │ • Chess Engine  │
                                                └─────────────────┘
```

## 🔧 Konfiguracja adresów IP

### Domyślne adresy (można zmienić w .env lub docker-compose.yml)

```bash
# Raspberry Pi - fizyczny kontroler szachownicy
RASPBERRY_PI_URL=http://host.docker.internal:8080

# Chess Engine - silnik szachowy AI
CHESS_ENGINE_URL=http://host.docker.internal:5000
```

### Opcje konfiguracji

#### 1. **Lokalnie na tym samym komputerze** (domyślnie)

```bash
# W docker-compose.yml (już skonfigurowane)
RASPBERRY_PI_URL: http://host.docker.internal:8080
CHESS_ENGINE_URL: http://host.docker.internal:5000
```

#### 2. **Na innych komputerach w sieci lokalnej**

```bash
# Zmień IP na rzeczywiste adresy w sieci
RASPBERRY_PI_URL: http://192.168.1.100:8080
CHESS_ENGINE_URL: http://192.168.1.101:5000
```

#### 3. **Raspberry Pi i inne urządzenia**

```bash
# Przykład dla Raspberry Pi w sieci lokalnej
RASPBERRY_PI_URL: http://192.168.1.50:8080
CHESS_ENGINE_URL: http://192.168.1.60:5000
```

## 📋 Wymagania dla zewnętrznych komponentów

### Raspberry Pi API (Port 8080)

Musi implementować endpoint:

```
GET /health
Response: HTTP 200-299 (dowolna odpowiedź)
```

### Chess Engine API (Port 5000)

Musi implementować endpoint:

```
GET /health
Response: HTTP 200-299 (dowolna odpowiedź)
```

## 🚀 Instrukcja konfiguracji

### Krok 1: Określ adresy IP

1. Sprawdź adresy IP urządzeń w sieci:

    ```bash
    # Windows
    ipconfig

    # Linux/Mac
    ifconfig
    ```

### Krok 2: Zaktualizuj konfigurację

Edytuj `docker-compose.yml` i zmień adresy:

```yaml
environment:
    RASPBERRY_PI_URL: http://[IP_RASPBERRY]:8080
    CHESS_ENGINE_URL: http://[IP_CHESS_ENGINE]:5000
```

### Krok 3: Przebuduj i uruchom

```bash
# Zastosuj zmiany
docker-compose down
docker-compose up -d

# Sprawdź status
curl http://localhost:8000/api/health
```

## 🔍 Monitoring i diagnostyka

### Dashboard monitoringu

-   **Web UI**: http://localhost:8000/health
-   **API**: http://localhost:8000/api/health

### Status komponentów

-   ✅ **healthy**: Komponent dostępny i działa
-   ⚠️ **warning**: Komponent niedostępny (ale system działa)
-   ❌ **unhealthy**: Krytyczny błąd

### Przykładowa odpowiedź API

```json
{
    "mqtt": { "status": "healthy", "response_time": "15ms" },
    "mercure": { "status": "healthy", "response_time": "8ms" },
    "raspberry": {
        "status": "warning",
        "message": "Raspberry Pi not available: Connection timeout",
        "endpoint": "http://host.docker.internal:8080",
        "note": "External component - ensure Raspberry Pi is running"
    },
    "chess_engine": {
        "status": "healthy",
        "response_time": "25ms",
        "endpoint": "http://host.docker.internal:5000"
    },
    "overall_status": "warning"
}
```

## 🛠️ Rozwiązywanie problemów

### "Connection timeout" lub "Connection refused"

1. **Sprawdź czy usługa działa**:

    ```bash
    # Test dostępności z hosta
    curl http://localhost:8080/health     # Raspberry Pi
    curl http://localhost:5000/health     # Chess Engine
    ```

2. **Sprawdź firewall**:

    - Windows: Windows Defender Firewall
    - Linux: `ufw status` lub `iptables -L`

3. **Sprawdź adresy IP**:
    - `host.docker.internal` działa tylko lokalnie
    - Dla innych komputerów użyj rzeczywistych IP

### "404 Not Found"

-   Sprawdź czy endpoint `/health` jest zaimplementowany
-   Sprawdź czy aplikacja nasłuchuje na poprawnym porcie

### Docker nie może połączyć się z hostem

Na Linuxie może być potrzebne dodanie:

```yaml
extra_hosts:
    - "host.docker.internal:host-gateway"
```

## 📝 Szablon implementacji /health endpoint

### Python Flask

```python
from flask import Flask
app = Flask(__name__)

@app.route('/health')
def health():
    return {'status': 'ok', 'service': 'raspberry-pi'}, 200

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8080)
```

### Node.js Express

```javascript
const express = require("express");
const app = express();

app.get("/health", (req, res) => {
    res.json({ status: "ok", service: "chess-engine" });
});

app.listen(5000, "0.0.0.0", () => {
    console.log("Chess Engine running on port 5000");
});
```

### Python FastAPI

```python
from fastapi import FastAPI
app = FastAPI()

@app.get("/health")
def health():
    return {"status": "ok", "service": "raspberry-pi"}

# uvicorn main:app --host 0.0.0.0 --port 8080
```

## 🎉 Gotowe!

Po wykonaniu tych kroków:

1. Backend automatycznie wykryje dostępne komponenty
2. Dashboard pokaże status wszystkich usług
3. System będzie działał w trybie plug & play
4. Nowe komponenty można łatwo dodawać zmieniając tylko IP/port

**Sprawdź status**: http://localhost:8000/health
