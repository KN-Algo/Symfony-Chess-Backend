<?php
namespace App\Controller;

use App\Service\StateStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GameController extends AbstractController
{
    public function __construct(private StateStorage $state) {}

    #[Route('/state', methods:['GET'])]
    public function state(): Response
    {
        return $this->json($this->state->getState());
    }

    #[Route('/log', methods:['GET'])]
    public function log(): Response
    {
        $moves = $this->state->getState()['moves'];
        return $this->json(['moves' => $moves]);
    }
}
