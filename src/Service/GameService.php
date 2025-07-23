<?php
namespace App\Service;

class GameService
{
    public function __construct(
        private StateStorage    $state,
        private MqttService     $mqtt,
        private NotifierService $notifier
    ) {}

    public function playerMove(string $from, string $to, bool $physical): void
    {
        // 1) Zaktualizuj stan
        $this->state->applyMove($from, $to);

        // 2) Jeśli ruch z frontu, powiadom RasPi
        if (!$physical) {
            $this->mqtt->publish('move/web', compact('from','to'));
        }

        // 3) Wyślij do silnika
        $this->mqtt->publish('move/engine', compact('from','to'));

        // 4) Publikacja pełnego stanu i logu ruchów
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 5) Notyfikacja do Mercure (front)
        $this->notifier->broadcast($state);
    }

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

        // 4) Notyfikacja do Mercure
        $this->notifier->broadcast($state);
    }

    public function resetGame(): void
    {
        // 1) Reset stanu
        $this->state->reset();

        // 2) Sygnał restartu do RasPi
        $this->mqtt->publish('control/restart', null);

        // 3) Publikacja pełnego, zresetowanego stanu i czystego logu
        $state = $this->state->getState();
        $this->mqtt->publish('state/update', $state);
        $this->mqtt->publish('log/update', ['moves' => $state['moves']]);

        // 4) Notyfikacja do frontu
        $this->notifier->broadcast($state);
    }
}
