# Przykłady obsługi różnych typów promocji pionka

Ten dokument przedstawia przykłady wszystkich typów promocji pionka obsługiwanych przez system.

## 🎯 Scenariusz: Biały pionek na a7

### Sytuacja na planszy:

-   Biały pionek na polu `a7`
-   Dostępne ruchy:
    -   `a8` (czysta promocja)
    -   `b8` (promocja z biciem czarnego konia)

---

## 📱 Typ 1: Czysta promocja (a7-a8=Q)

### 🌐 Web App → Backend (HTTP POST)

```http
POST /move
Content-Type: application/json

{
    "from": "a7",
    "to": "a8",
    "special_move": "promotion",
    "promotion_piece": "queen",
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

### 📤 Backend → Raspberry Pi (MQTT)

```mqtt
Topic: move/raspi

{
    "from": "a7",
    "to": "a8",
    "type": "promotion",
    "piece_removed": "pawn",
    "piece_placed": "queen",
    "color": "white",
    "notation": "a8=Q",
    "instructions": {
        "step1": "Usuń białego pionka z a7",
        "step2": "Umieść białego hetmana na a8",
        "step3": "Promocja zakończona"
    }
}
```

---

## ⚔️ Typ 2: Promocja z biciem (a7xb8=Q)

### 🌐 Web App → Backend (HTTP POST)

```http
POST /move
Content-Type: application/json

{
    "from": "a7",
    "to": "b8",
    "special_move": "promotion_capture",
    "promotion_piece": "queen",
    "captured_piece": "knight",
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

### 📤 Backend → Raspberry Pi (MQTT)

```mqtt
Topic: move/raspi

{
    "from": "a7",
    "to": "b8",
    "type": "promotion_capture",
    "piece_removed": "pawn",
    "piece_placed": "queen",
    "piece_captured": "knight",
    "capture": true,
    "color": "white",
    "notation": "axb8=Q",
    "instructions": {
        "step1": "Usuń białego pionka z a7",
        "step2": "Usuń zbitą figurę (knight) z b8",
        "step3": "Umieść białego hetmana na b8",
        "step4": "Promocja z biciem zakończona"
    }
}
```

---

## ♔ Typ 3: Promocja z szachem (a7-a8=Q+)

### 🌐 Web App → Backend (HTTP POST)

```http
POST /move
Content-Type: application/json

{
    "from": "a7",
    "to": "a8",
    "special_move": "promotion",
    "promotion_piece": "queen",
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

### 📥 Chess Engine → Backend (MQTT)

```mqtt
Topic: engine/move/confirmed

{
    "from": "a7",
    "to": "a8",
    "fen": "Q7/8/8/8/8/8/8/4k3 b - - 0 1",
    "next_player": "black",
    "special_move": "promotion",
    "promotion_piece": "queen",
    "notation": "a8=Q+",
    "gives_check": true
}
```

### 📤 Backend → Raspberry Pi (MQTT)

```mqtt
Topic: move/raspi

{
    "from": "a7",
    "to": "a8",
    "type": "promotion",
    "piece_removed": "pawn",
    "piece_placed": "queen",
    "color": "white",
    "notation": "a8=Q+",
    "gives_check": true,
    "instructions": {
        "step1": "Usuń białego pionka z a7",
        "step2": "Umieść białego hetmana na a8",
        "step3": "Figura daje szach przeciwnemu królowi"
    }
}
```

---

## ⚔️♔ Typ 4: Promocja z biciem i szachem (a7xb8=Q+)

### 🌐 Web App → Backend (HTTP POST)

```http
POST /move
Content-Type: application/json

{
    "from": "a7",
    "to": "b8",
    "special_move": "promotion_capture",
    "promotion_piece": "queen",
    "captured_piece": "knight",
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

### 📥 Chess Engine → Backend (MQTT)

```mqtt
Topic: engine/move/confirmed

{
    "from": "a7",
    "to": "b8",
    "fen": "1Q6/8/8/8/8/8/8/4k3 b - - 0 1",
    "next_player": "black",
    "special_move": "promotion_capture",
    "promotion_piece": "queen",
    "captured_piece": "knight",
    "notation": "axb8=Q+",
    "gives_check": true
}
```

### 📤 Backend → Raspberry Pi (MQTT)

```mqtt
Topic: move/raspi

{
    "from": "a7",
    "to": "b8",
    "type": "promotion_capture",
    "piece_removed": "pawn",
    "piece_placed": "queen",
    "piece_captured": "knight",
    "capture": true,
    "color": "white",
    "notation": "axb8=Q+",
    "gives_check": true,
    "instructions": {
        "step1": "Usuń białego pionka z a7",
        "step2": "Usuń zbitą figurę (knight) z b8",
        "step3": "Umieść białego hetmana na b8",
        "step4": "Figura daje szach przeciwnemu królowi"
    }
}
```

---

## 🔍 Możliwe ruchy dla promocji

### 🌐 Web App → Backend (HTTP POST)

```http
POST /possible-moves
Content-Type: application/json

{
    "position": "a7"
}
```

### 📥 Chess Engine → Backend (MQTT) - **ROZSZERZONA ODPOWIEDŹ**

```mqtt
Topic: engine/possible_moves/response

{
    "position": "a7",
    "moves": [
        {
            "to": "a8",
            "type": "promotion",
            "available_pieces": ["queen", "rook", "bishop", "knight"],
            "promotion_options": {
                "queen": { "gives_check": false, "notation": "a8=Q" },
                "rook": { "gives_check": false, "notation": "a8=R" },
                "bishop": { "gives_check": false, "notation": "a8=B" },
                "knight": { "gives_check": true, "notation": "a8=N+" }
            }
        },
        {
            "to": "b8",
            "type": "promotion_capture",
            "captured_piece": "knight",
            "available_pieces": ["queen", "rook", "bishop", "knight"],
            "promotion_options": {
                "queen": { "gives_check": true, "notation": "axb8=Q+" },
                "rook": { "gives_check": false, "notation": "axb8=R" },
                "bishop": { "gives_check": false, "notation": "axb8=B" },
                "knight": { "gives_check": false, "notation": "axb8=N" }
            }
        }
    ]
}
```

### ⚡ Backend → Web App (Mercure SSE)

```json
{
    "type": "possible_moves",
    "position": "a7",
    "moves": [
        {
            "to": "a8",
            "type": "promotion",
            "available_pieces": ["queen", "rook", "bishop", "knight"],
            "promotion_required": true
        },
        {
            "to": "b8",
            "type": "promotion_capture",
            "captured_piece": "knight",
            "available_pieces": ["queen", "rook", "bishop", "knight"],
            "promotion_required": true
        }
    ],
    "timestamp": "2025-09-03T14:30:15Z"
}
```

---

## 🛡️ Walidacja błędów

### ❌ Brak figury promocji dla czystej promocji

```json
{
    "from": "a7",
    "to": "a8",
    "special_move": "promotion"
    // BRAK: "promotion_piece"
}
```

**Odpowiedź:** `400 Bad Request`

```json
{
    "error": "Promotion piece required for promotion move"
}
```

### ❌ Brak zbitej figury dla promocji z biciem

```json
{
    "from": "a7",
    "to": "b8",
    "special_move": "promotion_capture",
    "promotion_piece": "queen"
    // BRAK: "captured_piece"
}
```

**Odpowiedź:** `400 Bad Request`

```json
{
    "error": "Captured piece required for promotion capture move"
}
```

### ❌ Nieprawidłowa figura promocji

```json
{
    "from": "a7",
    "to": "a8",
    "special_move": "promotion",
    "promotion_piece": "king", // ❌ NIEPRAWIDŁOWE
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

**Odpowiedź:** `400 Bad Request`

```json
{
    "error": "Invalid promotion piece"
}
```
