<?php
namespace App\Service;

class GameService
{
    public function __construct(
        private StateStorage     $state,
        private MqttService      $mqtt,
        private NotifierService  $notifier
    ) {}

    public function playerMove(string $from, string $to, bool $physical): void
    {
        $this->state->applyMove($from, $to);

        if (!$physical) {
            $this->mqtt->publish('move/web', compact('from','to'));
        }

        $this->mqtt->publish('move/engine', compact('from','to'));
        $this->notifier->broadcast($this->state->getState());
    }

    public function aiMove(string $from, string $to): void
    {
        $this->state->applyMove($from, $to);
        $this->mqtt->publish('move/raspi', compact('from','to'));
        $this->notifier->broadcast($this->state->getState());
    }

    public function resetGame(): void
    {
        $this->state->reset();
        $this->mqtt->publish('control/restart', null);
        $this->notifier->broadcast($this->state->getState());
    }
}
