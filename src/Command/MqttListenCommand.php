<?php
namespace App\Command;

use App\Service\MqttService;
use App\Service\GameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:mqtt-listen',
    description: 'Nasłuchuje MQTT i przekazuje komunikaty do GameService'
)]
class MqttListenCommand extends Command
{
    public function __construct(
        private MqttService $mqtt,
        private GameService $game,
        private ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Nasłuchuje MQTT i przekazuje do GameService');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('MQTT Chess Listener');
        $io->info('Starting MQTT listener for chess game...');

        try {
            // Subscribe to web moves (zamiast move/player)
            $this->mqtt->subscribe('move/web', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🌐 <fg=cyan>Web move received:</> $msg");
                
                $this->logger?->info('MQTT: Web move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'])) {
                    try {
                        $this->game->playerMove($decoded['from'], $decoded['to'], true);
                        $io->text("    ✅ <fg=green>Move processed:</> {$decoded['from']} → {$decoded['to']}");
                        
                        $this->logger?->info('Game: Web move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to process web move: " . $e->getMessage());
                        $this->logger?->error('Game: Web move failed', [
                            'from' => $decoded['from'] ?? 'unknown',
                            'to' => $decoded['to'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid web move format");
                    $this->logger?->warning('MQTT: Invalid web move format', ['message' => $msg]);
                }
            });
            
            // Subscribe to engine moves (zamiast move/ai)
            $this->mqtt->subscribe('move/engine', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] ⚙️ <fg=yellow>Engine move received:</> $msg");
                
                $this->logger?->info('MQTT: Engine move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'])) {
                    try {
                        $this->game->aiMove($decoded['from'], $decoded['to']);
                        $io->text("    ✅ <fg=green>Engine move processed:</> {$decoded['from']} → {$decoded['to']}");
                        
                        $this->logger?->info('Game: Engine move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to process engine move: " . $e->getMessage());
                        $this->logger?->error('Game: Engine move failed', [
                            'from' => $decoded['from'] ?? 'unknown',
                            'to' => $decoded['to'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid engine move format");
                    $this->logger?->warning('MQTT: Invalid engine move format', ['message' => $msg]);
                }
            });

            // Dodatkowa subscription na wszystkie move topiki dla debugging
            $this->mqtt->subscribe('move/+', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 📨 <fg=magenta>DEBUG - Any move on {$topic}:</> $msg");
                
                $this->logger?->debug('MQTT: Debug - any move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);
            });

            $io->success('MQTT subscriptions established');
            $io->comment('Subscribed to: move/web, move/engine, move/+');
            $io->comment('Listening for moves... Press Ctrl+C to stop');
            
            $this->logger?->info('MQTT Listener started successfully');
            
            $loopCount = 0;
            
            // Main loop with better error handling
            while (true) {
                try {
                    $this->mqtt->loop();
                    $loopCount++;
                    
                    // Log heartbeat every 1000 iterations (roughly every ~1.7 minutes)
                    if ($loopCount % 1000 === 0) {
                        $io->text("[" . date('H:i:s') . "] 💓 <fg=blue>Heartbeat - Loop #$loopCount</>");
                        $this->logger?->debug('MQTT: Heartbeat', ['loop_count' => $loopCount]);
                    }
                    
                    usleep(100000); // 100ms delay
                    
                } catch (\Exception $e) {
                    $io->error("MQTT loop error: " . $e->getMessage());
                    $this->logger?->error('MQTT: Loop error', [
                        'error' => $e->getMessage(),
                        'loop_count' => $loopCount
                    ]);
                    
                    // Wait before retrying
                    $io->comment('Waiting 5 seconds before retry...');
                    sleep(5);
                    
                    // Try to reconnect or break if too many failures
                    throw $e;
                }
            }
            
        } catch (\Exception $e) {
            $io->error('MQTT Listener failed: ' . $e->getMessage());
            $this->logger?->critical('MQTT Listener crashed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}