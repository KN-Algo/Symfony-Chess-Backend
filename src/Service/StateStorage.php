<?php

namespace App\Service;

/**
 * Magazyn stanu gry szachowej.
 * 
 * StateStorage przechowuje aktualny stan gry szachowej, w tym pozycje figur i historię ruchów.
 * Stan planszy (FEN) jest aktualizowany tylko po potwierdzeniu przez silnik szachowy,
 * który jest źródłem prawdy o legalności ruchów i aktualnym stanie gry.
 * 
 * Przepływ aktualizacji stanu:
 * 1. Gracz wykonuje ruch → ruch zostaje wysłany do silnika
 * 2. Silnik waliduje ruch i odpowiada z nowym FEN
 * 3. StateStorage aktualizuje stan na podstawie odpowiedzi silnika
 */
class StateStorage
{
    /** @var string Aktualny stan planszy w notacji FEN (aktualizowany przez silnik) */
    private string $fen;

    /** @var array Historia wszystkich potwierdzonych ruchów */
    private array $moves;

    /** @var array Ruchy oczekujące na potwierdzenie przez silnik */
    private array $pendingMoves;

    /** @var string Aktualny gracz ('white' lub 'black') */
    private string $currentPlayer;

    /** @var string|null Status gry ('playing', 'checkmate', 'stalemate', 'draw') */
    private ?string $gameStatus = null;

    /** @var string|null Zwycięzca gry ('white', 'black', lub null) */
    private ?string $winner = null;

    /** @var bool Czy gra się zakończyła */
    private bool $gameEnded = false;

    /** @var bool Czy obecny gracz jest w szachu */
    private bool $inCheck = false;

    /** @var string|null Gracz który jest w szachu */
    private ?string $checkPlayer = null;

    /**
     * Konstruktor magazynu stanu.
     * 
     * Inicjalizuje magazyn z pustym stanem i automatycznie wywołuje reset
     * do pozycji początkowej.
     */
    public function __construct()
    {
        $this->reset();
    }

    /**
     * Pobiera aktualny stan gry.
     * 
     * Zwraca kompletny stan gry zawierający pozycje figur oraz pełną historię ruchów.
     * FEN jest aktualizowany tylko po potwierdzeniu przez silnik szachowy.
     * 
     * @return array Aktualny stan gry
     */
    public function getState(): array
    {
        return [
            'fen' => $this->fen,
            'moves' => $this->moves,
            'turn' => $this->currentPlayer,
            'pending_moves' => $this->pendingMoves,
            'game_status' => $this->gameStatus,
            'winner' => $this->winner,
            'game_ended' => $this->gameEnded,
            'in_check' => $this->inCheck,
            'check_player' => $this->checkPlayer
        ];
    }

    /**
     * Dodaje ruch jako oczekujący na potwierdzenie przez silnik.
     * 
     * Ruch nie aktualizuje jeszcze stanu planszy - to nastąpi dopiero
     * po otrzymaniu potwierdzenia od silnika szachowego.
     * 
     * @param string $from Pole źródłowe w notacji szachowej (np. "e2")
     * @param string $to Pole docelowe w notacji szachowej (np. "e4")
     * @param array|null $metadata Dodatkowe metadane ruchu
     * @return void
     */
    public function addPendingMove(string $from, string $to, ?array $metadata = null): void
    {
        $moveData = ['from' => $from, 'to' => $to];

        if ($metadata) {
            $moveData = array_merge($moveData, $metadata);
        }

        $this->pendingMoves[] = $moveData;
    }

    /**
     * Potwierdza ruch na podstawie odpowiedzi od silnika szachowego.
     * 
     * Aktualizuje stan planszy (FEN), historię ruchów i aktualnego gracza
     * na podstawie danych otrzymanych od silnika, który jest źródłem prawdy.
     * 
     * @param string $from Pole źródłowe ruchu
     * @param string $to Pole docelowe ruchu  
     * @param string $newFen Nowy stan planszy w notacji FEN od silnika
     * @param string $nextPlayer Następny gracz ('white' lub 'black')
     * @param array|null $metadata Dodatkowe metadane ruchu
     * @return void
     */
    public function confirmMove(string $from, string $to, string $newFen, string $nextPlayer, ?array $metadata = null): void
    {
        // Znajdź ruch oczekujący
        $pendingMove = null;
        foreach ($this->pendingMoves as $index => $move) {
            if ($move['from'] === $from && $move['to'] === $to) {
                $pendingMove = $move;
                array_splice($this->pendingMoves, $index, 1);
                break;
            }
        }

        // Przygotuj dane ruchu
        $moveData = [
            'from' => $from,
            'to' => $to,
            'fen' => $newFen,
            'player' => $this->currentPlayer,
            'timestamp' => time()
        ];

        // Dodaj metadane jeśli zostały przekazane
        if ($metadata) {
            $moveData = array_merge($moveData, $metadata);
        }

        // Dodaj informacje z ruchu oczekującego jeśli istnieje
        if ($pendingMove) {
            // Zachowaj dane specjalne z ruchu oczekującego
            if (isset($pendingMove['special_move'])) {
                $moveData['special_move'] = $pendingMove['special_move'];
            }
            if (isset($pendingMove['promotion_piece'])) {
                $moveData['promotion_piece'] = $pendingMove['promotion_piece'];
            }
            if (isset($pendingMove['available_pieces'])) {
                $moveData['available_pieces'] = $pendingMove['available_pieces'];
            }
            if (isset($pendingMove['captured_piece'])) {
                $moveData['captured_piece'] = $pendingMove['captured_piece'];
            }
        }

        // Sprawdź czy ten ruch już istnieje w historii
        $moveExists = false;
        foreach ($this->moves as $existingMove) {
            if (
                $existingMove['from'] === $from &&
                $existingMove['to'] === $to &&
                $existingMove['fen'] === $newFen &&
                $existingMove['player'] === $this->currentPlayer
            ) {
                $moveExists = true;
                break;
            }
        }

        // Dodaj do historii ruchów tylko jeśli nie istnieje
        if (!$moveExists) {
            $this->moves[] = $moveData;
        }

        // Aktualizuj stan na podstawie odpowiedzi silnika
        $this->fen = $newFen;
        $this->currentPlayer = $nextPlayer;

        // Obsługa końca gry
        if (isset($metadata['game_status'])) {
            $this->gameStatus = $metadata['game_status'];

            if (isset($metadata['winner'])) {
                $this->winner = $metadata['winner'];
            }

            if (in_array($metadata['game_status'], ['checkmate', 'stalemate', 'draw'])) {
                $this->gameEnded = true;
            }
        }

        // Sprawdź czy gracz jest w szachu
        if (isset($metadata['gives_check']) && $metadata['gives_check']) {
            $this->inCheck = true;
            $this->checkPlayer = $nextPlayer;
        } else {
            $this->inCheck = false;
            $this->checkPlayer = null;
        }
    }

    /**
     * Odrzuca ruch jako nielegalny na podstawie odpowiedzi silnika.
     * 
     * @param string $from Pole źródłowe ruchu
     * @param string $to Pole docelowe ruchu
     * @param string $reason Powód odrzucenia ruchu
     * @return void
     */
    public function rejectMove(string $from, string $to, string $reason): void
    {
        // Usuń ruch z listy oczekujących
        $this->pendingMoves = array_filter(
            $this->pendingMoves,
            fn($move) => !($move['from'] === $from && $move['to'] === $to)
        );
        $this->pendingMoves = array_values($this->pendingMoves); // Reindeksuj

        // TODO: Opcjonalnie loguj powód odrzucenia
    }

    /**
     * Aktualizuje pełny stan gry na podstawie odpowiedzi silnika.
     * 
     * Używane gdy silnik przesyła kompletny stan (np. po restarcie).
     * 
     * @param string $fen Stan planszy w notacji FEN
     * @param array<array{from: string, to: string}> $moves Historia ruchów
     * @param string $currentPlayer Aktualny gracz
     * @return void
     */
    public function updateFromEngine(string $fen, array $moves, string $currentPlayer): void
    {
        $this->fen = $fen;
        $this->moves = $moves;
        $this->currentPlayer = $currentPlayer;
        $this->pendingMoves = []; // Wyczyść oczekujące ruchy
    }

    /**
     * Pobiera aktualnego gracza.
     * 
     * @return string 'white' lub 'black'
     */
    public function getCurrentPlayer(): string
    {
        return $this->currentPlayer;
    }

    /**
     * Sprawdza czy istnieją ruchy oczekujące na potwierdzenie.
     * 
     * @return bool True jeśli są oczekujące ruchy
     */
    public function hasPendingMoves(): bool
    {
        return !empty($this->pendingMoves);
    }

    /**
     * Resetuje stan gry do pozycji początkowej.
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->fen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
        $this->moves = [];
        $this->pendingMoves = [];
        $this->currentPlayer = 'white';
        $this->gameStatus = 'playing';
        $this->winner = null;
        $this->gameEnded = false;
        $this->inCheck = false;
        $this->checkPlayer = null;
    }
}
