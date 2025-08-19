# Przykład zaawansowanej komunikacji - Partia z roszadą i promocją pionka

Ten dokument przedstawia szczegółowy przepływ komunikacji podczas dłuższej partii szachowej demonstrującej specjalne ruchy: **roszadę** i **promocję pionka**. Partia pokazuje jak system obsługuje te złożone mechanizmy szachowe.

## 📋 Partia: Demonstracja roszady i promocji (15 ruchów)

```
1. e2-e4    e7-e5
2. Ng1-f3   Nb8-c6
3. Bf1-c4   Bf8-c5
4. 0-0      d7-d6        # Roszada krótka białych
5. d2-d3    Bc8-g4
6. h2-h3    Bg4xf3       # Zbicie gońca
7. Qd1xf3   Ng8-f6
8. Bc1-g5   0-0          # Roszada krótka czarnych
9. Bg5xf6   g7xf6        # Zbicie skoczka i kolejne zbicie
10. Ra1-e1  Qd8-d7
11. Qf3-h5  Rf8-e8
12. Qh5xh7+ Kg8-f8       # Zbicie pionka z szachem
13. Qh7-h8+ Kf8-e7
14. Re1xe5+ d6xe5        # Zbicie wieży z szachem
15. Qh8xa8  e5-e4        # Zbicie wieży w rogu
16. Qa8-a7  e4-e3        # Pionek zbliża się do promocji
17. Qa7xd7+ Ke7-f6       # Zbicie hetmana z szachem
18. Qd7-d6+ Kf6-g5
19. h3-h4+  Kg5-f4
20. Qd6-f6+ Kf4-e3       # Szach, król ucieka
21. b2-b4   e3-e2        # Pionek na przedostatniej linii!
22. Qf6-e5+ Ke3-d2
23. Qe5-d4+ Kd2-e1       # Król przy promocji
24. Bc4-d3  e2-e1=Q+     # PROMOCJA PIONKA NA HETMANA Z SZACHEM!
25. Qd4xe1+ Kd2-c3       # Zbicie promowanego hetmana
26. Qe1-e3+ Kc3-b2
27. Qe3-b3+ Kb2-a1
28. Qb3-a2# (mat)
```

---

## 🎯 RUCHY 1-3: Standardowe otwarcie

### Ruchy 1-3 (podobnie jak w poprzednim dokumencie)

_[Pominięte dla zwięzłości - standardowe e4 e5, Nf3 Nc6, Bc4 Bc5]_

---

## 🎯 RUCH 4: 0-0 (ROSZADA KRÓTKA BIAŁYCH!)

### 4.1 Kliknięcie na króla e1 - żądanie możliwych ruchów

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/possible-moves
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "position": "e1"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: engine/possible_moves/request
```

```json
{
    "position": "e1",
    "fen": "r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 4 4"
}
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/possible_moves/response
```

```json
{
    "position": "e1",
    "moves": ["f1", "castling_kingside"],
    "special_moves": {
        "castling_kingside": {
            "king_from": "e1",
            "king_to": "g1",
            "rook_from": "h1",
            "rook_to": "f1",
            "notation": "0-0"
        }
    }
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "possible_moves",
    "position": "e1",
    "moves": ["f1", "castling_kingside"],
    "special_moves": {
        "castling_kingside": {
            "king_from": "e1",
            "king_to": "g1",
            "rook_from": "h1",
            "rook_to": "f1",
            "notation": "0-0",
            "description": "Roszada krótka"
        }
    },
    "timestamp": "2025-01-28T15:15:30Z"
}
```

### 4.2 Kliknięcie na pole g1 (lub wybór roszady) - wykonanie roszady

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/move
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "from": "e1",
    "to": "g1",
    "special_move": "castling_kingside"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: move/engine
```

```json
{
    "from": "e1",
    "to": "g1",
    "current_fen": "r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 4 4",
    "type": "move_validation",
    "special_move": "castling_kingside",
    "physical": false
}
```

**📥 Chess Engine → Backend (MQTT)** - Potwierdzenie roszady

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "e1",
    "to": "g1",
    "fen": "r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQ1RK1 b kq - 5 4",
    "next_player": "black",
    "physical": false,
    "special_move": "castling_kingside",
    "additional_moves": [
        {
            "from": "h1",
            "to": "f1",
            "piece": "rook"
        }
    ],
    "notation": "0-0"
}
```

**📤 Backend → Raspberry Pi (MQTT)** - Dwa ruchy dla roszady

```mqtt
Topic: move/raspi
```

```json
{
    "type": "castling",
    "subtype": "kingside",
    "moves": [
        {
            "from": "e1",
            "to": "g1",
            "piece": "king",
            "order": 1
        },
        {
            "from": "h1",
            "to": "f1",
            "piece": "rook",
            "order": 2
        }
    ],
    "fen": "r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQ1RK1 b kq - 5 4",
    "notation": "0-0"
}
```

**⚡ Backend → Web App (Mercure SSE)** - Roszada potwierdzona

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": {
        "from": "e1",
        "to": "g1",
        "special_move": "castling_kingside",
        "notation": "0-0"
    },
    "physical": false,
    "state": {
        "fen": "r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQ1RK1 b kq - 5 4",
        "moves": [
            { "from": "e2", "to": "e4" },
            { "from": "e7", "to": "e5" },
            { "from": "g1", "to": "f3" },
            { "from": "b8", "to": "c6" },
            { "from": "f1", "to": "c4" },
            { "from": "f8", "to": "c5" },
            { "from": "e1", "to": "g1", "special": "0-0" }
        ],
        "turn": "black",
        "castling_rights": {
            "white_kingside": false,
            "white_queenside": true,
            "black_kingside": true,
            "black_queenside": true
        }
    }
}
```

---

## 🎯 RUCHY 5-7: Kontynuacja z pierwszymi zbiciami

### Ruchy 5-7 skrócone (standardowe ruchy)

_d3, Bg4, h3, Bxf3, Qxf3, Nf6_

---

## 🎯 RUCH 8: 0-0 (ROSZADA KRÓTKA CZARNYCH!)

**📥 Chess Engine → Backend (MQTT)** - Ruch AI (roszada)

```mqtt
Topic: move/ai
```

```json
{
    "from": "e8",
    "to": "g8",
    "fen": "r1bq1rk1/pppp1ppp/2n2n2/2b1p3/2B1P3/3P1Q1P/PPP2PP1/RNB2RK1 w - - 8 8",
    "next_player": "white",
    "special_move": "castling_kingside",
    "additional_moves": [
        {
            "from": "h8",
            "to": "f8",
            "piece": "rook"
        }
    ],
    "notation": "0-0"
}
```

**📤 Backend → Raspberry Pi (MQTT)**

```mqtt
Topic: move/raspi
```

```json
{
    "type": "castling",
    "subtype": "kingside",
    "moves": [
        {
            "from": "e8",
            "to": "g8",
            "piece": "king",
            "order": 1
        },
        {
            "from": "h8",
            "to": "f8",
            "piece": "rook",
            "order": 2
        }
    ],
    "fen": "r1bq1rk1/pppp1ppp/2n2n2/2b1p3/2B1P3/3P1Q1P/PPP2PP1/RNB2RK1 w - - 8 8",
    "notation": "0-0"
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "ai_move_executed",
    "move": {
        "from": "e8",
        "to": "g8",
        "special_move": "castling_kingside",
        "notation": "0-0"
    },
    "state": {
        "fen": "r1bq1rk1/pppp1ppp/2n2n2/2b1p3/2B1P3/3P1Q1P/PPP2PP1/RNB2RK1 w - - 8 8",
        "moves": ["...", { "from": "e8", "to": "g8", "special": "0-0" }],
        "turn": "white",
        "castling_rights": {
            "white_kingside": false,
            "white_queenside": true,
            "black_kingside": false,
            "black_queenside": true
        }
    }
}
```

---

## 🎯 RUCHY 9-23: Środek gry z wieloma zbiciami

_[Ruchy 9-23 przyspieszam dla demonstracji - liczne zbicia prowadzące do sytuacji promocji]_

### Kluczowe zbicia w skrócie:

-   Ruch 9: `Bxf6 gxf6` (zbicie skoczka i pionka)
-   Ruch 12: `Qxh7+` (zbicie pionka z szachem)
-   Ruch 14: `Rxe5+ dxe5` (zbicie wieży z szachem)
-   Ruch 15: `Qxa8` (zbicie wieży w rogu)
-   Ruch 17: `Qxd7+` (zbicie hetmana z szachem)

---

## 🎯 RUCH 24: e2-e1=Q+ (PROMOCJA PIONKA NA HETMANA Z SZACHEM!)

### 24.1 Pozycja przed promocją

```
Aktualna pozycja FEN: "8/8/8/4Q3/1P6/3B4/4p3/4k3 b - - 0 24"
```

**📥 Chess Engine → Backend (MQTT)** - Ruch AI z promocją

```mqtt
Topic: move/ai
```

```json
{
    "from": "e2",
    "to": "e1",
    "fen": "4Q3/8/8/8/1P6/3B4/8/4q3 w - - 0 25",
    "next_player": "white",
    "special_move": "promotion",
    "promotion_piece": "queen",
    "captured_pieces_available": ["queen", "rook", "bishop", "knight"],
    "notation": "e1=Q+",
    "gives_check": true
}
```

**📤 Backend → Raspberry Pi (MQTT)**

```mqtt
Topic: move/raspi
```

```json
{
    "type": "promotion",
    "from": "e2",
    "to": "e1",
    "piece_removed": "pawn",
    "piece_placed": "queen",
    "color": "black",
    "fen": "4Q3/8/8/8/1P6/3B4/8/4q3 w - - 0 25",
    "notation": "e1=Q+",
    "gives_check": true,
    "instructions": {
        "step1": "Usuń czarnego pionka z e2",
        "step2": "Umieść czarnego hetmana na e1",
        "step3": "Hetman daje szach białemu królowi"
    }
}
```

**⚡ Backend → Web App (Mercure SSE)** - Promocja wykonana

```http
Event: message
```

```json
{
    "type": "ai_move_executed",
    "move": {
        "from": "e2",
        "to": "e1",
        "special_move": "promotion",
        "promotion_piece": "queen",
        "notation": "e1=Q+",
        "gives_check": true
    },
    "state": {
        "fen": "4Q3/8/8/8/1P6/3B4/8/4q3 w - - 0 25",
        "moves": [
            "...",
            {
                "from": "e2",
                "to": "e1",
                "special": "promotion",
                "piece": "queen",
                "notation": "e1=Q+"
            }
        ],
        "turn": "white",
        "in_check": true,
        "promotion_occurred": {
            "square": "e1",
            "old_piece": "pawn",
            "new_piece": "queen",
            "color": "black"
        }
    }
}
```

### 24.2 Opcja promocji wybranej przez gracza

Jeśli gracz mógłby wybrać promocję:

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/move
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "from": "e2",
    "to": "e1",
    "special_move": "promotion",
    "promotion_piece": "rook",
    "available_pieces": ["queen", "rook", "bishop", "knight"]
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: move/engine
```

```json
{
    "from": "e2",
    "to": "e1",
    "current_fen": "8/8/8/4Q3/1P6/3B4/4p3/4k3 b - - 0 24",
    "type": "move_validation",
    "special_move": "promotion",
    "promotion_piece": "rook",
    "physical": false
}
```

---

## 🎯 RUCH 25: Qd4xe1+ (ZBICIE PROMOWANEGO HETMANA!)

**🌐 Web App → Backend (HTTP POST)**

```http
URL: http://localhost:8000/move
Method: POST
Headers: Content-Type: application/json
```

```json
{
    "from": "d4",
    "to": "e1"
}
```

**📤 Backend → Chess Engine (MQTT)**

```mqtt
Topic: move/engine
```

```json
{
    "from": "d4",
    "to": "e1",
    "current_fen": "4Q3/8/8/8/1P6/3B4/8/4q3 w - - 0 25",
    "type": "move_validation",
    "physical": false,
    "capture": true,
    "captured_piece": "queen"
}
```

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "d4",
    "to": "e1",
    "fen": "8/8/8/8/1P6/3B4/8/4Q3 b - - 0 25",
    "next_player": "black",
    "physical": false,
    "capture": true,
    "captured_piece": "queen",
    "notation": "Qxe1+",
    "gives_check": true
}
```

**📤 Backend → Raspberry Pi (MQTT)**

```mqtt
Topic: move/raspi
```

```json
{
    "from": "d4",
    "to": "e1",
    "type": "capture",
    "piece_moved": "queen",
    "piece_captured": "queen",
    "color_moved": "white",
    "color_captured": "black",
    "fen": "8/8/8/8/1P6/3B4/8/4Q3 b - - 0 25",
    "notation": "Qxe1+",
    "gives_check": true,
    "instructions": {
        "step1": "Usuń czarnego hetmana z e1",
        "step2": "Przenieś białego hetmana z d4 na e1",
        "step3": "Hetman daje szach czarnemu królowi"
    }
}
```

**⚡ Backend → Web App (Mercure SSE)**

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": {
        "from": "d4",
        "to": "e1",
        "capture": true,
        "captured_piece": "queen",
        "notation": "Qxe1+",
        "gives_check": true
    },
    "physical": false,
    "state": {
        "fen": "8/8/8/8/1P6/3B4/8/4Q3 b - - 0 25",
        "moves": [
            "...",
            {
                "from": "e2",
                "to": "e1",
                "special": "promotion",
                "piece": "queen"
            },
            {
                "from": "d4",
                "to": "e1",
                "capture": "queen",
                "notation": "Qxe1+"
            }
        ],
        "turn": "black",
        "in_check": true,
        "material_balance": {
            "white_captured": [
                "pawn",
                "pawn",
                "knight",
                "bishop",
                "rook",
                "rook",
                "queen"
            ],
            "black_captured": ["pawn", "pawn", "bishop", "knight", "queen"]
        }
    }
}
```

---

## 🎯 RUCHY 26-28: Końcówka prowadząca do mata

### Ruch 26-27: Pościg za królem

_[Qe3+ Kc3, Qb3+ Kb2 - standardowe szachy]_

### Ruch 28: Qa2# (MAT!)

**📥 Chess Engine → Backend (MQTT)**

```mqtt
Topic: engine/move/confirmed
```

```json
{
    "from": "b3",
    "to": "a2",
    "fen": "8/8/8/8/1P6/3B4/Q7/k7 b - - 2 28",
    "next_player": "black",
    "physical": false,
    "notation": "Qa2#",
    "game_status": "checkmate",
    "winner": "white",
    "mate_in": 1
}
```

**⚡ Backend → Web App (Mercure SSE)** - MAT z promocją w historii!

```http
Event: message
```

```json
{
    "type": "move_confirmed",
    "move": {
        "from": "b3",
        "to": "a2",
        "notation": "Qa2#"
    },
    "physical": false,
    "state": {
        "fen": "8/8/8/8/1P6/3B4/Q7/k7 b - - 2 28",
        "moves": [
            { "from": "e2", "to": "e4" },
            "...",
            { "from": "e1", "to": "g1", "special": "0-0" },
            "...",
            { "from": "e8", "to": "g8", "special": "0-0" },
            "...",
            {
                "from": "e2",
                "to": "e1",
                "special": "promotion",
                "piece": "queen"
            },
            { "from": "d4", "to": "e1", "capture": "queen" },
            "...",
            { "from": "b3", "to": "a2", "notation": "Qa2#" }
        ],
        "turn": "black",
        "game_status": "checkmate",
        "winner": "white"
    }
}
```

**⚡ Backend → Web App (Mercure SSE)** - Koniec gry zaawansowanej

```http
Event: message
```

```json
{
    "type": "game_over",
    "result": "checkmate",
    "winner": "white",
    "final_position": "8/8/8/8/1P6/3B4/Q7/k7 b - - 2 28",
    "moves_count": 28,
    "game_type": "advanced_tactics",
    "special_moves_used": [
        {
            "move": 4,
            "type": "castling_kingside",
            "player": "white",
            "notation": "0-0"
        },
        {
            "move": 8,
            "type": "castling_kingside",
            "player": "black",
            "notation": "0-0"
        },
        {
            "move": 24,
            "type": "promotion",
            "player": "black",
            "piece": "queen",
            "notation": "e1=Q+"
        }
    ],
    "captures_count": 12,
    "material_captured": {
        "white_lost": [
            "pawn",
            "pawn",
            "knight",
            "bishop",
            "rook",
            "rook",
            "queen"
        ],
        "black_lost": ["pawn", "pawn", "bishop", "knight", "queen"]
    }
}
```

---

## 📊 Podsumowanie zaawansowanej komunikacji

### Statystyki partii:

-   **Ruchy białych**: 14 (wszystkie przez Web App)
-   **Ruchy czarnych**: 14 (wszystkie AI)
-   **Łączna liczba ruchów**: 28
-   **Roszady wykonane**: 2 (biała i czarna roszada krótka)
-   **Promocje pionków**: 1 (czarny pionek → hetman)
-   **Zbicia**: 12 (w tym zbicie promowanego hetmana)
-   **Łączna liczba komunikatów MQTT**: ~84
-   **Łączna liczba komunikatów Mercure**: ~28
-   **Łączna liczba żądań HTTP**: 14

### Specjalne ruchy zademonstrowane:

#### 🏰 Roszada (Castling):

-   **Typ**: Krótka roszada (0-0) dla obu stron
-   **Komunikacja**: Dodatkowe pole `special_move` i `additional_moves`
-   **Raspberry Pi**: Otrzymuje instrukcje dla dwóch figur jednocześnie
-   **FEN update**: Automatyczna aktualizacja praw do roszady

#### ♛ Promocja pionka (Pawn Promotion):

-   **Typ**: Promocja na hetmana (e1=Q+)
-   **Wybór figury**: System obsługuje wybór spośród dostępnych figur
-   **Komunikacja**: Specjalne pole `promotion_piece`
-   **Raspberry Pi**: Instrukcje usunięcia pionka i umieszczenia nowej figury

### Wykorzystane protokoły:

-   **HTTP REST API**: 14 żądań (ruchy + possible moves)
-   **MQTT**: ~84 komunikaty (walidacja, AI, specjalne ruchy)
-   **Mercure SSE**: ~28 real-time updates (stan gry, specjalne ruchy)

### Kluczowe komponenty zaawansowane:

-   ✅ **Obsługa roszady**: Dwuruchowa operacja z walidacją praw
-   ✅ **Promocja pionka**: Wybór figury z dostępnych opcji
-   ✅ **Zbicia złożone**: Łańcuch zbić z aktualizacją bilansu materiału
-   ✅ **Real-time updates**: Natychmiastowe powiadomienia o specjalnych ruchach
-   ✅ **Historia partii**: Pełne śledzenie wszystkich ruchów specjalnych

Zaawansowana partia została pomyślnie wykonana z demonstracją wszystkich kluczowych mechanizmów szachowych! 🏰♛🎉
