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
     * Metoda obsługuje ruchy pochodzące zarówno z fizycznej szachownicy (Raspberry Pi)
     * jak i z aplikacji webowej. W zależności od źródła ruchu, odpowiednio zarządza
     * komunikacją z Raspberry Pi i silnikiem szachowym.
     * 
     * Przepływ dla ruchu fizycznego:
     * 1. Aktualizuje stan gry
     * 2. Wysyła ruch do silnika AI
     * 3. Publikuje stan na MQTT
     * 4. Powiadamia UI przez WebSocket
     * 
     * Przepływ dla ruchu z UI:
     * 1. Aktualizuje stan gry
     * 2. Wysyła polecenie wykonania fizycznego ruchu do Raspberry Pi
     * 3. Wysyła ruch do silnika AI
     * 4. Publikuje stan na MQTT
     * 5. Powiadamia UI przez WebSocket
     * 
     * @param string $from Pole źródłowe w notacji szachowej (np. "e2")
     * @param string $to Pole docelowe w notacji szachowej (np. "e4")
     * @param bool $physical Czy ruch został wykonany fizycznie na planszy (true) czy pochodzi z UI (false)
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT lub aktualizacji stanu
     */
    public function playerMove(string $from, string $to, bool $physical): void
    {
        // 1) Zaktualizuj stan
        $this->state->applyMove($from, $to);

        // 2) Jeśli ruch z UI (nie fizyczny), powiadom RasPi o potrzebie wykonania fizycznego ruchu
        if (!$physical) {
            $this->mqtt->publish('move/raspi', compact('from','to'));
        }

        // 3) Wyślij do silnika (zawsze)
        $this->mqtt->publish('move/engine', compact('from','to'));

        // 4) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 5) Powiadomienie do Mercure (frontend) - ZAWSZE powiadom UI o ruchu
        $this->notifier->broadcast([
            'type' => 'move_executed',
            'move' => compact('from', 'to'),
            'physical' => $physical,
            'state' => $state
        ]);
    }

    /**
     * Przetwarza ruch AI (silnika szachowego).
     * 
     * Metoda obsługuje ruchy generowane przez silnik szachowy w odpowiedzi na ruch gracza.
     * Aktualizuje stan gry i zarządza komunikacją z fizyczną szachownicą.
     * 
     * Przepływ:
     * 1. Aktualizuje stan gry z ruchem AI
     * 2. Wysyła polecenie wykonania fizycznego ruchu do Raspberry Pi
     * 3. Publikuje zaktualizowany stan na MQTT
     * 4. Powiadamia UI przez WebSocket o ruchu AI
     * 
     * @param string $from Pole źródłowe ruchu AI w notacji szachowej
     * @param string $to Pole docelowe ruchu AI w notacji szachowej
     * @return void
     * @throws \Exception W przypadku błędu komunikacji MQTT lub aktualizacji stanu
     */
    public function aiMove(string $from, string $to): void
    {
        // 1) Zaktualizuj stan
        $this->state->applyMove($from, $to);

        // 2) Powiadom RasPi o ruchu AI
        $this->mqtt->publish('move/raspi', compact('from','to'));

        // 3) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Powiadomienie do Mercure - informuj UI o ruchu AI
        $this->notifier->broadcast([
            'type' => 'ai_move_executed',
            'move' => compact('from', 'to'),
            'state' => $state
        ]);
    }

    /**
     * Resetuje grę do stanu początkowego.
     * 
     * Metoda wykonuje pełny reset gry szachowej, przywracając wszystkie komponenty
     * do stanu początkowego. Koordynuje reset między magazynem stanu, zewnętrznymi
     * komponentami (Raspberry Pi, silnik) i interfejsem użytkownika.
     * 
     * Przepływ:
     * 1. Resetuje wewnętrzny stan gry (pozycje figur, historia ruchów)
     * 2. Wysyła sygnał restartu do wszystkich komponentów przez MQTT
     * 3. Publikuje czysty stan gry na MQTT
     * 4. Powiadamia UI o restarcie przez WebSocket
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
