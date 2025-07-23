<?php
namespace App\Service;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class HealthCheckService
{
    private const TIMEOUT = 2; // timeout w sekundach - zmniejszony dla szybszych odpowiedzi
    private const MQTT_TIMEOUT = 3; // krótszy timeout dla MQTT

    public function __construct(
        private string $mqttBroker,
        private int $mqttPort,
        private string $mercureUrl = 'http://127.0.0.1:3000',
        private string $raspberryUrl = 'http://192.168.1.100:8080', // placeholder
        private string $chessEngineUrl = 'http://192.168.1.101:5000', // placeholder
        private ?LoggerInterface $logger = null,
        private ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT
        ]);
    }

    public function getSystemHealth(): array
    {
        $startTime = microtime(true);
        
        // Rozpocznij wszystkie sprawdzenia HTTP asynchronicznie
        $responses = [];
        
        // MQTT sprawdzamy synchronicznie (szybkie)
        $mqttResult = $this->checkMqttConnection();
        
        // HTTP requests asynchronicznie
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
                $mqttResult, $mercureResult, $raspberryResult, $chessEngineResult
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

            if ($statusCode >= 200 && $statusCode < 400) {
                return [
                    'status' => 'healthy',
                    'message' => 'Mercure hub connection successful',
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
                'status' => 'warning',
                'message' => 'Raspberry Pi not available (placeholder endpoint): Connection timeout',
                'endpoint' => $this->raspberryUrl,
                'response_time' => null,
                'note' => 'This is a placeholder endpoint - actual Raspberry Pi not yet deployed'
            ];
        }

        try {
            $startTime = microtime(true);
            $statusCode = $response->getStatusCode();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($statusCode >= 200 && $statusCode < 400) {
                return [
                    'status' => 'healthy',
                    'message' => 'Raspberry Pi connection successful',
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
                'status' => 'warning',
                'message' => 'Raspberry Pi not available (placeholder endpoint): ' . $e->getMessage(),
                'endpoint' => $this->raspberryUrl,
                'response_time' => null,
                'note' => 'This is a placeholder endpoint - actual Raspberry Pi not yet deployed'
            ];
        }
    }

    private function processChessEngineResponse($response): array
    {
        if ($response === null) {
            return [
                'status' => 'warning',
                'message' => 'Chess engine not available (placeholder endpoint): Connection timeout',
                'endpoint' => $this->chessEngineUrl,
                'response_time' => null,
                'note' => 'This is a placeholder endpoint - actual chess engine not yet deployed'
            ];
        }

        try {
            $startTime = microtime(true);
            $statusCode = $response->getStatusCode();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($statusCode >= 200 && $statusCode < 400) {
                return [
                    'status' => 'healthy',
                    'message' => 'Chess engine connection successful',
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
                'status' => 'warning',
                'message' => 'Chess engine not available (placeholder endpoint): ' . $e->getMessage(),
                'endpoint' => $this->chessEngineUrl,
                'response_time' => null,
                'note' => 'This is a placeholder endpoint - actual chess engine not yet deployed'
            ];
        }
    }

    private function calculateOverallStatusFromResults($mqttStatus, $mercureStatus, $raspberryStatus, $chessEngineStatus): string
    {
        $criticalServices = [$mqttStatus, $mercureStatus];
        
        // Sprawdź czy wszystkie krytyczne usługi są zdrowe
        foreach ($criticalServices as $status) {
            if ($status['status'] === 'unhealthy') {
                return 'unhealthy';
            }
        }
        
        // Sprawdź czy są jakieś ostrzeżenia
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
            
            // Ustawienia z krótszym timeoutem
            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(10)
                ->setConnectTimeout(self::MQTT_TIMEOUT)
                ->setSocketTimeout(self::MQTT_TIMEOUT);
                
            $client = new MqttClient($this->mqttBroker, $this->mqttPort, $clientId);
            
            $client->connect($settings, false);
            $client->disconnect();
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => 'healthy',
                'message' => 'MQTT broker connection successful',
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
