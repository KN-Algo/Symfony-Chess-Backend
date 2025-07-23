<?php
namespace App\Controller;

use App\Service\GameService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MoveController extends AbstractController
{
    public function __construct(private GameService $game) {}

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
            $this->game->playerMove($data['from'], $data['to'], false);
            return $this->json(['status' => 'ok']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

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