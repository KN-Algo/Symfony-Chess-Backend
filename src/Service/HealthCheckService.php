<?php

namespace App\Service;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Serwis sprawdzania stanu zdrowia komponentów systemu szachowego.
 * 
 * HealthCheckService monitoruje dostępność i responsywność wszystkich kluczowych
 * komponentów systemu szachowego, zapewniając kompleksowy obraz stanu całego systemu.
 * 
 * Monitorowane komponenty:
 * - MQTT Broker: komunikacja między komponentami
 * - Mercure Hub: WebSocket dla aplikacji webowej
 * - Raspberry Pi: kontroler fizycznej szachownicy (placeholder)
 * - Chess Engine: silnik szachowy AI (placeholder)
 * 
 * Serwis optymalizuje wydajność poprzez:
 * - Asynchroniczne sprawdzenia HTTP
 * - Krótkie, konfigurowalne timeouty
 * - Równoległe testowanie wszystkich komponentów
 */
class HealthCheckService
{
    /** @var int Timeout dla żądań HTTP w sekundach */
    private const TIMEOUT = 2; // timeout w sekundach - zmniejszony dla szybszej odpowiedzi

    /** @var int Timeout dla połączeń MQTT w sekundach */
    private const MQTT_TIMEOUT = 3; // krótszy timeout dla MQTT

    /** @var int Próg czasu odpowiedzi w milisekundach - powyżej tego to warning */
    private const RESPONSE_TIME_WARNING_THRESHOLD = 100;

    /**
     * @param string $mqttBroker Adres brokera MQTT
     * @param int $mqttPort Port brokera MQTT
     * @param string $mercureUrl URL hubu Mercure
     * @param string $raspberryUrl URL endpointu Raspberry Pi
     * @param string $chessEngineUrl URL endpointu silnika szachowego
     * @param LoggerInterface|null $logger Logger do zapisywania błędów (opcjonalny)
     * @param HttpClientInterface|null $httpClient Klient HTTP (zostanie utworzony jeśli null)
     */
    public function __construct(
        private string $mqttBroker,
        private int $mqttPort,
        private string $mercureUrl,
        private string $raspberryUrl,
        private string $chessEngineUrl,
        private ?LoggerInterface $logger = null,
        private ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT
        ]);
    }

    /**
     * Przeprowadza kompleksowe sprawdzenie stanu zdrowia systemu.
     * 
     * Metoda asynchronicznie testuje wszystkie komponenty systemu i zwraca
     * szczegółowy raport o ich dostępności i wydajności. Optymalizuje czas
     * wykonania poprzez równoległe sprawdzenia HTTP i szybkie testy MQTT.
     * 
     * @return array{
     *   mqtt: array,
     *   mercure: array,
     *   raspberry: array,
     *   chess_engine: array,
     *   overall_status: string,
     *   timestamp: string,
     *   total_time: string
     * } Kompletny raport stanu zdrowia systemu
     */
    public function getSystemHealth(): array
    {
        $startTime = microtime(true);

        // Rozpocznij wszystkie sprawdzenia HTTP asynchronicznie
        $responses = [];

        // MQTT sprawdzamy synchronicznie (szybki test)
        $mqttResult = $this->checkMqttConnection();

        // Żądania HTTP asynchronicznie
        try {
            $responses['mercure'] = $this->httpClient->request('GET', $this->mercureUrl, [
                'timeout' => self::TIMEOUT
            ]);
        } catch (\Exception $e) {
            $responses['mercure'] = null;
        }

        try {
            $responses['raspberry'] = $this->httpClient->request('GET', $this->raspberryUrl . '/health', [
                'timeout' => self::TIMEOUT
            ]);
        } catch (\Exception $e) {
            $responses['raspberry'] = null;
        }

        try {
            $responses['chess_engine'] = $this->httpClient->request('GET', $this->chessEngineUrl . '/health', [
                'timeout' => self::TIMEOUT
            ]);
        } catch (\Exception $e) {
            $responses['chess_engine'] = null;
        }

        // Przetwórz odpowiedzi
        $mercureResult = $this->processMercureResponse($responses['mercure']);
        $raspberryResult = $this->processRaspberryResponse($responses['raspberry']);
        $chessEngineResult = $this->processChessEngineResponse($responses['chess_engine']);

        $results = [
            'mqtt' => $mqttResult,
            'mercure' => $mercureResult,
            'raspberry' => $raspberryResult,
            'chess_engine' => $chessEngineResult,
            'overall_status' => $this->calculateOverallStatusFromResults(
                $mqttResult,
                $mercureResult,
                $raspberryResult,
                $chessEngineResult
            ),
            'timestamp' => date('c'),
            'total_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ];

        return $results;
    }

    private function processMercureResponse($response): array
    {
        if ($response === null) {
            return [
                'status' => 'unhealthy',
                'message' => 'Mercure hub connection failed: Request timeout or connection error',
                'endpoint' => $this->mercureUrl,
                'response_time' => null
            ];
        }

        try {
            $startTime = microtime(true);
            $statusCode = $response->getStatusCode();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Dla Mercure, używamy endpoint /healthz który zwraca czyste HTTP 200
            if ($statusCode >= 200 && $statusCode < 400) {
                $status = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD ? 'warning' : 'healthy';
                $message = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD
                    ? "Mercure hub connection successful but slow (>{self::RESPONSE_TIME_WARNING_THRESHOLD}ms)"
                    : 'Mercure hub connection successful';

                return [
                    'status' => $status,
                    'message' => $message,
                    'endpoint' => $this->mercureUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'message' => "Mercure hub returned status code: {$statusCode}",
                    'endpoint' => $this->mercureUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            }
        } catch (\Exception $e) {
            $this->logger?->error('Mercure health check failed: ' . $e->getMessage());
            return [
                'status' => 'unhealthy',
                'message' => 'Mercure hub connection failed: ' . $e->getMessage(),
                'endpoint' => $this->mercureUrl,
                'response_time' => null
            ];
        }
    }

    private function processRaspberryResponse($response): array
    {
        if ($response === null) {
            return [
                'status' => 'unhealthy',
                'message' => 'Raspberry Pi not available: Connection timeout or not deployed',
                'endpoint' => $this->raspberryUrl,
                'response_time' => null,
                'note' => 'External component - ensure Raspberry Pi is running and accessible'
            ];
        }

        try {
            $startTime = microtime(true);
            $statusCode = $response->getStatusCode();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($statusCode >= 200 && $statusCode < 400) {
                $status = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD ? 'warning' : 'healthy';
                $message = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD
                    ? "Raspberry Pi connection successful but slow (>{self::RESPONSE_TIME_WARNING_THRESHOLD}ms)"
                    : 'Raspberry Pi connection successful';

                return [
                    'status' => $status,
                    'message' => $message,
                    'endpoint' => $this->raspberryUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'message' => "Raspberry Pi returned status code: {$statusCode}",
                    'endpoint' => $this->raspberryUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Raspberry Pi not available: ' . $e->getMessage(),
                'endpoint' => $this->raspberryUrl,
                'response_time' => null,
                'note' => 'External component - ensure Raspberry Pi is running and accessible'
            ];
        }
    }

    private function processChessEngineResponse($response): array
    {
        if ($response === null) {
            return [
                'status' => 'unhealthy',
                'message' => 'Chess engine not available: Connection timeout or not deployed',
                'endpoint' => $this->chessEngineUrl,
                'response_time' => null,
                'note' => 'External component - ensure Chess Engine is running and accessible'
            ];
        }

        try {
            $startTime = microtime(true);
            $statusCode = $response->getStatusCode();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($statusCode >= 200 && $statusCode < 400) {
                $status = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD ? 'warning' : 'healthy';
                $message = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD
                    ? "Chess engine connection successful but slow (>{self::RESPONSE_TIME_WARNING_THRESHOLD}ms)"
                    : 'Chess engine connection successful';

                return [
                    'status' => $status,
                    'message' => $message,
                    'endpoint' => $this->chessEngineUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'message' => "Chess engine returned status code: {$statusCode}",
                    'endpoint' => $this->chessEngineUrl,
                    'response_time' => $responseTime . 'ms',
                    'status_code' => $statusCode
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Chess engine not available: ' . $e->getMessage(),
                'endpoint' => $this->chessEngineUrl,
                'response_time' => null,
                'note' => 'External component - ensure Chess Engine is running and accessible'
            ];
        }
    }

    private function calculateOverallStatusFromResults($mqttStatus, $mercureStatus, $raspberryStatus, $chessEngineStatus): string
    {
        $criticalServices = [$mqttStatus, $mercureStatus];
        $externalServices = [$raspberryStatus, $chessEngineStatus];

        // Sprawdź czy wszystkie krytyczne usługi są zdrowe
        foreach ($criticalServices as $status) {
            if ($status['status'] === 'unhealthy') {
                return 'unhealthy';
            }
        }

        // Sprawdź czy krytyczne usługi mają ostrzeżenia
        foreach ($criticalServices as $status) {
            if ($status['status'] === 'warning') {
                return 'warning';
            }
        }

        // Sprawdź czy zewnętrzne komponenty są niedostępne
        foreach ($externalServices as $status) {
            if ($status['status'] === 'unhealthy') {
                return 'warning'; // External component down = warning for overall system
            }
        }

        // Sprawdź czy są jakiekolwiek inne ostrzeżenia
        $allServices = [$mqttStatus, $mercureStatus, $raspberryStatus, $chessEngineStatus];
        foreach ($allServices as $status) {
            if ($status['status'] === 'warning') {
                return 'warning';
            }
        }

        return 'healthy';
    }

    private function checkMqttConnection(): array
    {
        try {
            $startTime = microtime(true);
            $clientId = 'health_check_' . uniqid();

            // Ustawienia z krótszym limitem czasu
            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(10)
                ->setConnectTimeout(self::MQTT_TIMEOUT)
                ->setSocketTimeout(self::MQTT_TIMEOUT);

            $client = new MqttClient($this->mqttBroker, $this->mqttPort, $clientId);

            $client->connect($settings, false);
            $client->disconnect();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $status = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD ? 'warning' : 'healthy';
            $message = $responseTime > self::RESPONSE_TIME_WARNING_THRESHOLD
                ? "MQTT broker connection successful but slow (>{self::RESPONSE_TIME_WARNING_THRESHOLD}ms)"
                : 'MQTT broker connection successful';

            return [
                'status' => $status,
                'message' => $message,
                'endpoint' => "{$this->mqttBroker}:{$this->mqttPort}",
                'response_time' => $responseTime . 'ms'
            ];
        } catch (\Exception $e) {
            $this->logger?->error('MQTT health check failed: ' . $e->getMessage());
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'unhealthy',
                'message' => 'MQTT broker connection failed: ' . $e->getMessage(),
                'endpoint' => "{$this->mqttBroker}:{$this->mqttPort}",
                'response_time' => $responseTime . 'ms'
            ];
        }
    }
}
