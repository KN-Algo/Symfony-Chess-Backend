<?php
namespace App\Service;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttService
{
    private MqttClient $client;

    public function __construct(
        string $broker,
        int    $port,
        string $clientId
    ) {
        $settings = (new ConnectionSettings())->setKeepAliveInterval(60);
        $this->client = new MqttClient($broker, $port, $clientId);
        $this->client->connect($settings, true);
    }

    public function subscribe(string $topic, callable $callback): void
    {
        $this->client->subscribe($topic, $callback, 0);
    }

    public function publish(string $topic, ?array $payload): void
    {
        $message = $payload ? json_encode($payload) : '';
        $this->client->publish($topic, $message, 0);
    }

    public function loop(): void
    {
        $this->client->loop(true);
    }
}
