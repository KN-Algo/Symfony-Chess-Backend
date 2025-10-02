<?php

namespace App\Command;

use App\Service\MqttService;
use App\Service\GameService;
use App\Service\NotifierService;
use App\Service\StateStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/**
 * Komenda konsoli odp                        // Wyślij żądanie ruchu do silnika na właściwym kanale
                        $this->mqtt->publish('move/engine/request', $this->pendingAiMoveRequest);iedzialna za nasłuchiwanie komunikatów MQTT i przekazywanie ich do GameService.
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
     * @param StateStorage $state Serwis przechowywania stanu gry
     * @param LoggerInterface|null $logger Logger do zapisywania logów (opcjonalny)
     */
    public function __construct(
        private MqttService $mqtt,
        private GameService $game,
        private NotifierService $notifier,
        private StateStorage $state,
        private ?LoggerInterface $logger = null,

        /** @var array Przechowuje informacje o już przetworzonych potwierdzeniach ruchów */
        private array $lastProcessedMoveHash = [],

        /** @var string Przechowuje aktualny status Raspberry Pi */
        private string $raspiStatus = 'unknown',

        /** @var array Przechowuje informacje o oczekującym ruchu AI */
        private array $pendingAiMoveRequest = [],

        /** @var bool Flaga oznaczająca czy czekamy na potwierdzenie ruchu przez Raspberry Pi */
        private bool $waitingForRaspiConfirmation = false,

        /** @var array Przechowuje oczekujące powiadomienie UI o ruchu AI */
        private array $pendingUiNotification = [],

        /** @var bool Flaga oznaczająca czy czekamy na cofnięcie nielegalnego ruchu fizycznego przez Raspberry Pi */
        private bool $waitingForMoveRevert = false

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

                // Ignoruj ruchy podczas cofania nielegalnego ruchu
                if ($this->waitingForMoveRevert) {
                    $io->warning("[$timestamp] ⚠️  <fg=yellow>Physical move IGNORED - waiting for illegal move revert confirmation</>");
                    $this->logger?->warning('MQTT: Physical move ignored - waiting for revert', [
                        'topic' => $topic,
                        'message' => $msg,
                        'timestamp' => $timestamp
                    ]);
                    return;
                }

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
                        // GameService automatycznie wyśle ruch do silnika, więc nie robimy tego tutaj

                        // Wykonaj ruch w GameService
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

                // Ignoruj ruchy podczas cofania nielegalnego ruchu
                if ($this->waitingForMoveRevert) {
                    $io->warning("[$timestamp] ⚠️  <fg=yellow>Web move IGNORED - waiting for illegal move revert confirmation</>");
                    $this->logger?->warning('MQTT: Web move ignored - waiting for revert', [
                        'topic' => $topic,
                        'message' => $msg,
                        'timestamp' => $timestamp
                    ]);
                    return;
                }

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
                        // GameService automatycznie wyśle ruch do silnika, więc nie robimy tego tutaj

                        // Wykonaj ruch w GameService
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

                    // Wygeneruj hash dla tego ruchu
                    $moveHash = md5($decoded['from'] . $decoded['to'] . $decoded['fen']);

                    // Sprawdź czy to nie duplikat w krótkim odstępie czasu
                    if (isset($this->lastProcessedMoveHash[$moveHash])) {
                        $lastTime = $this->lastProcessedMoveHash[$moveHash];
                        if (time() - $lastTime < 5) { // ignoruj duplikaty w ciągu 5 sekund
                            $io->text("    ⚠️ <fg=yellow>Ignoring duplicate move confirmation</>");
                            return;
                        }
                    }

                    // Zapisz czas przetworzenia tego ruchu
                    $this->lastProcessedMoveHash[$moveHash] = time();

                    // Wyczyść stare hashe (starsze niż 10 sekund)
                    $this->lastProcessedMoveHash = array_filter(
                        $this->lastProcessedMoveHash,
                        fn($time) => time() - $time < 10
                    );
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

                        // GameService będzie zarządzać żądaniami AI

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
                    // Jeśli odrzucony ruch był fizyczny, ustaw flagę oczekiwania na cofnięcie
                    $isPhysical = $decoded['physical'] ?? false;
                    if ($isPhysical) {
                        $this->waitingForMoveRevert = true;
                        $io->text("    🚫 <fg=red>PHYSICAL move rejected - waiting for RasPi to revert the move</>");
                        $this->logger?->warning('MQTT: Physical move rejected, waiting for revert', [
                            'from' => $decoded['from'],
                            'to' => $decoded['to'],
                            'reason' => $decoded['reason']
                        ]);
                    }

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

                // Zapisz status dla kontroli przepływu AI
                $decodedStatus = null;
                if (is_string($msg)) {
                    if (strtolower(trim($msg)) === 'ready' || strtolower(trim($msg)) === 'moving') {
                        $this->raspiStatus = strtolower(trim($msg));
                    } else {
                        // Próba zdekodowania jako JSON
                        $decodedStatus = json_decode($msg, true);
                        if ($decodedStatus && isset($decodedStatus['status'])) {
                            $this->raspiStatus = strtolower(trim($decodedStatus['status']));
                        }
                    }
                }

                // Jeśli status to "moving", ustaw flagę oczekiwania na potwierdzenie
                if ($this->raspiStatus === 'moving') {
                    // Rozróżnij czy to cofanie nielegalnego ruchu, czy normalny ruch
                    if ($this->waitingForMoveRevert) {
                        $io->text("    🔄 <fg=red>RasPi cofa nielegalny ruch fizyczny - czekamy na zakończenie...</>");
                        $this->logger?->info('MQTT: RasPi reverting illegal physical move');
                    } else {
                        $this->waitingForRaspiConfirmation = true;
                        $io->text("    🔄 <fg=yellow>RasPi wykonuje ruch - czekamy na potwierdzenie</>");
                    }
                }

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

                    // Jeśli status to "ready" i czekaliśmy na cofnięcie nielegalnego ruchu
                    if ($this->raspiStatus === 'ready' && $this->waitingForMoveRevert) {
                        $io->success("    ✅ <fg=green>RasPi zakończyło cofanie nielegalnego ruchu - system odblokowany!</>");
                        $this->logger?->info('MQTT: Illegal move revert completed, system unlocked');

                        // Odblokuj system
                        $this->waitingForMoveRevert = false;

                        // Powiadom UI że cofnięcie zostało zakończone i można wykonać kolejny ruch
                        $this->notifier->broadcast([
                            'type' => 'revert_completed',
                            'message' => 'Illegal move has been reverted. Board is ready for next move.',
                            'timestamp' => $timestamp,
                            'status' => 'ready_for_move'
                        ]);

                        $io->text("    📢 <fg=green>UI notified: system ready for next move</>");
                    }

                    // Jeśli status to "ready" i mamy oczekujące żądanie ruchu AI, uruchom je
                    // Dodatkowo sprawdź czy flaga waitingForRaspiConfirmation była ustawiona (oznacza że RasPi zakończyło ruch)
                    if ($this->raspiStatus === 'ready' && !empty($this->pendingAiMoveRequest) && $this->waitingForRaspiConfirmation) {
                        $io->text("    🤖 <fg=yellow>RasPi zakończyło ruch i jest gotowe, wysyłam oczekujące żądanie ruchu AI</>");
                        $this->logger?->info('MQTT: Sending pending AI move request after RasPi ready', [
                            'pending_request' => $this->pendingAiMoveRequest
                        ]);

                        // Wyślij żądanie ruchu do silnika
                        $this->mqtt->publish('move/engine/request', $this->pendingAiMoveRequest);

                        // Wyczyść oczekujące żądanie i flagę oczekiwania
                        $this->pendingAiMoveRequest = [];
                        $this->waitingForRaspiConfirmation = false;
                    }

                    // Jeśli status to "ready" i mamy oczekujące powiadomienie UI (po ruchu AI), wyślij je
                    if ($this->raspiStatus === 'ready' && !empty($this->pendingUiNotification) && $this->waitingForRaspiConfirmation) {
                        $io->text("    📢 <fg=green>RasPi zakończyło ruch AI, wysyłam powiadomienie do UI</>");
                        $this->logger?->info('MQTT: Sending pending UI notification after RasPi ready', [
                            'notification' => $this->pendingUiNotification
                        ]);

                        // Wyślij powiadomienie do UI przez Mercure
                        $notificationToSend = $this->pendingUiNotification;

                        // Jeśli jest informacja o końcu gry, wyślij osobne powiadomienie
                        if (isset($notificationToSend['game_over'])) {
                            $this->notifier->broadcast([
                                'type' => 'game_over',
                                'result' => $notificationToSend['game_over']['result'],
                                'winner' => $notificationToSend['game_over']['winner'],
                                'final_position' => $notificationToSend['game_over']['final_position'],
                                'moves_count' => $notificationToSend['game_over']['moves_count']
                            ]);
                            unset($notificationToSend['game_over']);
                        }

                        $this->notifier->broadcast($notificationToSend);

                        // Wyczyść oczekujące powiadomienie i flagę oczekiwania
                        $this->pendingUiNotification = [];
                        $this->waitingForRaspiConfirmation = false;
                    }
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

            // POPRAWKA: Subskrybuj aktualizacje stanu (zamiast control/restart)
            // GameService publikuje state/update po resecie
            $this->mqtt->subscribe('state/update', function ($topic, $msg) use ($io) {
                static $lastStateHash = null;

                // Deduplication check
                $currentHash = md5($msg);
                if ($lastStateHash === $currentHash) {
                    $io->text("    ⚠️ <fg=yellow>Duplicate state update ignored</>");
                    $this->logger?->debug('MQTT: Duplicate state update ignored', ['hash' => $currentHash]);
                    return;
                }
                $lastStateHash = $currentHash;

                $timestamp = date('H:i:s');

                // Parsuj JSON i pokaż tylko najważniejsze informacje
                try {
                    $data = json_decode($msg, true);
                    if ($data && isset($data['fen'], $data['moves'])) {
                        $movesCount = count($data['moves']);
                        $currentPlayer = $data['turn'] ?? 'unknown';
                        $gameStatus = $data['game_status'] ?? 'playing';

                        // Sprawdź czy to reset
                        $startFen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
                        if ($data['fen'] === $startFen && empty($data['moves'])) {
                            $io->text("[$timestamp] 🔄 <fg=green>State update:</> Game reset detected");
                            $this->logger?->info('Game: Reset detected in state update');
                        } else {
                            $summary = "Moves: $movesCount | Turn: $currentPlayer | Status: $gameStatus";
                            $io->text("[$timestamp] 📊 <fg=blue>State update:</> $summary");
                        }
                    } else {
                        $io->text("[$timestamp] 📊 <fg=blue>State update:</> " . substr($msg, 0, 50) . "...");
                    }
                } catch (\Exception $e) {
                    $io->text("[$timestamp] 📊 <fg=blue>State update:</> [parsing error]");
                    $this->logger?->warning('Failed to parse state update', ['error' => $e->getMessage()]);
                }

                $this->logger?->info('MQTT: State update received', [
                    'topic' => $topic,
                    'moves_count' => isset($data['moves']) ? count($data['moves']) : 0,
                    'current_player' => $data['turn'] ?? null,
                    'game_status' => $data['game_status'] ?? null,
                    'timestamp' => $timestamp
                ]);
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
                        // Przekaż żądanie do silnika szachowego wraz z aktualnym FEN
                        $currentState = $this->state->getState();
                        $this->mqtt->publish('engine/possible_moves/request', [
                            'position' => $decoded['position'],
                            'fen' => $currentState['fen']
                        ]);

                        $io->text("    ✅ <fg=green>Request forwarded to engine:</> {$decoded['position']} (FEN: {$currentState['fen']})");

                        $this->logger?->info('MQTT: Possible moves request forwarded to engine', [
                            'position' => $decoded['position'],
                            'fen' => $currentState['fen']
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

            // Subskrybuj aktualizacje logu ruchów
            $this->mqtt->subscribe('log/update', function ($topic, $msg) use ($io) {
                static $lastLogHash = null;

                // Deduplication check
                $currentHash = md5($msg);
                if ($lastLogHash === $currentHash) {
                    $io->text("    ⚠️ <fg=yellow>Duplicate log update ignored</>");
                    $this->logger?->debug('MQTT: Duplicate log update ignored', ['hash' => $currentHash]);
                    return;
                }
                $lastLogHash = $currentHash;

                $timestamp = date('H:i:s');

                // Parsuj JSON i pokaż tylko liczbę ruchów
                try {
                    $data = json_decode($msg, true);
                    if ($data && isset($data['moves'])) {
                        $moveCount = count($data['moves']);
                        $lastMove = !empty($data['moves']) ? end($data['moves']) : null;

                        if ($lastMove && isset($lastMove['from'], $lastMove['to'])) {
                            $lastMoveStr = $lastMove['from'] . '→' . $lastMove['to'];
                            $io->text("[$timestamp] 📝 <fg=yellow>Log update:</> $moveCount moves (last: $lastMoveStr)");
                        } else {
                            $io->text("[$timestamp] 📝 <fg=yellow>Log update:</> $moveCount moves");
                        }
                    } else {
                        $io->text("[$timestamp] 📝 <fg=yellow>Log update:</> [no moves data]");
                    }
                } catch (\Exception $e) {
                    $io->text("[$timestamp] 📝 <fg=yellow>Log update:</> [parsing error]");
                }

                $this->logger?->info('MQTT: Log update received', [
                    'topic' => $topic,
                    'moves_count' => isset($data['moves']) ? count($data['moves']) : 0,
                    'timestamp' => $timestamp
                ]);
            });

            // Subskrybuj potwierdnienia resetu od silnika
            $this->mqtt->subscribe('engine/reset/confirmed', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🔄 <fg=yellow>Engine reset confirmed:</> $msg");

                $this->logger?->info('MQTT: Engine reset confirmed', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                try {
                    $decoded = json_decode($msg, true);
                    if ($decoded && isset($decoded['fen']) && $decoded['type'] === 'reset_confirmed') {
                        $oldFEN = $this->state->getState()['fen'] ?? 'unknown';

                        $this->logger?->info('Updating StateStorage with engine reset FEN', [
                            'old_fen' => $oldFEN,
                            'new_fen' => $decoded['fen']
                        ]);

                        // Pełny reset StateStorage (nie tylko FEN!)
                        $this->state->reset();
                        $this->state->setCurrentFen($decoded['fen']);

                        // Teraz wyślij zaktualizowany stan do frontendu
                        $resetState = $this->state->getState();
                        $this->mqtt->publish('state/update', $resetState);

                        // Wyślij log update z dodatkowym polem reset, żeby uniknąć deduplication
                        $this->mqtt->publish('log/update', [
                            'moves' => $resetState['moves'],
                            'reset' => true,
                            'timestamp' => time()
                        ]);

                        $io->text("    ✅ <fg=green>StateStorage synchronized with engine FEN and frontend updated</>");

                        $this->logger?->info('StateStorage synchronized and frontend updated', [
                            'old_fen' => $oldFEN,
                            'new_fen' => $decoded['fen'],
                            'moves_count' => count($resetState['moves']),
                            'reset_moves_should_be_empty' => empty($resetState['moves']) ? 'YES' : 'NO'
                        ]);
                    }
                } catch (\Exception $e) {
                    $io->error("    ❌ Failed to process engine reset confirmation: " . $e->getMessage());
                    $this->logger?->error('MQTT: Engine reset confirmation failed', [
                        'error' => $e->getMessage(),
                        'message' => $msg
                    ]);
                }
            });

            // Subskrybuj wewnętrzny temat żądania ruchu AI (do kontroli przepływu)
            $this->mqtt->subscribe('internal/request_ai_move', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 🤖 <fg=yellow>AI move request received:</> $msg");

                $this->logger?->info('MQTT: Internal AI move request received', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['type']) && $decoded['type'] === 'request_ai_move' && isset($decoded['fen'])) {
                    // Zawsze zapisz żądanie do kolejki - będzie wysłane gdy RasPi potwierdzi gotowość po ruchu
                    $io->text("    🚦 <fg=yellow>Zapisuję żądanie AI - czekam na potwierdzenie ruchu przez RasPi</>");

                    // Zapisz żądanie do wykonania po potwierdzeniu przez RasPi
                    $this->pendingAiMoveRequest = $decoded;

                    // Ustaw flagę oczekiwania na potwierdzenie - będzie zresetowana gdy RasPi wyśle "moving"
                    // i ustawiona ponownie na false gdy RasPi wyśle "ready"

                    $this->logger?->info('MQTT: AI move request queued, waiting for RasPi to complete move', [
                        'fen' => $decoded['fen'],
                        'raspi_status' => $this->raspiStatus,
                        'waiting_for_confirmation' => $this->waitingForRaspiConfirmation
                    ]);
                } else {
                    $io->warning("    ⚠️  Invalid AI move request format");
                    $this->logger?->warning('MQTT: Invalid AI move request format', ['message' => $msg]);
                }
            });

            // Subskrybuj wewnętrzny temat oczekujących powiadomień UI (po ruchu AI)
            $this->mqtt->subscribe('internal/pending_ui_notification', function ($topic, $msg) use ($io) {
                $timestamp = date('H:i:s');
                $io->text("[$timestamp] 📢 <fg=cyan>UI notification queued (waiting for RasPi):</> $msg");

                $this->logger?->info('MQTT: UI notification queued, waiting for RasPi confirmation', [
                    'topic' => $topic,
                    'message' => $msg,
                    'timestamp' => $timestamp
                ]);

                $decoded = json_decode($msg, true);
                if ($decoded && isset($decoded['type'])) {
                    // Zapisz powiadomienie do wysłania po potwierdzeniu przez RasPi
                    $this->pendingUiNotification = $decoded;

                    $io->text("    📋 <fg=yellow>Powiadomienie zapisane - czekam na potwierdzenie RasPi</>");

                    $this->logger?->info('MQTT: UI notification stored, waiting for RasPi ready', [
                        'notification_type' => $decoded['type']
                    ]);
                } else {
                    $io->warning("    ⚠️  Invalid UI notification format");
                    $this->logger?->warning('MQTT: Invalid UI notification format', ['message' => $msg]);
                }
            });

            // Debug logging is now handled by specific subscriptions

            $io->success('MQTT subscriptions established');
            $io->comment('Subscribed to: move/player, move/web, move/ai, engine/move/confirmed, engine/move/rejected, status/raspi, status/engine, state/update, move/possible_moves/request, engine/possible_moves/response, log/update, engine/reset/confirmed, internal/request_ai_move, internal/pending_ui_notification');
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