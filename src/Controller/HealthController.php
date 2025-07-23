<?php
namespace App\Controller;

use App\Service\HealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private HealthCheckService $healthCheckService
    ) {}

    #[Route('/health', methods: ['GET'])]
    public function health(): Response
    {
        return $this->render('health/index.html.twig', [
            'title' => 'System Health Status'
        ]);
    }

    #[Route('/api/health', methods: ['GET'])]
    public function healthApi(): Response
    {
        $healthData = $this->healthCheckService->getSystemHealth();
        
        return $this->json($healthData);
    }
}
