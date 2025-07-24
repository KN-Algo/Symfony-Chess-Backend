<?php
namespace App\Service;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Psr\Log\LoggerInterface;

/**
 * Serwis komunikacji MQTT dla systemu szachowego.
 * 
 * MqttService zapewnia komunikację z brokerem MQTT, umożliwiając wymianę komunikatów
 * między komponentami systemu szachowego (backend, Raspberry Pi, silnik szachowy).
 * 
 * Serwis obsługuje:
 * - Połączenie z brokerem MQTT z automatycznym zarządzaniem sesją
 * - Subskrypcję na kanały (tematy) MQTT
 * - Publikowanie komunikatów na określone kanały
 * - Pętlę nasłuchiwania komunikatów
 * - Obsługę błędów połączenia i mechanizmy odzyskiwania

 */
class MqttService
{
    /** @var MqttClient Klient MQTT do komunikacji z brokerem */
    private MqttClient $client;
    
    /** @var bool Status połączenia z brokerem MQTT */
    private bool $connected = false;

    /**
     * Konstruktor serwisu MQTT.
     * 
     * Inicjalizuje połączenie z brokerem MQTT używając unikalnego identyfikatora klienta.
     * W przypadku błędu połączenia, serwis przechodzi w tryb offline.
     * 
     * @param string $broker Adres IP lub hostname brokera MQTT
     * @param int $port Port brokera MQTT (zazwyczaj 1883)
     * @param string $clientId Bazowy identyfikator klienta (zostanie rozszerzony o timestamp i random)
     * @param LoggerInterface|null $logger Logger do zapisywania informacji o połączeniu (opcjonalny)
     */
    public function __construct(
        string $broker,
        int    $port,
        string $clientId,
        private ?LoggerInterface $logger = null
    ) {
        try {
            // Dodaj znacznik czasu aby ID było unikalne
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

    /**
     * Subskrybuje kanał MQTT i rejestruje callback do przetwarzania komunikatów.
     * 
     * Metoda rejestruje funkcję callback, która będzie wywoływana za każdym razem,
     * gdy na określonym kanale pojawi się nowa wiadomość. Jeśli połączenie MQTT
     * nie jest aktywne, subskrypcja zostanie pominięta z ostrzeżeniem w logu.
     * 
     * @param string $topic Nazwa kanału MQTT do subskrypcji (np. "move/player")
     * @param callable $callback Funkcja callback(string $topic, string $message) do przetwarzania wiadomości
     * @return void
     */
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

    /**
     * Publikuje wiadomość na określonym kanale MQTT.
     * 
     * Metoda serializuje dane do JSON i wysyła je na określony kanał MQTT.
     * Jeśli połączenie nie jest aktywne, publikacja zostanie pominięta z ostrzeżeniem.
     * 
     * @param string $topic Nazwa kanału MQTT do publikacji (np. "move/raspi")
     * @param array|null $payload Dane do wysłania (zostaną zserializowane do JSON) lub null dla pustej wiadomości
     * @return void
     */
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

    /**
     * Uruchamia główną pętlę nasłuchiwania MQTT.
     * 
     * Metoda uruchamia blokującą pętlę, która nasłuchuje na przychodzące komunikaty MQTT
     * i wywołuje odpowiednie callbacki. Pętla działa w trybie ciągłym until explicitly stopped.
     * 
     * UWAGA: Ta metoda blokuje wykonanie do momentu zatrzymania lub wystąpienia błędu.
     * Powinna być uruchamiana w osobnym procesie lub wątku.
     * 
     * @return void
     * @throws \Exception W przypadku błędu połączenia MQTT lub braku aktywnego połączenia
     */
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
            throw $e; // Ponownie rzuć wyjątek aby Command mógł obsłużyć błąd
        }
    }
}