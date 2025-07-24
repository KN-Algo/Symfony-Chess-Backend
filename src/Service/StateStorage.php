<?php
namespace App\Service;

/**
 * Magazyn stanu gry szachowej.
 * 
 * StateStorage przechowuje aktualny stan gry szachowej, w tym pozycje figur i historię ruchów.
 * Zapewnia szybki dostęp do stanu gry dla REST API oraz synchronizację między różnymi
 * komponentami systemu (fizyczna szachownica, silnik AI, aplikacja webowa).
 * 
 * Aktualnie implementuje podstawową funkcjonalność z planem rozszerzenia o pełną
 * bibliotekę szachową w przyszłości.
 */
class StateStorage
{
    /** @var string Aktualny stan planszy w notacji FEN lub uproszczonej formie */
    private string $fen;
    
    /** @var array<array{from: string, to: string}> Historia wszystkich wykonanych ruchów */
    private array $moves;

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
     * Używane przez REST API oraz do synchronizacji stanu między komponentami.
     * 
     * @return array{fen: string, moves: array<array{from: string, to: string}>} Aktualny stan gry
     */
    public function getState(): array
    {
        return [
            'fen'   => $this->fen,
            'moves' => $this->moves,
        ];
    }

    /**
     * Aplikuje nowy ruch do stanu gry.
     * 
     * Dodaje nowy ruch do historii i aktualizuje stan planszy. W obecnej implementacji
     * tylko dodaje ruch do listy bez walidacji. W przyszłości zostanie rozszerzone
     * o pełną walidację ruchów przy użyciu biblioteki szachowej.
     * 
     * @param string $from Pole źródłowe w notacji szachowej (np. "e2")
     * @param string $to Pole docelowe w notacji szachowej (np. "e4")
     * @return void
     * @todo Dodać walidację legalności ruchu
     * @todo Aktualizować notację FEN na podstawie wykonanego ruchu
     */
    public function applyMove(string $from, string $to): void
    {
        // TODO: tu później zintegrować bibliotekę szachową dla walidacji
        $this->moves[] = ['from' => $from, 'to' => $to];
    }

    /**
     * Resetuje stan gry do pozycji początkowej.
     * 
     * Przywraca stan planszy do standardowej pozycji początkowej szachów
     * i czyści historię ruchów. Używane przy rozpoczynaniu nowej gry.
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->fen   = 'startpos';
        $this->moves = [];
    }
}
