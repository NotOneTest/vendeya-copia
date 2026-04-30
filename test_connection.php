<?php
echo "Testing different connection methods...\n\n";

// Method 1: Try database connection
echo "Method 1: Testing database connection to tenancy...\n";
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in tenancy: " . implode(', ', $tables) . "\n";
    
    if (in_array('items', $tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM items");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Items count: " . $count['count'] . "\n";
    }
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "\nMethod 2: Testing API endpoints...\n";

$endpoints = [
    '/api/login',
    '/login',
    '/api/v1/login',
    '/oauth/token'
];

foreach ($endpoints as $endpoint) {
    echo "Testing $endpoint...\n";
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['email' => 'admin@miempresa.com', 'password' => '123456']),
            'timeout' => 10
        ]
    ]);
    
    $result = @file_get_contents("http://miempresa.pro-8-2026.test$endpoint", false, $context);
    if ($result) {
        echo "Response: " . substr($result, 0, 200) . "\n";
    } else {
        echo "Failed or no response\n";
    }
}

echo "\nDone testing.\n";
