<?php
$db_host = '127.0.0.1';
$db_port = 3306;
$db_name = 'tenancy';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT id, email, api_token FROM users WHERE api_token IS NOT NULL AND api_token != '' LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && !empty($user['api_token'])) {
        $token = $user['api_token'];
        echo "Token encontrado: $token\n";
    } else {
        echo "Generando nuevo token...\n";
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE users SET api_token = ? WHERE email = 'admin@gmail.com'");
        $stmt->execute([$token]);
        echo "Nuevo token: $token\n";
    }
    
    $env_file = __DIR__ . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        $env_content = preg_replace('/^MIEMPRESA_API_TOKEN=.*$/m', "MIEMPRESA_API_TOKEN=$token", $env_content);
        file_put_contents($env_file, $env_content);
        echo "Token configurado en .env\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
