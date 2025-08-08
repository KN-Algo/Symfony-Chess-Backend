<?php

namespace App\Controller;

use App\Service\HealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Kontroler monitorowania stanu zdrowia systemu szachowego.
 * 
 * HealthController zapewnia interfejs do sprawdzania dostępności i wydajności
 * wszystkich komponentów systemu szachowego. Oferuje zarówno interfejs webowy
 * dla administratorów jak i API dla automatycznego monitoringu.
 * 
 * Endpointy:
 * - GET /health: dashboard webowy ze statusem komponentów
 * - GET /api/health: API JSON z danymi diagnostycznymi
 * 
 * @author Adrian Goral <adrian@example.com>
 * @since 1.0.0
 */
class HealthController extends AbstractController
{
    /**
     * @param HealthCheckService $healthCheckService Serwis sprawdzania stanu zdrowia komponentów
     */
    public function __construct(
        private HealthCheckService $healthCheckService
    ) {}

    /**
     * Wyświetla dashboard webowy ze statusem systemu.
     * 
     * Renderuje interaktywną stronę HTML z wizualnym przedstawieniem stanu
     * wszystkich komponentów systemu szachowego. Dashboard automatycznie
     * odświeża się co 60 sekund i umożliwia ręczne odświeżanie.
     * 
     * @return Response Strona HTML z dashboardem monitoringu
     */
    #[Route('/health', methods: ['GET'])]
    public function health(): Response
    {
        return $this->render('health/index.html.twig', [
            'title' => 'System Health Status'
        ]);
    }

    /**
     * API endpoint zwracający dane diagnostyczne systemu.
     * 
     * Przeprowadza kompleksowe sprawdzenie stanu wszystkich komponentów
     * i zwraca szczegółowe informacje w formacie JSON. Używane przez dashboard
     * webowy oraz systemy automatycznego monitoringu.
     * 
     * Format odpowiedzi:
     * ```json
     * {
     *   "mqtt": {"status": "healthy", "response_time": "15ms", ...},
     *   "mercure": {"status": "healthy", "response_time": "8ms", ...},
     *   "raspberry": {"status": "warning", "note": "placeholder", ...},
     *   "chess_engine": {"status": "warning", "note": "placeholder", ...},
     *   "overall_status": "warning",
     *   "timestamp": "2025-01-24T10:30:45+00:00",
     *   "total_time": "125ms"
     * }
     * ```
     * 
     * @return Response Odpowiedź JSON z danymi diagnostycznymi
     */
    #[Route('/api/health', methods: ['GET'])]
    public function healthApi(): Response
    {
        $healthData = $this->healthCheckService->getSystemHealth();

        return $this->json($healthData);
    }
}
