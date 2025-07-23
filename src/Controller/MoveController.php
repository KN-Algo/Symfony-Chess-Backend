<?php
namespace App\Controller;

use App\Service\GameService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MoveController extends AbstractController
{
    public function __construct(private GameService $game) {}

    #[Route('/move', methods:['POST'])]
    public function move(Request $req): Response
    {
        $d = json_decode($req->getContent(), true);
        $this->game->playerMove($d['from'], $d['to'], false);
        return $this->json(['status'=>'ok']);
    }

    #[Route('/restart', methods:['POST'])]
    public function restart(): Response
    {
        $this->game->resetGame();
        return $this->json(['status'=>'reset']);
    }
}
