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
    
    /** @var array<array{from: string, to: string}> Historia wszystkich potwierdzonych ruchów */
    private array $moves;
    
    /** @var array<array{from: string, to: string}> Ruchy oczekujące na potwierdzenie przez silnik */
    private array $pendingMoves;
    
    /** @var string Aktualny gracz ('white' lub 'black') */
    private string $currentPlayer;

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
     * @return array{fen: string, moves: array<array{from: string, to: string}>, turn: string, pending_moves: array<array{from: string, to: string}>} Aktualny stan gry
     */
    public function getState(): array
    {
        return [
            'fen' => $this->fen,
            'moves' => $this->moves,
            'turn' => $this->currentPlayer,
            'pending_moves' => $this->pendingMoves,
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
     * @return void
     */
    public function addPendingMove(string $from, string $to): void
    {
        $this->pendingMoves[] = ['from' => $from, 'to' => $to];
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
     * @return void
     */
    public function confirmMove(string $from, string $to, string $newFen, string $nextPlayer): void
    {
        // Dodaj ruch do potwierdzonej historii
        $this->moves[] = ['from' => $from, 'to' => $to];
        
        // Aktualizuj stan na podstawie odpowiedzi silnika
        $this->fen = $newFen;
        $this->currentPlayer = $nextPlayer;
        
        // Usuń ruch z listy oczekujących
        $this->pendingMoves = array_filter(
            $this->pendingMoves, 
            fn($move) => !($move['from'] === $from && $move['to'] === $to)
        );
        $this->pendingMoves = array_values($this->pendingMoves); // Reindeksuj
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
    }
}
