<?php
namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Serwis powiadomień real-time dla aplikacji webowej.
 * 
 * NotifierService zapewnia komunikację WebSocket z frontendem poprzez protokół Mercure.
 * Umożliwia wysyłanie aktualizacji stanu gry, powiadomień o ruchach oraz statusów
 * komponentów systemu do wszystkich podłączonych klientów webowych.
 * 
 * Mercure jest protokołem Server-Sent Events (SSE) zapewniającym real-time communication
 * między serwerem a przeglądarkami bez potrzeby implementacji pełnego WebSocket.
 */
class NotifierService
{
    /**
     * @param HubInterface $hub Hub Mercure do publikacji aktualizacji
     * @param HttpClientInterface $httpClient HTTP client do bezpośredniej komunikacji z Mercure
     */
    public function __construct(
        private HubInterface $hub, 
        private HttpClientInterface $httpClient
    ) {}

    /**
     * Publikuje wiadomość bezpośrednio do Mercure Hub przez HTTP
     */
    private function publishDirectly(string $topic, string $data): string
    {
        $token = $this->generateJwtToken();
        
        try {
            $response = $this->httpClient->request('POST', 'http://localhost:3000/.well-known/mercure', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'topic' => $topic,
                    'data' => $data,
                ])
            ]);
            
            return $response->getContent();
        } catch (\Exception $e) {
            throw new \Exception('Failed to publish to Mercure: ' . $e->getMessage());
        }
    }
    /**
     * Generuje JWT token dla publikowania w Mercure
     */
    private function generateJwtToken(): string
    {
        $secret = $_ENV['MERCURE_JWT_SECRET']; // Ten sam co w .env
        if (!$secret) {
            throw new \Exception('MERCURE_JWT_SECRET is not set in environment variables');
        }
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret)
        );

        $token = $config->builder()
            ->withClaim('mercure', ['publish' => ['*']])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    /**
     * Wysyła dane do wszystkich podłączonych klientów webowych.
     * 
     * Metoda publikuje dane jako aktualizację Mercure, która zostanie automatycznie
     * dostarczona do wszystkich przeglądarek subskrybujących kanał aktualizacji szachów.
     * 
     * Typowe zastosowania:
     * - Aktualizacje stanu planszy po ruchu
     * - Powiadomienia o ruchach AI
     * - Statusy komponentów (Raspberry Pi, silnik szachowy)
     * - Komunikaty o błędach lub zakończeniu gry
     * 
     * @param array $data Dane do wysłania (zostaną zserializowane do JSON)
     * @return void
     * @throws \Exception W przypadku błędu publikacji przez hub Mercure
     */
    public function broadcast(array $data): void
    {
        // SPRÓBUJ Z PEŁNYM URL
        $json = json_encode($data);
        $topic = 'http://127.0.0.1:8000/chess/updates';  // ZMIANA: pełny URL
        
        $logFile = __DIR__ . '/../../public/mercure-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $log = "===== NotifierService::broadcast() ===== {$timestamp}\n";
        $log .= "Topic: {$topic}\n";
        $log .= "Data: {$json}\n";
        
        try {
            // Spróbuj bezpośredniego HTTP zapytania
            $log .= "Attempting DIRECT HTTP publish...\n";
            $result = $this->publishDirectly($topic, $json);
            $log .= "Direct HTTP result: {$result}\n";
            $log .= "Direct HTTP SUCCESS!\n";
            
        } catch (\Exception $e) {
            $log .= "Direct HTTP failed: " . $e->getMessage() . "\n";
            
            // Fallback do Symfony Hub
            if ($this->hub) {
                $log .= "Fallback to Symfony Hub...\n";
                $log .= "Hub available: YES\n";
                $log .= "Hub class: " . get_class($this->hub) . "\n";
                
                try {
                    $update = new Update($topic, $json, false);
                    $log .= "Update object created successfully (PUBLIC)\n";
                    $log .= "Update topic: " . ($update->getTopics()[0] ?? 'NO_TOPIC') . "\n";
                    $log .= "Update data: " . $update->getData() . "\n";
                    $log .= "Update is private: " . ($update->isPrivate() ? 'YES' : 'NO') . "\n";
                    
                    $log .= "Attempting Symfony Hub publish...\n";
                    $hubResult = $this->hub->publish($update);
                    $log .= "Hub publish result: '" . $hubResult . "'\n";
                    $log .= "Hub publish result type: " . gettype($hubResult) . "\n";
                    
                } catch (\Exception $hubError) {
                    $log .= "Symfony Hub also failed: " . $hubError->getMessage() . "\n";
                    throw $hubError;
                }
            } else {
                $log .= "No Symfony Hub available\n";
                throw $e;
            }
        }
        
        $log .= "===== Broadcast completed =====\n";
        file_put_contents($logFile, $log, FILE_APPEND | LOCK_EX);
    }
}