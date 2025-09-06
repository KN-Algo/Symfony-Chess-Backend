<?php

namespace App\Controller;

use App\Service\GameService;
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
 * - POST /reset: resetowanie gry do stanu początkowego
 *
 */
class GameController extends AbstractController
{
    /**
     * @param StateStorage $state Magazyn stanu gry do odczytu danych
     * @param GameService $gameService Serwis gry do operacji resetowania
     */
    public function __construct(
        private StateStorage $state,
        private GameService $gameService
    ) {}

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
    #[Route('/state', methods: ['GET'])]
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
    #[Route('/log', methods: ['GET'])]
    public function log(): Response
    {
        $moves = $this->state->getState()['moves'];
        return $this->json(['moves' => $moves]);
    }

    /**
     * Resetuje grę do stanu początkowego.
     * UJEDNOLICONY ENDPOINT: /restart
     * 
     * Wykonuje pełny reset gry szachowej, przywracając wszystkie komponenty
     * do stanu początkowego. Koordynuje reset między magazynem stanu,
     * zewnętrznymi komponentami (Raspberry Pi, silnik szachowy) i interfejsem użytkownika.
     * 
     * Format odpowiedzi:
     * ```json
     * {
     *   "success": true,
     *   "status": "reset",
     *   "message": "Game reset successfully",
     *   "state": {
     *     "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
     *     "moves": []
     *   }
     * }
     * ```
     * 
     * @return Response Odpowiedź JSON z potwierdzeniem resetu i nowym stanem
     */
    #[Route('/restart', methods: ['POST'])]
    public function restart(): Response
    {
        try {
            $this->gameService->resetGame();

            return $this->json([
                'success' => true,
                'status' => 'reset',
                'message' => 'Game reset successfully',
                'state' => $this->state->getState()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to reset game: ' . $e->getMessage(),
                'status' => 'error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
