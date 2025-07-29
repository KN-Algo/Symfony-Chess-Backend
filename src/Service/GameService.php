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
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT
     */
    public function playerMove(string $from, string $to, bool $physical): void
    {
        // 1) Dodaj ruch jako oczekujący na potwierdzenie
        $this->state->addPendingMove($from, $to);

        // 2) Wyślij do silnika w celu walidacji i otrzymania nowego FEN
        // UWAGA: RPi otrzyma polecenie ruchu dopiero po potwierdzeniu przez silnik
        $this->mqtt->publish('move/engine', [
            'from' => $from,
            'to' => $to,
            'current_fen' => $this->state->getState()['fen'],
            'type' => 'move_validation',
            'physical' => $physical
        ]);

        // 3) Powiadomienie do UI o ruchu oczekującym na potwierdzenie
        $this->notifier->broadcast([
            'type' => 'move_pending',
            'move' => compact('from', 'to'),
            'physical' => $physical,
            'state' => $this->state->getState()
        ]);
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
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT
     */
    public function aiMove(string $from, string $to, string $newFen, string $nextPlayer): void
    {
        // 1) Potwierdź ruch AI w stanie gry
        $this->state->confirmMove($from, $to, $newFen, $nextPlayer);

        // 2) Powiadom RasPi o ruchu AI
        $this->mqtt->publish('move/raspi', compact('from','to'));

        // 3) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Powiadomienie do UI o ruchu AI
        $this->notifier->broadcast([
            'type' => 'ai_move_executed',
            'move' => compact('from', 'to'),
            'state' => $state
        ]);
    }

    /**
     * Obsługuje potwierdzenie ruchu przez silnik szachowy.
     * 
     * @param string $from Pole źródłowe ruchu
     * @param string $to Pole docelowe ruchu
     * @param string $newFen Nowy stan planszy po ruchu
     * @param string $nextPlayer Następny gracz
     * @param bool $physical Czy ruch był fizyczny
     * @return void
     */
    public function confirmMoveFromEngine(string $from, string $to, string $newFen, string $nextPlayer, bool $physical = false): void
    {
        // 1) Potwierdź ruch w stanie gry
        $this->state->confirmMove($from, $to, $newFen, $nextPlayer);

        // 2) Jeśli ruch z UI (nie fizyczny), teraz można powiadomić RPi o wykonaniu
        if (!$physical) {
            $this->mqtt->publish('move/raspi', compact('from','to'));
        }

        // 3) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Powiadomienie do UI o potwierdzeniu ruchu
        $this->notifier->broadcast([
            'type' => 'move_confirmed',
            'move' => compact('from', 'to'),
            'physical' => $physical,
            'state' => $state
        ]);
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

        // 2) Powiadomienie do UI o odrzuceniu ruchu
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

        // 2) Sygnał restartu do RasPi i silnika
        $this->mqtt->publish('control/restart', null);

        // 3) Publikacja pełnego, zresetowanego stanu i czystego logu
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Powiadomienie do frontendu o resecie
        $this->notifier->broadcast([
            'type' => 'game_reset',
            'state' => $state
        ]);
    }
}
