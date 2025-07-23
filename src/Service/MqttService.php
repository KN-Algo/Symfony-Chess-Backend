<?php
namespace App\Service;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Psr\Log\LoggerInterface;

class MqttService
{
    private MqttClient $client;
    private bool $connected = false;

    public function __construct(
        string $broker,
        int    $port,
        string $clientId,
        private ?LoggerInterface $logger = null
    ) {
        try {
            // Dodaj timestamp aby ID było unikalne
            $uniqueClientId = $clientId . '_' . time() . '_' . random_int(1000, 9999);
            
            $settings = (new ConnectionSettings())->setKeepAliveInterval(60);
            $this->client = new MqttClient($broker, $port, $uniqueClientId);
            $this->client->connect($settings, true);
            $this->connected = true;
            $this->logger?->info("MQTT connected to {$broker}:{$port} as {$uniqueClientId}");
        } catch (\Exception $e) {
            $this->logger?->error("MQTT connection failed: " . $e->getMessage());
            $this->connected = false;
        }
    }

    public function subscribe(string $topic, callable $callback): void
    {
        if (!$this->connected) {
            $this->logger?->warning("MQTT not connected, skipping subscription to {$topic}");
            return;
        }
        
        try {
            $this->client->subscribe($topic, $callback, 0);
            $this->logger?->info("Subscribed to MQTT topic: {$topic}");
        } catch (\Exception $e) {
            $this->logger?->error("MQTT subscription failed: " . $e->getMessage());
            $this->connected = false;
        }
    }

    public function publish(string $topic, ?array $payload): void
    {
        if (!$this->connected) {
            $this->logger?->warning("MQTT not connected, skipping publish to {$topic}");
            return;
        }
        
        try {
            $message = $payload ? json_encode($payload) : '';
            $this->client->publish($topic, $message, 0);
            $this->logger?->info("Published to MQTT topic: {$topic}");
        } catch (\Exception $e) {
            $this->logger?->error("MQTT publish failed: " . $e->getMessage());
        }
    }

    public function loop(): void
    {
        if (!$this->connected) {
            $this->logger?->error("MQTT not connected, cannot start loop");
            return;
        }
        
        try {
            $this->client->loop(true);
        } catch (\Exception $e) {
            $this->logger?->error("MQTT loop failed: " . $e->getMessage());
            $this->connected = false;
            throw $e; // Re-throw aby Command mógł obsłużyć błąd
        }
    }
}