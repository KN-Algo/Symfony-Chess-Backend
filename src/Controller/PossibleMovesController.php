<?php
namespace App\Controller;

use App\Service\MqttService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Kontroler REST API do żądań możliwych ruchów dla pionka.
 * 
 * PossibleMovesController zapewnia interfejs HTTP dla aplikacji webowej do żądania
 * możliwych ruchów dla wybranego pionka. Kontroler publikuje żądania na MQTT,
 * gdzie są przekazywane do silnika szachowego przez MqttListenCommand.
 * 
 * Odpowiedź z silnika jest przekazywana z powrotem do aplikacji webowej przez
 * WebSocket (Mercure) w czasie rzeczywistym.
 * 
 * Endpointy:
 * - POST /possible-moves: żądanie możliwych ruchów dla pozycji pionka
 */
class PossibleMovesController extends AbstractController
{
    /**
     * @param MqttService $mqtt Serwis MQTT do publikacji żądań możliwych ruchów
     */
    public function __construct(private MqttService $mqtt) {}

    /**
     * Żąda możliwych ruchów dla pionka na określonej pozycji.
     * 
     * Endpoint przyjmuje pozycję pionka w formacie JSON i publikuje żądanie na kanale
     * MQTT move/possible_moves/request, gdzie zostanie przetworzony przez MqttListenCommand
     * i przekazany do silnika szachowego. Odpowiedź od silnika zostanie automatycznie
     * przesłana do aplikacji webowej przez WebSocket.
     * 
     * Format żądania:
     * ```json
     * {
     *   "position": "e2"
     * }
     * ```
     * 
     * @param Request $req Żądanie HTTP zawierające pozycję pionka
     * @return Response Odpowiedź JSON z potwierdzeniem lub błędem
     * 
     * @throws \Exception W przypadku błędu publikacji MQTT
     */
    #[Route('/possible-moves', methods: ['POST'])]
    public function possibleMoves(Request $req): Response
    {
        $content = $req->getContent();
        
        if (empty($content)) {
            return $this->json(['error' => 'Empty request body'], 400);
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }
        
        if (!isset($data['position'])) {
            return $this->json(['error' => 'Missing position field'], 400);
        }
        
        // Walidacja formatu pozycji (podstawowa - a1-h8)
        if (!preg_match('/^[a-h][1-8]$/', $data['position'])) {
            return $this->json(['error' => 'Invalid position format, expected format like "e2"'], 400);
        }
        
        try {
            // Opublikuj żądanie możliwych ruchów na MQTT - MqttListenCommand przekaże to do silnika
            $this->mqtt->publish('move/possible_moves/request', [
                'position' => $data['position']
            ]);
            
            return $this->json(['status' => 'request_sent']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
