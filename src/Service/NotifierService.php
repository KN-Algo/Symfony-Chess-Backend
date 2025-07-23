<?php
namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class NotifierService
{
    public function __construct(private HubInterface $hub) {}

    public function broadcast(array $data): void
    {
        $update = new Update(
            'https://127.0.0.1:8000/chess/updates',
            json_encode($data)
        );
        $this->hub->publish($update);
    }
}
