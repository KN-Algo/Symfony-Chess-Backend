<?php

namespace App\Service;

/**
 * Główny serwis logiki gry szachowej.
 * 
 * GameService odpowiada za koordynację wszystkich operacji związanych z grą:
 * - Przetwarzanie ruchów gracza (fizycznych i z UI)
 * - Obsługa ruchów AI
 * - Resetowanie gry
 * - Komunikacja z zewnętrznymi komponentami (MQTT, Mercure)
 * - Zarządzanie stanem gry i historią ruchów
 * 
 * Serwis implementuje logikę opisaną w dokumentacji wymagań systemu szachowego,
 * zapewniając spójność między fizyczną szachownicą, silnikiem AI i aplikacją webową.
 * 
 */
class GameService
{
    /**
     * @param StateStorage $state Magazyn stanu gry (pozycje figur, historia ruchów)
     * @param MqttService $mqtt Serwis MQTT do komunikacji z zewnętrznymi komponentami
     * @param NotifierService $notifier Serwis powiadomień do komunikacji z UI przez WebSocket
     */
    public function __construct(
        private StateStorage    $state,
        private MqttService     $mqtt,
        private NotifierService $notifier
    ) {}

    /**
     * Przetwarza ruch gracza (fizyczny lub z aplikacji webowej).
     * 
     * Metoda dodaje ruch jako oczekujący i wysyła go do silnika szachowego w celu
     * walidacji. Stan planszy zostanie zaktualizowany dopiero po otrzymaniu
     * potwierdzenia lub odrzucenia od silnika.
     * 
     * @param string $from Pole źródłowe w notacji szachowej (np. "e2")
     * @param string $to Pole docelowe w notacji szachowej (np. "e4")
     * @param bool $physical Czy ruch został wykonany fizycznie na planszy (true) czy pochodzi z UI (false)
     * @param string|null $specialMove Typ specjalnego ruchu ("castling_kingside", "castling_queenside", "promotion", "promotion_capture", itp.)
     * @param string|null $promotionPiece Figura do promocji ("queen", "rook", "bishop", "knight")
     * @param array|null $availablePieces Dostępne figury do promocji
     * @param string|null $capturedPiece Zbita figura (dla promocji z biciem)
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT
     */
    public function playerMove(
        string $from,
        string $to,
        bool $physical,
        ?string $specialMove = null,
        ?string $promotionPiece = null,
        ?array $availablePieces = null,
        ?string $capturedPiece = null
    ): void {
        // 1) Dodaj ruch jako oczekujący na potwierdzenie
        $pendingMoveData = [
            'special_move' => $specialMove,
            'promotion_piece' => $promotionPiece
        ];
        if ($availablePieces) {
            $pendingMoveData['available_pieces'] = $availablePieces;
        }
        if ($capturedPiece) {
            $pendingMoveData['captured_piece'] = $capturedPiece;
        }

        $this->state->addPendingMove($from, $to, $pendingMoveData);

        // 2) Przygotuj dane dla silnika
        $engineData = [
            'from' => $from,
            'to' => $to,
            'current_fen' => $this->state->getState()['fen'],
            'type' => 'move_validation',
            'physical' => $physical
        ];

        // Dodaj specjalne dane jeśli istnieją
        if ($specialMove) {
            $engineData['special_move'] = $specialMove;
        }
        if ($promotionPiece) {
            $engineData['promotion_piece'] = $promotionPiece;
        }
        if ($availablePieces) {
            $engineData['available_pieces'] = $availablePieces;
        }
        if ($capturedPiece) {
            $engineData['captured_piece'] = $capturedPiece;
        }

        // 3) Wyślij do silnika w celu walidacji i otrzymania nowego FEN
        // UWAGA: RPi otrzyma polecenie ruchu dopiero po potwierdzeniu przez silnik
        $this->mqtt->publish('move/engine', $engineData);

        // 4) Powiadomienie do UI o ruchu oczekującym na potwierdzenie
        $notificationData = [
            'type' => 'move_pending',
            'move' => compact('from', 'to'),
            'physical' => $physical,
            'state' => $this->state->getState()
        ];

        // Dodaj specjalne dane do powiadomienia
        if ($specialMove) {
            $notificationData['move']['special_move'] = $specialMove;
        }
        if ($promotionPiece) {
            $notificationData['move']['promotion_piece'] = $promotionPiece;
        }

        $this->notifier->broadcast($notificationData);
    }

    /**
     * Przetwarza ruch AI (silnika szachowego).
     * 
     * Metoda obsługuje ruchy generowane przez silnik szachowy. Ponieważ silnik
     * jest źródłem prawdy, ruch jest od razu potwierdzany i stan aktualizowany.
     * 
     * @param string $from Pole źródłowe ruchu AI w notacji szachowej
     * @param string $to Pole docelowe ruchu AI w notacji szachowej
     * @param string $newFen Nowy stan planszy po ruchu AI
     * @param string $nextPlayer Następny gracz po ruchu AI
     * @param string|null $specialMove Typ specjalnego ruchu AI
     * @param array|null $additionalMoves Dodatkowe ruchy (np. dla roszady)
     * @param string|null $promotionPiece Figura do promocji
     * @param string|null $notation Notacja szachowa ruchu
     * @param bool $givesCheck Czy ruch daje szach
     * @param string|null $gameStatus Status gry ("checkmate", "stalemate", itp.)
     * @param string|null $winner Zwycięzca gry
     * @param string|null $capturedPiece Zbita figura (dla promocji z biciem)
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT
     */
    public function aiMove(
        string $from,
        string $to,
        string $newFen,
        string $nextPlayer,
        ?string $specialMove = null,
        ?array $additionalMoves = null,
        ?string $promotionPiece = null,
        ?string $notation = null,
        bool $givesCheck = false,
        ?string $gameStatus = null,
        ?string $winner = null,
        ?string $capturedPiece = null
    ): void {
        // 1) Potwierdź ruch AI w stanie gry
        $this->state->confirmMove($from, $to, $newFen, $nextPlayer, [
            'special_move' => $specialMove,
            'additional_moves' => $additionalMoves,
            'promotion_piece' => $promotionPiece,
            'notation' => $notation,
            'gives_check' => $givesCheck,
            'game_status' => $gameStatus,
            'winner' => $winner
        ]);

        // 2) Przygotuj dane dla Raspberry Pi
        $raspiData = [
            'from' => $from,
            'to' => $to,
            'fen' => $newFen
        ];

        // Obsługa specjalnych ruchów dla Raspberry Pi
        if ($specialMove === 'castling_kingside' || $specialMove === 'castling_queenside') {
            $raspiData['type'] = 'castling';
            $raspiData['subtype'] = $specialMove === 'castling_kingside' ? 'kingside' : 'queenside';
            if ($additionalMoves) {
                $raspiData['moves'] = [
                    [
                        'from' => $from,
                        'to' => $to,
                        'piece' => 'king',
                        'order' => 1
                    ],
                    [
                        'from' => $additionalMoves[0]['from'],
                        'to' => $additionalMoves[0]['to'],
                        'piece' => 'rook',
                        'order' => 2
                    ]
                ];
            }
            if ($notation) {
                $raspiData['notation'] = $notation;
            }
        } elseif ($specialMove === 'promotion') {
            $raspiData['type'] = 'promotion';
            $raspiData['piece_removed'] = 'pawn';
            $raspiData['piece_placed'] = $promotionPiece ?? 'queen';
            $raspiData['color'] = $nextPlayer === 'white' ? 'black' : 'white'; // Kolor który wykonał ruch
            if ($notation) {
                $raspiData['notation'] = $notation;
            }
            if ($givesCheck) {
                $raspiData['gives_check'] = true;
            }
            $raspiData['instructions'] = [
                'step1' => "Usuń " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " pionka z " . $from,
                'step2' => "Umieść " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " " . ($promotionPiece ?? 'hetmana') . " na " . $to,
                'step3' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Promocja zakończona"
            ];
        } elseif ($specialMove === 'promotion_capture') {
            $raspiData['type'] = 'promotion_capture';
            $raspiData['piece_removed'] = 'pawn';
            $raspiData['piece_placed'] = $promotionPiece ?? 'queen';
            $raspiData['piece_captured'] = $capturedPiece ?? 'unknown';
            $raspiData['capture'] = true;
            $raspiData['color'] = $nextPlayer === 'white' ? 'black' : 'white'; // Kolor który wykonał ruch
            if ($notation) {
                $raspiData['notation'] = $notation;
            }
            if ($givesCheck) {
                $raspiData['gives_check'] = true;
            }
            $raspiData['instructions'] = [
                'step1' => "Usuń " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " pionka z " . $from,
                'step2' => "Usuń zbitą figurę (" . ($capturedPiece ?? 'nieznana') . ") z " . $to,
                'step3' => "Umieść " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " " . ($promotionPiece ?? 'hetmana') . " na " . $to,
                'step4' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Promocja z biciem zakończona"
            ];
        } elseif ($capturedPiece && !$specialMove) {
            // Zwykłe bicie (nie promocja)
            $raspiData['type'] = 'capture';
            $raspiData['piece_captured'] = $capturedPiece;
            $raspiData['capture'] = true;
            $raspiData['color_moved'] = $nextPlayer === 'white' ? 'black' : 'white'; // Kolor który wykonał ruch
            $raspiData['color_captured'] = $nextPlayer === 'white' ? 'white' : 'black'; // Kolor zbitej figury
            if ($notation) {
                $raspiData['notation'] = $notation;
            }
            if ($givesCheck) {
                $raspiData['gives_check'] = true;
            }
            $raspiData['instructions'] = [
                'step1' => "Usuń zbitą figurę (" . $capturedPiece . ") z " . $to,
                'step2' => "Przenieś figurę z " . $from . " na " . $to,
                'step3' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Bicie zakończone"
            ];
        }

        if ($givesCheck) {
            $raspiData['gives_check'] = true;
        }

        // 3) Powiadom RasPi o ruchu AI
        $this->mqtt->publish('move/raspi', $raspiData);

        // 4) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 5) Przygotuj powiadomienie do UI
        $notificationData = [
            'type' => 'ai_move_executed',
            'move' => compact('from', 'to'),
            'state' => $state
        ];

        // Dodaj specjalne dane do powiadomienia
        if ($specialMove) {
            $notificationData['move']['special_move'] = $specialMove;
        }
        if ($notation) {
            $notificationData['move']['notation'] = $notation;
        }
        if ($promotionPiece) {
            $notificationData['move']['promotion_piece'] = $promotionPiece;
        }
        if ($givesCheck) {
            $notificationData['move']['gives_check'] = $givesCheck;
        }
        if ($additionalMoves) {
            $notificationData['move']['additional_moves'] = $additionalMoves;
        }

        // Sprawdź koniec gry
        if ($gameStatus && in_array($gameStatus, ['checkmate', 'stalemate', 'draw'])) {
            $notificationData['game_over'] = [
                'result' => $gameStatus,
                'winner' => $winner,
                'final_position' => $newFen,
                'moves_count' => count($state['moves'])
            ];
        }

        // 6) Wyślij powiadomienie przez wewnętrzny temat - będzie przekazane do UI
        // po potwierdzeniu wykonania ruchu przez Raspberry Pi
        $this->mqtt->publish('internal/pending_ui_notification', $notificationData);
    }

    /**
     * Obsługuje potwierdzenie ruchu przez silnik szachowy.
     * 
     * Metoda wywoływana gdy silnik szachowy potwierdza poprawność ruchu gracza
     * i przesyła aktualny stan planszy po wykonaniu ruchu.
     * 
     * @param string $from Pole źródłowe ruchu
     * @param string $to Pole docelowe ruchu
     * @param string $newFen Nowy stan planszy po ruchu
     * @param string $nextPlayer Następny gracz po ruchu
     * @param bool $physical Czy ruch był fizyczny
     * @param string|null $specialMove Typ specjalnego ruchu
     * @param array|null $additionalMoves Dodatkowe ruchy (np. dla roszady)
     * @param string|null $promotionPiece Figura do promocji
     * @param string|null $notation Notacja szachowa ruchu
     * @param bool $givesCheck Czy ruch daje szach
     * @param string|null $gameStatus Status gry ("checkmate", "stalemate", itp.)
     * @param string|null $winner Zwycięzca gry
     * @param string|null $capturedPiece Zbita figura (dla promocji z biciem)
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT
     */
    public function confirmMoveFromEngine(
        string $from,
        string $to,
        string $newFen,
        string $nextPlayer,
        bool $physical = false,
        ?string $specialMove = null,
        ?array $additionalMoves = null,
        ?string $promotionPiece = null,
        ?string $notation = null,
        bool $givesCheck = false,
        ?string $gameStatus = null,
        ?string $winner = null,
        ?string $capturedPiece = null
    ): void {
        // 1) Potwierdź ruch w stanie gry
        $this->state->confirmMove($from, $to, $newFen, $nextPlayer, [
            'physical' => $physical,
            'special_move' => $specialMove,
            'additional_moves' => $additionalMoves,
            'promotion_piece' => $promotionPiece,
            'notation' => $notation,
            'gives_check' => $givesCheck,
            'game_status' => $gameStatus,
            'winner' => $winner,
            'captured_piece' => $capturedPiece
        ]);

        // 2) Przygotuj dane dla Raspberry Pi (tylko jeśli ruch nie był fizyczny)
        if (!$physical) {
            $raspiData = [
                'from' => $from,
                'to' => $to,
                'fen' => $newFen
            ];

            // Obsługa specjalnych ruchów dla Raspberry Pi
            if ($specialMove === 'castling_kingside' || $specialMove === 'castling_queenside') {
                $raspiData['type'] = 'castling';
                $raspiData['subtype'] = $specialMove === 'castling_kingside' ? 'kingside' : 'queenside';
                if ($additionalMoves) {
                    $raspiData['moves'] = [
                        [
                            'from' => $from,
                            'to' => $to,
                            'piece' => 'king',
                            'order' => 1
                        ],
                        [
                            'from' => $additionalMoves[0]['from'],
                            'to' => $additionalMoves[0]['to'],
                            'piece' => 'rook',
                            'order' => 2
                        ]
                    ];
                }
            } elseif ($specialMove === 'promotion') {
                $raspiData['type'] = 'promotion';
                $raspiData['piece_removed'] = 'pawn';
                $raspiData['piece_placed'] = $promotionPiece ?? 'queen';
                $raspiData['color'] = $nextPlayer === 'white' ? 'black' : 'white';
                $raspiData['instructions'] = [
                    'step1' => "Usuń " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " pionka z " . $from,
                    'step2' => "Umieść " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " " . ($promotionPiece ?? 'hetmana') . " na " . $to,
                    'step3' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Promocja zakończona"
                ];
            } elseif ($specialMove === 'promotion_capture') {
                $raspiData['type'] = 'promotion_capture';
                $raspiData['piece_removed'] = 'pawn';
                $raspiData['piece_placed'] = $promotionPiece ?? 'queen';
                $raspiData['piece_captured'] = $capturedPiece ?? 'unknown';
                $raspiData['capture'] = true;
                $raspiData['color'] = $nextPlayer === 'white' ? 'black' : 'white';
                $raspiData['instructions'] = [
                    'step1' => "Usuń " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " pionka z " . $from,
                    'step2' => "Usuń zbitą figurę (" . ($capturedPiece ?? 'nieznana') . ") z " . $to,
                    'step3' => "Umieść " . ($nextPlayer === 'white' ? 'czarnego' : 'białego') . " " . ($promotionPiece ?? 'hetmana') . " na " . $to,
                    'step4' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Promocja z biciem zakończona"
                ];
            } elseif ($capturedPiece && !$specialMove) {
                // Zwykłe bicie (nie promocja)
                $raspiData['type'] = 'capture';
                $raspiData['piece_captured'] = $capturedPiece;
                $raspiData['capture'] = true;
                $raspiData['color_moved'] = $nextPlayer === 'white' ? 'black' : 'white'; // Kolor który wykonał ruch
                $raspiData['color_captured'] = $nextPlayer === 'white' ? 'white' : 'black'; // Kolor zbitej figury
                $raspiData['instructions'] = [
                    'step1' => "Usuń zbitą figurę (" . $capturedPiece . ") z " . $to,
                    'step2' => "Przenieś figurę z " . $from . " na " . $to,
                    'step3' => $givesCheck ? "Figura daje szach przeciwnemu królowi" : "Bicie zakończone"
                ];
            }

            if ($notation) {
                $raspiData['notation'] = $notation;
            }

            if ($givesCheck) {
                $raspiData['gives_check'] = true;
            }

            // Powiadom RasPi o potwierdzonym ruchu
            $this->mqtt->publish('move/raspi', $raspiData);
        }

        // 3) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Przygotuj powiadomienie do UI
        $notificationData = [
            'type' => 'move_confirmed',
            'move' => [
                'from' => $from,
                'to' => $to
            ],
            'physical' => $physical,
            'state' => $state
        ];

        // Dodaj specjalne dane do powiadomienia
        if ($specialMove) {
            $notificationData['move']['special_move'] = $specialMove;
        }
        if ($notation) {
            $notificationData['move']['notation'] = $notation;
        }
        if ($promotionPiece) {
            $notificationData['move']['promotion_piece'] = $promotionPiece;
        }
        if ($givesCheck) {
            $notificationData['move']['gives_check'] = $givesCheck;
        }
        if ($additionalMoves) {
            $notificationData['move']['additional_moves'] = $additionalMoves;
        }

        // Sprawdź koniec gry
        if ($gameStatus && in_array($gameStatus, ['checkmate', 'stalemate', 'draw'])) {
            // Wyślij osobne powiadomienie o końcu gry
            $this->notifier->broadcast([
                'type' => 'game_over',
                'result' => $gameStatus,
                'winner' => $winner,
                'final_position' => $newFen,
                'moves_count' => count($state['moves'])
            ]);
        }

        // 5) Powiadomienie do UI o potwierdzonym ruchu
        $this->notifier->broadcast($notificationData);

        // 6) Jeśli następny gracz to czarny (AI), wyślij żądanie ruchu
        if ($nextPlayer === 'black') {
            // Użyj wewnętrznego tematu do kontroli przepływu
            $this->mqtt->publish('internal/request_ai_move', [
                'fen' => $newFen,
                'type' => 'request_ai_move'
            ]);
        }
    }

    /**
     * Obsługuje odrzucenie ruchu przez silnik szachowy.
     * 
     * @param string $from Pole źródłowe ruchu
     * @param string $to Pole docelowe ruchu
     * @param string $reason Powód odrzucenia
     * @param bool $physical Czy ruch był fizyczny
     * @return void
     */
    public function rejectMoveFromEngine(string $from, string $to, string $reason, bool $physical = false): void
    {
        // 1) Odrzuć ruch w stanie gry
        $this->state->rejectMove($from, $to, $reason);

        // 2) Jeśli ruch był fizyczny, powiadom RPI o konieczności cofnięcia ruchu (z FEN)
        if ($physical) {
            $fen = $this->state->getState()['fen'];
            $this->mqtt->publish('move/raspi/rejected', [
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
                'action' => 'revert_move', // RPI powinno przywrócić pionek na $from
                'fen' => $fen
            ]);
        }

        // 3) Powiadomienie do UI o odrzuceniu ruchu
        $this->notifier->broadcast([
            'type' => 'move_rejected',
            'move' => compact('from', 'to'),
            'reason' => $reason,
            'physical' => $physical,
            'state' => $this->state->getState()
        ]);
    }

    /**
     * Resetuje grę do stanu początkowego.
     * 
     * Metoda wykonuje pełny reset gry szachowej, przywracając wszystkie komponenty
     * do stanu początkowego. Koordynuje reset między magazynem stanu, zewnętrznymi
     * komponentami (Raspberry Pi, silnik) i interfejsem użytkownika.
     * 
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT lub resetowania stanu
     */
    public function resetGame(): void
    {
        // 1) Reset stanu
        $this->state->reset();

        // 2) Sygnał restartu do zewnętrznych komponentów (RasPi i silnik)
        // POPRAWKA: używamy innego kanału niż ten, którego nasłuchuje MqttListener
        $fen = $this->state->getState()['fen'];
        error_log("[RESET] Wysyłam sygnał resetu do silnika C++: topic=control/restart/external, fen=$fen");
        $this->mqtt->publish('control/restart/external', [
            'fen' => $fen,
            'command' => 'reset_board'
        ]);

        // 3) Powiadomienie do frontendu o resecie (PRZED state/update!)
        $state = $this->state->getState();
        $this->notifier->broadcast([
            'type' => 'game_reset',
            'state' => $state
        ]);

        // 4) Stan będzie opublikowany przez MqttListenCommand po otrzymaniu potwierdzenia od silnika
        error_log("[RESET] Czekam na potwierdzenie resetu od silnika przed publikacją state/update");
    }
}