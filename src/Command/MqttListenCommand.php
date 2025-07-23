<?php
namespace App\Command;

use App\Service\MqttService;
use App\Service\GameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mqtt-listen',
    description: 'Nasłuchuje MQTT i przekazuje komunikaty do GameService'
)]
class MqttListenCommand extends Command
{
    protected static $defaultName = 'app:mqtt-listen';

    public function __construct(
        private MqttService $mqtt,
        private GameService $game
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Nasłuchuje MQTT i przekazuje do GameService');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $this->mqtt->subscribe('move/player', function($topic, $msg) {
            $decoded = json_decode($msg, true);
            $this->game->playerMove($decoded['from'], $decoded['to'], true);
        });
        $this->mqtt->subscribe('move/ai', function($topic, $msg) {
            $decoded = json_decode($msg, true);
            $this->game->aiMove($decoded['from'], $decoded['to']);
        });

        $out->writeln('[✓] MQTT listener started');
        $this->mqtt->loop();

        return Command::SUCCESS;
    }
}
