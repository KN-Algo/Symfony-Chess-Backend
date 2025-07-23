<?php
namespace App\Service;

class StateStorage
{
    private string $fen;
    private array $moves;

    public function __construct()
    {
        $this->reset();
    }

    public function getState(): array
    {
        return [
            'fen'   => $this->fen,
            'moves' => $this->moves,
        ];
    }

    public function applyMove(string $from, string $to): void
    {
        // TODO: tu później zintegrować bibliotekę szachową
        $this->moves[] = ['from' => $from, 'to' => $to];
    }

    public function reset(): void
    {
        $this->fen   = 'startpos';
        $this->moves = [];
    }
}
