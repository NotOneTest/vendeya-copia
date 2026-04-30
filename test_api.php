<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

echo "Testing MiEmpresa API connection...\n\n";

// Test login
$response = Http::timeout(30)->post('http://miempresa.pro-8-2026.test/api/login', [
    'email' => 'admin@miempresa.com',
    'password' => '123456',
]);

echo "Login status: " . $response->status() . "\n";
$data = $response->json();
echo "Login response: " . substr(json_encode($data), 0, 500) . "\n\n";

if (isset($data['token'])) {
    $token = $data['token'];
    echo "Token obtained: " . substr($token, 0, 50) . "...\n\n";
    
    // Test items endpoint
    $response2 = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
    ])->timeout(30)->get('http://miempresa.pro-8-2026.test/api/items', ['limit' => 10]);
    
    echo "Items status: " . $response2->status() . "\n";
    $items = $response2->json();
    echo "Items response: " . substr(json_encode($items), 0, 2000) . "\n";
} else {
    echo "No token received. Check credentials.\n";
}
