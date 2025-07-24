<?php
namespace App\Controller;

use App\Service\GameService;
use App\Service\MqttService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kontroler REST API do obsługi ruchów w grze szachowej.
 * 
 * MoveController zapewnia interfejs HTTP dla aplikacji webowej do wykonywania
 * ruchów i resetowania gry. Kontroler publikuje ruchy na MQTT, gdzie są
 * przetwarzane przez MqttListenCommand.
 * 
 * Endpointy:
 * - POST /move: wykonanie ruchu gracza z aplikacji webowej
 * - POST /restart: reset gry do stanu początkowego
 *
 */
class MoveController extends AbstractController
{
    /**
     * @param GameService $game Serwis gry (używany tylko dla restartu)
     * @param MqttService $mqtt Serwis MQTT do publikacji ruchów z UI
     */
    public function __construct(
        private GameService $game,
        private MqttService $mqtt
    ) {}

    /**
     * Wykonuje ruch gracza z aplikacji webowej.
     * 
     * Endpoint przyjmuje ruch w formacie JSON i publikuje go na kanale MQTT move/web,
     * gdzie zostanie przetworzony przez MqttListenCommand. Ta architektura zapewnia
     * spójne przetwarzanie wszystkich ruchów (zarówno z UI jak i fizycznych).
     * 
     * Format żądania:
     * ```json
     * {
     *   "from": "e2",
     *   "to": "e4"
     * }
     * ```
     * 
     * @param Request $req Żądanie HTTP zawierające dane ruchu
     * @return Response Odpowiedź JSON z potwierdzeniem lub błędem
     * 
     * @throws \Exception W przypadku błędu publikacji MQTT
     */
    #[Route('/move', methods: ['POST'])]
    public function move(Request $req): Response
    {
        $content = $req->getContent();
        
        if (empty($content)) {
            return $this->json(['error' => 'Empty request body'], 400);
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }
        
        if (!isset($data['from']) || !isset($data['to'])) {
            return $this->json(['error' => 'Missing from/to fields'], 400);
        }
        
        try {
            // Opublikuj ruch z UI na MQTT - MqttListenCommand go przetworzy
            $this->mqtt->publish('move/web', [
                'from' => $data['from'],
                'to' => $data['to']
            ]);
            
            return $this->json(['status' => 'ok']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resetuje grę do stanu początkowego.
     * 
     * Endpoint wywołuje reset gry, który czyści stan planszy i historię ruchów,
     * oraz wysyła sygnały restartu do wszystkich komponentów systemu przez MQTT.
     * 
     * @return Response Odpowiedź JSON z potwierdzeniem restartu lub błędem
     * 
     * @throws \Exception W przypadku błędu resetowania gry
     */
    #[Route('/restart', methods: ['POST'])]
    public function restart(): Response
    {
        try {
            $this->game->resetGame();
            return $this->json(['status' => 'reset']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}