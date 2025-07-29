#!/usr/bin/env php
<?php
/**
 * Prosty skrypt testowy dla endpointu możliwych ruchów.
 * 
 * Skrypt wysyła żądanie na endpoint /possible-moves i wyświetla wynik.
 * Można go używać do testowania nowej funkcjonalności bez konieczności
 * uruchamiania pełnej aplikacji webowej.
 * 
 * Użycie:
 * php test_possible_moves.php [pozycja]
 * 
 * Przykłady:
 * php test_possible_moves.php e2
 * php test_possible_moves.php a1
 */

// Sprawdź argumenty
$position = $argv[1] ?? 'e2';

// Walidacja pozycji
if (!preg_match('/^[a-h][1-8]$/', $position)) {
    echo "❌ Błąd: Nieprawidłowy format pozycji '$position'. Oczekiwany format: a1-h8\n";
    exit(1);
}

// Konfiguracja
$baseUrl = 'http://localhost:8000';
$endpoint = '/possible-moves';

// Przygotuj dane żądania
$data = json_encode(['position' => $position]);

echo "🔍 Testuję endpoint możliwych ruchów...\n";
echo "📍 Pozycja: $position\n";
echo "🌐 URL: $baseUrl$endpoint\n";
echo "📤 Wysyłam żądanie...\n\n";

// Konfiguracja cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl . $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5
]);

// Wykonaj żądanie
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Sprawdź błędy cURL
if ($error) {
    echo "❌ Błąd cURL: $error\n";
    exit(1);
}

// Wyświetl wynik
echo "📥 Odpowiedź HTTP: $httpCode\n";

if ($httpCode === 200) {
    echo "✅ Sukces! Żądanie zostało wysłane.\n";
    
    $result = json_decode($response, true);
    if ($result && isset($result['status'])) {
        echo "📊 Status: {$result['status']}\n";
    }
    
    echo "\n💡 Uwaga: Odpowiedź z możliwymi ruchami zostanie przesłana przez WebSocket.\n";
    echo "   Sprawdź logi aplikacji lub subskrybuj Mercure na http://localhost:3000\n";
    echo "   Topic: https://127.0.0.1:8000/chess/updates\n";
    
} else {
    echo "❌ Błąd HTTP $httpCode\n";
    echo "📄 Odpowiedź: $response\n";
}

echo "\n🔧 Aby zobaczyć komunikaty MQTT, uruchom: php bin/console app:mqtt-listen\n";
