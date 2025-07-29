<?php
namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Serwis powiadomień real-time dla aplikacji webowej.
 * 
 * NotifierService zapewnia komunikację WebSocket z frontendem poprzez protokół Mercure.
 * Umożliwia wysyłanie aktualizacji stanu gry, powiadomień o ruchach oraz statusów
 * komponentów systemu do wszystkich podłączonych klientów webowych.
 * 
 * Mercure jest protokołem Server-Sent Events (SSE) zapewniającym real-time communication
 * między serwerem a przeglądarkami bez potrzeby implementacji pełnego WebSocket.
 */
class NotifierService
{
    /**
     * @param HubInterface $hub Hub Mercure do publikacji aktualizacji
     */
    public function __construct(private HubInterface $hub) {}

    /**
     * Wysyła dane do wszystkich podłączonych klientów webowych.
     * 
     * Metoda publikuje dane jako aktualizację Mercure, która zostanie automatycznie
     * dostarczona do wszystkich przeglądarek subskrybujących kanał aktualizacji szachów.
     * 
     * Typowe zastosowania:
     * - Aktualizacje stanu planszy po ruchu
     * - Powiadomienia o ruchach AI
     * - Statusy komponentów (Raspberry Pi, silnik szachowy)
     * - Komunikaty o błędach lub zakończeniu gry
     * 
     * @param array $data Dane do wysłania (zostaną zserializowane do JSON)
     * @return void
     * @throws \Exception W przypadku błędu publikacji przez hub Mercure
     */
    public function broadcast(array $data): void
    {
        $update = new Update(
            'https://127.0.0.1:8000/chess/updates',
            json_encode($data)
        );
        $this->hub->publish($update);
    }
}
