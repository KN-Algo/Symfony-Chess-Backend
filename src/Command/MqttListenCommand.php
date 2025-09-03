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
 * - engine/move/confirmed: potwierdzenia legalnych ruchów od silnika z nowym FEN
 * - engine/move/rejected: odrzucenia nielegalnych ruchów od silnika z powodem
 * - move/possible_moves/request: żądania możliwych ruchów z aplikacji webowej
 * - engine/possible_moves/response: odpowiedzi z możliwymi ruchami od silnika szachowego
 * - status/raspi: statusy Raspberry Pi (przekazywane do UI)
 * - status/engine: statusy silnika szachowego (przekazywane do UI)
 * - control/restart: sygnały restartu gry
 * - move/+: debug - wszystkie komunikaty move/* dla diagnostyki
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
            $this->mqtt->subscribe('move/player', function ($topic, $msg) use ($io) {
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
                        // Obsługa dodatkowych parametrów dla specjalnych ruchów fizycznych
                        $specialMove = $decoded['special_move'] ?? null;
                        $promotionPiece = $decoded['promotion_piece'] ?? null;
                        $availablePieces = $decoded['available_pieces'] ?? null;
                        $capturedPiece = $decoded['captured_piece'] ?? null;

                        // Ruch fizyczny - backend powiadamia UI i silnik
                        $this->game->playerMove(
                            $decoded['from'],
                            $decoded['to'],
                            true,
                            $specialMove,
                            $promotionPiece,
                            $availablePieces,
                            $capturedPiece
                        );

                        $moveDesc = "{$decoded['from']} → {$decoded['to']}";
                        if ($specialMove) {
                            $moveDesc .= " ($specialMove)";
                        }
                        $io->text("    ✅ <fg=green>Physical move processed:</> $moveDesc");

                        $this->logger?->info('Game: Physical move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'special_move' => $specialMove,
                            'promotion_piece' => $promotionPiece,
                            'captured_piece' => $capturedPiece
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
            $this->mqtt->subscribe('move/web', function ($topic, $msg) use ($io) {
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
                        // Obsługa dodatkowych parametrów dla specjalnych ruchów z aplikacji webowej
                        $specialMove = $decoded['special_move'] ?? null;
                        $promotionPiece = $decoded['promotion_piece'] ?? null;
                        $availablePieces = $decoded['available_pieces'] ?? null;
                        $capturedPiece = $decoded['captured_piece'] ?? null;

                        // Ruch z aplikacji web - backend powiadamia Raspberry Pi i silnik
                        $this->game->playerMove(
                            $decoded['from'],
                            $decoded['to'],
                            false,
                            $specialMove,
                            $promotionPiece,
                            $availablePieces,
                            $capturedPiece
                        );

                        $moveDesc = "{$decoded['from']} → {$decoded['to']}";
                        if ($specialMove) {
                            $moveDesc .= " ($specialMove)";
                        }
                        $io->text("    ✅ <fg=green>Web move processed:</> $moveDesc");

                        $this->logger?->info('Game: Web move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'special_move' => $specialMove,
                            'promotion_piece' => $promotionPiece,
                            'captured_piece' => $capturedPiece
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
            $this->mqtt->subscribe('move/ai', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🤖 <fg=yellow>AI move received:</> $msg");

                $this->logger?->info('MQTT: AI move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'], $decoded['fen'], $decoded['next_player'])) {
                    try {
                        // Obsługa dodatkowych parametrów dla specjalnych ruchów AI
                        $specialMove = $decoded['special_move'] ?? null;
                        $additionalMoves = $decoded['additional_moves'] ?? null;
                        $promotionPiece = $decoded['promotion_piece'] ?? null;
                        $notation = $decoded['notation'] ?? null;
                        $givesCheck = $decoded['gives_check'] ?? false;
                        $gameStatus = $decoded['game_status'] ?? null;
                        $winner = $decoded['winner'] ?? null;
                        $capturedPiece = $decoded['captured_piece'] ?? null;

                        $this->game->aiMove(
                            $decoded['from'],
                            $decoded['to'],
                            $decoded['fen'],
                            $decoded['next_player'],
                            $specialMove,
                            $additionalMoves,
                            $promotionPiece,
                            $notation,
                            $givesCheck,
                            $gameStatus,
                            $winner,
                            $capturedPiece
                        );

                        $moveDesc = "{$decoded['from']} → {$decoded['to']}";
                        if ($specialMove) {
                            $moveDesc .= " ($specialMove)";
                        }
                        if ($givesCheck) {
                            $moveDesc .= " - SZACH!";
                        }
                        if ($gameStatus && in_array($gameStatus, ['checkmate', 'stalemate', 'draw'])) {
                            $moveDesc .= " - KONIEC GRY ($gameStatus)";
                        }

                        $io->text("    ✅ <fg=green>AI move processed:</> $moveDesc");

                        $this->logger?->info('Game: AI move processed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'fen' => $decoded['fen'],
                            'next_player' => $decoded['next_player'],
                            'special_move' => $specialMove,
                            'gives_check' => $givesCheck,
                            'game_status' => $gameStatus
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
                    $io->warning("    ⚠️  Invalid AI move format (expected: from, to, fen, next_player)");
                    $this->logger?->warning('MQTT: Invalid AI move format', ['message' => $msg]);
                }
            });

            // Subskrybuj potwierdzenia ruchów od silnika
            $this->mqtt->subscribe('engine/move/confirmed', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] ✅ <fg=green>Move confirmed by engine:</> $msg");

                $this->logger?->info('MQTT: Move confirmed by engine', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'], $decoded['fen'], $decoded['next_player'])) {
                    try {
                        // Obsługa dodatkowych parametrów dla specjalnych ruchów
                        $physical = $decoded['physical'] ?? false;
                        $specialMove = $decoded['special_move'] ?? null;
                        $additionalMoves = $decoded['additional_moves'] ?? null;
                        $promotionPiece = $decoded['promotion_piece'] ?? null;
                        $notation = $decoded['notation'] ?? null;
                        $givesCheck = $decoded['gives_check'] ?? false;
                        $gameStatus = $decoded['game_status'] ?? null;
                        $winner = $decoded['winner'] ?? null;
                        $capturedPiece = $decoded['captured_piece'] ?? null;

                        $this->game->confirmMoveFromEngine(
                            $decoded['from'],
                            $decoded['to'],
                            $decoded['fen'],
                            $decoded['next_player'],
                            $physical,
                            $specialMove,
                            $additionalMoves,
                            $promotionPiece,
                            $notation,
                            $givesCheck,
                            $gameStatus,
                            $winner,
                            $capturedPiece
                        );

                        $moveDesc = "{$decoded['from']} → {$decoded['to']}";
                        if ($specialMove) {
                            $moveDesc .= " ($specialMove)";
                        }
                        if ($givesCheck) {
                            $moveDesc .= " - SZACH!";
                        }
                        if ($gameStatus && in_array($gameStatus, ['checkmate', 'stalemate', 'draw'])) {
                            $moveDesc .= " - KONIEC GRY ($gameStatus)";
                        }

                        $io->text("    ✅ <fg=green>Move confirmed:</> $moveDesc");

                        $this->logger?->info('Game: Move confirmed successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'fen' => $decoded['fen'],
                            'special_move' => $specialMove,
                            'gives_check' => $givesCheck,
                            'game_status' => $gameStatus
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to confirm move: " . $e->getMessage());
                        $this->logger?->error('Game: Move confirmation failed', [
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid move confirmation format");
                    $this->logger?->warning('MQTT: Invalid move confirmation format', ['message' => $msg]);
                }
            });

            // Subskrybuj odrzucenia ruchów od silnika
            $this->mqtt->subscribe('engine/move/rejected', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] ❌ <fg=red>Move rejected by engine:</> $msg");

                $this->logger?->info('MQTT: Move rejected by engine', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['from'], $decoded['to'], $decoded['reason'])) {
                    try {
                        $this->game->rejectMoveFromEngine(
                            $decoded['from'],
                            $decoded['to'],
                            $decoded['reason'],
                            $decoded['physical'] ?? false
                        );
                        $io->text("    ❌ <fg=red>Move rejected:</> {$decoded['from']} → {$decoded['to']} ({$decoded['reason']})");

                        $this->logger?->info('Game: Move rejected successfully', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'reason' => $decoded['reason']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to reject move: " . $e->getMessage());
                        $this->logger?->error('Game: Move rejection failed', [
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️  Invalid move rejection format");
                    $this->logger?->warning('MQTT: Invalid move rejection format', ['message' => $msg]);
                }
            });

            // Subskrybuj aktualizacje statusu Raspberry Pi
            $this->mqtt->subscribe('status/raspi', function ($topic, $msg) use ($io) {
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
            $this->mqtt->subscribe('status/engine', function ($topic, $msg) use ($io) {
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
            $this->mqtt->subscribe('control/restart', function ($topic, $msg) use ($io) {
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

            // Subskrybuj żądania możliwych ruchów z aplikacji webowej
            $this->mqtt->subscribe('move/possible_moves/request', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🔍 <fg=cyan>Possible moves request:</> $msg");

                $this->logger?->info('MQTT: Possible moves request received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['position'])) {
                    try {
                        // Przekaż żądanie do silnika szachowego
                        $this->mqtt->publish('engine/possible_moves/request', [
                            'position' => $decoded['position']
                        ]);

                        $io->text("    ✅ <fg=green>Request forwarded to engine:</> {$decoded['position']}");

                        $this->logger?->info('MQTT: Possible moves request forwarded to engine', [
                            'position' => $decoded['position']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to forward request to engine: " . $e->getMessage());
                        $this->logger?->error('MQTT: Failed to forward possible moves request', [
                            'error' => $e->getMessage(),
                            'position' => $decoded['position'] ?? 'unknown'
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️ Invalid possible moves request format");
                    $this->logger?->warning('MQTT: Invalid possible moves request format', [
                        'message' => $msg
                    ]);
                }
            });

            // Subskrybuj odpowiedzi możliwych ruchów od silnika szachowego
            $this->mqtt->subscribe('engine/possible_moves/response', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 📋 <fg=cyan>Possible moves response from engine:</> $msg");

                $this->logger?->info('MQTT: Possible moves response received from engine', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['position'], $decoded['moves'])) {
                    try {
                        // DODAJ DODATKOWE LOGOWANIE
                        $broadcastData = [
                            'type' => 'possible_moves',
                            'position' => $decoded['position'],
                            'moves' => $decoded['moves']
                        ];

                        $io->text("    🔄 <fg=yellow>Broadcasting to UI:</> " . json_encode($broadcastData));
                        $this->logger?->info('MQTT: Broadcasting possible moves to UI', $broadcastData);

                        // Przekaż odpowiedź do aplikacji webowej przez WebSocket
                        $this->notifier->broadcast($broadcastData);

                        $movesCount = count($decoded['moves']);
                        $io->text("    ✅ <fg=green>Response sent to webapp:</> {$decoded['position']} → $movesCount moves");

                        $this->logger?->info('MQTT: Possible moves response sent to webapp', [
                            'position' => $decoded['position'],
                            'moves_count' => $movesCount,
                            'moves' => $decoded['moves']
                        ]);
                    } catch (\Exception $e) {
                        $io->error("    ❌ Failed to send response to webapp: " . $e->getMessage());
                        $this->logger?->error('MQTT: Failed to send possible moves response to webapp', [
                            'error' => $e->getMessage(),
                            'position' => $decoded['position'] ?? 'unknown'
                        ]);
                    }
                } else {
                    $io->warning("    ⚠️ Invalid possible moves response format from engine");
                    $this->logger?->warning('MQTT: Invalid possible moves response format from engine', [
                        'message' => $msg
                    ]);
                }
            });

            // Dodatkowa subscription na wszystkie move topiki dla debugging
            $this->mqtt->subscribe('move/+', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 📨 <fg=magenta>DEBUG - Any move on {$topic}:</> $msg");

                $this->logger?->debug('MQTT: Debug - any move received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);
            });

            $io->success('MQTT subscriptions established');
            $io->comment('Subscribed to: move/player, move/web, move/ai, engine/move/confirmed, engine/move/rejected, status/raspi, status/engine, control/restart, move/possible_moves/request, engine/possible_moves/response, move/+');
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

            case 'analyzing':
                $processedStatus['state'] = 'busy';
                $processedStatus['message'] = 'Chess engine is analyzing the position';
                $processedStatus['severity'] = 'info';
                break;

            default:
                $processedStatus['state'] = 'unknown';
                $processedStatus['message'] = "Unknown status: {$rawStatus}";
                $processedStatus['severity'] = 'warning';
        }

        return $processedStatus;
    }
}
