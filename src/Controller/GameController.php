<?php
namespace App\Controller;

use App\Service\StateStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Kontroler REST API do pobierania stanu gry szachowej.
 * 
 * GameController zapewnia endpointy tylko do odczytu, umożliwiające aplikacji webowej
 * pobranie aktualnego stanu planszy i historii ruchów. Używane głównie przy inicjalizacji
 * aplikacji webowej lub odświeżeniu strony.
 * 
 * Endpointy:
 * - GET /state: aktualny stan planszy (pozycje figur)
 * - GET /log: historia wszystkich wykonanych ruchów
 *
 */
class GameController extends AbstractController
{
    /**
     * @param StateStorage $state Magazyn stanu gry do odczytu danych
     */
    public function __construct(private StateStorage $state) {}

    /**
     * Pobiera aktualny stan planszy szachowej.
     * 
     * Zwraca kompletny stan gry zawierający pozycje figur (w notacji FEN)
     * oraz pełną historię ruchów. Używane przez aplikację webową do
     * inicjalizacji lub synchronizacji stanu planszy.
     * 
     * Format odpowiedzi:
     * ```json
     * {
     *   "fen": "startpos",
     *   "moves": [
     *     {"from": "e2", "to": "e4"},
     *     {"from": "e7", "to": "e5"}
     *   ]
     * }
     * ```
     * 
     * @return Response Odpowiedź JSON z aktualnym stanem gry
     */
    #[Route('/state', methods:['GET'])]
    public function state(): Response
    {
        return $this->json($this->state->getState());
    }

    /**
     * Pobiera historię ruchów w bieżącej grze.
     * 
     * Zwraca listę wszystkich wykonanych ruchów od początku gry w kolejności
     * chronologicznej. Używane do wyświetlania notacji partii w aplikacji webowej.
     * 
     * Format odpowiedzi:
     * ```json
     * {
     *   "moves": [
     *     {"from": "e2", "to": "e4"},
     *     {"from": "e7", "to": "e5"}
     *   ]
     * }
     * ```
     * 
     * @return Response Odpowiedź JSON z historią ruchów
     */
    #[Route('/log', methods:['GET'])]
    public function log(): Response
    {
        $moves = $this->state->getState()['moves'];
        return $this->json(['moves' => $moves]);
    }
}
