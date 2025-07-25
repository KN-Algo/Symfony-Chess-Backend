<?php
namespace App\Command;

use App\Service\MqttService;
use App\Service\GameService;
use App\Service\NotifierService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/**
 * Komenda konsoli odpowiedzialna za nasłuchiwanie komunikatów MQTT i przekazywanie ich do GameService.
 * 
 * Ta komenda implementuje główną pętlę komunikacyjną systemu szachowego, nasłuchując na następujących kanałach MQTT:
 * - move/player: ruchy fizyczne z Raspberry Pi
 * - move/web: ruchy z aplikacji webowej
 * - move/ai: ruchy od silnika szachowego
 * - status/raspi: statusy Raspberry Pi (przekazywane do UI)
 * - status/engine: statusy silnika szachowego (przekazywane do UI)
 * - control/restart: sygnały restartu gry
 * 
 * Komenda przetwarza statusy komponentów i przekazuje je do aplikacji webowej przez WebSocket,
 * mapując różne formaty statusów na ustandaryzowane komunikaty dla UI.
 */
#[AsCommand(
    name: 'app:mqtt-listen',
    description: 'Nasłuchuje MQTT i przekazuje komunikaty do GameService'
)]
class MqttListenCommand extends Command
{
    /**
     * @param MqttService $mqtt Serwis MQTT do komunikacji z brokerem
     * @param GameService $game Serwis gry do przetwarzania ruchów
     * @param NotifierService $notifier Serwis powiadomień do komunikacji z UI
     * @param LoggerInterface|null $logger Logger do zapisywania logów (opcjonalny)
     */
    public function __construct(
        private MqttService $mqtt,
        private GameService $game,
        private NotifierService $notifier,
        private ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    /**
     * Konfiguruje komendę - ustawia opis i parametry.
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Nasłuchuje MQTT i przekazuje do GameService');
    }

    /**
     * Główna metoda wykonywania komendy - uruchamia pętlę nasłuchiwania MQTT.
     * 
     * Metoda ustanawia subskrypcje na wszystkie wymagane kanały MQTT i uruchamia
     * nieskończoną pętlę przetwarzania komunikatów. Obsługuje błędy połączenia
     * i zapewnia mechanizmy odzyskiwania po awariach.
     * 
     * @param InputInterface $input Interfejs wejściowy komendy
     * @param OutputInterface $output Interfejs wyjściowy komendy
     * @return int Kod wyjścia komendy (Command::SUCCESS lub Command::FAILURE)
     * @throws \Exception W przypadku krytycznych błędów MQTT
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('MQTT Chess Listener');
        $io->info('Starting MQTT listener for chess game...');

        try {
            // Subskrybuj ruchy fizycznej planszy z Raspberry Pi
            $this->mqtt->subscribe('move/player', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] � <fg=green>Physical move received:</> $msg");
                
                $this->logger?->info('MQTT: Physical move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'])) {
                    try {
                        // Ruch fizyczny - backend powiadamia UI i silnik
                        $this->game->playerMove($decoded['from'], $decoded['to'], true);
                        $io->text("    ✅ <fg=green>Physical move processed:</> {$decoded['from']} → {$decoded['to']}");
                        
                        $this->logger?->info('Game: Physical move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to process physical move: " . $e->getMessage());
                        $this->logger?->error('Game: Physical move failed', [
                            'from' => $decoded['from'] ?? 'unknown',
                            'to' => $decoded['to'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid physical move format");
                    $this->logger?->warning('MQTT: Invalid physical move format', ['message' => $msg]);
                }
            });

            // Subskrybuj ruchy z aplikacji webowej (publikowane przez backend gdy REST API jest wywołane)
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
                        // Ruch z aplikacji web - backend powiadamia Raspberry Pi i silnik
                        $this->game->playerMove($decoded['from'], $decoded['to'], false);
                        $io->text("    ✅ <fg=green>Web move processed:</> {$decoded['from']} → {$decoded['to']}");
                        
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
            
            // Subskrybuj ruchy AI z silnika szachowego
            $this->mqtt->subscribe('move/ai', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🤖 <fg=yellow>AI move received:</> $msg");
                
                $this->logger?->info('MQTT: AI move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'])) {
                    try {
                        $this->game->aiMove($decoded['from'], $decoded['to']);
                        $io->text("    ✅ <fg=green>AI move processed:</> {$decoded['from']} → {$decoded['to']}");
                        
                        $this->logger?->info('Game: AI move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to process AI move: " . $e->getMessage());
                        $this->logger?->error('Game: AI move failed', [
                            'from' => $decoded['from'] ?? 'unknown',
                            'to' => $decoded['to'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid AI move format");
                    $this->logger?->warning('MQTT: Invalid AI move format', ['message' => $msg]);
                }
            });

            // Subskrybuj aktualizacje statusu Raspberry Pi
            $this->mqtt->subscribe('status/raspi', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 📡 <fg=blue>RasPi status:</> $msg");
                
                $this->logger?->info('MQTT: Raspberry Pi status received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);
                
                // Przekaż status do UI przez WebSocket/Mercure
                try {
                    $processedStatus = $this->processStatusForUI($msg, 'raspberry_pi');
                    
                    $this->notifier->broadcast([
                        'type' => 'raspi_status',
                        'data' => $processedStatus,
                        'timestamp' => $timestamp
                    ]);
                    
                    $statusDisplay = $processedStatus['format'] === 'json' 
                        ? json_encode($processedStatus['status']) 
                        : $processedStatus['message'] ?? $msg;
                        
                    $io->text("    ✅ <fg=green>Status forwarded to UI:</> {$statusDisplay}");
                    $this->logger?->debug('MQTT: Raspberry Pi status forwarded to UI', ['processed_status' => $processedStatus]);
                } catch (\Exception $e) {
                    $io->error("    ❌ Failed to forward RasPi status to UI: " . $e->getMessage());
                    $this->logger?->error('MQTT: Failed to forward RasPi status', [
                        'status' => $msg,
                        'error' => $e->getMessage()
                    ]);
                }
            });

            // Subskrybuj aktualizacje statusu silnika szachowego
            $this->mqtt->subscribe('status/engine', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🧠 <fg=magenta>Engine status:</> $msg");
                
                $this->logger?->info('MQTT: Chess engine status received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);
                
                // Przekaż status do UI przez WebSocket/Mercure
                try {
                    $processedStatus = $this->processStatusForUI($msg, 'chess_engine');
                    
                    $this->notifier->broadcast([
                        'type' => 'engine_status',
                        'data' => $processedStatus,
                        'timestamp' => $timestamp
                    ]);
                    
                    $statusDisplay = $processedStatus['format'] === 'json' 
                        ? json_encode($processedStatus['status']) 
                        : $processedStatus['message'] ?? $msg;
                        
                    $io->text("    ✅ <fg=green>Status forwarded to UI:</> {$statusDisplay}");
                    $this->logger?->debug('MQTT: Chess engine status forwarded to UI', ['processed_status' => $processedStatus]);
                } catch (\Exception $e) {
                    $io->error("    ❌ Failed to forward engine status to UI: " . $e->getMessage());
                    $this->logger?->error('MQTT: Failed to forward engine status', [
                        'status' => $msg,
                        'error' => $e->getMessage()
                    ]);
                }
            });

            // Subskrybuj wiadomości kontroli restartu
            $this->mqtt->subscribe('control/restart', function($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🔄 <fg=red>Restart control received:</> $msg");
                
                $this->logger?->info('MQTT: Restart control received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                try {
                    $this->game->resetGame();
                    $io->text("    ✅ <fg=green>Game reset completed</>");
                    
                    $this->logger?->info('Game: Reset completed successfully');
                } catch (\Exception $e) {
                    $io->error("    ❌ Failed to reset game: " . $e->getMessage());
                    $this->logger?->error('Game: Reset failed', [
                        'error' => $e->getMessage()
                    ]);
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
            $io->comment('Subscribed to: move/player, move/web, move/ai, status/raspi, status/engine, control/restart, move/+');
            $io->comment('Listening for moves and status updates... Press Ctrl+C to stop');
            
            $this->logger?->info('MQTT Listener started successfully');
            
            $loopCount = 0;
            
            // Główna pętla z lepszą obsługą błędów
            while (true) {
                try {
                    $this->mqtt->loop();
                    $loopCount++;
                    
                    // Loguj sygnał życia co 1000 iteracji (mniej więcej co ~1.7 minuty)
                    if ($loopCount % 1000 === 0) {
                        $io->text("[" . date('H:i:s') . "] 💓 <fg=blue>Heartbeat - Loop #$loopCount</>");
                        $this->logger?->debug('MQTT: Heartbeat', ['loop_count' => $loopCount]);
                    }
                    
                    usleep(100000); // 100ms opóźnienie
                    
                } catch (\Exception $e) {
                    $io->error("MQTT loop error: " . $e->getMessage());
                    $this->logger?->error('MQTT: Loop error', [
                        'error' => $e->getMessage(),
                        'loop_count' => $loopCount
                    ]);
                    
                    // Poczekaj przed ponowną próbą
                    $io->comment('Waiting 5 seconds before retry...');
                    sleep(5);
                    
                    // Spróbuj się połączyć ponownie lub przerwij po zbyt wielu błędach
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

    /**
     * Przetwarza i mapuje status komponentu na format zrozumiały dla UI.
     * 
     * Funkcja interpretuje różne formaty statusów (string, JSON) i mapuje je
     * na ustandaryzowane formaty z dodatkowymi metadanymi dla aplikacji webowej.
     * 
     * @param string $rawStatus Surowy status otrzymany z MQTT
     * @param string $component Nazwa komponentu ('raspberry_pi' lub 'chess_engine')
     * @return array Przetworzony status z metadanymi
     */
    private function processStatusForUI(string $rawStatus, string $component): array
    {
        // Spróbuj zdekodować jako JSON
        $statusData = json_decode($rawStatus, true);
        
        if ($statusData && is_array($statusData)) {
            // Status w formacie JSON - zachowaj wszystkie dane
            return [
                'status' => $statusData,
                'format' => 'json',
                'component' => $component
            ];
        }
        
        // Status w formacie string - zmapuj na standardowe znaczenia
        $processedStatus = [
            'raw' => $rawStatus,
            'format' => 'string',
            'component' => $component
        ];
        
        // Mapowanie statusów według dokumentacji
        switch (strtolower(trim($rawStatus))) {
            case 'ready':
                $processedStatus['state'] = 'ready';
                $processedStatus['message'] = $component === 'raspberry_pi' 
                    ? 'Raspberry Pi is ready for commands'
                    : 'Chess engine is ready for moves';
                $processedStatus['severity'] = 'info';
                break;
                
            case 'moving':
                $processedStatus['state'] = 'busy';
                $processedStatus['message'] = 'Raspberry Pi is executing a physical move';
                $processedStatus['severity'] = 'info';
                break;
                
            case 'thinking':
                $processedStatus['state'] = 'busy';
                $processedStatus['message'] = 'Chess engine is calculating the next move';
                $processedStatus['severity'] = 'info';
                break;
                
            case 'error':
                $processedStatus['state'] = 'error';
                $processedStatus['message'] = $component === 'raspberry_pi'
                    ? 'Raspberry Pi encountered an error'
                    : 'Chess engine encountered an error';
                $processedStatus['severity'] = 'error';
                break;
                
            default:
                $processedStatus['state'] = 'unknown';
                $processedStatus['message'] = "Unknown status: {$rawStatus}";
                $processedStatus['severity'] = 'warning';
        }
        
        return $processedStatus;
    }
}